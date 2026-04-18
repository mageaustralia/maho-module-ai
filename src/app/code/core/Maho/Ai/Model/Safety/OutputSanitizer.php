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

class Maho_Ai_Model_Safety_OutputSanitizer
{
    /**
     * Tags that are never allowed in AI output
     */
    private const array FORBIDDEN_TAGS = ['script', 'iframe', 'object', 'embed', 'form', 'base', 'link', 'meta', 'style'];

    /**
     * PII patterns — detects but doesn't block
     */
    private const array PII_PATTERNS = [
        'email'   => '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
        'phone'   => '/\+?[\d\s\-\(\)]{10,}/',
        'ssn'     => '/\b\d{3}[-\s]?\d{2}[-\s]?\d{4}\b/',
        'cc'      => '/\b(?:\d{4}[-\s]?){3}\d{4}\b/',
    ];

    /**
     * Sanitize AI output for safe use
     */
    public function sanitize(string $output, bool $isHtml = false, array &$metadata = []): string
    {
        if ($isHtml && Mage::getStoreConfigFlag('maho_ai/safety/output_sanitize_html')) {
            $output = $this->sanitizeHtml($output);
        }

        if (Mage::getStoreConfigFlag('maho_ai/safety/pii_detection')) {
            $this->detectPii($output, $metadata);
        }

        return $output;
    }

    private function sanitizeHtml(string $html): string
    {
        // Strip forbidden tags entirely (including content for script)
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', (string) $html);

        foreach (self::FORBIDDEN_TAGS as $tag) {
            $html = preg_replace(sprintf('/<%s(\s[^>]*)?\/?>/i', $tag), '', (string) $html);
            $html = preg_replace(sprintf('/<\/%s>/i', $tag), '', (string) $html);
        }

        // Remove on* event handlers (onclick, onerror, onload, etc.)
        $html = preg_replace('/\s+on[a-z]+\s*=\s*["\'][^"\']*["\']/i', '', (string) $html);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*[^\s>]+/i', '', (string) $html);

        // Remove javascript: URLs
        $html = preg_replace('/href\s*=\s*["\']?\s*javascript:/i', 'href="javascript:void(0)"', (string) $html);
        $html = preg_replace('/src\s*=\s*["\']?\s*javascript:/i', 'src=""', (string) $html);

        // Remove data: URLs in src attributes (potential XSS vector)
        $html = preg_replace('/src\s*=\s*["\']?\s*data:/i', 'src=""', (string) $html);

        return $html;
    }

    private function detectPii(string $text, array &$metadata): void
    {
        $foundTypes = [];
        foreach (self::PII_PATTERNS as $type => $pattern) {
            if (preg_match($pattern, $text)) {
                $foundTypes[] = $type;
            }
        }

        if ($foundTypes !== []) {
            $metadata['pii_flagged'] = true;
            $metadata['pii_types'] = $foundTypes;
            Mage::log(
                sprintf('Maho AI: PII detected in output (%s)', implode(', ', $foundTypes)),
                Mage::LOG_WARNING,
                'maho_ai.log',
            );
        }
    }
}
