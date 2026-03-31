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

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();

// -------------------------------------------------------------------------
// 1. Add task_type column to maho_ai_task
// -------------------------------------------------------------------------
$taskTable = $installer->getTable('ai/task');

if ($connection->isTableExists($taskTable) && !$connection->tableColumnExists($taskTable, 'task_type')) {
    $connection->addColumn(
        $taskTable,
        'task_type',
        [
            'type'     => Varien_Db_Ddl_Table::TYPE_VARCHAR,
            'length'   => 16,
            'nullable' => false,
            'default'  => 'completion',
            'comment'  => 'Task type: completion, embedding, image',
            'after'    => 'action',
        ],
    );

    $connection->addIndex(
        $taskTable,
        $installer->getIdxName('ai/task', ['task_type']),
        ['task_type'],
    );
}

// -------------------------------------------------------------------------
// 2. Create maho_ai_vector table
// -------------------------------------------------------------------------
$vectorTable = $installer->getTable('ai/vector');

if (!$connection->isTableExists($vectorTable)) {
    $table = $connection->newTable($vectorTable)
        ->addColumn(
            'vector_id',
            Varien_Db_Ddl_Table::TYPE_INTEGER,
            null,
            ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true],
            'Vector ID',
        )
        ->addColumn(
            'entity_type',
            Varien_Db_Ddl_Table::TYPE_VARCHAR,
            32,
            ['nullable' => false],
            'Entity type (product, category)',
        )
        ->addColumn(
            'entity_id',
            Varien_Db_Ddl_Table::TYPE_INTEGER,
            null,
            ['unsigned' => true, 'nullable' => false],
            'Entity ID',
        )
        ->addColumn(
            'store_id',
            Varien_Db_Ddl_Table::TYPE_SMALLINT,
            null,
            ['unsigned' => true, 'nullable' => false, 'default' => 0],
            'Store ID',
        )
        ->addColumn(
            'platform',
            Varien_Db_Ddl_Table::TYPE_VARCHAR,
            32,
            ['nullable' => true],
            'AI platform used to generate the vector',
        )
        ->addColumn(
            'model',
            Varien_Db_Ddl_Table::TYPE_VARCHAR,
            128,
            ['nullable' => true],
            'Embedding model used',
        )
        ->addColumn(
            'dimensions',
            Varien_Db_Ddl_Table::TYPE_INTEGER,
            null,
            ['unsigned' => true, 'nullable' => true],
            'Number of dimensions in the vector',
        )
        ->addColumn(
            'vector',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            '16M',
            ['nullable' => false],
            'JSON-encoded float array',
        )
        ->addColumn(
            'created_at',
            Varien_Db_Ddl_Table::TYPE_TIMESTAMP,
            null,
            ['nullable' => false, 'default' => Varien_Db_Ddl_Table::TIMESTAMP_INIT],
            'Created At',
        )
        ->addColumn(
            'updated_at',
            Varien_Db_Ddl_Table::TYPE_TIMESTAMP,
            null,
            ['nullable' => false, 'default' => Varien_Db_Ddl_Table::TIMESTAMP_INIT_UPDATE],
            'Updated At',
        )
        ->addIndex(
            $installer->getIdxName(
                'ai/vector',
                ['entity_type', 'entity_id', 'store_id'],
                Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE,
            ),
            ['entity_type', 'entity_id', 'store_id'],
            ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE],
        )
        ->addIndex(
            $installer->getIdxName('ai/vector', ['entity_type', 'entity_id']),
            ['entity_type', 'entity_id'],
        )
        ->setComment('Maho AI — Entity Embedding Vectors');

    $connection->createTable($table);
}

$installer->endSetup();
