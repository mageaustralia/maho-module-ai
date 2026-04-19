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

class Maho_Ai_Model_TaskRunner
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
                "CASE WHEN {{priority}} = 'interactive' THEN 0 ELSE 1 END",
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
     * Aggregate completed task usage into the daily usage table.
     *
     * Idempotent: deletes yesterday's existing rollup before reinserting, so
     * running the cron twice on the same day doesn't double-count. Uses the
     * query builder + insertMultiple to stay portable across MySQL/Postgres/SQLite
     * (no ON DUPLICATE KEY UPDATE).
     */
    public function aggregateUsage(): void
    {
        $connection = Mage::getSingleton('core/resource')->getConnection('core_write');
        $taskTable  = Mage::getSingleton('core/resource')->getTableName('ai/task');
        $usageTable = Mage::getSingleton('core/resource')->getTableName('ai/usage');

        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $select = $connection->select()
            ->from($taskTable, [
                'consumer',
                'platform',
                'model',
                'store_id',
                'period_date'    => new \Maho\Db\Expr('DATE(completed_at)'),
                'request_count'  => new \Maho\Db\Expr('COUNT(*)'),
                'input_tokens'   => new \Maho\Db\Expr('SUM(input_tokens)'),
                'output_tokens'  => new \Maho\Db\Expr('SUM(output_tokens)'),
                'estimated_cost' => new \Maho\Db\Expr('SUM(estimated_cost)'),
            ])
            ->where('status = ?', Maho_Ai_Model_Task::STATUS_COMPLETE)
            ->where('DATE(completed_at) = ?', $yesterday)
            ->where('platform IS NOT NULL')
            ->group(['consumer', 'platform', 'model', 'store_id', new \Maho\Db\Expr('DATE(completed_at)')]);

        $rows = $connection->fetchAll($select);
        if ($rows === []) {
            return;
        }

        $connection->beginTransaction();
        try {
            $connection->delete($usageTable, ['period_date = ?' => $yesterday]);
            $connection->insertMultiple($usageTable, $rows);
            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();
            throw $throwable;
        }
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
            $taskType = $task->getData('task_type') ?: Maho_Ai_Model_Task::TYPE_COMPLETION;

            match ($taskType) {
                Maho_Ai_Model_Task::TYPE_COMPLETION => $this->executeCompletionTask($task),
                Maho_Ai_Model_Task::TYPE_EMBEDDING  => $this->executeEmbedTask($task),
                Maho_Ai_Model_Task::TYPE_IMAGE      => $this->executeImageTask($task),
                default => throw new Mage_Core_Exception('Unknown task type: ' . $taskType),
            };
        } catch (Throwable $throwable) {
            $task->markFailed($throwable->getMessage())->save();
            Mage::log(
                sprintf('Maho AI task #%d failed: %s', $task->getId(), $throwable->getMessage()),
                Mage::LOG_ERROR,
                'maho_ai.log',
            );
        }
    }

    private function executeCompletionTask(Maho_Ai_Model_Task $task): void
    {
        $messages = $task->getMessagesArray();

        if ($task->getData('system_prompt')) {
            array_unshift($messages, ['role' => 'system', 'content' => $task->getData('system_prompt')]);
        }

        $options = array_filter(['model' => $task->getData('model')]);

        $provider = Mage::getSingleton('ai/platform_factory')->create(
            $task->getData('platform') ?: null,
            $task->getData('store_id') ?: null,
        );

        $response = $provider->complete($messages, $options);

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

        $this->fireCallback($task, $response);
    }

    private function executeEmbedTask(Maho_Ai_Model_Task $task): void
    {
        $messages = $task->getMessagesArray();
        $text     = $messages[0]['content'] ?? '';

        $storeId = $task->getData('store_id') ?: null;
        $options = array_filter(['model' => $task->getData('model')]);

        $targetDims = (int) Mage::getStoreConfig('maho_ai/embed/target_dimensions', $storeId);
        if ($targetDims > 0) {
            $options['dimensions'] = $targetDims;
        }

        /** @var Maho_Ai_Model_Platform_Factory $factory */
        $factory  = Mage::getSingleton('ai/platform_factory');
        $provider = $factory->createEmbed(
            $task->getData('platform') ?: null,
            $storeId,
        );

        $vectors = $provider->embed($text, $options);
        $vector  = $vectors[0] ?? [];

        // Auto-save to maho_ai_vector if entity info provided
        $context = $task->getContextArray();
        if (!empty($context['entity_type']) && !empty($context['entity_id'])) {
            /** @var Maho_Ai_Model_Resource_Vector $vectorResource */
            $vectorResource = Mage::getResourceSingleton('ai/vector');
            $vectorResource->saveForEntity(
                entityType: $context['entity_type'],
                entityId: (int) $context['entity_id'],
                storeId: (int) ($task->getData('store_id') ?? 0),
                vector: $vector,
                dimensions: count($vector),
                platform: $provider->getEmbedPlatformCode(),
                model: $provider->getLastEmbedModel(),
            );
        }

        $usage    = $provider->getLastEmbedTokenUsage();
        $response = json_encode($vector);

        $task->markComplete(
            response: $response,
            inputTokens: $usage['input'],
            outputTokens: 0,
            platform: $provider->getEmbedPlatformCode(),
            model: $provider->getLastEmbedModel(),
        )->save();

        $this->fireCallback($task, $response);
    }

    private function executeImageTask(Maho_Ai_Model_Task $task): void
    {
        $messages = $task->getMessagesArray();
        $prompt   = $messages[0]['content'] ?? '';

        $context = $task->getContextArray();
        $options = array_filter([
            'model'   => $task->getData('model'),
            'width'   => $context['width'] ?? null,
            'height'  => $context['height'] ?? null,
            'quality' => $context['quality'] ?? null,
            'style'   => $context['style'] ?? null,
        ]);

        /** @var Maho_Ai_Model_Platform_Factory $factory */
        $factory  = Mage::getSingleton('ai/platform_factory');
        $provider = $factory->createImage(
            $task->getData('platform') ?: null,
            $task->getData('store_id') ?: null,
        );

        $response = $provider->generateImage($prompt, $options);

        $task->markComplete(
            response: $response,
            inputTokens: 0,
            outputTokens: 0,
            platform: $provider->getImagePlatformCode(),
            model: $provider->getLastImageModel(),
        )->save();

        $this->fireCallback($task, $response);
    }

    private function fireCallback(Maho_Ai_Model_Task $task, string $response): void
    {
        $callbackClass  = $task->getData('callback_class');
        $callbackMethod = $task->getData('callback_method');

        if (!$callbackClass || !$callbackMethod) {
            return;
        }

        if (!class_exists($callbackClass)) {
            Mage::log(sprintf('Maho AI: callback class %s not found', $callbackClass), Mage::LOG_WARNING, 'maho_ai.log');
            return;
        }

        $instance = new $callbackClass();
        if (!method_exists($instance, $callbackMethod)) {
            Mage::log(sprintf('Maho AI: callback method %s::%s not found', $callbackClass, $callbackMethod), Mage::LOG_WARNING, 'maho_ai.log');
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
