<?php

declare(strict_types=1);

/**
 * Maho
 *
 * @category   Maho
 * @package    Maho_Ai
 * @copyright  Copyright (c) 2025-2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Maho_Ai_Model_Task_Runner
{
    /**
     * Process pending tasks from the queue (cron entry point)
     */
    public function processQueue(): void
    {
        if (!Mage::getStoreConfigFlag('maho_ai/queue/enabled')) {
            return;
        }

        $maxTasks = (int) Mage::getStoreConfig('maho_ai/queue/max_tasks_per_run') ?: 10;
        $timeout  = (int) Mage::getStoreConfig('maho_ai/queue/task_timeout') ?: 120;

        // Mark timed-out processing tasks as failed first
        $this->recoverTimedOutTasks($timeout);

        // Load pending tasks ordered by priority (interactive first) then age
        /** @var Maho_Ai_Model_Resource_Task_Collection $collection */
        $collection = Mage::getModel('ai/task')->getCollection();
        $collection->addFieldToFilter('status', Maho_Ai_Model_Task::STATUS_PENDING)
            ->addExpressionFieldToSelect(
                'priority_order',
                'CASE WHEN {{priority}} = \'interactive\' THEN 0 ELSE 1 END',
                ['priority' => 'priority'],
            )
            ->setOrder('priority_order', 'ASC')
            ->setOrder('created_at', 'ASC')
            ->setPageSize($maxTasks);

        foreach ($collection as $task) {
            $this->executeTask($task);
        }
    }

    /**
     * Aggregate completed task usage into the daily usage table
     */
    public function aggregateUsage(): void
    {
        $connection = Mage::getSingleton('core/resource')->getConnection('core_write');
        $taskTable  = Mage::getSingleton('core/resource')->getTableName('ai/task');
        $usageTable = Mage::getSingleton('core/resource')->getTableName('ai/usage');

        // Aggregate yesterday's completed tasks into usage
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $connection->query("
            INSERT INTO {$usageTable} 
                (consumer, platform, model, store_id, period_date, request_count, input_tokens, output_tokens, estimated_cost)
            SELECT 
                consumer,
                platform,
                model,
                store_id,
                DATE(completed_at) as period_date,
                COUNT(*) as request_count,
                SUM(input_tokens) as input_tokens,
                SUM(output_tokens) as output_tokens,
                SUM(estimated_cost) as estimated_cost
            FROM {$taskTable}
            WHERE status = 'complete'
                AND DATE(completed_at) = '{$yesterday}'
                AND platform IS NOT NULL
            GROUP BY consumer, platform, model, store_id, DATE(completed_at)
            ON DUPLICATE KEY UPDATE
                request_count  = request_count + VALUES(request_count),
                input_tokens   = input_tokens + VALUES(input_tokens),
                output_tokens  = output_tokens + VALUES(output_tokens),
                estimated_cost = estimated_cost + VALUES(estimated_cost)
        ");
    }

    /**
     * Clean up old completed/failed tasks (keeps last 90 days)
     */
    public function cleanupOldTasks(): void
    {
        $connection = Mage::getSingleton('core/resource')->getConnection('core_write');
        $taskTable  = Mage::getSingleton('core/resource')->getTableName('ai/task');
        $cutoff     = date('Y-m-d H:i:s', strtotime('-90 days'));

        $connection->delete($taskTable, [
            'status IN (?)' => [Maho_Ai_Model_Task::STATUS_COMPLETE, Maho_Ai_Model_Task::STATUS_FAILED, Maho_Ai_Model_Task::STATUS_CANCELLED],
            'completed_at < ?' => $cutoff,
        ]);
    }

    private function executeTask(Maho_Ai_Model_Task $task): void
    {
        $task->markProcessing()->save();

        try {
            $messages = $task->getMessagesArray();

            // Prepend system prompt if present
            if ($task->getData('system_prompt')) {
                array_unshift($messages, ['role' => 'system', 'content' => $task->getData('system_prompt')]);
            }

            $options = array_filter([
                'model' => $task->getData('model'),
            ]);

            $provider = Mage::getSingleton('ai/platform_factory')->create(
                $task->getData('platform') ?: null,
                $task->getData('store_id') ?: null,
            );

            $response = $provider->complete($messages, $options);

            // Sanitize output
            $metadata = [];
            $response = Mage::getSingleton('ai/safety_outputSanitizer')->sanitize($response, false, $metadata);

            $usage = $provider->getLastTokenUsage();

            $task->markComplete(
                response: $response,
                inputTokens: $usage['input'],
                outputTokens: $usage['output'],
                platform: $provider->getPlatformCode(),
                model: $provider->getLastModel(),
            )->save();

            // Fire callback if registered
            $this->fireCallback($task, $response);

        } catch (Throwable $e) {
            $task->markFailed($e->getMessage())->save();
            Mage::log(
                sprintf('Maho AI task #%d failed: %s', $task->getId(), $e->getMessage()),
                Mage::LOG_ERROR,
                'maho_ai.log',
            );
        }
    }

    private function fireCallback(Maho_Ai_Model_Task $task, string $response): void
    {
        $callbackClass  = $task->getData('callback_class');
        $callbackMethod = $task->getData('callback_method');

        if (!$callbackClass || !$callbackMethod) {
            return;
        }

        if (!class_exists($callbackClass)) {
            Mage::log("Maho AI: callback class {$callbackClass} not found", Mage::LOG_WARNING, 'maho_ai.log');
            return;
        }

        $instance = new $callbackClass();
        if (!method_exists($instance, $callbackMethod)) {
            Mage::log("Maho AI: callback method {$callbackClass}::{$callbackMethod} not found", Mage::LOG_WARNING, 'maho_ai.log');
            return;
        }

        $instance->$callbackMethod($task, $response);
    }

    private function recoverTimedOutTasks(int $timeoutSeconds): void
    {
        $connection = Mage::getSingleton('core/resource')->getConnection('core_write');
        $taskTable  = Mage::getSingleton('core/resource')->getTableName('ai/task');
        $cutoff     = date('Y-m-d H:i:s', time() - $timeoutSeconds);

        // Re-queue timed-out tasks (they'll be retried up to max_retries)
        $connection->query("
            UPDATE {$taskTable}
            SET 
                status = CASE WHEN retries >= max_retries THEN 'failed' ELSE 'pending' END,
                retries = retries + 1,
                error_message = 'Task timed out',
                completed_at = CASE WHEN retries >= max_retries THEN NOW() ELSE NULL END
            WHERE status = 'processing'
                AND started_at < '{$cutoff}'
        ");
    }
}
