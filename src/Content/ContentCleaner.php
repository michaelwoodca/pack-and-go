<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Content;

defined('ABSPATH') || exit;

final class ContentCleaner
{
    /**
     * @return array<string, array<string, bool>>
     */
    private function allowedTags(): array
    {
        return array(
            'p' => array(),
            'br' => array(),
            'strong' => array(), 'b' => array(),
            'em' => array(), 'i' => array(),
            'u' => array(),
            'a' => array('href' => true, 'title' => true),
            'ul' => array(), 'ol' => array(), 'li' => array(),
            'h2' => array(), 'h3' => array(), 'h4' => array(),
            'blockquote' => array(),
            'img' => array('src' => true, 'alt' => true),
        );
    }

    public function clean(string $raw): string
    {
        if (trim($raw) === '') {
            return '';
        }

        $html = $raw;

        if (function_exists('has_blocks') && has_blocks($html) && function_exists('do_blocks')) {
            $html = do_blocks($html);
        }

        $html = $this->rescueDiviImages($html);

        $html = $this->unwrapShortcodes($html);

        if (function_exists('wpautop')) {
            $html = wpautop($html);
        }

        $html = wp_kses($html, $this->allowedTags());

        return $this->tidy($html);
    }

    public function cleanToText(string $raw): string
    {
        return trim(wp_strip_all_tags($this->clean($raw)));
    }

    private function rescueDiviImages(string $html): string
    {
        return (string) preg_replace_callback(
            '/\[et_pb(?:_fullwidth)?_image\b[^\]]*?\bsrc=(["\'])(.*?)\1[^\]]*\]/i',
            static function (array $m): string {
                $src = esc_url_raw(html_entity_decode($m[2]));

                return $src !== '' ? '<img src="' . $src . '" alt="" />' : '';
            },
            $html,
        );
    }

    private function unwrapShortcodes(string $html): string
    {
        $pattern = '/\[\/?[a-zA-Z0-9_]+(?:[^\]]*)?\]/';

        for ($i = 0; $i < 6; $i++) {
            $next = preg_replace($pattern, '', $html);
            if ($next === null || $next === $html) {
                $html = $next ?? $html;
                break;
            }
            $html = $next;
        }

        return $html;
    }

    private function tidy(string $html): string
    {
        $html = (string) preg_replace('#<p>(?:\s|&nbsp;|<br\s*/?>)*</p>#i', '', $html);
        $html = (string) preg_replace('/\n{2,}/', "\n", $html);
        $html = (string) preg_replace('/[ \t]{2,}/', ' ', $html);

        return trim($html);
    }
}
