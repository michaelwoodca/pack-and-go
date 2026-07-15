<?php

/**
 * @package NoTrouble\PackAndGo
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

class WP_Post
{
    public string $post_title = '';

    public string $post_content = '';

    public string $post_excerpt = '';
}

/** @var array<int, WP_Post> */
$GLOBALS['wp_posts'] = array();

/** @var array<int, array<string, string>> */
$GLOBALS['wp_meta'] = array();

function get_post(int $id): ?WP_Post
{
    return $GLOBALS['wp_posts'][$id] ?? null;
}

function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
{
    return $GLOBALS['wp_meta'][$postId][$key] ?? '';
}

function esc_url_raw(string $url): string
{
    return $url;
}

/**
 * @param array<string, mixed> $allowed
 */
function wp_kses(string $html, array $allowed): string
{
    return $html;
}

function wpautop(string $text): string
{
    $blocks = preg_split('/\n{2,}/', trim($text)) ?: array();
    $out = '';
    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block !== '') {
            $out .= '<p>' . $block . '</p>' . "\n";
        }
    }

    return $out;
}

$src = dirname(__DIR__) . '/src';
require $src . '/Content/ContentCleaner.php';
require $src . '/Content/MediaResolver.php';
require $src . '/Sync/PostBuilder.php';

use NoTrouble\PackAndGo\Content\ContentCleaner;
use NoTrouble\PackAndGo\Content\MediaResolver;
use NoTrouble\PackAndGo\Sync\PostBuilder;

$failures = 0;
function check(string $label, bool $pass): void
{
    global $failures;
    echo '  ' . ($pass ? 'ok  ' : 'FAIL') . ' ' . $label . "\n";
    if (! $pass) {
        $failures++;
    }
}

$post = new WP_Post();
$post->post_title = 'Riverside Rebrand';
$GLOBALS['wp_posts'][42] = $post;
$GLOBALS['wp_meta'][42] = array(
    'wpcf-challenge' => 'The brand felt dated.',
    'wpcf-strategy' => 'We rebuilt the identity.',
    'wpcf-endorsement' => 'Best work we have seen.',
    'wpcf-empty' => '   ',
);

$builder = new PostBuilder(new ContentCleaner(), new MediaResolver());

echo "PostBuilder merges multiple WordPress fields into the body:\n";

$built = $builder->build(42, array(
    'contentFields' => array('wpcf-challenge', 'wpcf-strategy', 'wpcf-endorsement'),
));
$content = is_string($built['attributes']['content'] ?? null) ? $built['attributes']['content'] : '';

check('includes challenge text', str_contains($content, 'The brand felt dated.'));
check('includes strategy text', str_contains($content, 'We rebuilt the identity.'));
check('includes endorsement text', str_contains($content, 'Best work we have seen.'));

$posChallenge = strpos($content, 'brand felt dated');
$posStrategy = strpos($content, 'rebuilt the identity');
$posEndorsement = strpos($content, 'Best work');
check('keeps field order', $posChallenge !== false && $posStrategy !== false && $posEndorsement !== false
    && $posChallenge < $posStrategy && $posStrategy < $posEndorsement);

check('fields are separated by a blank line (not one line)', str_contains($content, "\n\n"));
check('each field is its own paragraph', substr_count($content, '<p>') === 3);

echo "\nEmpty and missing fields are skipped:\n";

$built2 = $builder->build(42, array(
    'contentFields' => array('wpcf-challenge', 'wpcf-empty', 'wpcf-missing', 'wpcf-strategy'),
));
$content2 = is_string($built2['attributes']['content'] ?? null) ? $built2['attributes']['content'] : '';
check('drops blank field, keeps two real ones', substr_count($content2, '<p>') === 2);
check('no leading or trailing blank join', trim($content2) === $content2 && ! str_contains($content2, "\n\n\n"));

echo "\nNo content fields means no body attribute:\n";
$built3 = $builder->build(42, array('contentFields' => array()));
check('content attribute is absent', ! isset($built3['attributes']['content']));
check('name still falls back to the post title', ($built3['attributes']['name'] ?? '') === 'Riverside Rebrand');

echo "\n" . ($failures === 0 ? 'PASS — all assertions green' : "FAIL — {$failures} assertion(s) failed") . "\n";
exit($failures === 0 ? 0 : 1);
