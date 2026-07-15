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

$GLOBALS['wp_options'] = array();

function get_option(string $key, mixed $default = false): mixed
{
    return $GLOBALS['wp_options'][$key] ?? $default;
}

function update_option(string $key, mixed $value, bool $autoload = true): bool
{
    $GLOBALS['wp_options'][$key] = $value;

    return true;
}

function wp_json_encode(mixed $value): string
{
    return json_encode($value);
}

require dirname(__DIR__) . '/src/Sync/SyncLedger.php';

use NoTrouble\PackAndGo\Sync\SyncLedger;

$failures = 0;
$assert = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . PHP_EOL;
    if (! $ok) {
        $failures++;
    }
};

echo "SyncLedger:" . PHP_EOL;

$profile = 'prof_abc';
$type = 'project';

$ledger = new SyncLedger($profile);
$assert($ledger->sectionId($type) === '', 'no section id before first push');
$assert($ledger->syncedCountForType($type) === 0, 'synced count starts at zero');
$assert($ledger->statusFor($type, 101, 1000) === 'new', 'unknown post is "new"');

$ledger->setSectionId($type, 'sec_1');
$hash = SyncLedger::hash(array('attributes' => array('name' => 'Alpha')));
$ledger->record($type, 101, 'nt_101', $hash, 5000);
$ledger->persist();

$reloaded = new SyncLedger($profile);
$assert($reloaded->sectionId($type) === 'sec_1', 'section id survives persist/reload');
$entry = $reloaded->entry($type, 101);
$assert($entry !== null && $entry['nt_post_id'] === 'nt_101', 'entry records NoTrouble post id');
$assert($reloaded->isSynced($type, 101), 'recorded post is synced');
$assert($reloaded->syncedCountForType($type) === 1, 'synced count reflects one item');
$assert($reloaded->lastSyncedAt($type) === 5000, 'last synced timestamp tracked');

$assert($reloaded->statusFor($type, 101, 4999) === 'synced', 'not modified since sync -> synced');
$assert($reloaded->statusFor($type, 101, 6000) === 'changed', 'modified after sync -> changed');

$assert(! $reloaded->hasChanged($type, 101, $hash), 'same hash -> not changed');
$assert($reloaded->hasChanged($type, 101, 'deadbeef'), 'different hash -> changed');

$ledger->record($type, 202, 'nt_202', $hash, 5000, 'mediahash_abc');
$ledger->persist();
$reloadedMedia = new SyncLedger($profile);
$assert(($reloadedMedia->entry($type, 202)['media_hash'] ?? null) === 'mediahash_abc', 'media_hash round-trips through record/entry');
$assert(($reloadedMedia->entry($type, 101)['media_hash'] ?? null) === '', 'media_hash defaults to empty when the arg is omitted');

$stats = $reloaded->statsForType($type, array(101 => 4000, 102 => 9000));
$assert($stats['synced'] === 1 && $stats['new'] === 1 && $stats['changed'] === 0 && $stats['total'] === 2, 'statsForType buckets new/synced correctly');

$reloaded->forgetType($type);
$assert($reloaded->sectionId($type) === '' && $reloaded->syncedCountForType($type) === 0, 'forgetType clears section and items');

$other = new SyncLedger('prof_other');
$assert($other->syncedCountForType($type) === 0, 'a different profile shares nothing');

$wipe = new SyncLedger($profile);
$wipe->setSectionId('page', 'sec_page');
$wipe->record('page', 5, 'nt_5', 'h', 7000);
$wipe->record($type, 9, 'nt_9', 'h', 7000);
$wipe->persist();
$wipe->forgetAll();
$wipe->persist();
$after = new SyncLedger($profile);
$assert($after->syncedCountForType('page') === 0 && $after->sectionId('page') === '', 'forgetAll clears all sections and items');

echo PHP_EOL . ($failures === 0 ? "PASS — all assertions green" : "FAIL — {$failures} assertion(s) failed") . PHP_EOL;

exit($failures === 0 ? 0 : 1);
