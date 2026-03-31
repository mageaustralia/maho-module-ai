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

/**
 * Ollama provider — OpenAI-compatible local inference server
 * Supports completion and embeddings via /v1/embeddings (Ollama ≥ 0.1.24).
 */
class Maho_Ai_Model_Platform_Ollama extends Maho_Ai_Model_Platform_OpenAi
{
    public function __construct(string $baseUrl, string $defaultModel)
    {
        parent::__construct(
            apiKey: 'ollama',  // Ollama doesn't require a real API key
            defaultModel: $defaultModel,
            baseUrl: rtrim($baseUrl, '/') . '/v1',
        );
        $this->platformCode = Maho_Ai_Model_Platform::OLLAMA;
    }

    #[\Override]
    protected function resolveEmbedModel(): string
    {
        return (string) Mage::getStoreConfig('maho_ai/embed/ollama_model') ?: 'nomic-embed-text';
    }
}
