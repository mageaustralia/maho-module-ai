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

/**
 * OpenAI-compatible provider (also used for OpenRouter, Ollama, Azure, Generic)
 */
class Maho_Ai_Model_Platform_OpenAi implements Maho_Ai_Model_Platform_ProviderInterface
{
    protected string $baseUrl = 'https://api.openai.com/v1';
    protected string $platformCode = Maho_Ai_Model_Platform::OPENAI;

    private array $lastTokenUsage = ['input' => 0, 'output' => 0];
    private string $lastModel = '';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $defaultModel,
        private readonly array $extraHeaders = [],
        ?string $baseUrl = null,
    ) {
        if ($baseUrl) {
            $this->baseUrl = rtrim($baseUrl, '/');
        }
    }

    #[\Override]
    public function complete(array $messages, array $options = []): string
    {
        $model = $options['model'] ?? $this->defaultModel;
        $this->lastModel = $model;

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $options['max_tokens'] ?? (int) Mage::getStoreConfig('maho_ai/limits/max_tokens_per_request'),
            'temperature' => $options['temperature'] ?? 0.7,
        ];

        if (isset($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }

        $headers = array_merge([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
        ], $this->extraHeaders);

        $client = HttpClient::create();
        $response = $client->request('POST', $this->baseUrl . '/chat/completions', [
            'headers' => $headers,
            'json'    => $payload,
            'timeout' => (int) Mage::getStoreConfig('maho_ai/queue/task_timeout') ?: 120,
        ]);

        $statusCode = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($statusCode !== 200) {
            $error = $data['error']['message'] ?? 'Unknown error';
            throw new Mage_Core_Exception("OpenAI API error ({$statusCode}): {$error}");
        }

        $this->lastTokenUsage = [
            'input'  => $data['usage']['prompt_tokens'] ?? 0,
            'output' => $data['usage']['completion_tokens'] ?? 0,
        ];

        return $data['choices'][0]['message']['content'] ?? '';
    }

    #[\Override]
    public function getLastTokenUsage(): array
    {
        return $this->lastTokenUsage;
    }

    #[\Override]
    public function getPlatformCode(): string
    {
        return $this->platformCode;
    }

    #[\Override]
    public function getLastModel(): string
    {
        return $this->lastModel;
    }
}
