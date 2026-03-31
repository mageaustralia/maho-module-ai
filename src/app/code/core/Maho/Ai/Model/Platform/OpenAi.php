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
class Maho_Ai_Model_Platform_OpenAi implements
    Maho_Ai_Model_Platform_ProviderInterface,
    Maho_Ai_Model_Platform_EmbedProviderInterface,
    Maho_Ai_Model_Platform_ImageProviderInterface
{
    protected string $baseUrl = 'https://api.openai.com/v1';
    protected string $platformCode = Maho_Ai_Model_Platform::OPENAI;

    private array $lastTokenUsage = ['input' => 0, 'output' => 0];
    private string $lastModel = '';

    private array $lastEmbedTokenUsage = ['input' => 0];
    private string $lastEmbedModel = '';
    private string $lastImageModel = '';

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

    // -------------------------------------------------------------------------
    // EmbedProviderInterface
    // -------------------------------------------------------------------------

    #[\Override]
    public function embed(string|array $input, array $options = []): array
    {
        $model = $options['model'] ?? $this->resolveEmbedModel();
        $this->lastEmbedModel = $model;

        $inputs = is_string($input) ? [$input] : array_values($input);

        $payload = ['model' => $model, 'input' => $inputs];

        // text-embedding-3-* support native dimension reduction
        if (isset($options['dimensions']) && (int) $options['dimensions'] > 0
            && str_contains($model, 'text-embedding-3')
        ) {
            $payload['dimensions'] = (int) $options['dimensions'];
        }

        $headers = array_merge([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
        ], $this->extraHeaders);

        $client   = HttpClient::create();
        $response = $client->request('POST', $this->baseUrl . '/embeddings', [
            'headers' => $headers,
            'json'    => $payload,
            'timeout' => (int) Mage::getStoreConfig('maho_ai/queue/task_timeout') ?: 120,
        ]);

        $statusCode = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($statusCode !== 200) {
            $error = $data['error']['message'] ?? 'Unknown error';
            throw new Mage_Core_Exception("OpenAI Embeddings API error ({$statusCode}): {$error}");
        }

        $this->lastEmbedTokenUsage = ['input' => $data['usage']['total_tokens'] ?? 0];

        $vectors = [];
        foreach ($data['data'] as $item) {
            $vectors[$item['index']] = $item['embedding'];
        }
        ksort($vectors);

        // Truncate if target dimensions requested and not natively reduced
        if (isset($options['dimensions']) && (int) $options['dimensions'] > 0
            && !str_contains($model, 'text-embedding-3')
        ) {
            $target  = (int) $options['dimensions'];
            $vectors = array_map(fn(array $v): array => array_slice($v, 0, $target), $vectors);
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
        return $this->platformCode;
    }

    #[\Override]
    public function getLastEmbedModel(): string
    {
        return $this->lastEmbedModel;
    }

    /** Subclasses override to provide their embed model config path. */
    protected function resolveEmbedModel(): string
    {
        return (string) Mage::getStoreConfig('maho_ai/embed/openai_model') ?: 'text-embedding-3-small';
    }

    // -------------------------------------------------------------------------
    // ImageProviderInterface
    // -------------------------------------------------------------------------

    #[\Override]
    public function generateImage(string $prompt, array $options = []): string
    {
        $model = $options['model'] ?? $this->resolveImageModel();
        $this->lastImageModel = $model;

        $size = $this->resolveImageSize($options);

        $payload = [
            'model'  => $model,
            'prompt' => $prompt,
            'n'      => 1,
            'size'   => $size,
        ];

        if (isset($options['quality'])) {
            $payload['quality'] = $options['quality'];
        }
        if (isset($options['style'])) {
            $payload['style'] = $options['style'];
        }

        $headers = array_merge([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
        ], $this->extraHeaders);

        $client   = HttpClient::create();
        $response = $client->request('POST', $this->baseUrl . '/images/generations', [
            'headers' => $headers,
            'json'    => $payload,
            'timeout' => 120,
        ]);

        $statusCode = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($statusCode !== 200) {
            $error = $data['error']['message'] ?? 'Unknown error';
            throw new Mage_Core_Exception("OpenAI Images API error ({$statusCode}): {$error}");
        }

        return $data['data'][0]['url'] ?? '';
    }

    #[\Override]
    public function getImagePlatformCode(): string
    {
        return $this->platformCode;
    }

    #[\Override]
    public function getLastImageModel(): string
    {
        return $this->lastImageModel;
    }

    /** Subclasses override to provide their image model config path. */
    protected function resolveImageModel(): string
    {
        return (string) Mage::getStoreConfig('maho_ai/image/openai_model') ?: 'dall-e-3';
    }

    private function resolveImageSize(array $options): string
    {
        $w = (int) ($options['width'] ?? 1024);
        $h = (int) ($options['height'] ?? 1024);
        // DALL-E 3 supports 1024x1024, 1024x1792, 1792x1024
        // DALL-E 2 supports 256x256, 512x512, 1024x1024
        return "{$w}x{$h}";
    }
}
