<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Preview;

defined('ABSPATH') || exit;

use NoTrouble\PackAndGo\Content\ContentCleaner;

final class PostSampler
{
    private ContentCleaner $cleaner;

    public function __construct(?ContentCleaner $cleaner = null)
    {
        $this->cleaner = $cleaner ?? new ContentCleaner();
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    public function samplePosts(string $wpType, int $limit = 25): array
    {
        $posts = get_posts(array(
            'post_type' => $wpType,
            'post_status' => 'publish',
            'numberposts' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'suppress_filters' => true,
        ));

        if (! is_array($posts)) {
            return array();
        }

        $out = array();
        foreach ($posts as $post) {
            $title = $post->post_title !== '' ? $post->post_title : sprintf(/* translators: %d: post id */ __('(untitled #%d)', 'pack-and-go'), (int) $post->ID);
            $out[] = array('id' => (int) $post->ID, 'title' => $title);
        }

        return $out;
    }

    /**
     * @param array<int, array{key: string, label: string, type: string, isMedia: bool}> $wpItems
     * @return array<string, string> wpItemKey => display value
     */
    public function values(int $postId, array $wpItems): array
    {
        $post = get_post($postId);

        if ($post === null) {
            return array();
        }

        $values = array();

        foreach ($wpItems as $item) {
            $values[$item['key']] = $this->valueFor($item, $post);
        }

        return $values;
    }

    /**
     * @param array{key: string, label: string, type: string, isMedia: bool} $item
     */
    private function valueFor(array $item, \WP_Post $post): string
    {
        switch ($item['key']) {
            case '_title':
                return (string) $post->post_title;
            case '_content':
                return $this->truncate($this->cleaner->cleanToText((string) $post->post_content));
            case '_excerpt':
                return (string) $post->post_excerpt;
            case '_featured_image':
                $url = get_the_post_thumbnail_url($post->ID, 'thumbnail');

                return is_string($url) ? $url : '';
            default:
                $raw = get_post_meta($post->ID, $item['key'], true);

                if ($item['isMedia']) {
                    return $this->imageUrl($raw);
                }

                return $this->stringify($raw);
        }
    }

    private function imageUrl(mixed $raw): string
    {
        if (is_array($raw)) {
            $thumb = $raw['sizes']['thumbnail'] ?? ($raw['url'] ?? '');

            return is_string($thumb) ? $thumb : '';
        }

        if (is_numeric($raw)) {
            $url = wp_get_attachment_image_url((int) $raw, 'thumbnail');

            return is_string($url) ? $url : '';
        }

        if (is_string($raw) && preg_match('#^https?://#i', $raw) === 1) {
            return $raw;
        }

        return '';
    }

    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $this->truncate($value);
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return $this->truncate(wp_json_encode($value) ?: '');
        }

        return '';
    }

    private function truncate(string $value, int $length = 220): string
    {
        $value = trim($value);

        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length) . '…';
    }
}
