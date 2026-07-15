<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Sync;

defined('ABSPATH') || exit;

use NoTrouble\PackAndGo\Content\ContentCleaner;
use NoTrouble\PackAndGo\Content\MediaResolver;

final class PostBuilder
{
    private const MAX_CONTENT = 50000;

    public function __construct(
        private readonly ContentCleaner $cleaner,
        private readonly MediaResolver $media,
    ) {}

    /**
     * @param array<string, mixed> $mapping The saved mapping for this post type.
     * @param array<string, string> $fieldTypes NoTrouble field key => inputType, used to coerce custom properties.
     * @return array{attributes: array<string, mixed>, media: array<int, array{slot: string, url: string, alt: string}>, tags: array<int, string>}
     */
    public function build(int $postId, array $mapping, array $fieldTypes = array()): array
    {
        $post = get_post($postId);
        $map = is_array($mapping['map'] ?? null) ? $mapping['map'] : array();

        $attributes = array('isPublished' => false);
        $customProps = array();
        $media = array();

        foreach ($map as $target => $wpField) {
            if (! is_string($target) || ! is_string($wpField) || $wpField === '') {
                continue;
            }

            if (str_starts_with($target, 'media:')) {
                $slot = substr($target, 6);

                // The post_image slot backs the gallery collection, which holds many images;
                // fan a gallery/repeater field out into one attach per image. Other slots
                // (cover, after_image, map) are single.
                if ($slot === 'post_image') {
                    foreach ($this->media->resolveMany($postId, $wpField) as $image) {
                        $media[] = array('slot' => $slot, 'url' => $image['url'], 'alt' => $image['alt']);
                    }
                } else {
                    $resolved = $this->media->resolve($postId, $wpField);
                    if ($resolved !== null) {
                        $media[] = array('slot' => $slot, 'url' => $resolved['url'], 'alt' => $resolved['alt']);
                    }
                }

                continue;
            }

            $value = $this->rawValue($postId, $wpField, $post);

            if ($target === 'name') {
                $attributes['name'] = $value;
            } elseif ($target === 'preview') {
                $attributes['preview'] = $value;
            } elseif ($target === 'content') {
                $attributes['content'] = mb_substr($this->cleaner->clean($value), 0, self::MAX_CONTENT);
            } elseif ($target === 'link_url') {
                $attributes['primaryLinkUrl'] = $value;
            } elseif ($target === 'link_label') {
                $attributes['primaryLinkLabel'] = $value;
            } elseif (str_starts_with($target, 'cp:')) {
                $key = substr($target, 3);
                $inputType = is_string($fieldTypes[$key] ?? null) ? $fieldTypes[$key] : 'text';

                if ($inputType === 'address') {
                    $address = $this->resolveAddress($postId, $wpField);
                    if ($address !== null) {
                        $customProps[$key] = $address;
                    }

                    continue;
                }

                $coerced = $this->coerceCustomProperty($value, $inputType);
                if ($coerced !== null) {
                    $customProps[$key] = $coerced;
                }
            }
        }

        $contentFields = is_array($mapping['contentFields'] ?? null) ? $mapping['contentFields'] : array();
        $contentParts = array();
        foreach ($contentFields as $entry) {
            $part = $this->contentPartFor($entry, $postId, $post);
            if ($part !== '') {
                $contentParts[] = $part;
            }
        }
        if ($contentParts !== array()) {
            $attributes['content'] = mb_substr(implode("\n\n", $contentParts), 0, self::MAX_CONTENT);
        }

        $otherLinks = $this->buildOtherLinks($postId, $mapping, $post);
        if ($otherLinks !== array()) {
            $attributes['otherLinks'] = $otherLinks;
        }

        if ($customProps !== array()) {
            $attributes['customProperties'] = $customProps;
        }

        if (! isset($attributes['name']) || $attributes['name'] === '') {
            $title = $post !== null ? (string) $post->post_title : '';
            $attributes['name'] = $title !== '' ? $title : __('Untitled', 'pack-and-go');
        }

        return array(
            'attributes' => $attributes,
            'media' => $media,
            'tags' => $this->tagTerms($postId, $mapping),
        );
    }

    private function rawValue(int $postId, string $wpField, ?\WP_Post $post): string
    {
        switch ($wpField) {
            case '_title':
                return $post !== null ? (string) $post->post_title : '';
            case '_content':
                return $post !== null ? (string) $post->post_content : '';
            case '_excerpt':
                return $post !== null ? (string) $post->post_excerpt : '';
            default:
                $raw = get_post_meta($postId, $wpField, true);

                return is_scalar($raw) ? (string) $raw : '';
        }
    }

    /**
     * Coerce a raw WordPress meta string into the shape NoTrouble stores for the target field's
     * input type. The v1 REST API persists custom_properties verbatim (no server-side transform),
     * so the client must send the final stored shape: currency in integer cents, datetimes as UTC
     * ISO-8601, booleans as real booleans.
     *
     * @return int|float|bool|string|null Null drops the property (empty or uncoercible).
     */
    private function coerceCustomProperty(string $raw, string $inputType): int|float|bool|string|null
    {
        if (trim($raw) === '') {
            return null;
        }

        switch ($inputType) {
            case 'currency':
                $numeric = preg_replace('/[^0-9.\-]/', '', $raw) ?? '';

                return is_numeric($numeric) ? (int) round((float) $numeric * 100) : null;
            case 'boolean':
                return in_array(strtolower(trim($raw)), array('1', 'true', 'yes', 'on'), true);
            case 'date':
            case 'datetime':
                return $this->toUtcIso($raw);
            case 'number':
                if (! is_numeric($raw)) {
                    return null;
                }

                return str_contains($raw, '.') ? (float) $raw : (int) $raw;
            default:
                return $raw;
        }
    }

