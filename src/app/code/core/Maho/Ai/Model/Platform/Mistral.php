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
 * Mistral AI provider — OpenAI-compatible API
 * Supports completion and embeddings (mistral-embed, 1024d, no native truncation).
 */
class Maho_Ai_Model_Platform_Mistral extends Maho_Ai_Model_Platform_OpenAi
{
    public function __construct(string $apiKey, string $defaultModel)
    {
        parent::__construct(
            apiKey: $apiKey,
            defaultModel: $defaultModel,
            baseUrl: 'https://api.mistral.ai/v1',
        );
        $this->platformCode = Maho_Ai_Model_Platform::MISTRAL;
    }

    #[\Override]
    protected function resolveEmbedModel(): string
    {
        return (string) Mage::getStoreConfig('maho_ai/embed/mistral_model') ?: 'mistral-embed';
    }
}
