<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Discovery;

defined('ABSPATH') || exit;

use NoTrouble\PackAndGo\Discovery\FieldAdapters\AcfFieldAdapter;
use NoTrouble\PackAndGo\Discovery\FieldAdapters\FieldAdapter;
use NoTrouble\PackAndGo\Discovery\FieldAdapters\MetaBoxFieldAdapter;
use NoTrouble\PackAndGo\Discovery\FieldAdapters\RawMetaFieldAdapter;
use NoTrouble\PackAndGo\Discovery\FieldAdapters\ToolsetFieldAdapter;
use NoTrouble\PackAndGo\Discovery\FieldAdapters\WooCommerceFieldAdapter;

final class PostTypeDiscovery
{
    /**
     * @var array<int, string>
     */
    private const EXCLUDED_TYPES = array(
        'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
        'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part',
        'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face',
        'wp-types-group', 'wp-types-user-group', 'acf-field', 'acf-field-group',
        'acf-post-type', 'acf-taxonomy', 'meta-box', 'mb-post-type', 'mb-taxonomy',
        'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_coupon',
        'shop_subscription', 'product_variation',
    );

    /**
     * @var array<int, string>
     */
    private const EXCLUDED_TAXONOMIES = array(
        'post_format', 'nav_menu', 'link_category', 'wp_theme', 'wp_template_part_area',
        'product_type', 'product_visibility', 'product_shipping_class',
        'post_translations', 'term_translations', 'translation_priority',
        'author', 'language',
    );

    /**
     * @var array<int, FieldAdapter>
     */
    private array $frameworkAdapters;

    private FieldAdapter $fallbackAdapter;

    /**
     * @param array<int, FieldAdapter>|null $frameworkAdapters
     * @param FieldAdapter|null             $fallbackAdapter
     */
    public function __construct(?array $frameworkAdapters = null, ?FieldAdapter $fallbackAdapter = null)
    {
        $this->frameworkAdapters = $frameworkAdapters ?? array(
            new AcfFieldAdapter(),
            new ToolsetFieldAdapter(),
            new MetaBoxFieldAdapter(),
            new WooCommerceFieldAdapter(),
        );

        $this->fallbackAdapter = $fallbackAdapter ?? new RawMetaFieldAdapter();
    }

    /**
     * @return array<int, DiscoveredPostType>
     */
    public function all(): array
    {
        $types = get_post_types(array('show_ui' => true), 'objects');

        if (! is_array($types)) {
            return array();
        }

        $discovered = array();

        foreach ($types as $postType) {
            if (! is_object($postType) || ! isset($postType->name) || in_array($postType->name, self::EXCLUDED_TYPES, true)) {
                continue;
            }

            $discovered[] = $this->describe($postType);
        }

        return $discovered;
    }

    private function describe(object $postType): DiscoveredPostType
    {
        $name = (string) $postType->name;

        [$fields, $source] = $this->resolveFields($name);

        return new DiscoveredPostType(
            name: $name,
            label: $this->typeLabel($postType, $name),
            publishedCount: $this->publishedCount($name),
            builtIn: (bool) ($postType->_builtin ?? false),
            fieldSource: $source,
            fields: $fields,
            taxonomies: $this->taxonomies($name),
        );
    }

    /**
     * Merge fields from every active framework adapter (deduping by meta key), so a post type
     * managed by more than one plugin — e.g. a WooCommerce product that also has Toolset fields —
     * surfaces all of them. Only when no framework adapter matches does the raw-meta fallback run.
     *
     * @return array{0: array<int, DiscoveredField>, 1: string}
     */
    private function resolveFields(string $postType): array
    {
        $fields = array();
        $sources = array();
        $seen = array();

        foreach ($this->frameworkAdapters as $adapter) {
            if (! $adapter->isActive()) {
                continue;
            }

            foreach ($adapter->fieldsFor($postType) as $field) {
                if (isset($seen[$field->metaKey])) {
                    continue;
                }

                $seen[$field->metaKey] = true;
                $fields[] = $field;
                $sources[$adapter->source()] = true;
            }
        }

        if ($fields === array()) {
            $fallback = $this->fallbackAdapter->fieldsFor($postType);

            return array($fallback, $fallback === array() ? 'none' : $this->fallbackAdapter->source());
        }

        return array($fields, implode('+', array_keys($sources)));
    }

    private function typeLabel(object $postType, string $fallback): string
    {
        if (isset($postType->labels) && is_object($postType->labels) && isset($postType->labels->name) && is_string($postType->labels->name)) {
            return $postType->labels->name;
        }

        return $fallback;
    }

    private function publishedCount(string $postType): int
    {
        $counts = wp_count_posts($postType);

        return is_object($counts) && isset($counts->publish) ? (int) $counts->publish : 0;
    }

    /**
     * @return array<int, array{name: string, label: string}>
     */
    private function taxonomies(string $postType): array
    {
        $taxonomies = get_object_taxonomies($postType, 'objects');

        if (! is_array($taxonomies)) {
            return array();
        }

        $result = array();

        foreach ($taxonomies as $taxonomy) {
            if (! is_object($taxonomy) || empty($taxonomy->public)) {
                continue;
            }

            if (in_array((string) $taxonomy->name, self::EXCLUDED_TAXONOMIES, true)) {
                continue;
            }

            $label = isset($taxonomy->labels->name) && is_string($taxonomy->labels->name)
                ? $taxonomy->labels->name
                : (string) $taxonomy->name;

            $result[] = array('name' => (string) $taxonomy->name, 'label' => $label);
        }

        return $result;
    }
}
