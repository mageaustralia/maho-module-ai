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

class Maho_Ai_Model_Task extends Mage_Core_Model_Abstract
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETE   = 'complete';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELLED  = 'cancelled';

    public const PRIORITY_INTERACTIVE = 'interactive';
    public const PRIORITY_BACKGROUND  = 'background';

    #[\Override]
    protected function _construct(): void
    {
        $this->_init('ai/task');
    }

    public function getMessagesArray(): array
    {
        $json = $this->getData('messages');
        if (!$json) {
            return [];
        }
        return json_decode($json, true) ?? [];
    }

    public function getContextArray(): array
    {
        $json = $this->getData('context');
        if (!$json) {
            return [];
        }
        return json_decode($json, true) ?? [];
    }

    public function isPending(): bool
    {
        return $this->getData('status') === self::STATUS_PENDING;
    }

    public function isComplete(): bool
    {
        return $this->getData('status') === self::STATUS_COMPLETE;
    }

    public function isFailed(): bool
    {
        return $this->getData('status') === self::STATUS_FAILED;
    }

    public function markProcessing(): static
    {
        $this->setData('status', self::STATUS_PROCESSING);
        $this->setData('started_at', date('Y-m-d H:i:s'));
        return $this;
    }

    public function markComplete(string $response, int $inputTokens, int $outputTokens, string $platform, string $model): static
    {
        $this->setData('status', self::STATUS_COMPLETE);
        $this->setData('response', $response);
        $this->setData('input_tokens', $inputTokens);
        $this->setData('output_tokens', $outputTokens);
        $this->setData('platform', $platform);
        $this->setData('model', $model);
        $this->setData('estimated_cost', Maho_Ai_Model_Platform::estimateCost($platform, $model, $inputTokens, $outputTokens));
        $this->setData('completed_at', date('Y-m-d H:i:s'));
        return $this;
    }

    public function markFailed(string $errorMessage): static
    {
        $retries = (int) $this->getData('retries');
        $maxRetries = (int) $this->getData('max_retries');

        if ($retries < $maxRetries) {
            // Re-queue for retry
            $this->setData('status', self::STATUS_PENDING);
            $this->setData('retries', $retries + 1);
            $this->setData('error_message', $errorMessage);
        } else {
            $this->setData('status', self::STATUS_FAILED);
            $this->setData('error_message', $errorMessage);
            $this->setData('completed_at', date('Y-m-d H:i:s'));
        }
        return $this;
    }
}
