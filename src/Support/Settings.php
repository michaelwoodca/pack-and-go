<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Support;

defined('ABSPATH') || exit;

final class Settings
{
    private const OPTION = 'pack_and_go_settings';

    private const DEFAULT_BATCH_SIZE = 5;

    private const MIN_BATCH_SIZE = 1;

    private const MAX_BATCH_SIZE = 50;

    public function batchSize(): int
    {
        $stored = $this->all()['batch_size'] ?? self::DEFAULT_BATCH_SIZE;
        $value = is_numeric($stored) ? (int) $stored : self::DEFAULT_BATCH_SIZE;

        return max(self::MIN_BATCH_SIZE, min(self::MAX_BATCH_SIZE, $value));
    }

    public function setBatchSize(int $value): void
    {
        $value = max(self::MIN_BATCH_SIZE, min(self::MAX_BATCH_SIZE, $value));
        $this->merge(array('batch_size' => $value));
    }

    public function minBatchSize(): int
    {
        return self::MIN_BATCH_SIZE;
    }

    public function maxBatchSize(): int
    {
        return self::MAX_BATCH_SIZE;
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        $stored = get_option(self::OPTION, array());

        return is_array($stored) ? $stored : array();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function merge(array $values): void
    {
        update_option(self::OPTION, array_merge($this->all(), $values), false);
    }
}
