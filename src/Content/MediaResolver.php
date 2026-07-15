<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Content;

defined('ABSPATH') || exit;

final class MediaResolver
{
    /**
     * @return array{url: string, alt: string}|null
     */
    public function resolve(int $postId, string $wpFieldKey): ?array
    {
        if ($wpFieldKey === '_featured_image') {
            $attachmentId = (int) get_post_thumbnail_id($postId);

            return $attachmentId > 0 ? $this->fromAttachment($attachmentId) : null;
        }

        $raw = get_post_meta($postId, $wpFieldKey, true);

        return $this->fromValue($raw);
    }

    /**
     * @param mixed $raw
     * @return array{url: string, alt: string}|null
     */
    private function fromValue(mixed $raw): ?array
    {
        if (is_array($raw)) {
            $url = is_string($raw['url'] ?? null) ? $raw['url'] : '';
            $alt = is_string($raw['alt'] ?? null) ? $raw['alt'] : '';
            if ($alt === '' && is_numeric($raw['ID'] ?? null)) {
                $alt = $this->altFor((int) $raw['ID']);
            }

            return $url !== '' ? array('url' => $url, 'alt' => $alt) : null;
        }

        if (is_numeric($raw)) {
            return $this->fromAttachment((int) $raw);
        }

        if (is_string($raw) && preg_match('#^https?://#i', $raw) === 1) {
            $attachmentId = attachment_url_to_postid($raw);

            return array('url' => $raw, 'alt' => $attachmentId > 0 ? $this->altFor($attachmentId) : '');
        }

        return null;
    }

    /**
     * @return array{url: string, alt: string}|null
     */
    private function fromAttachment(int $attachmentId): ?array
    {
        $url = wp_get_attachment_url($attachmentId);

        if (! is_string($url) || $url === '') {
            return null;
        }

        return array('url' => $url, 'alt' => $this->altFor($attachmentId));
    }

    private function altFor(int $attachmentId): string
    {
        $alt = get_post_meta($attachmentId, '_wp_attachment_image_alt', true);

        return is_string($alt) ? trim($alt) : '';
    }
}
