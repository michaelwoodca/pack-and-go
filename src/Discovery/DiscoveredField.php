<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Discovery;

defined('ABSPATH') || exit;

final class DiscoveredField
{
    /**
     * @param string                $metaKey
     * @param string                $label
     * @param FieldType             $type
     * @param string                $source
     * @param array<string, string> $choices
     * @param bool                  $repeats
     */
    public function __construct(
        public readonly string $metaKey,
        public readonly string $label,
        public readonly FieldType $type,
        public readonly string $source,
        public readonly array $choices = array(),
        public readonly bool $repeats = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array(
            'metaKey' => $this->metaKey,
            'label' => $this->label,
            'type' => $this->type->value,
            'isMedia' => $this->type->isMedia(),
            'source' => $this->source,
            'choices' => $this->choices,
            'repeats' => $this->repeats,
        );
    }
}
