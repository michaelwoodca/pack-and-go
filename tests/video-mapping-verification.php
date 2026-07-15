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

function attachment_url_to_postid(string $url): int
{
    return 0;
}

$src = dirname(__DIR__) . '/src';
require $src . '/Content/ContentCleaner.php';
require $src . '/Content/MediaResolver.php';
require $src . '/Sync/PostBuilder.php';
require $src . '/Mapping/TargetCatalog.php';

use NoTrouble\PackAndGo\Content\ContentCleaner;
use NoTrouble\PackAndGo\Content\MediaResolver;
use NoTrouble\PackAndGo\Mapping\TargetCatalog;
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

echo "TargetCatalog exposes a post_video media target for a NoTrouble video field:\n";

$section = array('fields' => array(
    array('key' => 'video', 'inputType' => 'video', 'uploadCollection' => 'video-source', 'label' => 'Video Upload'),
    array('key' => 'image', 'inputType' => 'image', 'uploadCollection' => 'image', 'label' => 'Thumbnail Image'),
    array('key' => 'video_url', 'inputType' => 'url', 'uploadCollection' => 'video_url', 'label' => 'Video URL'),
));

$targets = (new TargetCatalog())->forSectionType($section);
$byValue = array();
foreach ($targets as $t) {
    $byValue[$t['value']] = $t;
}

check('a media:post_video target exists', isset($byValue['media:post_video']));
check('post_video is labelled as Video, not Image', isset($byValue['media:post_video']) && str_starts_with($byValue['media:post_video']['label'], 'Video:'));
check('the image field still maps to media:post_image', isset($byValue['media:post_image']));
check('video_url (a url field) is a custom property, not a media slot', isset($byValue['cp:video_url']) && ! isset($byValue['media:post_video_url']));

echo "\nPostBuilder resolves a direct .mp4 URL into the post_video slot:\n";

$post = new WP_Post();
$post->post_title = 'Teaser';
$GLOBALS['wp_posts'][11] = $post;
$mp4 = 'https://helium-mediaserver.s3.ca-central-1.amazonaws.com/video/portfolio/jwo1911a_-_teaser-1080p.mp4';
$GLOBALS['wp_meta'][11] = array('wp-video' => $mp4);

$built = (new PostBuilder(new ContentCleaner(), new MediaResolver()))
    ->build(11, array('map' => array('media:post_video' => 'wp-video')));

$media = is_array($built['media'] ?? null) ? $built['media'] : array();
check('one media item is queued', count($media) === 1);
check('it targets the post_video slot', ($media[0]['slot'] ?? null) === 'post_video');
check('it carries the .mp4 URL for server-side fetch + HLS', ($media[0]['url'] ?? null) === $mp4);

echo "\nTargetCatalog skips internal-id fields and carries select options (G6):\n";

$g6 = (new TargetCatalog())->forSectionType(array('fields' => array(
    array('key' => 'form_id', 'inputType' => 'select', 'uploadCollection' => 'form_id', 'label' => 'Form', 'options' => array()),
    array('key' => 'list_id', 'inputType' => 'text', 'uploadCollection' => 'list_id', 'label' => 'Mailing List ID'),
    array('key' => 'subtype', 'inputType' => 'select', 'uploadCollection' => 'subtype', 'label' => 'Product type', 'options' => array(
        'generic' => 'General product', 'book' => 'Book', 'course' => 'Course',
    )),
)));
$g6ByValue = array();
foreach ($g6 as $t) {
    $g6ByValue[$t['value']] = $t;
}

check('form_id is not offered as a target', ! isset($g6ByValue['cp:form_id']));
check('list_id is not offered as a target', ! isset($g6ByValue['cp:list_id']));
check('a select field is still offered', isset($g6ByValue['cp:subtype']));
check('the select target carries its allowed option values', isset($g6ByValue['cp:subtype'])
    && ($g6ByValue['cp:subtype']['options'] ?? null) === array('generic', 'book', 'course'));

echo "\nTargets carry the required flag for the unmapped-required guardrail (G-UX):\n";

$req = (new TargetCatalog())->forSectionType(array('fields' => array(
    array('key' => 'image', 'inputType' => 'image', 'uploadCollection' => 'image', 'label' => 'Before Image', 'required' => true),
    array('key' => 'star_rating', 'inputType' => 'number', 'uploadCollection' => 'star_rating', 'label' => 'Rating', 'required' => false),
    array('key' => 'content', 'inputType' => 'rich_text', 'uploadCollection' => 'content', 'label' => 'Body', 'required' => true),
)));
$reqByValue = array();
foreach ($req as $t) {
    $reqByValue[$t['value']] = $t;
}

check('a required media field is flagged required', ($reqByValue['media:post_image']['required'] ?? null) === true);
check('an optional field is not flagged required', ($reqByValue['cp:star_rating']['required'] ?? null) === false);
check('a required core content field is flagged required', ($reqByValue['content']['required'] ?? null) === true);
check('the title target is never required (auto-falls back)', ($reqByValue['name']['required'] ?? null) === false);

echo "\nA gallery field fans out into multiple post_image attaches (G8):\n";

$galleryPost = new WP_Post();
$galleryPost->post_title = 'Portfolio';
$GLOBALS['wp_posts'][21] = $galleryPost;
$GLOBALS['wp_meta'][21] = array(
    'wp-gallery' => array('https://cdn.example/a.jpg', 'https://cdn.example/b.jpg', 'https://cdn.example/c.jpg'),
    'wp-single' => 'https://cdn.example/solo.jpg',
);

$builtGallery = (new PostBuilder(new ContentCleaner(), new MediaResolver()))
    ->build(21, array('map' => array('media:post_image' => 'wp-gallery')));
$galleryMedia = is_array($builtGallery['media'] ?? null) ? $builtGallery['media'] : array();
check('three images are queued from the gallery field', count($galleryMedia) === 3);
check('every one targets the post_image slot', array_values(array_unique(array_column($galleryMedia, 'slot'))) === array('post_image'));
check('image order is preserved', ($galleryMedia[0]['url'] ?? null) === 'https://cdn.example/a.jpg' && ($galleryMedia[2]['url'] ?? null) === 'https://cdn.example/c.jpg');

$builtSingle = (new PostBuilder(new ContentCleaner(), new MediaResolver()))
    ->build(21, array('map' => array('media:post_image' => 'wp-single')));
$singleMedia = is_array($builtSingle['media'] ?? null) ? $builtSingle['media'] : array();
check('a single-image field still yields exactly one attach', count($singleMedia) === 1);

echo "\n" . ($failures === 0 ? 'PASS — all assertions green' : "FAIL — {$failures} assertion(s) failed") . "\n";
exit($failures === 0 ? 0 : 1);
