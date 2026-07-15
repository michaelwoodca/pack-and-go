<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Discovery\FieldAdapters;

defined('ABSPATH') || exit;

use NoTrouble\PackAndGo\Discovery\DiscoveredField;

interface FieldAdapter
{
    public function source(): string;

    public function isActive(): bool;

    /**
     * @return array<int, DiscoveredField>
     */
    public function fieldsFor(string $postType): array;
}
