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

class Maho_Ai_Model_Source_Models
{
    /** Provider being filtered to. Empty string = all providers. */
    protected string $provider = '';

    /**
     * Hardcoded defaults shipped with the module.
     * Overridden by cached results from the "Update Models" button.
     *
     * @var array<string, list<array{value: string, label: string}>>
     */
    protected static array $defaults = [
        'openai' => [
            ['value' => 'o3',          'label' => 'o3'],
            ['value' => 'o3-mini',     'label' => 'o3 Mini'],
            ['value' => 'o1',          'label' => 'o1'],
            ['value' => 'o1-mini',     'label' => 'o1 Mini'],
            ['value' => 'gpt-4o',      'label' => 'GPT-4o'],
            ['value' => 'gpt-4o-mini', 'label' => 'GPT-4o Mini'],
            ['value' => 'gpt-4-turbo', 'label' => 'GPT-4 Turbo'],
        ],
        'anthropic' => [
            ['value' => 'claude-opus-4-6',            'label' => 'Claude Opus 4.6'],
            ['value' => 'claude-sonnet-4-6',          'label' => 'Claude Sonnet 4.6'],
            ['value' => 'claude-haiku-4-5-20251001',  'label' => 'Claude Haiku 4.5'],
            ['value' => 'claude-3-5-sonnet-20241022', 'label' => 'Claude 3.5 Sonnet'],
            ['value' => 'claude-3-5-haiku-20241022',  'label' => 'Claude 3.5 Haiku'],
            ['value' => 'claude-3-opus-20240229',     'label' => 'Claude 3 Opus'],
        ],
        'google' => [
            ['value' => 'gemini-2.0-flash',      'label' => 'Gemini 2.0 Flash'],
            ['value' => 'gemini-2.0-flash-lite', 'label' => 'Gemini 2.0 Flash Lite'],
            ['value' => 'gemini-1.5-pro-002',    'label' => 'Gemini 1.5 Pro'],
            ['value' => 'gemini-1.5-flash-002',  'label' => 'Gemini 1.5 Flash'],
        ],
        'mistral' => [
            ['value' => 'mistral-large-latest',  'label' => 'Mistral Large'],
            ['value' => 'mistral-medium-latest', 'label' => 'Mistral Medium'],
            ['value' => 'mistral-small-latest',  'label' => 'Mistral Small'],
            ['value' => 'codestral-latest',      'label' => 'Codestral'],
        ],
    ];

    public function toOptionArray(): array
    {
        if ($this->provider !== '') {
            return $this->getForProvider($this->provider);
        }
        $options = [];
        foreach (static::$defaults as $models) {
            foreach ($models as $model) {
                $options[] = $model;
            }
        }
        return $options;
    }

    protected function getForProvider(string $provider): array
    {
        $cached = Mage::getStoreConfig("maho_ai/models_cache/{$provider}");
        if ($cached) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && $decoded !== []) {
                return $decoded;
            }
        }
        return static::$defaults[$provider] ?? [];
    }
}
