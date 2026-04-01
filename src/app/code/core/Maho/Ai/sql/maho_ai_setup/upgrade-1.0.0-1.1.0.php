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

/** @var Mage_Core_Model_Resource_Setup $this */
$this->startSetup();

$connection = $this->getConnection();

// Add task_type column to maho_ai_task
$taskTable = $this->getTable('ai/task');

if (!$connection->tableColumnExists($taskTable, 'task_type')) {
    $connection->addColumn($taskTable, 'task_type', [
        'type'     => Maho\Db\Ddl\Table::TYPE_VARCHAR,
        'length'   => 16,
        'nullable' => false,
        'default'  => 'completion',
        'comment'  => 'Task type: completion, embedding, image',
        'after'    => 'action',
    ]);
}

// Add index on task_type
$idxName = $this->getIdxName('ai/task', ['task_type']);
$indexes = $connection->getIndexList($taskTable);
if (!isset($indexes[strtoupper($idxName)])) {
    $connection->addIndex($taskTable, $idxName, ['task_type']);
}

// Create maho_ai_vector table
if (!$connection->isTableExists($this->getTable('ai/vector'))) {
    $table = $connection->newTable($this->getTable('ai/vector'))
        ->addColumn('vector_id', Maho\Db\Ddl\Table::TYPE_INTEGER, null, [
            'identity' => true,
            'unsigned' => true,
            'nullable' => false,
            'primary'  => true,
        ], 'Vector ID')
        ->addColumn('entity_type', Maho\Db\Ddl\Table::TYPE_VARCHAR, 32, [
            'nullable' => false,
        ], 'Entity type (product, category)')
        ->addColumn('entity_id', Maho\Db\Ddl\Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => false,
        ], 'Entity ID')
        ->addColumn('store_id', Maho\Db\Ddl\Table::TYPE_SMALLINT, null, [
            'unsigned' => true,
            'nullable' => false,
            'default'  => 0,
        ], 'Store ID')
        ->addColumn('platform', Maho\Db\Ddl\Table::TYPE_VARCHAR, 32, [
            'nullable' => true,
        ], 'AI platform used to generate the vector')
        ->addColumn('model', Maho\Db\Ddl\Table::TYPE_VARCHAR, 128, [
            'nullable' => true,
        ], 'Embedding model used')
        ->addColumn('dimensions', Maho\Db\Ddl\Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => true,
        ], 'Number of dimensions in the vector')
        ->addColumn('vector', Maho\Db\Ddl\Table::TYPE_TEXT, '16M', [
            'nullable' => false,
        ], 'JSON-encoded float array')
        ->addColumn('created_at', Maho\Db\Ddl\Table::TYPE_TIMESTAMP, null, [
            'nullable' => false,
            'default'  => Maho\Db\Ddl\Table::TIMESTAMP_INIT,
        ], 'Created At')
        ->addColumn('updated_at', Maho\Db\Ddl\Table::TYPE_TIMESTAMP, null, [
            'nullable' => false,
            'default'  => Maho\Db\Ddl\Table::TIMESTAMP_INIT_UPDATE,
        ], 'Updated At')
        ->addIndex(
            $this->getIdxName('ai/vector', ['entity_type', 'entity_id', 'store_id'], Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            ['entity_type', 'entity_id', 'store_id'],
            ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE],
        )
        ->addIndex(
            $this->getIdxName('ai/vector', ['entity_type', 'entity_id']),
            ['entity_type', 'entity_id'],
        )
        ->setComment('Maho AI — Entity Embedding Vectors');

    $connection->createTable($table);
}

$this->endSetup();
