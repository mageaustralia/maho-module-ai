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

class Maho_Ai_Helper_Data extends Mage_Core_Helper_Abstract
{
    /**
     * Synchronous single-shot completion.
     *
     * Usage:
     *   $response = Mage::helper('ai')->invoke(
     *       userMessage: 'Write a product description for tennis racquet',
     *       systemPrompt: 'You are an e-commerce copywriter.',
     *   );
     *
     * @param string|array<array{role: string, content: string}> $userMessage
     *   Either a plain string (treated as single user message) or a full messages array.
     * @param array<string, mixed> $options
     *   Supports: temperature, max_tokens, model, is_html (bool — enables HTML sanitization).
     *
     * @throws Mage_Core_Exception if AI is disabled, not configured, or injection detected
     */
    public function invoke(
        string|array $userMessage,
        ?string $systemPrompt = null,
        ?string $platform = null,
        ?string $model = null,
        array $options = [],
        ?int $storeId = null,
        string $consumer = '_direct',
    ): string {
        if (!$this->isEnabled($storeId)) {
            throw new Mage_Core_Exception('Maho AI is disabled.');
        }

        // Build messages array
        $messages = [];
        if ($systemPrompt !== null) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        if (is_string($userMessage)) {
            // Validate user-supplied text for injection
            $validation = $this->getInputValidator()->validate($userMessage);
            if (!$validation['safe']) {
                throw new Mage_Core_Exception('AI request rejected: ' . $validation['reason']);
            }
            $messages[] = ['role' => 'user', 'content' => $userMessage];
        } else {
            // Validate each user message in the array
            foreach ($userMessage as $msg) {
                if ($msg['role'] === 'user') {
                    $validation = $this->getInputValidator()->validate((string) $msg['content']);
                    if (!$validation['safe']) {
                        throw new Mage_Core_Exception('AI request rejected: ' . $validation['reason']);
                    }
                }
            }
            $messages = array_merge($messages, $userMessage);
        }

        if ($model !== null) {
            $options['model'] = $model;
        }

        // Create provider and call
        $provider = $this->getFactory()->create($platform, $storeId);
        $response = $provider->complete($messages, $options);

        // Sanitize output
        $isHtml = (bool) ($options['is_html'] ?? false);
        $metadata = [];
        $response = $this->getOutputSanitizer()->sanitize($response, $isHtml, $metadata);

        // Log request if enabled
        $this->logRequest(
            consumer: $consumer,
            platform: $provider->getPlatformCode(),
            model: $provider->getLastModel(),
            tokenUsage: $provider->getLastTokenUsage(),
            storeId: $storeId ?? 0,
        );

        return $response;
    }

    /**
     * Submit a task to the async queue.
     *
     * @param array{
     *   consumer: string,
     *   action: string,
     *   system_prompt?: string,
     *   messages: array<array{role: string, content: string}>,
     *   context?: array<string, mixed>,
     *   callback_class?: string,
     *   callback_method?: string,
     *   priority?: string,
     *   platform?: string,
     *   model?: string,
     *   max_retries?: int,
     *   store_id?: int,
     * } $data
     *
     * @return int Task ID
     */
    public function submitTask(array $data): int
    {
        if (!$this->isEnabled($data['store_id'] ?? null)) {
            throw new Mage_Core_Exception('Maho AI is disabled.');
        }

        $task = Mage::getModel('ai/task');
        $task->setData([
            'consumer'        => $data['consumer'],
            'action'          => $data['action'] ?? 'generate',
            'status'          => 'pending',
            'priority'        => $data['priority'] ?? 'background',
            'platform'        => $data['platform'] ?? null,
            'model'           => $data['model'] ?? null,
            'system_prompt'   => $data['system_prompt'] ?? null,
            'messages'        => json_encode($data['messages'] ?? []),
            'context'         => isset($data['context']) ? json_encode($data['context']) : null,
            'callback_class'  => $data['callback_class'] ?? null,
            'callback_method' => $data['callback_method'] ?? null,
            'max_retries'     => $data['max_retries'] ?? 3,
            'store_id'        => $data['store_id'] ?? 0,
            'admin_user_id'   => Mage::getSingleton('admin/session')->isLoggedIn()
                ? (int) Mage::getSingleton('admin/session')->getUser()->getId()
                : null,
        ]);
        $task->save();

        return (int) $task->getId();
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return Mage::getStoreConfigFlag('maho_ai/general/enabled', $storeId);
    }

    private function getFactory(): Maho_Ai_Model_Platform_Factory
    {
        return Mage::getSingleton('ai/platform_factory');
    }

    private function getInputValidator(): Maho_Ai_Model_Safety_InputValidator
    {
        return Mage::getSingleton('ai/safety_inputValidator');
    }

    private function getOutputSanitizer(): Maho_Ai_Model_Safety_OutputSanitizer
    {
        return Mage::getSingleton('ai/safety_outputSanitizer');
    }

    private function logRequest(
        string $consumer,
        string $platform,
        string $model,
        array $tokenUsage,
        int $storeId,
    ): void {
        if (!Mage::getStoreConfigFlag('maho_ai/general/log_requests')) {
            return;
        }

        $logLevel = Mage::getStoreConfig('maho_ai/general/log_level');

        $message = sprintf(
            '[%s] consumer=%s platform=%s model=%s in=%d out=%d cost=$%.6f',
            date('Y-m-d H:i:s'),
            $consumer,
            $platform,
            $model,
            $tokenUsage['input'],
            $tokenUsage['output'],
            Maho_Ai_Model_Platform::estimateCost($platform, $model, $tokenUsage['input'], $tokenUsage['output']),
        );

        Mage::log($message, Mage::LOG_INFO, 'maho_ai.log');
    }
}
