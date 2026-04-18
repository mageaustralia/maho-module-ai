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

use Symfony\Component\HttpClient\HttpClient;

class Maho_Ai_Model_Platform_Anthropic implements Maho_Ai_Model_Platform_ProviderInterface
{
    private const string API_URL = 'https://api.anthropic.com/v1/messages';

    private const string API_VERSION = '2023-06-01';

    private array $lastTokenUsage = ['input' => 0, 'output' => 0];

    private string $lastModel = '';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $defaultModel,
    ) {}

    #[\Override]
    public function complete(array $messages, array $options = []): string
    {
        $model = $options['model'] ?? $this->defaultModel;
        $this->lastModel = $model;

        // Anthropic separates system prompt from messages
        $systemPrompt = null;
        $filteredMessages = [];
        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $systemPrompt = $message['content'];
            } else {
                $filteredMessages[] = $message;
            }
        }

        $payload = [
            'model'      => $model,
            'messages'   => $filteredMessages,
            'max_tokens' => $options['max_tokens'] ?? (int) Mage::getStoreConfig('maho_ai/limits/max_tokens_per_request'),
        ];

        if ($systemPrompt !== null) {
            $payload['system'] = $systemPrompt;
        }

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        $client = HttpClient::create();
        $response = $client->request('POST', self::API_URL, [
            'headers' => [
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'json'    => $payload,
            'timeout' => (int) Mage::getStoreConfig('maho_ai/queue/task_timeout') ?: 120,
        ]);

        $statusCode = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($statusCode !== 200) {
            $error = $data['error']['message'] ?? 'Unknown error';
            throw new Mage_Core_Exception(sprintf('Anthropic API error (%s): %s', $statusCode, $error));
        }

        $this->lastTokenUsage = [
            'input'  => $data['usage']['input_tokens'] ?? 0,
            'output' => $data['usage']['output_tokens'] ?? 0,
        ];

        return $data['content'][0]['text'] ?? '';
    }

    #[\Override]
    public function getLastTokenUsage(): array
    {
        return $this->lastTokenUsage;
    }

    #[\Override]
    public function getPlatformCode(): string
    {
        return Maho_Ai_Model_Platform::ANTHROPIC;
    }

    #[\Override]
    public function getLastModel(): string
    {
        return $this->lastModel;
    }
}
