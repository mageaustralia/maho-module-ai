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

class Maho_Ai_Model_Platform
{
    // Backward-compatible constants for built-in providers
    public const OPENAI     = 'openai';
    public const ANTHROPIC  = 'anthropic';
    public const GOOGLE     = 'google';
    public const MISTRAL    = 'mistral';
    public const OPENROUTER = 'openrouter';
    public const OLLAMA     = 'ollama';
    public const GENERIC    = 'generic';

    /**
     * Per-token pricing in USD (input, output) — approximate, updated periodically
     */
    public const PRICING = [
        self::OPENAI => [
            'gpt-4o'              => [0.0000025, 0.000010],
            'gpt-4o-mini'         => [0.00000015, 0.0000006],
            'gpt-4-turbo'         => [0.000010, 0.000030],
            'gpt-3.5-turbo'       => [0.0000005, 0.0000015],
        ],
        self::ANTHROPIC => [
            'claude-opus-4-20250514'   => [0.000015, 0.000075],
            'claude-sonnet-4-20250514' => [0.000003, 0.000015],
            'claude-haiku-4-5-20251001' => [0.00000025, 0.00000125],
        ],
        self::GOOGLE => [
            'gemini-2.0-flash'         => [0.00000010, 0.00000040],
            'gemini-1.5-pro'           => [0.0000035, 0.0000105],
        ],
    ];

    /**
     * Estimate cost for a given platform/model/tokens
     */
    public static function estimateCost(string $platform, string $model, int $inputTokens, int $outputTokens): float
    {
        $pricing = self::PRICING[$platform][$model] ?? null;
        if (!$pricing) {
            return 0.0;
        }
        return ($inputTokens * $pricing[0]) + ($outputTokens * $pricing[1]);
    }

    /**
     * Get all registered providers as code => label, sorted by sort_order.
     */
    public static function getAll(): array
    {
        $providers = Mage::getConfig()->getNode('global/ai/providers');
        if (!$providers) {
            return [];
        }

        $result = [];
        foreach ($providers->children() as $code => $node) {
            $result[$code] = [
                'label'      => (string) $node->label,
                'sort_order' => (int) ($node->sort_order ?? 999),
            ];
        }

        uasort($result, fn(array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);

        return array_map(fn(array $item): string => $item['label'], $result);
    }

    /**
     * Get the full config node for a registered provider.
     */
    public static function getProviderConfig(string $code): ?Varien_Simplexml_Element
    {
        $node = Mage::getConfig()->getNode("global/ai/providers/{$code}");
        return $node ?: null;
    }

    /**
     * Get providers that declare a given capability, sorted by sort_order.
     *
     * @param string $capability  One of: chat, embed, image, video
     * @return array<string, string>  code => label
     */
    public static function getProvidersWithCapability(string $capability): array
    {
        $providers = Mage::getConfig()->getNode('global/ai/providers');
        if (!$providers) {
            return [];
        }

        $result = [];
        foreach ($providers->children() as $code => $node) {
            $capabilities = array_map('trim', explode(',', (string) ($node->capabilities ?? '')));
            if (in_array($capability, $capabilities, true)) {
                $result[(string) $code] = [
                    'label'      => (string) $node->label,
                    'sort_order' => (int) ($node->sort_order ?? 999),
                ];
            }
        }

        uasort($result, fn(array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);

        return array_map(fn(array $item): string => $item['label'], $result);
    }
}
