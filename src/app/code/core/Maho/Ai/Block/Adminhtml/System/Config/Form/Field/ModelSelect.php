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
    /** Providers that expose a models API endpoint. */
    private const FETCHABLE_PROVIDERS = ['openai', 'anthropic', 'google', 'mistral', 'openrouter', 'ollama'];

    #[\Override]
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element): string
    {
        $html = parent::_getElementHtml($element);

        // Extract provider from element HTML ID: maho_ai_general_{provider}_model
        $elementId = $element->getHtmlId();
        $provider = null;
        if (preg_match('/^maho_ai_general_(.+)_model$/', $elementId, $matches)) {
            $candidate = $matches[1];
            if (in_array($candidate, self::FETCHABLE_PROVIDERS, true)) {
                $provider = $candidate;
            }
        }

        if ($provider === null) {
            return $html;
        }

        $fetchUrl = $this->escapeHtml(
            $this->getUrl('*/ai/fetchModels', ['provider' => $provider]),
        );
        $escapedId = $this->escapeHtml($elementId);

        // Only inject the JS function definition once per page
        $jsInit = '';
        if (!$this->getData('_model_select_js_injected')) {
            $this->setData('_model_select_js_injected', true);
            $jsInit = <<<'JS'
<script>
function mahoAiFetchModels(url, selectId, btn) {
    // Replace the baked-in admin secret key with the current page's key,
    // since the key is session-dependent and the URL was rendered at page-load time.
    var keyMatch = window.location.href.match(/\/key\/([a-f0-9]+)\//);
    if (keyMatch) {
        url = url.replace(/\/key\/[a-f0-9]+\//, '/key/' + keyMatch[1] + '/');
    }
    var orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Fetching\u2026';
    fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) {
                alert('Error: ' + data.error);
                return;
            }
            var select = document.getElementById(selectId);
            var current = select ? select.value : '';
            if (select) {
                select.innerHTML = '';
                data.models.forEach(function(m) {
                    var opt = document.createElement('option');
                    opt.value = m.value;
                    opt.text = m.label;
                    if (m.value === current) { opt.selected = true; }
                    select.appendChild(opt);
                });
            }
        })
        .catch(function(e) { alert('Error: ' + e.message); })
        .finally(function() { btn.disabled = false; btn.innerHTML = orig; });
}
</script>
JS;
        }

        $btn = sprintf(
            '<button type="button" class="scalable" onclick="mahoAiFetchModels(\'%s\', \'%s\', this)" style="white-space:nowrap;margin-left:8px"><span>%s</span></button>',
            $fetchUrl,
            $escapedId,
            $this->escapeHtml(Mage::helper('ai')->__('Update Models')),
        );

        // Wrap select + button in a flex row so the button sits beside the dropdown
        return $jsInit
            . '<div style="display:flex;align-items:center;gap:0">'
            . $html
            . $btn
            . '</div>';
    }
}
