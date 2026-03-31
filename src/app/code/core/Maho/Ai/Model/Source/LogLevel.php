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

class Maho_Ai_Model_Source_LogLevel
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'minimal',  'label' => Mage::helper('ai')->__('Minimal (consumer + tokens)')],
            ['value' => 'standard', 'label' => Mage::helper('ai')->__('Standard (+ model + cost)')],
            ['value' => 'verbose',  'label' => Mage::helper('ai')->__('Verbose (+ prompt preview)')],
        ];
    }
}
