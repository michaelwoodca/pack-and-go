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

/** @var array<string, mixed> $GLOBALS_wp_options */
$GLOBALS['wp_options'] = array(
    'wpcf-fields' => array(
        'project-tagline' => array('name' => 'Tagline', 'type' => 'textfield', 'slug' => 'project-tagline'),
        'project-challenge' => array('name' => 'Challenge', 'type' => 'wysiwyg', 'slug' => 'project-challenge'),
        'project-cover-image' => array('name' => 'Cover Image', 'type' => 'image', 'slug' => 'project-cover-image'),
        'project-type' => array(
            'name' => 'Type',
            'type' => 'select',
            'slug' => 'project-type',
            'data' => array('options' => array(
                'option-1' => array('value' => 'video', 'title' => 'Video'),
                'option-2' => array('value' => 'photo', 'title' => 'Photo'),
            )),
        ),
    ),
);

function get_option(string $key, mixed $default = false): mixed
{
    return $GLOBALS['wp_options'][$key] ?? $default;
}

/** @var array<int, array<string, string>> */
$GLOBALS['wp_group_meta'] = array(
    100 => array(
        '_wp_types_group_post_types' => ',project,',
        '_wp_types_group_fields' => ',project-tagline,project-challenge,project-cover-image,project-type,',
    ),
);

function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
{
    return $GLOBALS['wp_group_meta'][$postId][$key] ?? '';
}

/**
 * @param array<string, mixed> $args
 * @return array<int, int>
 */
function get_posts(array $args): array
{
    if (($args['post_type'] ?? '') === 'wp-types-group') {
        return array_keys($GLOBALS['wp_group_meta']);
    }

    return array();
}

$src = dirname(__DIR__) . '/src';
require $src . '/Discovery/FieldType.php';
require $src . '/Discovery/DiscoveredField.php';
require $src . '/Discovery/DiscoveredPostType.php';
require $src . '/Discovery/FieldAdapters/FieldAdapter.php';
require $src . '/Discovery/FieldAdapters/ToolsetFieldAdapter.php';

use NoTrouble\PackAndGo\Discovery\FieldAdapters\ToolsetFieldAdapter;
use NoTrouble\PackAndGo\Discovery\FieldType;

$failures = 0;
$assert = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . PHP_EOL;
    if (! $ok) {
        $failures++;
    }
};

echo "ToolsetFieldAdapter against Helium-shaped `project` data:" . PHP_EOL;

$adapter = new ToolsetFieldAdapter();
$assert($adapter->isActive(), 'isActive() true when wpcf-fields option present');

$fields = $adapter->fieldsFor('project');
$assert(count($fields) === 4, 'discovers 4 fields (got ' . count($fields) . ')');

$byKey = array();
foreach ($fields as $field) {
    $byKey[$field->metaKey] = $field;
}

$assert(isset($byKey['wpcf-project-tagline']), 'meta key prefixed wpcf- (tagline)');
$assert(isset($byKey['wpcf-project-tagline']) && $byKey['wpcf-project-tagline']->type === FieldType::Text, 'textfield -> Text');
$assert(isset($byKey['wpcf-project-challenge']) && $byKey['wpcf-project-challenge']->type === FieldType::RichText, 'wysiwyg -> RichText');
$assert(isset($byKey['wpcf-project-cover-image']) && $byKey['wpcf-project-cover-image']->type === FieldType::Image, 'image -> Image');
$assert(isset($byKey['wpcf-project-cover-image']) && $byKey['wpcf-project-cover-image']->type->isMedia(), 'image field flagged isMedia');

$type = $byKey['wpcf-project-type'] ?? null;
$assert($type !== null && $type->type === FieldType::Select, 'select -> Select');
$assert($type !== null && $type->choices === array('video' => 'Video', 'photo' => 'Photo'), 'select choices extracted');
$assert($type !== null && $type->label === 'Type', 'label read from definition name');

$assert($adapter->fieldsFor('post') === array(), 'unrelated post type yields no toolset fields');

echo PHP_EOL . ($failures === 0 ? "PASS — all assertions green" : "FAIL — {$failures} assertion(s) failed") . PHP_EOL;

exit($failures === 0 ? 0 : 1);
