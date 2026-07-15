<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Admin;

defined('ABSPATH') || exit;

final class View
{
    public const HELP_URL = 'https://notrouble.com/help';

    /**
     * @return array<string, string>
     */
    private static function steps(): array
    {
        return array(
            'connect' => __('Connect', 'pack-and-go'),
            'profile' => __('Choose profile', 'pack-and-go'),
            'configure' => __('Select & map', 'pack-and-go'),
            'push' => __('Push', 'pack-and-go'),
        );
    }

    public static function header(string $helpSlug = ''): void
    {
        printf(
            '<div class="pag-header"><span class="pag-header__logo"><img src="%1$s" alt="%2$s" /></span>'
            . '<span class="pag-header__tag">%3$s</span><span class="pag-header__spacer"></span>'
            . '<a class="pag-header__help" href="%4$s" target="_blank" rel="noopener noreferrer">'
            . '<span class="dashicons dashicons-editor-help"></span>%5$s</a></div>',
            esc_url(Assets::logoUrl()),
            esc_attr__('NoTrouble', 'pack-and-go'),
            esc_html__('Move your WordPress content to NoTrouble', 'pack-and-go'),
            esc_url(self::helpUrl($helpSlug)),
            esc_html__('Help', 'pack-and-go'),
        );
    }

    /**
     * @param array<int, string> $done
     */
    public static function stepper(string $active, array $done = array()): void
    {
        echo '<ol class="pag-stepper">';

        $steps = self::steps();
        $index = 0;
        $last = count($steps) - 1;
        foreach ($steps as $key => $label) {
            $isActive = $key === $active;
            $isDone = in_array($key, $done, true) && ! $isActive;

            $classes = 'pag-stepper__step';
            if ($isActive) {
                $classes .= ' is-active';
            } elseif ($isDone) {
                $classes .= ' is-done';
            }

            printf('<li class="%s">', esc_attr($classes));
            if ($isDone) {
                echo '<span class="pag-stepper__num"><span class="dashicons dashicons-yes" style="font-size:14px;width:14px;height:14px;"></span></span>';
            } else {
                printf('<span class="pag-stepper__num">%d</span>', $index + 1);
            }
            echo '<span class="pag-stepper__text">' . esc_html($label) . '</span>';
            echo '</li>';

            if ($index < $last) {
                echo '<li class="pag-stepper__sep" aria-hidden="true"><span class="dashicons dashicons-arrow-right-alt2"></span></li>';
            }
            $index++;
        }

        echo '</ol>';
    }

    public static function chip(string $status, string $label = ''): string
    {
        $map = array(
            'synced' => array('dashicons-yes-alt', __('Synced', 'pack-and-go')),
            'changed' => array('dashicons-update', __('Changed', 'pack-and-go')),
            'new' => array('dashicons-plus-alt2', __('Not moved yet', 'pack-and-go')),
            'ready' => array('dashicons-arrow-right-alt', __('Ready to push', 'pack-and-go')),
            'error' => array('dashicons-warning', __('Needs attention', 'pack-and-go')),
            'notset' => array('dashicons-minus', __('Not set up', 'pack-and-go')),
        );

        [$icon, $default] = $map[$status] ?? array('dashicons-marker', $status);
        $text = $label !== '' ? $label : $default;

        return sprintf(
            '<span class="pag-chip pag-chip--%1$s"><span class="dashicons %2$s"></span>%3$s</span>',
            esc_attr($status),
            esc_attr($icon),
            esc_html($text),
        );
    }

    public static function helpLink(string $slug, string $text): string
    {
        return sprintf(
            '<a href="%1$s" target="_blank" rel="noopener noreferrer" class="pag-help-inline">%2$s <span class="dashicons dashicons-external" style="font-size:13px;width:13px;height:13px;vertical-align:text-bottom;"></span></a>',
            esc_url(self::helpUrl($slug)),
            esc_html($text),
        );
    }

    public static function helpUrl(string $slug = ''): string
    {
        return $slug === '' ? self::HELP_URL : self::HELP_URL . '/' . ltrim($slug, '/');
    }

    public static function progressPanel(): void
    {
        ?>
<div id="pag-progress" class="pag-progress" style="display:none;" role="status" aria-live="polite">
    <h2 class="pag-progress__title">
        <span class="dashicons dashicons-update pag-spin" aria-hidden="true"></span>
        <span id="pag-title"><?php echo esc_html__('Moving your content…', 'pack-and-go'); ?></span>
    </h2>
    <div class="pag-progress__track" id="pag-track">
        <div class="pag-progress__bar" id="pag-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?php echo esc_attr__('Import progress', 'pack-and-go'); ?>"></div>
    </div>
    <p class="pag-progress__status" id="pag-status"></p>
    <div class="pag-progress__errors" id="pag-errors"></div>
    <div class="pag-progress__actions">
        <button type="button" class="button button-link-delete" id="pag-cancel" style="display:none;"><?php echo esc_html__('Cancel', 'pack-and-go'); ?></button>
    </div>
</div>
        <?php
    }
}
