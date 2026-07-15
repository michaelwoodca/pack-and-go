<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Discovery;

defined('ABSPATH') || exit;

final class DiscoveredPostType
{
    /**
     * @param string                       $name
     * @param string                       $label
     * @param int                          $publishedCount
     * @param bool                         $builtIn
     * @param string                       $fieldSource
     * @param array<int, DiscoveredField>  $fields
     * @param array<int, array{name: string, label: string}> $taxonomies
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly int $publishedCount,
        public readonly bool $builtIn,
        public readonly string $fieldSource,
        public readonly array $fields,
        public readonly array $taxonomies,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array(
            'name' => $this->name,
            'label' => $this->label,
            'publishedCount' => $this->publishedCount,
            'builtIn' => $this->builtIn,
            'fieldSource' => $this->fieldSource,
            'fields' => array_map(static fn (DiscoveredField $f): array => $f->toArray(), $this->fields),
            'taxonomies' => $this->taxonomies,
        );
    }
}
