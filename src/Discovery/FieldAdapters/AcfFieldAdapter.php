<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Discovery\FieldAdapters;

defined('ABSPATH') || exit;

use NoTrouble\PackAndGo\Discovery\DiscoveredField;
use NoTrouble\PackAndGo\Discovery\FieldType;

final class AcfFieldAdapter implements FieldAdapter
{
    public function source(): string
    {
        return 'acf';
    }

    public function isActive(): bool
    {
        return function_exists('acf_get_field_groups') && function_exists('acf_get_fields');
    }

    public function fieldsFor(string $postType): array
    {
        if (! $this->isActive()) {
            return array();
        }

        $groups = acf_get_field_groups(array('post_type' => $postType));

        if (! is_array($groups)) {
            return array();
        }

        $fields = array();

        foreach ($groups as $group) {
            $groupKey = is_array($group) ? ($group['key'] ?? '') : '';

            if (! is_string($groupKey) || $groupKey === '') {
                continue;
            }

            $acfFields = acf_get_fields($groupKey);

            if (! is_array($acfFields)) {
                continue;
            }

            foreach ($acfFields as $acfField) {
                if (is_array($acfField)) {
                    $fields[] = $this->mapField($acfField);
                }
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $acfField
     */
    private function mapField(array $acfField): DiscoveredField
    {
        $metaKey = is_string($acfField['name'] ?? null) ? $acfField['name'] : '';
        $label = is_string($acfField['label'] ?? null) && $acfField['label'] !== ''
            ? $acfField['label']
            : $metaKey;
        $acfType = is_string($acfField['type'] ?? null) ? $acfField['type'] : '';

        $repeats = in_array($acfType, array('repeater', 'flexible_content', 'gallery', 'group'), true);

        return new DiscoveredField(
            metaKey: $metaKey,
            label: $label,
            type: $this->normalizeType($acfType),
            source: $this->source(),
            choices: $this->extractChoices($acfField),
            repeats: $repeats,
        );
    }

    /**
     * @param array<string, mixed> $acfField
     * @return array<string, string>
     */
    private function extractChoices(array $acfField): array
    {
        $choices = $acfField['choices'] ?? null;

        if (! is_array($choices)) {
            return array();
        }

        $normalized = array();

        foreach ($choices as $value => $label) {
            $normalized[(string) $value] = is_scalar($label) ? (string) $label : (string) $value;
        }

        return $normalized;
    }

    private function normalizeType(string $acfType): FieldType
    {
        return match ($acfType) {
            'textarea' => FieldType::Textarea,
            'wysiwyg' => FieldType::RichText,
            'number', 'range' => FieldType::Number,
            'email' => FieldType::Email,
            'url', 'link', 'page_link' => FieldType::Url,
            'true_false' => FieldType::Boolean,
            'select', 'checkbox', 'radio', 'button_group' => FieldType::Select,
            'date_picker', 'date_time_picker', 'time_picker' => FieldType::Date,
            'image' => FieldType::Image,
            'file' => FieldType::File,
            'gallery' => FieldType::Gallery,
            'oembed' => FieldType::Video,
            'color_picker' => FieldType::Color,
            'text', 'password' => FieldType::Text,
            default => FieldType::Unknown,
        };
    }
}