    /**
     * Build one body block. A bare string (or a {kind:'field'} entry) reads a WordPress field; a
     * {kind:'heading'|'subheading'|'text'} entry inserts literal, admin-entered content, so a user
     * can put a heading ("Product Specs") above a field's value to make the imported body read well.
     *
     * @param mixed $entry
     */
    private function contentPartFor(mixed $entry, int $postId, ?\WP_Post $post): string
    {
        if (is_string($entry)) {
            return $this->fieldContentPart($entry, $postId, $post);
        }

        if (! is_array($entry)) {
            return '';
        }

        $kind = is_string($entry['kind'] ?? null) ? $entry['kind'] : 'field';
        $value = is_string($entry['value'] ?? null) ? trim($entry['value']) : '';

        if ($value === '') {
            return '';
        }

        return match ($kind) {
            'heading' => '<h2>' . esc_html($value) . '</h2>',
            'subheading' => '<h3>' . esc_html($value) . '</h3>',
            'text' => $this->cleaner->clean($value),
            default => $this->fieldContentPart($value, $postId, $post),
        };
    }

    private function fieldContentPart(string $wpField, int $postId, ?\WP_Post $post): string
    {
        if ($wpField === '') {
            return '';
        }

        $clean = $this->cleaner->clean($this->rawValue($postId, $wpField, $post));

        return trim($clean) !== '' ? $clean : '';
    }

    /**
     * Build the post's secondary links from the saved link mappings. Each entry pairs a literal
     * label with a WordPress field that holds a URL. NoTrouble requires a label per link, so rows
     * missing a label or a resolvable URL are skipped.
     *
     * @param array<string, mixed> $mapping
     * @return array<int, array{label: string, url: string}>
     */
    private function buildOtherLinks(int $postId, array $mapping, ?\WP_Post $post): array
    {
        $linkFields = is_array($mapping['linkFields'] ?? null) ? $mapping['linkFields'] : array();
        $links = array();

        foreach ($linkFields as $link) {
            if (! is_array($link)) {
                continue;
            }

            $label = is_string($link['label'] ?? null) ? trim($link['label']) : '';
            $urlField = is_string($link['urlField'] ?? null) ? $link['urlField'] : '';
            if ($label === '' || $urlField === '') {
                continue;
            }

            $url = trim($this->rawValue($postId, $urlField, $post));
            if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
                continue;
            }

            $links[] = array('label' => $label, 'url' => $url);
        }

        return $links;
    }

    /**
     * Build NoTrouble's structured address shape from a WordPress address field. Handles a plain
     * text field (the whole value becomes line 1) and an ACF-map style array (mapping its known
     * component keys). Returns null when there's nothing usable.
     *
     * @return array<string, string>|null
     */
    private function resolveAddress(int $postId, string $wpField): ?array
    {
        $raw = get_post_meta($postId, $wpField, true);

        if (is_string($raw)) {
            $raw = trim($raw);

            return $raw !== '' ? array('addressLine1' => $raw) : null;
        }

        if (! is_array($raw)) {
            return null;
        }

        $candidates = array(
            'addressLine1' => array('address', 'street_address', 'street_name', 'addressLine1', 'line1'),
            'locality' => array('city', 'locality', 'town'),
            'administrativeArea' => array('state', 'province', 'region', 'administrativeArea'),
            'postalCode' => array('post_code', 'postal_code', 'zip', 'postcode', 'postalCode'),
            'countryCode' => array('country_short', 'country_code', 'countryCode'),
        );

        $out = array();
        foreach ($candidates as $ntKey => $keys) {
            foreach ($keys as $wpKey) {
                if (isset($raw[$wpKey]) && is_string($raw[$wpKey]) && trim($raw[$wpKey]) !== '') {
                    $out[$ntKey] = trim($raw[$wpKey]);

                    break;
                }
            }
        }

        return $out !== array() ? $out : null;
    }

    /**
     * Normalise a WordPress date/datetime value to a UTC ISO-8601 string. A naive wall-clock string
     * is read in the site timezone; a bare Unix timestamp is treated as UTC. Unparseable input is
     * passed through so NoTrouble's tolerant date parser gets a chance.
     */
    private function toUtcIso(string $raw): string
    {
        $raw = trim($raw);

        if (ctype_digit($raw) && strlen($raw) >= 10) {
            return gmdate('Y-m-d\TH:i:s\Z', (int) $raw);
        }

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');

        try {
            $dt = new \DateTimeImmutable($raw, $timezone);

            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception $e) {
            return $raw;
        }
    }

    /**
     * @param array<string, mixed> $mapping
     * @return array<int, string>
     */
    private function tagTerms(int $postId, array $mapping): array
    {
        $taxonomies = is_array($mapping['taxonomies'] ?? null) ? $mapping['taxonomies'] : array();
        $names = array();

        foreach ($taxonomies as $taxonomy => $mode) {
            if ($mode !== 'tags' || ! is_string($taxonomy)) {
                continue;
            }

            $terms = get_the_terms($postId, $taxonomy);
            if (! is_array($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                if (is_object($term) && isset($term->name) && is_string($term->name)) {
                    $names[$term->name] = $term->name;
                }
            }
        }

        return array_values($names);
    }
}
