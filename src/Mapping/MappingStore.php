<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Mapping;

defined('ABSPATH') || exit;

final class MappingStore
{
    private const OPTION = 'pack_and_go_mappings';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $stored = get_option(self::OPTION, array());

        return is_array($stored) ? $stored : array();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forType(string $wpType): ?array
    {
        $mapping = $this->all()[$wpType] ?? null;

        return is_array($mapping) ? $mapping : null;
    }

    /**
     * @param array<string, mixed> $mapping
     */
    public function save(string $wpType, array $mapping): void
    {
        $all = $this->all();
        $all[$wpType] = $mapping;
        update_option(self::OPTION, $all, false);
    }

    public function clearAll(): void
    {
        delete_option(self::OPTION);
    }
}
