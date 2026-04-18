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

class Maho_Ai_Block_Adminhtml_System_Config_Form_Field_ModelSelect extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    #[\Override]
    protected function _getElementHtml(\Maho\Data\Form\Element\AbstractElement $element): string
    {
        $html = parent::_getElementHtml($element);

        // Extract provider and capability group from element HTML ID: maho_ai_{group}_{provider}_model
        $elementId = $element->getHtmlId();
        $provider = null;
        $capability = 'chat';
        if (preg_match('/^maho_ai_(general|image|embed|video)_(.+)_model$/', $elementId, $matches)) {
            $group = $matches[1];
            $candidate = $matches[2];
            $config = Maho_Ai_Model_Platform::getProviderConfig($candidate);
            if ($config && ((string) ($config->model_fetcher_method ?? '') || (string) ($config->model_fetcher_class ?? ''))) {
                $provider = $candidate;
                $capability = $group === 'general' ? 'chat' : $group;
            }
        }

        if ($provider === null) {
            return $html;
        }

        $fetchUrl = $this->getUrl('*/ai/fetchModels', ['provider' => $provider, 'capability' => $capability]);

        $btn = sprintf(
            '<button type="button" class="scalable" data-maho-ai-fetch-models data-target="%s" data-url="%s"><span>%s</span></button>',
            $this->escapeHtml($elementId),
            $this->escapeHtml($fetchUrl),
            $this->escapeHtml(Mage::helper('ai')->__('Update Models')),
        );

        return '<div class="ai-model-select-row">' . $html . $btn . '</div>';
    }
}
