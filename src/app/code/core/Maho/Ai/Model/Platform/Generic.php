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
 * Generic OpenAI-compatible provider for custom endpoints
 */
class Maho_Ai_Model_Platform_Generic extends Maho_Ai_Model_Platform_OpenAi
{
    public function __construct(string $baseUrl, string $apiKey, string $defaultModel)
    {
        parent::__construct(
            apiKey: $apiKey,
            defaultModel: $defaultModel,
            baseUrl: $baseUrl,
        );
        $this->platformCode = Maho_Ai_Model_Platform::GENERIC;
    }
}
