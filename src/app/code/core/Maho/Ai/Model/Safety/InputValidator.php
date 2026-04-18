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

class Maho_Ai_Model_Safety_InputValidator
{
    /**
     * Patterns that indicate prompt injection attempts
     */
    private const array INJECTION_PATTERNS = [
        '/ignore\s+(all\s+)?(previous|prior|above|earlier)\s+instructions?/i',
        '/disregard\s+(all\s+)?(previous|prior|above|earlier)\s+instructions?/i',
        '/forget\s+(all\s+)?(previous|prior|above|earlier)\s+instructions?/i',
        '/you\s+are\s+now\s+(a|an)\s+/i',
        '/act\s+as\s+(if\s+you\s+are\s+)?(a|an)\s+/i',
        '/pretend\s+(you\s+are|to\s+be)\s+/i',
        '/jailbreak/i',
        '/dan\s+mode/i',
        '/developer\s+mode/i',
        '/\[system\]/i',
        '/\[assistant\]/i',
        '/\<\|system\|\>/i',
        '/\<\|assistant\|\>/i',
        '/###\s*system/i',
        '/###\s*assistant/i',
    ];

    /**
     * Validate user input before sending to AI
     *
     * @return array{safe: bool, reason: string}
     */
    public function validate(string $input): array
    {
        if (!Mage::getStoreConfigFlag('maho_ai/safety/injection_detection')) {
            return ['safe' => true, 'reason' => ''];
        }

        // Check built-in injection patterns
        foreach (self::INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $input)) {
                return [
                    'safe'   => false,
                    'reason' => 'Input contains a potential prompt injection pattern.',
                ];
            }
        }

        // Check base64 encoded content that might hide instructions
        if (preg_match('/[A-Za-z0-9+\/]{50,}={0,2}/', $input)) {
            $decoded = base64_decode((string) preg_replace('/[^A-Za-z0-9+\/=]/', '', $input), true);
            if ($decoded !== false && strlen($decoded) > 20) {
                // Check decoded content for injection patterns too
                foreach (self::INJECTION_PATTERNS as $pattern) {
                    if (preg_match($pattern, $decoded)) {
                        return [
                            'safe'   => false,
                            'reason' => 'Input contains base64-encoded prompt injection.',
                        ];
                    }
                }
            }
        }

        // Check custom blocked patterns from config
        $customPatterns = (string) Mage::getStoreConfig('maho_ai/safety/blocked_patterns');
        if ($customPatterns !== '' && $customPatterns !== '0') {
            foreach (array_filter(array_map(trim(...), explode("\n", $customPatterns))) as $pattern) {
                if (@preg_match($pattern, $input)) {
                    return [
                        'safe'   => false,
                        'reason' => 'Input matches a blocked pattern.',
                    ];
                }
            }
        }

        return ['safe' => true, 'reason' => ''];
    }
}
