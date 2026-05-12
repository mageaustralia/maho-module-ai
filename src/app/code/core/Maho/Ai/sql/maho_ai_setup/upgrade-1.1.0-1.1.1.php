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

/**
 * Maho's setup scripts run inside Mage_Core_Model_Resource_Setup::_modifyResourceDb()
 * with $this bound to the setup instance - PHPStan can't see that binding
 * (the script file isn't a method body) but the runtime context is solid.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */
/** @phpstan-ignore variable.undefined */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$table      = $installer->getTable('ai/task');

// Widen context / messages / response to MEDIUMTEXT (16M) for long-form
// completions. MySQL's default TEXT caps at 64KB which trims responses
// past ~16k tokens.
//
// PostgreSQL's TEXT is already unlimited (1GB practical max), so the
// widening is a no-op there. Worse, asking Maho's PG adapter to
// "modify a TEXT column to length 16M" translates to
// ALTER COLUMN ... TYPE VARCHAR(16777216), which exceeds PG's 10MB
// varchar ceiling and errors. Gate the modifyColumn calls to MySQL only.
$driver  = (string) $connection->getDriverName();
$isMysql = str_contains(strtolower($driver), 'mysql');

if ($isMysql) {
    $connection->modifyColumn($table, 'context', [
        'type'     => \Maho\Db\Ddl\Table::TYPE_TEXT,
        'length'   => '16M',
        'nullable' => true,
        'comment'  => 'JSON-encoded task context (options, callback hints, source images, ...)',
    ]);

    $connection->modifyColumn($table, 'messages', [
        'type'     => \Maho\Db\Ddl\Table::TYPE_TEXT,
        'length'   => '16M',
        'nullable' => true,
        'comment'  => 'JSON-encoded chat-style messages array',
    ]);

    $connection->modifyColumn($table, 'response', [
        'type'     => \Maho\Db\Ddl\Table::TYPE_TEXT,
        'length'   => '16M',
        'nullable' => true,
        'comment'  => 'Model output: completion text, image URL/data, or embedding JSON',
    ]);
}

$installer->endSetup();
