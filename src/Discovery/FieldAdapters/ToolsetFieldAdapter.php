<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Discovery\FieldAdapters;

defined('ABSPATH') || exit;

use NoTrouble\PackAndGo\Discovery\DiscoveredField;
use NoTrouble\PackAndGo\Discovery\FieldType;

final class ToolsetFieldAdapter implements FieldAdapter
{
    public function source(): string
    {
        return 'toolset';
    }

    public function isActive(): bool
    {
        return defined('WPCF_VERSION')
            || defined('TYPES_VERSION')
            || function_exists('wpcf_admin_fields_get_fields')
            || is_array(get_option('wpcf-fields', false) ?: null);
    }

    public function fieldsFor(string $postType): array
    {
        $definitions = get_option('wpcf-fields');

        if (! is_array($definitions) || $definitions === array()) {
            return array();
        }

        $slugs = $this->fieldSlugsForPostType($postType);

        if ($slugs === array()) {
            return array();
        }

        $fields = array();

        foreach ($slugs as $slug) {
            $definition = $definitions[$slug] ?? null;

            if (! is_array($definition)) {
                continue;
            }

            $fields[] = $this->mapField($slug, $definition);
        }

        return $fields;
    }

    /**
     * @return array<int, string>
     */
    private function fieldSlugsForPostType(string $postType): array
    {
        $groups = get_posts(array(
            'post_type' => 'wp-types-group',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
        ));

        if (! is_array($groups)) {
            return array();
        }

        $slugs = array();

        foreach ($groups as $groupId) {
            $groupId = (int) $groupId;

            $assignedTypes = $this->splitCsvMeta((string) get_post_meta($groupId, '_wp_types_group_post_types', true));

            $appliesToAll = in_array('all', $assignedTypes, true);

            if (! $appliesToAll && ! in_array($postType, $assignedTypes, true)) {
                continue;
            }

            foreach ($this->splitCsvMeta((string) get_post_meta($groupId, '_wp_types_group_fields', true)) as $slug) {
                $slugs[$slug] = $slug;
            }
        }

        return array_values($slugs);
    }

    /**
     * @return array<int, string>
     */
    private function splitCsvMeta(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function mapField(string $slug, array $definition): DiscoveredField
    {
        $label = is_string($definition['name'] ?? null) && $definition['name'] !== '' ? $definition['name'] : $slug;
        $toolsetType = is_string($definition['type'] ?? null) ? $definition['type'] : '';

        // Values are stored at `wpcf-{slug}`, but honour an explicit meta_key if Types provides one.
        $metaKey = is_string($definition['meta_key'] ?? null) && $definition['meta_key'] !== ''
            ? $definition['meta_key']
            : 'wpcf-' . $slug;

        return new DiscoveredField(
            metaKey: $metaKey,
            label: $label,
            type: $this->normalizeType($toolsetType),
            source: $this->source(),
            choices: $this->extractChoices($definition),
            repeats: (bool) ($definition['data']['repetitive'] ?? false),
        );
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, string>
     */
    private function extractChoices(array $definition): array
    {
        $options = $definition['data']['options'] ?? null;

        if (! is_array($options)) {
            return array();
        }

        $choices = array();

        foreach ($options as $key => $option) {
            if ($key === 'default') {
                continue;
            }

            if (is_array($option)) {
                $value = is_scalar($option['value'] ?? null) ? (string) $option['value'] : (string) $key;
                $title = is_scalar($option['title'] ?? null) ? (string) $option['title'] : $value;
                $choices[$value] = $title;
            }
        }

        return $choices;
    }

    private function normalizeType(string $toolsetType): FieldType
    {
        return match ($toolsetType) {
            'textfield', 'phone', 'skype' => FieldType::Text,
            'textarea' => FieldType::Textarea,
            'wysiwyg' => FieldType::RichText,
            'email' => FieldType::Email,
            'url' => FieldType::Url,
            'numeric' => FieldType::Number,
            'date' => FieldType::Date,
            'checkbox' => FieldType::Boolean,
            'checkboxes', 'radio', 'select' => FieldType::Select,
            'image' => FieldType::Image,
            'file', 'audio' => FieldType::File,
            'video', 'embed' => FieldType::Video,
            'colorpicker' => FieldType::Color,
            default => FieldType::Unknown,
        };
    }
}
