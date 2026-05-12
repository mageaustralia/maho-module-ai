<?php

declare(strict_types=1);

/**
 * Maho
 *
 * @package    Maho_Ai
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Maho_Ai_Adminhtml_AiController extends Mage_Adminhtml_Controller_Action
{
    public const ADMIN_RESOURCE = 'system/config';

    /** Skip secret key validation for AJAX — session cookie + ACL is sufficient. */
    protected $_publicActions = ['fetchModels', 'taskStatus'];

    #[\Override]
    public function preDispatch(): static
    {
        $this->_setForcedFormKeyActions(['reindexPost']);
        return parent::preDispatch();
    }

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

    #[Maho\Config\Route('/admin/ai/dashboard')]
    public function dashboardAction(): void
    {
        $this->_redirect('*/system_config/edit', ['section' => 'maho_ai']);
    }

    #[Maho\Config\Route('/admin/ai/tasks')]
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

    #[Maho\Config\Route('/admin/ai/view')]
    public function viewAction(): void
    {
        $id   = (int) $this->getRequest()->getParam('id');
        $task = Mage::getModel('ai/task')->load($id);

        if (!$task->getId()) {
            Mage::getSingleton('adminhtml/session')->addError(Mage::helper('ai')->__('Task not found.'));
            $this->_redirect('*/*/tasks');
            return;
        }

        Mage::register('current_ai_task', $task);

        $this->_title(Mage::helper('ai')->__('Task #%s', $id));
        $this->_initAction();
        $this->_addBreadcrumb(
            Mage::helper('ai')->__('Task History'),
            Mage::helper('ai')->__('Task History'),
            $this->getUrl('*/*/tasks'),
        );
        $this->_addBreadcrumb(
            Mage::helper('ai')->__('Task #%s', $id),
            Mage::helper('ai')->__('Task #%s', $id),
        );
        $this->renderLayout();
    }

    /**
     * JSON endpoint for polling task status — used by frontends that submit
     * an async AI task and want to display progress to the user without
     * blocking the originating HTTP request (Cloudflare's 60s edge timeout
     * is the typical motivator). Pair with submitImageTask() / submitTask()
     * + processTask() in the submitting controller.
     *
     * Returns: {task_id, status, task_type, response, error_message, completed_at}.
     * `response` is only populated when status is 'complete'; for image tasks,
     * it is the generated image URL.
     */
    #[Maho\Config\Route('/admin/ai/task_status')]
    public function taskStatusAction(): void
    {
        $this->getResponse()->setHeader('Content-Type', 'application/json');

        $id = (int) $this->getRequest()->getParam('id');
        if ($id <= 0) {
            $this->getResponse()->setHttpResponseCode(400);
            $this->getResponse()->setBody(json_encode(['error' => 'Missing or invalid id parameter.']));
            return;
        }

        /** @var Maho_Ai_Model_Task $task */
        $task = Mage::getModel('ai/task')->load($id);
        if (!$task->getId()) {
            $this->getResponse()->setHttpResponseCode(404);
            $this->getResponse()->setBody(json_encode(['error' => 'Task not found.']));
            return;
        }

        $status = (string) $task->getData('status');
        $this->getResponse()->setBody(json_encode([
            'task_id'       => $id,
            'status'        => $status,
            'task_type'     => (string) $task->getData('task_type'),
            'response'      => $status === Maho_Ai_Model_Task::STATUS_COMPLETE
                ? (string) $task->getData('response')
                : null,
            'error_message' => (string) $task->getData('error_message') ?: null,
            'completed_at'  => $task->getData('completed_at'),
        ]));
    }

    #[Maho\Config\Route('/admin/ai/usage')]
    public function usageAction(): void
    {
        $this->_title(Mage::helper('ai')->__('AI Usage'));
        $this->_initAction();
        $this->_addBreadcrumb(
            Mage::helper('ai')->__('Usage'),
            Mage::helper('ai')->__('Usage'),
        );
        $this->renderLayout();
    }

    #[Maho\Config\Route('/admin/ai/reindex')]
    public function reindexAction(): void
    {
        $this->_title(Mage::helper('ai')->__('Queue All Embeddings'));
        $this->_initAction();
        $this->_addBreadcrumb(
            Mage::helper('ai')->__('Queue All Embeddings'),
            Mage::helper('ai')->__('Queue All Embeddings'),
        );
        $this->renderLayout();
    }

    #[Maho\Config\Route('/admin/ai/reindexPost')]
    public function reindexPostAction(): void
    {
        $types   = (array) $this->getRequest()->getPost('types', []);
        $storeId = 0;
        $queued  = 0;

        if (in_array('products', $types)) {
            $queued += $this->_queueEntityType('product', $storeId);
        }
        if (in_array('categories', $types)) {
            $queued += $this->_queueEntityType('category', $storeId);
        }

        if ($queued > 0) {
            Mage::getSingleton('adminhtml/session')->addSuccess(
                Mage::helper('ai')->__('%s items queued for embedding.', number_format($queued)),
            );
        } else {
            Mage::getSingleton('adminhtml/session')->addNotice(
                Mage::helper('ai')->__('No items were queued. Make sure products/categories have text content.'),
            );
        }

        $this->_redirect('*/*/reindex');
    }

    /**
     * AJAX: fetch available models for a provider and cache in config.
     * Listed in $_publicActions to skip URL secret key (incompatible with AJAX).
     * CSRF is mitigated by ACL (admin session required) and browser same-origin policy.
     * Provider is implicitly whitelisted by fetchForProvider()'s match() statement.
     */
    #[Maho\Config\Route('/admin/ai/fetchModels')]
    public function fetchModelsAction(): void
    {
        $this->getResponse()->setHeader('Content-Type', 'application/json');

        $provider = (string) $this->getRequest()->getParam('provider');
        $capability = (string) $this->getRequest()->getParam('capability') ?: 'chat';
        if ($provider === '') {
            $this->getResponse()->setBody(json_encode(['error' => 'Provider is required.']));
            return;
        }

        try {
            /** @var Maho_Ai_Model_Platform_ModelFetcher $fetcher */
            $fetcher = Mage::getModel('ai/platform_modelFetcher');
            $models = $fetcher->fetchForProvider($provider, $capability);

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

    /**
     * Batch-queue all entities of a given type for embedding.
     * Loads entities in pages of 500 and bulk-inserts task rows.
     */
    private function _queueEntityType(string $type, int $storeId): int
    {
        $conn      = Mage::getSingleton('core/resource')->getConnection('core_write');
        $taskTable = Mage::getSingleton('core/resource')->getTableName('ai/task');
        $now       = date('Y-m-d H:i:s');
        $batch     = [];
        $count     = 0;

        $baseRow = [
            'action'      => 'embed',
            'task_type'   => Maho_Ai_Model_Task::TYPE_EMBEDDING,
            'status'      => Maho_Ai_Model_Task::STATUS_PENDING,
            'priority'    => Maho_Ai_Model_Task::PRIORITY_BACKGROUND,
            'max_retries' => 3,
            'store_id'    => $storeId,
            'created_at'  => $now,
        ];

        $flush = function () use (&$batch, &$count, $conn, $taskTable): void {
            // PHPStan can't track that $batch gets appended to outside the
            // closure (the loop below grows it before each flush call), so
            // it sees the initial [] and concludes this if is always false.
            // @phpstan-ignore if.alwaysFalse
            if ($batch) {
                $conn->insertMultiple($taskTable, $batch);
                $count += count($batch);
                $batch = [];
            }
        };

        if ($type === 'product') {
            $collection = Mage::getResourceModel('catalog/product_collection')
                ->addAttributeToSelect(['name', 'short_description', 'description'])
                ->setStoreId($storeId)
                ->addAttributeToFilter('status', ['eq' => Mage_Catalog_Model_Product_Status::STATUS_ENABLED])
                ->setPageSize(500);

            $pages = $collection->getLastPageNumber();
            for ($page = 1; $page <= $pages; $page++) {
                $collection->setCurPage($page)->load();
                foreach ($collection as $product) {
                    $text = $this->_buildProductText($product);
                    if ($text === '') {
                        continue;
                    }
                    $batch[] = $baseRow + [
                        'consumer' => 'catalog_product',
                        'messages' => json_encode([['role' => 'user', 'content' => $text]]),
                        'context'  => json_encode(['entity_type' => 'product', 'entity_id' => (int) $product->getId()]),
                    ];
                    if (count($batch) >= 500) {
                        $flush();
                    }
                }
                $collection->clear();
            }
        } elseif ($type === 'category') {
            $collection = Mage::getResourceModel('catalog/category_collection')
                ->addAttributeToSelect(['name', 'description'])
                ->addAttributeToFilter('is_active', ['eq' => 1])
                ->addAttributeToFilter('level', ['gt' => 1])
                ->setPageSize(500);

            $pages = $collection->getLastPageNumber();
            for ($page = 1; $page <= $pages; $page++) {
                $collection->setCurPage($page)->load();
                foreach ($collection as $category) {
                    $text = trim(implode(' ', array_filter([
                        $category->getName(),
                        strip_tags((string) ($category->getData('description') ?? '')),
                    ])));
                    if ($text === '') {
                        continue;
                    }
                    $batch[] = $baseRow + [
                        'consumer' => 'catalog_category',
                        'messages' => json_encode([['role' => 'user', 'content' => $text]]),
                        'context'  => json_encode(['entity_type' => 'category', 'entity_id' => (int) $category->getId()]),
                    ];
                    if (count($batch) >= 500) {
                        $flush();
                    }
                }
                $collection->clear();
            }
        }

        $flush();

        return $count;
    }

    private function _buildProductText(Mage_Catalog_Model_Product $product): string
    {
        return trim(implode(' ', array_filter([
            $product->getName(),
            strip_tags((string) ($product->getData('short_description') ?? '')),
            strip_tags((string) ($product->getData('description') ?? '')),
        ])));
    }
}
