<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Admin;

defined('ABSPATH') || exit;

use NoTrouble\PackAndGo\Plugin;
use NoTrouble\PackAndGo\Sync\ImportState;
use Throwable;

final class SyncController
{
    private const CAPABILITY = 'manage_options';

    private const NONCE = 'pack_and_go_sync';

    public function __construct(private readonly Plugin $plugin) {}

    public function register(): void
    {
        add_action('wp_ajax_pack_and_go_sync_start', array($this, 'handleStart'));
        add_action('wp_ajax_pack_and_go_sync_batch', array($this, 'handleBatch'));
        add_action('wp_ajax_pack_and_go_sync_cancel', array($this, 'handleCancel'));
    }

    public static function nonceAction(): string
    {
        return self::NONCE;
    }

    public function handleStart(): void
    {
        $wpType = $this->guard();

        if (isset($_POST['all']) && wp_unslash($_POST['all']) === '1') {
            (new ImportState($wpType))->setSelection(null);
        }

        try {
            $progress = $this->plugin->syncRunner()->start($wpType);
            wp_send_json_success($progress);
        } catch (Throwable $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function handleBatch(): void
    {
        $wpType = $this->guard();

        try {
            $progress = $this->plugin->syncRunner()->runBatch($wpType, $this->plugin->settings()->batchSize());
            wp_send_json_success($progress);
        } catch (Throwable $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function handleCancel(): void
    {
        $wpType = $this->guard();

        try {
            wp_send_json_success($this->plugin->syncRunner()->cancel($wpType));
        } catch (Throwable $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    private function guard(): string
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_send_json_error(array('message' => __('You do not have permission to import.', 'pack-and-go')), 403);
        }

        check_ajax_referer(self::NONCE);

        $wpType = isset($_POST['wp_type']) ? sanitize_key(wp_unslash($_POST['wp_type'])) : '';
        if ($wpType === '') {
            wp_send_json_error(array('message' => __('No content type was specified.', 'pack-and-go')));
        }

        return $wpType;
    }
}
