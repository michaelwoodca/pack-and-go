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

$src = dirname(__DIR__) . '/src';
require $src . '/Discovery/FieldType.php';
require $src . '/Mapping/TargetCatalog.php';
require $src . '/Mapping/MappingSuggester.php';

use NoTrouble\PackAndGo\Mapping\MappingSuggester;
use NoTrouble\PackAndGo\Mapping\TargetCatalog;

$failures = 0;
function check(string $label, bool $pass): void
{
    global $failures;
    echo '  ' . ($pass ? 'ok  ' : 'FAIL') . ' ' . $label . "\n";
    if (! $pass) {
        $failures++;
    }
}

// NoTrouble Video post type: a video-upload slot, a URL fallback, and a thumbnail.
$section = array('fields' => array(
    array('key' => 'video', 'inputType' => 'video', 'uploadCollection' => 'video-source', 'label' => 'Video Upload'),
    array('key' => 'video_url', 'inputType' => 'url', 'uploadCollection' => 'video_url', 'label' => 'Video URL'),
    array('key' => 'image', 'inputType' => 'image', 'uploadCollection' => 'image', 'label' => 'Thumbnail'),
));
$targets = (new TargetCatalog())->forSectionType($section);

// Helium-shaped WordPress fields: the real videos are Toolset URL fields holding .mp4 links.
$wpItems = array(
    array('key' => '_featured_image', 'label' => 'Featured image', 'type' => 'image', 'isMedia' => true),
    array('key' => 'wpcf-project-video-url-720p', 'label' => 'Video Url 720p', 'type' => 'url', 'isMedia' => false),
    array('key' => 'wpcf-project-video-url-1080p', 'label' => 'Video Url 1080p', 'type' => 'url', 'isMedia' => false),
    array('key' => 'wpcf-project-video-url-captions', 'label' => 'Video Url Captions', 'type' => 'url', 'isMedia' => false),
);

$map = (new MappingSuggester())->suggestTargetMap($targets, $wpItems);

echo "Auto-suggest routes a direct video-file URL to the upload slot (not the URL/link field):\n";
check('post_video gets a video-url field', ($map['media:post_video'] ?? null) === 'wpcf-project-video-url-720p');
check('the captions track is not chosen as the video', ($map['media:post_video'] ?? null) !== 'wpcf-project-video-url-captions');
check('the featured image is not chosen as the video', ($map['media:post_video'] ?? null) !== '_featured_image');
check('the thumbnail still maps to the image slot', ($map['media:post_image'] ?? null) === '_featured_image');

echo "\n" . ($failures === 0 ? 'PASS — all assertions green' : "FAIL — {$failures} assertion(s) failed") . "\n";
exit($failures === 0 ? 0 : 1);
