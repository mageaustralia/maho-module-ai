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

class Maho_Ai_Model_Source_EmbedPlatform
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Maho_Ai_Model_Platform::OPENAI,  'label' => 'OpenAI'],
            ['value' => Maho_Ai_Model_Platform::GOOGLE,  'label' => 'Google'],
            ['value' => Maho_Ai_Model_Platform::MISTRAL, 'label' => 'Mistral'],
            ['value' => Maho_Ai_Model_Platform::OLLAMA,  'label' => 'Ollama (local)'],
            ['value' => Maho_Ai_Model_Platform::GENERIC, 'label' => 'Generic (OpenAI-compatible)'],
        ];
    }
}
