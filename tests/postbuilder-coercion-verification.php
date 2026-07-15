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

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone('UTC');
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
$post->post_title = 'Sunny 3-bed craftsman';
$GLOBALS['wp_posts'][7] = $post;
$GLOBALS['wp_meta'][7] = array(
    'wp-price' => '19.99',
    'wp-price-formatted' => '$1,250.00',
    'wp-start' => '2026-08-15 18:30:00',
    'wp-flag-yes' => 'yes',
    'wp-flag-no' => '0',
    'wp-beds' => '3',
    'wp-area' => '1450.5',
    'wp-brand' => 'Acme',
);

$mapping = array('map' => array(
    'cp:price' => 'wp-price',
    'cp:ticket_price' => 'wp-price-formatted',
    'cp:started_at' => 'wp-start',
    'cp:has_location' => 'wp-flag-yes',
    'cp:show_currency' => 'wp-flag-no',
    'cp:bedrooms' => 'wp-beds',
    'cp:area' => 'wp-area',
    'cp:brand' => 'wp-brand',
));

$fieldTypes = array(
    'price' => 'currency',
    'ticket_price' => 'currency',
    'started_at' => 'datetime',
    'has_location' => 'boolean',
    'show_currency' => 'boolean',
    'bedrooms' => 'number',
    'area' => 'number',
    'brand' => 'text',
);

$builder = new PostBuilder(new ContentCleaner(), new MediaResolver());
$built = $builder->build(7, $mapping, $fieldTypes);
$cp = is_array($built['attributes']['customProperties'] ?? null) ? $built['attributes']['customProperties'] : array();

echo "Currency coerces to integer cents (G1):\n";
check('"19.99" -> 1999 (int)', ($cp['price'] ?? null) === 1999);
check('"$1,250.00" -> 125000 (int)', ($cp['ticket_price'] ?? null) === 125000);

echo "\nDatetime coerces to UTC ISO-8601 (G4):\n";
check('"2026-08-15 18:30:00" (UTC site tz) -> "2026-08-15T18:30:00Z"', ($cp['started_at'] ?? null) === '2026-08-15T18:30:00Z');

echo "\nBoolean coerces to a real bool (G7):\n";
check('"yes" -> true (strict bool)', ($cp['has_location'] ?? null) === true);
check('"0" -> false (strict bool)', ($cp['show_currency'] ?? null) === false);

echo "\nNumber coerces to int/float:\n";
check('"3" -> 3 (int)', ($cp['bedrooms'] ?? null) === 3);
check('"1450.5" -> 1450.5 (float)', ($cp['area'] ?? null) === 1450.5);

echo "\nText passes through unchanged:\n";
check('"Acme" -> "Acme" (string)', ($cp['brand'] ?? null) === 'Acme');

echo "\nEmpty/uncoercible values are dropped:\n";
$GLOBALS['wp_meta'][7]['wp-empty-price'] = '   ';
$built2 = $builder->build(7, array('map' => array('cp:price' => 'wp-empty-price')), array('price' => 'currency'));
$cp2 = is_array($built2['attributes']['customProperties'] ?? null) ? $built2['attributes']['customProperties'] : array();
check('blank currency omitted, not sent as 0', ! array_key_exists('price', $cp2));

echo "\nAddress builds NoTrouble's structured shape (G3):\n";
$GLOBALS['wp_meta'][7]['wp-addr-text'] = '123 Main St, Austin, TX 78701';
$builtAddrText = $builder->build(7, array('map' => array('cp:address' => 'wp-addr-text')), array('address' => 'address'));
$addrText = $builtAddrText['attributes']['customProperties']['address'] ?? null;
check('a plain string becomes addressLine1', is_array($addrText) && ($addrText['addressLine1'] ?? null) === '123 Main St, Austin, TX 78701');

$GLOBALS['wp_meta'][8] = array('wp-addr-map' => array(
    'address' => '500 Congress Ave',
    'city' => 'Austin',
    'state' => 'TX',
    'post_code' => '78701',
    'country_short' => 'US',
));
$postB = new WP_Post();
$postB->post_title = 'Venue';
$GLOBALS['wp_posts'][8] = $postB;
$builtAddrMap = $builder->build(8, array('map' => array('cp:address' => 'wp-addr-map')), array('address' => 'address'));
$addrMap = $builtAddrMap['attributes']['customProperties']['address'] ?? null;
check('an ACF-map array maps to camelCase AddressData keys', is_array($addrMap)
    && ($addrMap['addressLine1'] ?? null) === '500 Congress Ave'
    && ($addrMap['locality'] ?? null) === 'Austin'
    && ($addrMap['administrativeArea'] ?? null) === 'TX'
    && ($addrMap['postalCode'] ?? null) === '78701'
    && ($addrMap['countryCode'] ?? null) === 'US');

echo "\nSecondary links become otherLinks (G5):\n";
$GLOBALS['wp_meta'][7]['wp-site'] = 'https://example.com';
$GLOBALS['wp_meta'][7]['wp-portfolio'] = 'https://portfolio.example';
$GLOBALS['wp_meta'][7]['wp-noturl'] = 'not a link';
$builtLinks = $builder->build(7, array('linkFields' => array(
    array('label' => 'Website', 'urlField' => 'wp-site'),
    array('label' => 'Portfolio', 'urlField' => 'wp-portfolio'),
    array('label' => '', 'urlField' => 'wp-site'),
    array('label' => 'Broken', 'urlField' => 'wp-noturl'),
)), array());
$links = $builtLinks['attributes']['otherLinks'] ?? null;
check('two valid labelled links are built', is_array($links) && count($links) === 2);
check('first link pairs label with url', is_array($links) && ($links[0] ?? null) === array('label' => 'Website', 'url' => 'https://example.com'));
check('a link missing a label is skipped', is_array($links) && ! in_array('', array_column($links, 'label'), true));
check('a non-URL value is skipped', is_array($links) && ! in_array('not a link', array_column($links, 'url'), true));

echo "\n" . ($failures === 0 ? 'PASS — all assertions green' : "FAIL — {$failures} assertion(s) failed") . "\n";
exit($failures === 0 ? 0 : 1);
