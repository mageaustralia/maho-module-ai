<?php

declare(strict_types=1);

/**
 * Maho
 *
 * @category   Maho
 * @package    Maho_Ai
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Maho_Ai_Model_Platform_Factory
{
    /**
     * Create a provider instance for the given platform.
     *
     * @throws Mage_Core_Exception if platform is not configured or unknown
     */
    public function create(?string $platformCode = null, ?int $storeId = null): Maho_Ai_Model_Platform_ProviderInterface
    {
        $platformCode ??= $this->getDefaultPlatform($storeId);

        return match ($platformCode) {
            Maho_Ai_Model_Platform::OPENAI     => $this->createOpenAi($storeId),
            Maho_Ai_Model_Platform::ANTHROPIC  => $this->createAnthropic($storeId),
            Maho_Ai_Model_Platform::GOOGLE     => $this->createGoogle($storeId),
            Maho_Ai_Model_Platform::MISTRAL    => $this->createMistral($storeId),
            Maho_Ai_Model_Platform::OPENROUTER => $this->createOpenRouter($storeId),
            Maho_Ai_Model_Platform::OLLAMA     => $this->createOllama($storeId),
            Maho_Ai_Model_Platform::GENERIC    => $this->createGeneric($storeId),
            default => throw new Mage_Core_Exception("Unknown AI platform: {$platformCode}"),
        };
    }

    public function getDefaultPlatform(?int $storeId = null): string
    {
        return (string) Mage::getStoreConfig('maho_ai/general/default_platform', $storeId)
            ?: Maho_Ai_Model_Platform::OPENAI;
    }

    /**
     * Create a provider instance that implements EmbedProviderInterface.
     *
     * @throws Mage_Core_Exception if platform is not configured, unknown, or does not support embeddings
     */
    public function createEmbed(?string $platformCode = null, ?int $storeId = null): Maho_Ai_Model_Platform_EmbedProviderInterface
    {
        $platformCode ??= (string) Mage::getStoreConfig('maho_ai/embed/default_platform', $storeId)
            ?: Maho_Ai_Model_Platform::OPENAI;

        $provider = match ($platformCode) {
            Maho_Ai_Model_Platform::OPENAI   => $this->createOpenAi($storeId),
            Maho_Ai_Model_Platform::GOOGLE   => $this->createGoogle($storeId),
            Maho_Ai_Model_Platform::MISTRAL  => $this->createMistralForEmbed($storeId),
            Maho_Ai_Model_Platform::OLLAMA   => $this->createOllamaForEmbed($storeId),
            Maho_Ai_Model_Platform::GENERIC  => $this->createGenericForEmbed($storeId),
            default => throw new Mage_Core_Exception("Platform '{$platformCode}' does not support embeddings."),
        };

        if (!($provider instanceof Maho_Ai_Model_Platform_EmbedProviderInterface)) {
            throw new Mage_Core_Exception("Platform '{$platformCode}' does not implement EmbedProviderInterface.");
        }

        return $provider;
    }

    /**
     * Create a provider instance that implements ImageProviderInterface.
     *
     * @throws Mage_Core_Exception if platform is not configured, unknown, or does not support image generation
     */
    public function createImage(?string $platformCode = null, ?int $storeId = null): Maho_Ai_Model_Platform_ImageProviderInterface
    {
        $platformCode ??= (string) Mage::getStoreConfig('maho_ai/image/default_platform', $storeId)
            ?: Maho_Ai_Model_Platform::OPENAI;

        $provider = match ($platformCode) {
            Maho_Ai_Model_Platform::OPENAI  => $this->createOpenAi($storeId),
            Maho_Ai_Model_Platform::GOOGLE  => $this->createGoogle($storeId),
            Maho_Ai_Model_Platform::GENERIC => $this->createGenericForImage($storeId),
            default => throw new Mage_Core_Exception("Platform '{$platformCode}' does not support image generation."),
        };

        if (!($provider instanceof Maho_Ai_Model_Platform_ImageProviderInterface)) {
            throw new Mage_Core_Exception("Platform '{$platformCode}' does not implement ImageProviderInterface.");
        }

        return $provider;
    }

    private function getConfig(string $path, ?int $storeId = null): string
    {
        return (string) Mage::getStoreConfig($path, $storeId);
    }

    /** For fields stored with backend_model=adminhtml/system_config_backend_encrypted. */
    private function getEncryptedConfig(string $path, ?int $storeId = null): string
    {
        return (string) Mage::helper('core')->decrypt($this->getConfig($path, $storeId));
    }

    private function resolveModel(string $platform, ?int $storeId): string
    {
        return $this->getConfig("maho_ai/general/{$platform}_model", $storeId);
    }

    private function createOpenAi(?int $storeId): Maho_Ai_Model_Platform_OpenAi
    {
        $apiKey = $this->getEncryptedConfig('maho_ai/general/openai_api_key', $storeId);
        if (!$apiKey) {
            throw new Mage_Core_Exception('OpenAI API key is not configured.');
        }
        return new Maho_Ai_Model_Platform_OpenAi(
            apiKey: $apiKey,
            defaultModel: $this->resolveModel(Maho_Ai_Model_Platform::OPENAI, $storeId),
            extraHeaders: array_filter([
                'OpenAI-Organization' => $this->getConfig('maho_ai/general/openai_organization_id', $storeId),
            ]),
        );
    }

    private function createAnthropic(?int $storeId): Maho_Ai_Model_Platform_Anthropic
    {
        $apiKey = $this->getEncryptedConfig('maho_ai/general/anthropic_api_key', $storeId);
        if (!$apiKey) {
            throw new Mage_Core_Exception('Anthropic API key is not configured.');
        }
        return new Maho_Ai_Model_Platform_Anthropic(
            apiKey: $apiKey,
            defaultModel: $this->resolveModel(Maho_Ai_Model_Platform::ANTHROPIC, $storeId),
        );
    }

    private function createGoogle(?int $storeId): Maho_Ai_Model_Platform_Google
    {
        $apiKey = $this->getEncryptedConfig('maho_ai/general/google_api_key', $storeId);
        if (!$apiKey) {
            throw new Mage_Core_Exception('Google AI API key is not configured.');
        }
        return new Maho_Ai_Model_Platform_Google(
            apiKey: $apiKey,
            defaultModel: $this->resolveModel(Maho_Ai_Model_Platform::GOOGLE, $storeId),
        );
    }

    private function createMistral(?int $storeId): Maho_Ai_Model_Platform_Mistral
    {
        $apiKey = $this->getEncryptedConfig('maho_ai/general/mistral_api_key', $storeId);
        if (!$apiKey) {
            throw new Mage_Core_Exception('Mistral API key is not configured.');
        }
        return new Maho_Ai_Model_Platform_Mistral(
            apiKey: $apiKey,
            defaultModel: $this->resolveModel(Maho_Ai_Model_Platform::MISTRAL, $storeId),
        );
    }

    private function createOpenRouter(?int $storeId): Maho_Ai_Model_Platform_OpenRouter
    {
        $apiKey = $this->getEncryptedConfig('maho_ai/general/openrouter_api_key', $storeId);
        if (!$apiKey) {
            throw new Mage_Core_Exception('OpenRouter API key is not configured.');
        }
        return new Maho_Ai_Model_Platform_OpenRouter(
            apiKey: $apiKey,
            defaultModel: $this->resolveModel(Maho_Ai_Model_Platform::OPENROUTER, $storeId),
        );
    }

    private function createOllama(?int $storeId): Maho_Ai_Model_Platform_Ollama
    {
        $baseUrl = $this->getConfig('maho_ai/general/ollama_base_url', $storeId) ?: 'http://localhost:11434';
        return new Maho_Ai_Model_Platform_Ollama(
            baseUrl: $baseUrl,
            defaultModel: $this->resolveModel(Maho_Ai_Model_Platform::OLLAMA, $storeId),
        );
    }

    private function createGeneric(?int $storeId): Maho_Ai_Model_Platform_Generic
    {
        $baseUrl = $this->getConfig('maho_ai/general/generic_base_url', $storeId);
        if (!$baseUrl) {
            throw new Mage_Core_Exception('Generic provider base URL is not configured.');
        }
        return new Maho_Ai_Model_Platform_Generic(
            baseUrl: $baseUrl,
            apiKey: $this->getEncryptedConfig('maho_ai/general/generic_api_key', $storeId),
            defaultModel: $this->resolveModel(Maho_Ai_Model_Platform::GENERIC, $storeId),
        );
    }

    // -------------------------------------------------------------------------
    // Embed-specific creators (use embed config group for base URL / API key)
    // -------------------------------------------------------------------------

    private function createMistralForEmbed(?int $storeId): Maho_Ai_Model_Platform_Mistral
    {
        $apiKey = $this->getEncryptedConfig('maho_ai/general/mistral_api_key', $storeId);
        if (!$apiKey) {
            throw new Mage_Core_Exception('Mistral API key is not configured.');
        }
        return new Maho_Ai_Model_Platform_Mistral(
            apiKey: $apiKey,
            defaultModel: $this->getConfig('maho_ai/embed/mistral_model', $storeId) ?: 'mistral-embed',
        );
    }

    private function createOllamaForEmbed(?int $storeId): Maho_Ai_Model_Platform_Ollama
    {
        $baseUrl = $this->getConfig('maho_ai/general/ollama_base_url', $storeId) ?: 'http://localhost:11434';
        return new Maho_Ai_Model_Platform_Ollama(
            baseUrl: $baseUrl,
            defaultModel: $this->getConfig('maho_ai/embed/ollama_model', $storeId) ?: 'nomic-embed-text',
        );
    }

    private function createGenericForEmbed(?int $storeId): Maho_Ai_Model_Platform_Generic
    {
        $baseUrl = $this->getConfig('maho_ai/embed/generic_base_url', $storeId)
            ?: $this->getConfig('maho_ai/general/generic_base_url', $storeId);
        if (!$baseUrl) {
            throw new Mage_Core_Exception('Generic embed provider base URL is not configured.');
        }
        $apiKey = $this->getEncryptedConfig('maho_ai/embed/generic_api_key', $storeId)
            ?: $this->getEncryptedConfig('maho_ai/general/generic_api_key', $storeId);
        return new Maho_Ai_Model_Platform_Generic(
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            defaultModel: $this->getConfig('maho_ai/embed/generic_model', $storeId),
        );
    }

    // -------------------------------------------------------------------------
    // Image-specific creators (use image config group for base URL / API key)
    // -------------------------------------------------------------------------

    private function createGenericForImage(?int $storeId): Maho_Ai_Model_Platform_Generic
    {
        $baseUrl = $this->getConfig('maho_ai/image/generic_base_url', $storeId)
            ?: $this->getConfig('maho_ai/general/generic_base_url', $storeId);
        if (!$baseUrl) {
            throw new Mage_Core_Exception('Generic image provider base URL is not configured.');
        }
        $apiKey = $this->getEncryptedConfig('maho_ai/image/generic_api_key', $storeId)
            ?: $this->getEncryptedConfig('maho_ai/general/generic_api_key', $storeId);
        return new Maho_Ai_Model_Platform_Generic(
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            defaultModel: $this->getConfig('maho_ai/image/generic_model', $storeId),
        );
    }
}
