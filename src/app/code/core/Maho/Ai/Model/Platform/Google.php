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

class Maho_Ai_Model_Platform_Google implements
    Maho_Ai_Model_Platform_ProviderInterface,
    Maho_Ai_Model_Platform_EmbedProviderInterface,
    Maho_Ai_Model_Platform_ImageProviderInterface
{
    private const string BASE_URL       = 'https://generativelanguage.googleapis.com/v1beta/models';

    private const string IMAGE_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    private array $lastTokenUsage     = ['input' => 0, 'output' => 0];

    private string $lastModel         = '';

    private array $lastEmbedTokenUsage = ['input' => 0];

    private string $lastEmbedModel    = '';

    private string $lastImageModel    = '';

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
            throw new Mage_Core_Exception(sprintf('Google AI API error (%s): %s', $statusCode, $error));
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

    // -------------------------------------------------------------------------
    // EmbedProviderInterface
    // -------------------------------------------------------------------------

    #[\Override]
    public function embed(string|array $input, array $options = []): array
    {
        $model = $options['model'] ?? ((string) Mage::getStoreConfig('maho_ai/embed/google_model') ?: 'text-embedding-004');
        $this->lastEmbedModel = $model;

        $inputs  = is_string($input) ? [$input] : array_values($input);
        $vectors = [];

        $client = HttpClient::create();

        foreach ($inputs as $idx => $text) {
            $payload = ['content' => ['parts' => [['text' => $text]]]];

            if (isset($options['dimensions']) && (int) $options['dimensions'] > 0) {
                $payload['outputDimensionality'] = (int) $options['dimensions'];
            }

            $url      = self::BASE_URL . '/' . $model . ':embedContent?key=' . $this->apiKey;
            $response = $client->request('POST', $url, [
                'headers' => ['Content-Type' => 'application/json'],
                'json'    => $payload,
                'timeout' => (int) Mage::getStoreConfig('maho_ai/queue/task_timeout') ?: 120,
            ]);

            $statusCode = $response->getStatusCode();
            $data       = $response->toArray(false);

            if ($statusCode !== 200) {
                $error = $data['error']['message'] ?? 'Unknown error';
                throw new Mage_Core_Exception(sprintf('Google Embeddings API error (%s): %s', $statusCode, $error));
            }

            $vectors[$idx] = $data['embedding']['values'] ?? [];
            $this->lastEmbedTokenUsage['input'] += count(explode(' ', $text)); // approximate
        }

        return array_values($vectors);
    }

    #[\Override]
    public function getLastEmbedTokenUsage(): array
    {
        return $this->lastEmbedTokenUsage;
    }

    #[\Override]
    public function getEmbedPlatformCode(): string
    {
        return Maho_Ai_Model_Platform::GOOGLE;
    }

    #[\Override]
    public function getLastEmbedModel(): string
    {
        return $this->lastEmbedModel;
    }

    // -------------------------------------------------------------------------
    // ImageProviderInterface
    // -------------------------------------------------------------------------

    #[\Override]
    public function generateImage(string $prompt, array $options = []): string
    {
        $model = $options['model'] ?? ((string) Mage::getStoreConfig('maho_ai/image/google_model') ?: 'imagen-3.0-generate-001');
        $this->lastImageModel = $model;

        $payload = [
            'instances'  => [['prompt' => $prompt]],
            'parameters' => [
                'sampleCount' => 1,
                'aspectRatio' => $this->resolveAspectRatio($options),
            ],
        ];

        $url = self::IMAGE_BASE_URL . '/' . $model . ':predict?key=' . $this->apiKey;

        $client   = HttpClient::create();
        $response = $client->request('POST', $url, [
            'headers' => ['Content-Type' => 'application/json'],
            'json'    => $payload,
            'timeout' => 120,
        ]);

        $statusCode = $response->getStatusCode();
        $data       = $response->toArray(false);

        if ($statusCode !== 200) {
            $error = $data['error']['message'] ?? 'Unknown error';
            throw new Mage_Core_Exception(sprintf('Google Imagen API error (%s): %s', $statusCode, $error));
        }

        $b64 = $data['predictions'][0]['bytesBase64Encoded'] ?? '';
        if (!$b64) {
            throw new Mage_Core_Exception('Google Imagen returned no image data.');
        }

        $mimeType = $data['predictions'][0]['mimeType'] ?? 'image/png';
        return sprintf('data:%s;base64,%s', $mimeType, $b64);
    }

    #[\Override]
    public function getImagePlatformCode(): string
    {
        return Maho_Ai_Model_Platform::GOOGLE;
    }

    #[\Override]
    public function getLastImageModel(): string
    {
        return $this->lastImageModel;
    }

    private function resolveAspectRatio(array $options): string
    {
        $w = (int) ($options['width'] ?? 1024);
        $h = (int) ($options['height'] ?? 1024);

        if ($w === $h) {
            return '1:1';
        }

        if ($w > $h) {
            return '16:9';
        }

        return '9:16';
    }
}
