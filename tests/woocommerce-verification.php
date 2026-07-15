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
require $src . '/Discovery/DiscoveredField.php';
require $src . '/Discovery/FieldAdapters/FieldAdapter.php';
require $src . '/Discovery/FieldAdapters/WooCommerceFieldAdapter.php';

use NoTrouble\PackAndGo\Discovery\FieldAdapters\WooCommerceFieldAdapter;

$failures = 0;
function check(string $label, bool $pass): void
{
    global $failures;
    echo '  ' . ($pass ? 'ok  ' : 'FAIL') . ' ' . $label . "\n";
    if (! $pass) {
        $failures++;
    }
}

/**
 * @param array<int, \NoTrouble\PackAndGo\Discovery\DiscoveredField> $fields
 * @return array<int, string>
 */
function metaKeys(array $fields): array
{
    return array_map(static fn ($f): string => $f->metaKey, $fields);
}

$adapter = new WooCommerceFieldAdapter();

echo "Activation + scoping:\n";
check('inactive without WooCommerce present', $adapter->isActive() === false);

define('WC_VERSION', '9.0');
check('active once WC_VERSION is defined', $adapter->isActive() === true);
check('no fields for a non-product type', $adapter->fieldsFor('post') === array());

echo "\nCore price fields on the product type (no Subscriptions):\n";
$core = metaKeys($adapter->fieldsFor('product'));
check('exposes _regular_price', in_array('_regular_price', $core, true));
check('exposes _sale_price', in_array('_sale_price', $core, true));
check('exposes _price', in_array('_price', $core, true));
check('exposes _sku', in_array('_sku', $core, true));
check('does NOT expose subscription fields yet', ! in_array('_subscription_price', $core, true));

echo "\nSubscription fields appear when WooCommerce Subscriptions is active:\n";
eval('class WC_Subscriptions {}'); // eval avoids PHP hoisting so the no-subs check above is valid
$withSubs = metaKeys($adapter->fieldsFor('product'));
check('exposes _subscription_price', in_array('_subscription_price', $withSubs, true));
check('exposes _subscription_period', in_array('_subscription_period', $withSubs, true));
check('exposes _subscription_period_interval', in_array('_subscription_period_interval', $withSubs, true));

$period = null;
foreach ($adapter->fieldsFor('product') as $f) {
    if ($f->metaKey === '_subscription_period') {
        $period = $f;
    }
}
check('billing period carries day/week/month/year choices', $period !== null && $period->choices === array(
    'day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year',
));

echo "\n" . ($failures === 0 ? 'PASS — all assertions green' : "FAIL — {$failures} assertion(s) failed") . "\n";
exit($failures === 0 ? 0 : 1);
