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

class Maho_Ai_Model_Platform_Google implements Maho_Ai_Model_Platform_ProviderInterface
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

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

        // Convert standard messages format to Gemini format
        $systemInstruction = null;
        $contents = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $systemInstruction = ['parts' => [['text' => $message['content']]]];
                continue;
            }
            $role = $message['role'] === 'assistant' ? 'model' : 'user';
            $contents[] = ['role' => $role, 'parts' => [['text' => $message['content']]]];
        }

        $payload = [
            'contents'         => $contents,
            'generationConfig' => [
                'maxOutputTokens' => $options['max_tokens'] ?? (int) Mage::getStoreConfig('maho_ai/limits/max_tokens_per_request'),
                'temperature'     => $options['temperature'] ?? 0.7,
            ],
        ];

        if ($systemInstruction) {
            $payload['systemInstruction'] = $systemInstruction;
        }

        $url = self::BASE_URL . '/' . $model . ':generateContent?key=' . $this->apiKey;

        $client = HttpClient::create();
        $response = $client->request('POST', $url, [
            'headers' => ['Content-Type' => 'application/json'],
            'json'    => $payload,
            'timeout' => (int) Mage::getStoreConfig('maho_ai/queue/task_timeout') ?: 120,
        ]);

        $statusCode = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($statusCode !== 200) {
            $error = $data['error']['message'] ?? 'Unknown error';
            throw new Mage_Core_Exception("Google AI API error ({$statusCode}): {$error}");
        }

        $this->lastTokenUsage = [
            'input'  => $data['usageMetadata']['promptTokenCount'] ?? 0,
            'output' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
        ];

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    #[\Override]
    public function getLastTokenUsage(): array
    {
        return $this->lastTokenUsage;
    }

    #[\Override]
    public function getPlatformCode(): string
    {
        return Maho_Ai_Model_Platform::GOOGLE;
    }

    #[\Override]
    public function getLastModel(): string
    {
        return $this->lastModel;
    }
}
