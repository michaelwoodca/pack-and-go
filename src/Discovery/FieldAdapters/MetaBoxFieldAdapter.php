<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Discovery\FieldAdapters;

defined('ABSPATH') || exit;

use NoTrouble\PackAndGo\Discovery\DiscoveredField;
use NoTrouble\PackAndGo\Discovery\FieldType;

final class MetaBoxFieldAdapter implements FieldAdapter
{
    public function source(): string
    {
        return 'metabox';
    }

    public function isActive(): bool
    {
        return function_exists('rwmb_get_registry') && function_exists('rwmb_meta');
    }

    public function fieldsFor(string $postType): array
    {
        if (! $this->isActive()) {
            return array();
        }

        $registry = rwmb_get_registry('meta_box');

        if (! is_object($registry) || ! method_exists($registry, 'all')) {
            return array();
        }

        $fields = array();

        foreach ($registry->all() as $metaBox) {
            $config = $this->metaBoxConfig($metaBox);

            if (! $this->appliesToPostType($config, $postType)) {
                continue;
            }

            $mbFields = $config['fields'] ?? array();

            if (! is_array($mbFields)) {
                continue;
            }

            foreach ($mbFields as $mbField) {
                if (is_array($mbField) && is_string($mbField['id'] ?? null) && $mbField['id'] !== '') {
                    $fields[] = $this->mapField($mbField);
                }
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function metaBoxConfig(mixed $metaBox): array
    {
        if (is_object($metaBox) && property_exists($metaBox, 'meta_box') && is_array($metaBox->meta_box)) {
            return $metaBox->meta_box;
        }

        if (is_array($metaBox)) {
            return $metaBox;
        }

        return array();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function appliesToPostType(array $config, string $postType): bool
    {
        $postTypes = $config['post_types'] ?? ($config['post_type'] ?? array());

        if (is_string($postTypes)) {
            $postTypes = array($postTypes);
        }

        return is_array($postTypes) && in_array($postType, $postTypes, true);
    }

    /**
     * @param array<string, mixed> $mbField
     */
    private function mapField(array $mbField): DiscoveredField
    {
        $metaKey = (string) $mbField['id'];
        $label = is_string($mbField['name'] ?? null) && $mbField['name'] !== '' ? $mbField['name'] : $metaKey;
        $mbType = is_string($mbField['type'] ?? null) ? $mbField['type'] : '';
        $clone = (bool) ($mbField['clone'] ?? false);

        return new DiscoveredField(
            metaKey: $metaKey,
            label: $label,
            type: $this->normalizeType($mbType),
            source: $this->source(),
            choices: $this->extractChoices($mbField),
            repeats: $clone || in_array($mbType, array('image_advanced', 'file_advanced', 'group'), true),
        );
    }

    /**
     * @param array<string, mixed> $mbField
     * @return array<string, string>
     */
    private function extractChoices(array $mbField): array
    {
        $options = $mbField['options'] ?? null;

        if (! is_array($options)) {
            return array();
        }

        $choices = array();

        foreach ($options as $value => $label) {
            $choices[(string) $value] = is_scalar($label) ? (string) $label : (string) $value;
        }

        return $choices;
    }

    private function normalizeType(string $mbType): FieldType
    {
        return match ($mbType) {
            'textarea' => FieldType::Textarea,
            'wysiwyg' => FieldType::RichText,
            'number', 'range', 'slider' => FieldType::Number,
            'email' => FieldType::Email,
            'url', 'oembed' => FieldType::Url,
            'checkbox', 'switch' => FieldType::Boolean,
            'select', 'select_advanced', 'radio', 'checkbox_list', 'button_group' => FieldType::Select,
            'date', 'datetime', 'time' => FieldType::Date,
            'single_image', 'image', 'image_upload', 'image_advanced', 'image_select' => FieldType::Image,
            'file', 'file_upload', 'file_advanced' => FieldType::File,
            'video' => FieldType::Video,
            'color' => FieldType::Color,
            'text', 'password', 'tel' => FieldType::Text,
            default => FieldType::Unknown,
        };
    }
}
