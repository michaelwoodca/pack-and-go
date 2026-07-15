<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Discovery\FieldAdapters;

defined('ABSPATH') || exit;

use NoTrouble\PackAndGo\Discovery\DiscoveredField;
use NoTrouble\PackAndGo\Discovery\FieldType;

final class RawMetaFieldAdapter implements FieldAdapter
{
    private const SAMPLE_SIZE = 25;

    private const DENYLIST_PREFIXES = array('_', 'wpcf-fields', 'field_');

    public function source(): string
    {
        return 'meta';
    }

    public function isActive(): bool
    {
        return true;
    }

    public function fieldsFor(string $postType): array
    {
        $postIds = get_posts(array(
            'post_type' => $postType,
            'post_status' => 'publish',
            'numberposts' => self::SAMPLE_SIZE,
            'fields' => 'ids',
            'suppress_filters' => true,
        ));

        if (! is_array($postIds) || $postIds === array()) {
            return array();
        }

        $keys = array();

        foreach ($postIds as $postId) {
            $meta = get_post_meta((int) $postId);

            if (! is_array($meta)) {
                continue;
            }

            foreach (array_keys($meta) as $key) {
                $key = (string) $key;

                if ($this->isDenied($key)) {
                    continue;
                }

                $keys[$key] = $key;
            }
        }

        $fields = array();

        foreach ($keys as $key) {
            $fields[] = new DiscoveredField(
                metaKey: $key,
                label: $this->humanize($key),
                type: FieldType::Text,
                source: $this->source(),
            );
        }

        return $fields;
    }

    private function isDenied(string $key): bool
    {
        foreach (self::DENYLIST_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function humanize(string $key): string
    {
        return ucwords(trim(str_replace(array('-', '_'), ' ', $key)));
    }
}
