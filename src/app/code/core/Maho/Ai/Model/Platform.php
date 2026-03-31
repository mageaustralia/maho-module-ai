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
     * Get all supported platforms as code => label
     */
    public static function getAll(): array
    {
        return [
            self::OPENAI     => 'OpenAI',
            self::ANTHROPIC  => 'Anthropic (Claude)',
            self::GOOGLE     => 'Google (Gemini)',
            self::MISTRAL    => 'Mistral AI',
            self::OPENROUTER => 'OpenRouter',
            self::OLLAMA     => 'Ollama (Local)',
            self::GENERIC    => 'Generic (OpenAI-compatible)',
        ];
    }
}
