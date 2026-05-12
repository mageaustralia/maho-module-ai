<?php

declare(strict_types=1);

/**
 * Maho
 *
 * @package    Maho_Ai
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *
 * Schema upgrade 1.1.0 → 1.1.1: widen TEXT columns on maho_ai_task to
 * MEDIUMTEXT.
 *
 * `context` holds arbitrary JSON-encoded options passed by consumers — for
 * image-generation tasks this can include `imageDataUrl` (a full base64
 * data: URL of a source image for img2img). A typical 1024×1024 source
 * image base64-encodes to ~70–200KB, which silently truncates against
 * MySQL's TEXT limit (65,535 bytes) — the model then receives broken
 * JSON / broken JPEG and returns garbage output.
 *
 * `messages` (prompt JSON) and `response` (model output) hit the same
 * ceiling for long-form completions, so widening them at the same time.
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$table      = $installer->getTable('ai/task');

$connection->modifyColumn($table, 'context', [
    'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
    'length'   => '16M',
    'nullable' => true,
    'comment'  => 'JSON-encoded task context (options, callback hints, source images, ...)',
]);

$connection->modifyColumn($table, 'messages', [
    'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
    'length'   => '16M',
    'nullable' => true,
    'comment'  => 'JSON-encoded chat-style messages array',
]);

$connection->modifyColumn($table, 'response', [
    'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
    'length'   => '16M',
    'nullable' => true,
    'comment'  => 'Model output: completion text, image URL/data, or embedding JSON',
]);

$installer->endSetup();
