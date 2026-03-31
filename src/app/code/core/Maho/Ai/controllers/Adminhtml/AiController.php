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

class Maho_Ai_Adminhtml_AiController extends Mage_Adminhtml_Controller_Action
{
    public const ADMIN_RESOURCE = 'system/config';

    /** Skip secret key validation for AJAX — session cookie + ACL is sufficient. */
    protected $_publicActions = ['fetchModels'];

    protected function _initAction(): static
    {
        $this->loadLayout()
            ->_setActiveMenu('system/maho_ai/dashboard')
            ->_addBreadcrumb(
                Mage::helper('ai')->__('Maho AI'),
                Mage::helper('ai')->__('Maho AI'),
            );

        return $this;
    }

    public function dashboardAction(): void
    {
        $this->_redirect('*/system_config/edit', ['section' => 'maho_ai']);
    }

    public function tasksAction(): void
    {
        $this->_title(Mage::helper('ai')->__('AI Task History'));
        $this->_initAction();
        $this->_addBreadcrumb(
            Mage::helper('ai')->__('Task History'),
            Mage::helper('ai')->__('Task History'),
        );
        $this->renderLayout();
    }

    /**
     * AJAX: fetch available models for a provider and cache in config.
     * The admin secret key in the URL provides CSRF protection.
     */
    public function fetchModelsAction(): void
    {
        $this->getResponse()->setHeader('Content-Type', 'application/json');

        $provider = (string) $this->getRequest()->getParam('provider');
        if ($provider === '') {
            $this->getResponse()->setBody(json_encode(['error' => 'Provider is required.']));
            return;
        }

        try {
            /** @var Maho_Ai_Model_Platform_ModelFetcher $fetcher */
            $fetcher = Mage::getModel('ai/platform_modelFetcher');
            $models = $fetcher->fetchForProvider($provider);

            // Cache result in config so source models can use it
            Mage::getModel('core/config')->saveConfig(
                "maho_ai/models_cache/{$provider}",
                json_encode($models),
            );
            Mage::app()->getCache()->cleanType('config');

            $this->getResponse()->setBody(json_encode(['models' => $models]));
        } catch (Exception $e) {
            $this->getResponse()->setBody(json_encode(['error' => $e->getMessage()]));
        }
    }
}
