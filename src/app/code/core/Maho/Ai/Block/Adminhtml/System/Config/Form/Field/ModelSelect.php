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
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element): string
    {
        $html = parent::_getElementHtml($element);

        // Extract provider and capability group from element HTML ID: maho_ai_{group}_{provider}_model
        $elementId = $element->getHtmlId();
        $provider = null;
        $capability = 'chat';
        if (preg_match('/^maho_ai_(general|image|embed|video)_(.+)_model$/', $elementId, $matches)) {
            $group = $matches[1];
            $candidate = $matches[2];
            // Check if provider has a model fetcher (built-in method or community class)
            $config = Maho_Ai_Model_Platform::getProviderConfig($candidate);
            if ($config && ((string) ($config->model_fetcher_method ?? '') || (string) ($config->model_fetcher_class ?? ''))) {
                $provider = $candidate;
                $capability = $group === 'general' ? 'chat' : $group;
            }
        }

        if ($provider === null) {
            return $html;
        }

        $fetchUrl = $this->escapeHtml(
            $this->getUrl('*/ai/fetchModels', ['provider' => $provider, 'capability' => $capability]),
        );
        $escapedId = $this->escapeHtml($elementId);

        // Only inject the JS function definition once per page
        $jsInit = '';
        if (!$this->getData('_model_select_js_injected')) {
            $this->setData('_model_select_js_injected', true);
            $jsInit = <<<'JS'
<script>
function mahoAiFetchModels(url, selectId, btn) {
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
            var el = document.getElementById(selectId);
            if (!el) return;
            var current = el.value;
            // If the element is an input (text field), replace it with a select
            if (el.tagName === 'INPUT') {
                var sel = document.createElement('select');
                sel.id = el.id;
                sel.name = el.name;
                sel.className = el.className;
                sel.style.cssText = el.style.cssText;
                el.parentNode.replaceChild(sel, el);
                el = sel;
            }
            el.innerHTML = '';
            data.models.forEach(function(m) {
                var opt = document.createElement('option');
                opt.value = m.value;
                opt.text = m.label;
                if (m.value === current) { opt.selected = true; }
                el.appendChild(opt);
            });
            // If no option matched the previous value, select it anyway
            if (current && !el.querySelector('option[selected]')) {
                el.value = current;
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
