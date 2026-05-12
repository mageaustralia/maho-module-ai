<?php

declare(strict_types=1);

/**
 * Maho
 *
 * @package    Maho_Ai
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Maho_Ai_Model_Usage extends Mage_Core_Model_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('ai/usage');
    }

    /**
     * Record a single AI call. Aggregates per (consumer, platform, model,
     * store, day) into one row whose counters are incremented in place.
     *
     * Portable across MySQL/Postgres/SQLite: avoids `INSERT ... ON DUPLICATE
     * KEY UPDATE` (MySQL-only). Uses SELECT-then-INSERT-or-UPDATE with a
     * single retry on the unique-constraint violation that another worker
     * may produce between SELECT and INSERT for the very first call of the
     * day on a new (consumer, platform, model, store) combo.
     */
    public function recordCall(
        string $consumer,
        string $platform,
        string $model,
        int $storeId,
        int $inputTokens,
        int $outputTokens,
        float $estimatedCost,
    ): void {
        $today = date('Y-m-d');

        $existing = $this->loadAggregateRow($consumer, $platform, $model, $storeId, $today);
        if ($existing !== null) {
            $this->incrementCounters($existing, $inputTokens, $outputTokens, $estimatedCost);
            return;
        }

        $row = Mage::getModel('ai/usage');
        $row->setData([
            'consumer'       => $consumer,
            'platform'       => $platform,
            'model'          => $model,
            'store_id'       => $storeId,
            'period_date'    => $today,
            'request_count'  => 1,
            'input_tokens'   => $inputTokens,
            'output_tokens'  => $outputTokens,
            'estimated_cost' => $estimatedCost,
        ]);

        try {
            $row->save();
        } catch (\Throwable $e) {
            // Race: another worker inserted the unique row between our SELECT
            // and our INSERT. Retry the increment path once and swallow if it
            // still misses (counter loss is preferable to fataling the AI call).
            $existing = $this->loadAggregateRow($consumer, $platform, $model, $storeId, $today);
            if ($existing !== null) {
                $this->incrementCounters($existing, $inputTokens, $outputTokens, $estimatedCost);
                return;
            }
            Mage::logException($e);
        }
    }

    private function loadAggregateRow(
        string $consumer,
        string $platform,
        string $model,
        int $storeId,
        string $periodDate,
    ): ?self {
        $row = $this->getCollection()
            ->addFieldToFilter('consumer', $consumer)
            ->addFieldToFilter('platform', $platform)
            ->addFieldToFilter('model', $model)
            ->addFieldToFilter('store_id', $storeId)
            ->addFieldToFilter('period_date', $periodDate)
            ->setPageSize(1)
            ->getFirstItem();
        return ($row && $row->getId()) ? $row : null;
    }

    private function incrementCounters(
        self $row,
        int $inputTokens,
        int $outputTokens,
        float $estimatedCost,
    ): void {
        $row->setRequestCount((int) $row->getRequestCount() + 1);
        $row->setInputTokens((int) $row->getInputTokens() + $inputTokens);
        $row->setOutputTokens((int) $row->getOutputTokens() + $outputTokens);
        $row->setEstimatedCost((float) $row->getEstimatedCost() + $estimatedCost);
        $row->save();
    }
}
