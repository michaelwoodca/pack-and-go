<?php
/**
 * @package NoTrouble\PackAndGo
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

$options = array(
    'pack_and_go_connection',
    'pack_and_go_mappings',
    'pack_and_go_import_state',
    'pack_and_go_import_ledger',
    'pack_and_go_settings',
);

foreach ($options as $option) {
    delete_option($option);
}
