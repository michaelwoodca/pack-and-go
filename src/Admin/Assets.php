<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Admin;

defined('ABSPATH') || exit;

use const NoTrouble\PackAndGo\PLUGIN_URL;
use const NoTrouble\PackAndGo\VERSION;

final class Assets
{
    public const HANDLE = 'pack-and-go';

    public function register(): void
    {
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
    }

    public static function isPluginScreen(string $hook): bool
    {
        return str_contains($hook, 'pack-and-go');
    }

    public function enqueue(string $hook): void
    {
        if (! self::isPluginScreen($hook)) {
            return;
        }

        wp_enqueue_style(
            self::HANDLE,
            PLUGIN_URL . 'assets/css/pack-and-go.css',
            array('dashicons'),
            VERSION,
        );

        wp_enqueue_script(
            self::HANDLE,
            PLUGIN_URL . 'assets/js/pack-and-go.js',
            array(),
            VERSION,
            true,
        );
    }

    public static function menuIcon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="47 18 56 55" fill="#a7aaad">'
            . '<path fill-rule="evenodd" d="M72.253 24.066c13.009 0 23.57 10.561 23.57 23.57s-10.561 '
            . '23.57-23.57 23.57-23.57-10.561-23.57-23.57 10.561-23.57 23.57-23.57zm0 3.5c-11.077 '
            . '0-20.07 8.993-20.07 20.07s8.993 20.07 20.07 20.07 20.07-8.993 20.07-20.07-8.993-20.07-20.07-20.07z"/>'
            . '<path fill-rule="nonzero" d="M61.353 40.126c2.29 2.58 10.44 13.96 10.44 13.96s6.49-13 '
            . '11.33-19.73c6.38-8.87 7.84-10.98 11.42-14.47a.52.52 0 0 1 .68.01c1.07 1.02 4.91 4.74 5.92 '
            . '6.12.17.23 0 .54-.21.73-2.69 2.47-4.25 3.4-9.72 9.93-8.23 9.8-18.24 27.47-19.09 28.97-.08.15-.29.14-.38 '
            . '0-1.75-2.48-12.93-16.97-16.28-20.95-.23.13-1.37.9-1.74.21-.3-.56.45-1.09.75-1.26l6.4-3.65c.16-.09.37-.01.49.12l-.01.01z"/>'
            . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public static function logoUrl(): string
    {
        return PLUGIN_URL . 'assets/img/notrouble-logo.png';
    }

    /**
     * @return array<string, string>
     */
    public static function i18n(): array
    {
        return array(
            'preparing' => __('Preparing…', 'pack-and-go'),
            'importing' => __('Moving your content…', 'pack-and-go'),
            'movedOf' => __('Moved %1 of %2…', 'pack-and-go'),
            'movingSet' => __('Moving %1 — set %2 of %3…', 'pack-and-go'),
            'nothingToMove' => __('Everything was already up to date — nothing new to move.', 'pack-and-go'),
            'stillWorking' => __('Still working — large items and images can take a moment…', 'pack-and-go'),
            'complete' => __('All moved!', 'pack-and-go'),
            'doneWithIssues' => __('Done — a few items need a look', 'pack-and-go'),
            'canceled' => __('Import canceled', 'pack-and-go'),
            'canceledTail' => __('Anything already moved is safe in NoTrouble.', 'pack-and-go'),
            'stopped' => __('Import stopped', 'pack-and-go'),
            'draftsTail' => __("They're waiting as drafts in NoTrouble — review and publish when you're ready.", 'pack-and-go'),
            'itemMoved' => __('%1 item moved', 'pack-and-go'),
            'itemsMoved' => __('%1 items moved', 'pack-and-go'),
            'skipped' => __('%1 already up to date', 'pack-and-go'),
            'failed' => __('%1 need attention', 'pack-and-go'),
            'someNeedAttention' => __('Some items need attention:', 'pack-and-go'),
            'tryAgain' => __('Try again', 'pack-and-go'),
            'pushAgain' => __('Push again', 'pack-and-go'),
            'cancel' => __('Cancel', 'pack-and-go'),
            'canceling' => __('Canceling…', 'pack-and-go'),
            'interrupted' => __('The connection was interrupted. Please try again.', 'pack-and-go'),
            'couldNotStart' => __('We could not start the import. Please try again.', 'pack-and-go'),
            'couldNotComplete' => __('The import could not be completed.', 'pack-and-go'),
            'noImage' => __('(no image)', 'pack-and-go'),
        );
    }
}
