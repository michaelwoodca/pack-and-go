<?php
/**
 * Plugin Name:       Pack & Go – Easy Site Migration
 * Plugin URI:        https://notrouble.com/pack-and-go
 * Description:        Pack up your WordPress posts, portfolio, and custom content and move it into your NoTrouble profile — no trouble.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            NoTrouble.com
 * Author URI:        https://notrouble.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pack-and-go
 * Domain Path:       /languages
 *
 * @package NoTrouble\PackAndGo
 */

declare(strict_types=1);

namespace NoTrouble\PackAndGo;

defined('ABSPATH') || exit;

const VERSION = '0.1.0';

define('NoTrouble\PackAndGo\PLUGIN_DIR', plugin_dir_path(__FILE__));

define('NoTrouble\PackAndGo\PLUGIN_URL', plugin_dir_url(__FILE__));

define('NoTrouble\PackAndGo\PLUGIN_FILE', __FILE__);

/**
 * @return array<int, string>
 */
function requirement_errors(): array
{
    $errors = array();

    if (version_compare(PHP_VERSION, '8.1', '<')) {
        $errors[] = sprintf(
            /* translators: %s: the site's current PHP version */
            __('Pack & Go needs PHP 8.1 or newer, but this site is running PHP %s. Ask your host to update PHP.', 'pack-and-go'),
            PHP_VERSION
        );
    }

    foreach (array('json', 'mbstring', 'openssl') as $extension) {
        if (! extension_loaded($extension)) {
            $errors[] = sprintf(
                /* translators: %s: PHP extension name */
                __('Pack & Go needs the PHP “%s” extension, which isn’t enabled on this site. Ask your host to enable it.', 'pack-and-go'),
                $extension
            );
        }
    }

    if (! is_readable(PLUGIN_DIR . 'src/Plugin.php')) {
        $errors[] = __('Some Pack & Go files are missing or can’t be read. Re-upload the plugin in full, and make sure its folders are set to 755 and files to 644.', 'pack-and-go');
    }

    return $errors;
}

register_activation_hook(__FILE__, static function (): void {
    $errors = requirement_errors();
    if ($errors === array()) {
        return;
    }

    deactivate_plugins(plugin_basename(__FILE__));

    $message = '<h1>' . esc_html__('Pack & Go couldn’t be activated', 'pack-and-go') . '</h1>'
        . '<p>' . esc_html__('Please fix the following, then try activating again:', 'pack-and-go') . '</p><ul>';
    foreach ($errors as $error) {
        $message .= '<li>' . esc_html($error) . '</li>';
    }
    $message .= '</ul>';

    wp_die($message, esc_html__('Plugin activation error', 'pack-and-go'), array('back_link' => true));
});

$packAndGoRequirementErrors = requirement_errors();
if ($packAndGoRequirementErrors !== array()) {
    add_action('admin_notices', static function () use ($packAndGoRequirementErrors): void {
        echo '<div class="notice notice-error"><p><strong>' . esc_html__('Pack & Go can’t run:', 'pack-and-go') . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
        foreach ($packAndGoRequirementErrors as $error) {
            echo '<li>' . esc_html($error) . '</li>';
        }
        echo '</ul></div>';
    });

    return;
}

if (is_file(PLUGIN_DIR . 'vendor/autoload.php')) {
    require PLUGIN_DIR . 'vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = __NAMESPACE__ . '\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($path)) {
            require $path;
        }
    });
}

if (class_exists(\YahnisElsts\PluginUpdateChecker\v5\PucFactory::class)) {
    $packAndGoUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/michaelwoodca/pack-and-go/',
        PLUGIN_FILE,
        'pack-and-go'
    );
    $packAndGoUpdateChecker->setBranch('main');
    $packAndGoUpdateChecker->getVcsApi()->enableReleaseAssets();
}

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});
