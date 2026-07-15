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
     * @return array{attributes: array<string, mixed>, media: array<int, array{slot: string, url: string, alt: string}>, tags: array<int, string>}
     */
    public function build(int $postId, array $mapping): array
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
                $resolved = $this->media->resolve($postId, $wpField);
                if ($resolved !== null) {
                    $media[] = array('slot' => substr($target, 6), 'url' => $resolved['url'], 'alt' => $resolved['alt']);
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
                $customProps[substr($target, 3)] = $value;
            }
        }

        $contentFields = is_array($mapping['contentFields'] ?? null) ? $mapping['contentFields'] : array();
        $contentParts = array();
        foreach ($contentFields as $wpField) {
            if (! is_string($wpField) || $wpField === '') {
                continue;
            }

            $clean = $this->cleaner->clean($this->rawValue($postId, $wpField, $post));
            if (trim($clean) !== '') {
                $contentParts[] = $clean;
            }
        }
        if ($contentParts !== array()) {
            $attributes['content'] = mb_substr(implode("\n\n", $contentParts), 0, self::MAX_CONTENT);
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
