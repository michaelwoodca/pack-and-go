<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Admin;

defined('ABSPATH') || exit;

use NoTrouble\PackAndGo\Plugin;
use NoTrouble\PackAndGo\Sync\ImportState;
use RuntimeException;
use Throwable;

use const NoTrouble\PackAndGo\VERSION;

final class ConnectPage
{
    private const SLUG = 'pack-and-go';

    private const TROUBLESHOOT_SLUG = 'pack-and-go-troubleshooting';

    private const CAPABILITY = 'manage_options';

    public function __construct(private readonly Plugin $plugin) {}

    public function register(): void
    {
        add_action('admin_menu', array($this, 'addMenu'));
        add_action('admin_init', array($this, 'maybeHandleCallback'));
        add_action('admin_post_pack_and_go_connect', array($this, 'handleConnect'));
        add_action('admin_post_pack_and_go_disconnect', array($this, 'handleDisconnect'));
        add_action('admin_post_pack_and_go_select_profile', array($this, 'handleSelectProfile'));
        add_action('admin_post_pack_and_go_change_profile', array($this, 'handleChangeProfile'));
        add_action('admin_post_pack_and_go_save_settings', array($this, 'handleSaveSettings'));
        add_action('admin_post_pack_and_go_test_connection', array($this, 'handleTestConnection'));
        add_action('admin_post_pack_and_go_clear_progress', array($this, 'handleClearProgress'));
        add_action('admin_post_pack_and_go_reset_sync', array($this, 'handleResetSync'));
        add_action('admin_post_pack_and_go_reset_mappings', array($this, 'handleResetMappings'));
    }

    public function addMenu(): void
    {
        add_menu_page(
            __('Pack & Go', 'pack-and-go'),
            __('Pack & Go', 'pack-and-go'),
            self::CAPABILITY,
            self::SLUG,
            array($this, 'render'),
            Assets::menuIcon(),
            76,
        );

        add_submenu_page(
            self::SLUG,
            __('Troubleshooting & advanced', 'pack-and-go'),
            __('Troubleshooting', 'pack-and-go'),
            self::CAPABILITY,
            self::TROUBLESHOOT_SLUG,
            array($this, 'renderTroubleshootingPage'),
        );
    }

    private function redirectUri(): string
    {
        return admin_url('admin.php?page=' . self::SLUG);
    }

    private function pageUrl(): string
    {
        return $this->redirectUri();
    }

    public function handleConnect(): void
    {
        $this->authorizeRequest('pack_and_go_connect');

        try {
            $connection = $this->plugin->connection();
            $connection->registerClientIfNeeded($this->redirectUri());
            $authorizeUrl = $connection->beginAuthorization($this->redirectUri());
        } catch (Throwable $e) {
            $this->redirectWithNotice('error', $e->getMessage());
        }

        wp_redirect($authorizeUrl);
        exit;
    }

    public function maybeHandleCallback(): void
    {
        if (! is_admin() || ! current_user_can(self::CAPABILITY)) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        if ($page !== self::SLUG || ! isset($_GET['code'], $_GET['state'])) {
            return;
        }

        if (isset($_GET['error'])) {
            $this->redirectWithNotice('error', sanitize_text_field(wp_unslash($_GET['error_description'] ?? $_GET['error'])));
        }

        $code = sanitize_text_field(wp_unslash($_GET['code']));
        $state = sanitize_text_field(wp_unslash($_GET['state']));

        try {
            $this->plugin->connection()->completeAuthorization($code, $state);
        } catch (Throwable $e) {
            $this->redirectWithNotice('error', $e->getMessage());
        }

        $this->redirectWithNotice('success', __('Connected to NoTrouble. Now choose the profile to import into.', 'pack-and-go'));
    }

    public function handleSelectProfile(): void
    {
        $this->authorizeRequest('pack_and_go_select_profile');

        $profileId = isset($_POST['profile_id']) ? sanitize_text_field(wp_unslash($_POST['profile_id'])) : '';
        $accountId = isset($_POST['account_id']) ? sanitize_text_field(wp_unslash($_POST['account_id'])) : '';
        $label = isset($_POST['profile_label']) ? sanitize_text_field(wp_unslash($_POST['profile_label'])) : '';
        $avatarUrl = isset($_POST['avatar_url']) ? esc_url_raw(wp_unslash($_POST['avatar_url'])) : '';
        $publicUrl = isset($_POST['public_url']) ? esc_url_raw(wp_unslash($_POST['public_url'])) : '';
        $handle = isset($_POST['handle']) ? sanitize_text_field(wp_unslash($_POST['handle'])) : '';

        if ($profileId === '' || $accountId === '') {
            $this->redirectWithNotice('error', __('Please choose a profile.', 'pack-and-go'));
        }

        $this->plugin->store()->merge(array(
            'profile_id' => $profileId,
            'account_id' => $accountId,
            'profile_label' => $label,
            'avatar_url' => $avatarUrl,
            'public_url' => $publicUrl,
            'handle' => $handle,
        ));

        $this->redirectWithNotice('success', __('Profile selected. You are ready to import.', 'pack-and-go'));
    }

    public function handleChangeProfile(): void
    {
        $this->authorizeRequest('pack_and_go_change_profile');

        $this->plugin->store()->merge(array(
            'profile_id' => '',
            'account_id' => '',
            'profile_label' => '',
            'avatar_url' => '',
            'public_url' => '',
            'handle' => '',
        ));

        $this->redirectWithNotice('success', __('Choose a different profile to import into.', 'pack-and-go'));
    }

    public function handleDisconnect(): void
    {
        $this->authorizeRequest('pack_and_go_disconnect');

        $this->plugin->store()->clear();

        $this->redirectWithNotice('success', __('Disconnected from NoTrouble.', 'pack-and-go'));
    }

    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'pack-and-go'));
        }

        $store = $this->plugin->store();

        echo '<div class="wrap pag-shell">';
        View::header();
        echo '<h1 class="screen-reader-text">' . esc_html__('Pack & Go', 'pack-and-go') . '</h1>';

        if (! $store->isConnected()) {
            View::stepper('connect');
            $this->renderNotice();
            $this->renderConnect();
        } elseif (! $store->hasProfile()) {
            View::stepper('profile', array('connect'));
            $this->renderNotice();
            $this->renderProfilePicker();
        } else {
            View::stepper('configure', array('connect', 'profile'));
            $this->renderNotice();
            $this->renderConnected();
        }

        echo '</div>';
    }

    private function renderConnect(): void
    {
        echo '<div class="pag-panel">';
        echo '<h2 style="margin-top:0;">' . esc_html__('Let\'s move your content — it\'s no trouble', 'pack-and-go') . '</h2>';
        echo '<p>' . esc_html__('Connect this site to your NoTrouble account and we\'ll bring your posts, pages, and custom content across for you. You\'ll sign in and approve the move on NoTrouble — no passwords are ever stored here, and you can disconnect any time.', 'pack-and-go') . '</p>';
        echo '<p><strong>' . esc_html__('This never changes your WordPress site.', 'pack-and-go') . '</strong> '
            . esc_html__('Pack & Go only reads your content — it never edits or deletes anything here.', 'pack-and-go') . '</p>';

        $this->renderActionForm('pack_and_go_connect', __('Connect to NoTrouble', 'pack-and-go'), 'primary');

        echo '<p class="description" style="margin-top:12px;">'
            . wp_kses_post(sprintf(
                /* translators: %s: link to the help centre */
                __('New to this? %s walks you through it.', 'pack-and-go'),
                View::helpLink('connect-wordpress', __('Connecting WordPress', 'pack-and-go')),
            ))
            . '</p>';
        echo '</div>';
    }

    private function renderProfilePicker(): void
    {
        try {
            $profiles = $this->plugin->client()->myProfiles();
        } catch (RuntimeException $e) {
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
            $this->renderActionForm('pack_and_go_disconnect', __('Disconnect', 'pack-and-go'), 'secondary');

            return;
        }

        if ($profiles === array()) {
            echo '<p>' . esc_html__('No NoTrouble profiles found for your account.', 'pack-and-go') . '</p>';

            return;
        }

        echo '<div class="pag-panel">';
        echo '<h2 style="margin-top:0;">' . esc_html__('Which profile should we move your content into?', 'pack-and-go') . '</h2>';
        echo '<p>' . esc_html__('Pick the NoTrouble profile this site\'s content belongs to. You can change this later without losing your place.', 'pack-and-go') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('pack_and_go_select_profile');
        echo '<input type="hidden" name="action" value="pack_and_go_select_profile" />';

        $onchange = 'var o=this.selectedOptions[0];this.form.account_id.value=o.dataset.account||"";'
            . 'this.form.profile_label.value=o.dataset.label||"";this.form.avatar_url.value=o.dataset.avatar||"";'
            . 'this.form.public_url.value=o.dataset.url||"";this.form.handle.value=o.dataset.handle||"";';

        printf('<select name="profile_id" id="pack-and-go-profile" required onchange="%s">', esc_attr($onchange));
        echo '<option value="">' . esc_html__('— Select a profile —', 'pack-and-go') . '</option>';

        foreach ($profiles as $profile) {
            $id = isset($profile['id']) ? (string) $profile['id'] : '';
            $attributes = is_array($profile['attributes'] ?? null) ? $profile['attributes'] : array();
            $accountId = isset($attributes['accountId']) ? (string) $attributes['accountId'] : '';
            $name = isset($attributes['name']) && $attributes['name'] !== '' ? (string) $attributes['name'] : (string) ($attributes['handle'] ?? $id);

            if ($id === '' || $accountId === '') {
                continue;
            }

            printf(
                '<option value="%1$s" data-account="%2$s" data-label="%3$s" data-avatar="%4$s" data-url="%5$s" data-handle="%6$s">%7$s</option>',
                esc_attr($id),
                esc_attr($accountId),
                esc_attr($name),
                esc_attr(isset($attributes['avatarUrl']) ? (string) $attributes['avatarUrl'] : ''),
                esc_attr(isset($attributes['publicUrl']) ? (string) $attributes['publicUrl'] : ''),
                esc_attr(isset($attributes['handle']) ? (string) $attributes['handle'] : ''),
                esc_html($name),
            );
        }

        echo '</select>';
        echo '<input type="hidden" name="account_id" value="" />';
        echo '<input type="hidden" name="profile_label" value="" />';
        echo '<input type="hidden" name="avatar_url" value="" />';
        echo '<input type="hidden" name="public_url" value="" />';
        echo '<input type="hidden" name="handle" value="" />';
        echo '<p>';
        submit_button(__('Use this profile', 'pack-and-go'), 'primary', 'submit', false);
        echo '</p>';
        echo '</form>';
        echo '</div>';
    }

    private function renderConnected(): void
    {
        $this->renderProfileCard();
        $this->renderContentList();
        $this->renderReadyToMove();

        printf(
            '<p style="margin-top:16px;"><a href="%s" class="pag-help-inline"><span class="dashicons dashicons-admin-tools" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom;"></span> %s</a></p>',
            esc_url(admin_url('admin.php?page=' . self::TROUBLESHOOT_SLUG)),
            esc_html__('Something not working? Troubleshooting & advanced', 'pack-and-go'),
        );
    }

    private function renderProfileCard(): void
    {
        $store = $this->plugin->store();
        $name = (string) $store->get('profile_label', $store->get('profile_id', ''));
        $avatar = (string) $store->get('avatar_url', '');
        $url = (string) $store->get('public_url', '');
        $handle = (string) $store->get('handle', '');

        echo '<div class="pag-panel pag-profile">';

        if ($avatar !== '') {
            printf('<img class="pag-profile__avatar" src="%s" alt="" width="56" height="56" />', esc_url($avatar));
        } else {
            $initial = mb_strtoupper(mb_substr($name !== '' ? $name : 'N', 0, 1));
            printf('<span aria-hidden="true" class="pag-profile__avatar pag-profile__avatar--initial">%s</span>', esc_html($initial));
        }

        echo '<div class="pag-profile__body">';
        echo '<div class="pag-profile__status"><span class="dashicons dashicons-yes-alt"></span>'
            . esc_html__('Connected', 'pack-and-go') . '</div>';
        echo '<div class="pag-profile__name">' . esc_html($name) . '</div>';
        if ($url !== '') {
            $display = $handle !== '' ? '@' . $handle : preg_replace('#^https?://#', '', $url);
            printf(
                '<a class="pag-profile__url" href="%s" target="_blank" rel="noopener noreferrer">%s ↗</a>',
                esc_url($url),
                esc_html((string) $display),
            );
        }
        echo '</div>';

        echo '<div class="pag-profile__actions">';
        $this->renderActionForm('pack_and_go_change_profile', __('Change profile', 'pack-and-go'), 'secondary', false);
        $this->renderActionForm('pack_and_go_disconnect', __('Disconnect', 'pack-and-go'), 'secondary', false);
        echo '</div>';

        echo '</div>';
    }

    private function renderReadyToMove(): void
    {
        $ledger = $this->plugin->ledger();
        $totalItems = 0;
        $totalSynced = 0;
        $pending = 0;
        $needSetup = 0;
        $pushTypes = array();

        foreach ($this->plugin->discovery()->all() as $postType) {
            if ($postType->publishedCount === 0) {
                continue;
            }

            $mapping = $this->plugin->mappingStore()->forType($postType->name);
            $configured = is_array($mapping) && ($mapping['enabled'] ?? false) === true
                && is_string($mapping['sectionType'] ?? null) && $mapping['sectionType'] !== '';

            $synced = min($ledger->syncedCountForType($postType->name), $postType->publishedCount);
            $totalItems += $postType->publishedCount;
            $totalSynced += $synced;

            if ($configured) {
                $pushTypes[] = array('name' => $postType->name, 'label' => $postType->label);
                $pending += max(0, $postType->publishedCount - $synced);
            } else {
                $needSetup++;
            }
        }

        if ($totalItems === 0) {
            return;
        }

        echo '<div class="pag-panel pag-ready">';
        echo '<h2 style="margin-top:0;">' . esc_html__('Ready to move', 'pack-and-go') . '</h2>';

        if ($pushTypes === array()) {
            echo '<p>' . esc_html__('Set up a content type above, then come back here to push it across to NoTrouble.', 'pack-and-go') . '</p>';
            echo '</div>';

            return;
        }

        $pct = (int) round(($totalSynced / max(1, $totalItems)) * 100);

        $headline = $pending > 0
            ? sprintf(
                /* translators: %s: number of items */
                _n('You have %s item ready to move.', 'You have %s items ready to move.', $pending, 'pack-and-go'),
                number_format_i18n($pending),
            )
            : __('You\'re all caught up — everything set up is in NoTrouble. 🎉', 'pack-and-go');

        echo '<p class="pag-summary__headline"><strong>' . esc_html($headline) . '</strong></p>';
        printf(
            '<div class="pag-summary__meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%1$d" aria-label="%2$s"><div class="pag-summary__meter-fill" style="width:%1$d%%;"></div></div>',
            $pct,
            esc_attr__('Content moved to NoTrouble', 'pack-and-go'),
        );
        echo '<p class="pag-summary__hint">' . esc_html(sprintf(
            /* translators: 1: synced count, 2: total count */
            __('%1$s of %2$s items moved so far.', 'pack-and-go'),
            number_format_i18n($totalSynced),
            number_format_i18n($totalItems),
        )) . '</p>';

        if ($needSetup > 0) {
            echo '<p class="pag-summary__hint">' . esc_html(sprintf(
                /* translators: %s: number of content types */
                _n('%s content type above still needs setting up.', '%s content types above still need setting up.', $needSetup, 'pack-and-go'),
                number_format_i18n($needSetup),
            )) . '</p>';
        }

        echo '<p style="margin-top:14px;">';
        if ($pending > 0) {
            printf(
                '<button type="button" class="button button-primary button-hero" id="pag-push-all">%s</button>',
                esc_html(sprintf(
                    /* translators: %s: number of items */
                    _n('Push %s item to NoTrouble', 'Push %s items to NoTrouble', $pending, 'pack-and-go'),
                    number_format_i18n($pending),
                )),
            );
        } else {
            printf(
                '<button type="button" class="button button-hero" id="pag-push-all">%s</button>',
                esc_html__('Check for updates', 'pack-and-go'),
            );
        }
        echo '</p>';
        echo '<p class="description">' . esc_html__('Items already in NoTrouble are skipped automatically. Everything arrives as a draft so you can review before publishing.', 'pack-and-go') . '</p>';

        View::progressPanel();
        echo '</div>';

        wp_localize_script(Assets::HANDLE, 'PAG', array(
            'pushAll' => array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(SyncController::nonceAction()),
                'types' => $pushTypes,
                'i18n' => Assets::i18n(),
            ),
        ));
    }

    private function renderContentList(): void
    {
        echo '<h2>' . esc_html__('Your content', 'pack-and-go') . '</h2>';
        echo '<p class="description" style="margin-top:-4px;max-width:var(--pag-maxw);">'
            . esc_html__('Choose what each WordPress content type should become in NoTrouble, then set it up. Nothing moves until you push.', 'pack-and-go')
            . '</p>';

        try {
            $contentTypes = $this->plugin->client()->contentTypes();
        } catch (Throwable $e) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($e->getMessage()) . '</p></div>';

            return;
        }

        $postTypes = $this->plugin->discovery()->all();
        if ($postTypes === array()) {
            echo '<p>' . esc_html__('No content types found on this site.', 'pack-and-go') . '</p>';

            return;
        }

        $ledger = $this->plugin->ledger();

        echo '<table class="widefat striped pag-typelist"><thead><tr>';
        echo '<th>' . esc_html__('WordPress content', 'pack-and-go') . '</th>';
        echo '<th>' . esc_html__('Import into NoTrouble as', 'pack-and-go') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($postTypes as $postType) {
            $saved = $this->plugin->mappingStore()->forType($postType->name);
            $enabled = is_array($saved) && (bool) ($saved['enabled'] ?? false)
                && is_string($saved['sectionType'] ?? null) && $saved['sectionType'] !== '';
            $current = $enabled ? (string) $saved['sectionType'] : '';

            $total = (int) $postType->publishedCount;
            $synced = min($ledger->syncedCountForType($postType->name), $total);

            echo '<tr><td>';
            echo '<div class="pag-type__meta">';
            echo '<span class="pag-type__label">' . esc_html($postType->label) . '</span> ';
            printf('<span class="pag-type__count">(%d %s)</span>', $total, esc_html(_n('item', 'items', $total, 'pack-and-go')));
            echo $this->typeChip($enabled, $total, $synced); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- View::chip() output is fully escaped
            echo '</div>';

            $lastAt = $ledger->lastSyncedAt($postType->name);
            if ($lastAt > 0) {
                echo '<div class="pag-type__stats">' . esc_html(sprintf(
                    /* translators: %s: human-readable time difference, e.g. "3 days" */
                    __('Last pushed %s ago', 'pack-and-go'),
                    human_time_diff($lastAt),
                )) . '</div>';
            }

            echo '</td><td>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
            wp_nonce_field('pack_and_go_choose_type');
            echo '<input type="hidden" name="action" value="pack_and_go_choose_type" />';
            printf('<input type="hidden" name="wp_type" value="%s" />', esc_attr($postType->name));
            echo '<select name="section_type">';
            printf('<option value="">%s</option>', esc_html__('— Don\'t import —', 'pack-and-go'));
            foreach ($contentTypes as $type) {
                $value = is_string($type['type'] ?? null) ? $type['type'] : '';
                $typeLabel = is_string($type['label'] ?? null) ? $type['label'] : $value;
                printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($value, $current, false), esc_html($typeLabel));
            }
            echo '</select>';
            $buttonLabel = $enabled ? __('Edit setup', 'pack-and-go') : __('Set up', 'pack-and-go');
            submit_button($buttonLabel, 'secondary', 'submit', false);
            echo '</form>';

            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    private function typeChip(bool $enabled, int $total, int $synced): string
    {
        if (! $enabled) {
            return View::chip('notset');
        }

        if ($total === 0 || $synced === 0) {
            return View::chip('ready');
        }

        if ($synced >= $total) {
            return View::chip('synced', __('All synced', 'pack-and-go'));
        }

        return View::chip('synced', sprintf(
            /* translators: 1: synced count, 2: total count */
            __('%1$d of %2$d synced', 'pack-and-go'),
            $synced,
            $total,
        ));
    }

    public function renderTroubleshootingPage(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'pack-and-go'));
        }

        echo '<div class="wrap pag-shell">';
        View::header('');
        echo '<h1>' . esc_html__('Troubleshooting & advanced', 'pack-and-go') . '</h1>';
        printf(
            '<p><a href="%s">%s</a></p>',
            esc_url(admin_url('admin.php?page=' . self::SLUG)),
            esc_html__('← Back to Pack & Go', 'pack-and-go'),
        );

        $this->renderNotice();

        $this->renderDiagnostics();

        if ($this->plugin->store()->isConnected()) {
            $this->renderTroubleshootingBody();
        } else {
            echo '<div class="pag-panel"><p>' . esc_html__('The import-speed and reset tools appear here once you’re connected. The diagnostics above work without a connection.', 'pack-and-go') . '</p></div>';
        }

        echo '</div>';
    }

    private function renderDiagnostics(): void
    {
        $store = $this->plugin->store();
        $endpoints = $this->plugin->endpoints();

        echo '<div class="pag-panel">';
        echo '<h2 style="margin-top:0;">' . esc_html__('Diagnostics', 'pack-and-go') . '</h2>';
        echo '<p>' . esc_html__('These work whether or not you’re connected. If connecting fails, start here.', 'pack-and-go') . '</p>';

        $result = get_transient('pack_and_go_test_result');
        if (is_array($result)) {
            delete_transient('pack_and_go_test_result');
            $ok = ! empty($result['ok']);
            printf(
                '<div class="notice %1$s inline" style="margin:0 0 12px;"><p><strong>%2$s</strong><br>%3$s</p></div>',
                $ok ? 'notice-success' : 'notice-error',
                esc_html($ok ? __('Connection test passed', 'pack-and-go') : __('Connection test failed', 'pack-and-go')),
                esc_html(is_string($result['message'] ?? null) ? $result['message'] : ''),
            );
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:14px;">';
        wp_nonce_field('pack_and_go_test_connection');
        echo '<input type="hidden" name="action" value="pack_and_go_test_connection" />';
        submit_button(__('Test connection to NoTrouble', 'pack-and-go'), 'primary', 'submit', false);
        echo '</form>';

        $env = array(
            array(__('Plugin version', 'pack-and-go'), \NoTrouble\PackAndGo\VERSION, null),
            array(__('WordPress', 'pack-and-go'), (string) get_bloginfo('version'), null),
            array(__('PHP', 'pack-and-go'), PHP_VERSION, version_compare(PHP_VERSION, '8.1', '>=')),
        );
        foreach (array('json', 'mbstring', 'openssl', 'curl') as $extension) {
            $loaded = extension_loaded($extension);
            $env[] = array(
                sprintf(/* translators: %s: extension name */ __('PHP extension: %s', 'pack-and-go'), $extension),
                $loaded ? __('enabled', 'pack-and-go') : __('missing', 'pack-and-go'),
                $loaded,
            );
        }
        $this->renderDiagTable(__('Environment', 'pack-and-go'), $env);

        $domainNote = $endpoints->domain();
        if (defined('PACK_AND_GO_NOTROUBLE_DOMAIN')) {
            $domainNote .= '  ' . __('(set by PACK_AND_GO_NOTROUBLE_DOMAIN in wp-config.php)', 'pack-and-go');
        } elseif (apply_filters('pack_and_go_notrouble_domain', 'notrouble.com') !== 'notrouble.com') {
            $domainNote .= '  ' . __('(changed by a filter or dev drop-in)', 'pack-and-go');
        }
        $this->renderDiagTable(__('NoTrouble endpoints', 'pack-and-go'), array(
            array(__('Domain', 'pack-and-go'), $domainNote, null),
            array(__('Registration URL', 'pack-and-go'), $endpoints->registrationUrl(), null),
            array(__('Authorize URL', 'pack-and-go'), $endpoints->authorizeUrl(), null),
            array(__('Token URL', 'pack-and-go'), $endpoints->tokenUrl(), null),
            array(__('API base', 'pack-and-go'), $endpoints->apiBase(), null),
        ));

        $clientId = (string) $store->get('client_id', '');
        $this->renderDiagTable(__('Connection', 'pack-and-go'), array(
            array(__('Connected', 'pack-and-go'), $store->isConnected() ? __('yes', 'pack-and-go') : __('no', 'pack-and-go'), $store->isConnected()),
            array(__('Registered client id', 'pack-and-go'), $clientId !== '' ? '…' . substr($clientId, -8) : __('none', 'pack-and-go'), null),
            array(__('Target profile', 'pack-and-go'), (string) $store->get('profile_label', '') ?: __('none selected', 'pack-and-go'), null),
        ));

        $errors = \NoTrouble\PackAndGo\requirement_errors();
        if ($errors !== array()) {
            echo '<div class="notice notice-warning inline" style="margin-top:12px;"><p><strong>' . esc_html__('Issues found:', 'pack-and-go') . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
            foreach ($errors as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            echo '</ul></div>';
        }

        echo '</div>';
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: bool|null}> $rows
     */
    private function renderDiagTable(string $title, array $rows): void
    {
        echo '<h3 style="margin:14px 0 6px;">' . esc_html($title) . '</h3>';
        echo '<table class="widefat striped" style="max-width:760px;"><tbody>';
        foreach ($rows as $row) {
            [$label, $value, $status] = $row;
            $icon = '';
            if ($status === true) {
                $icon = ' <span class="dashicons dashicons-yes" style="color:#008a20;"></span>';
            } elseif ($status === false) {
                $icon = ' <span class="dashicons dashicons-warning" style="color:#b32d2e;"></span>';
            }
            echo '<tr><td style="width:220px;font-weight:600;">' . esc_html($label) . '</td><td><code style="word-break:break-all;">' . esc_html($value) . '</code>' . $icon . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public function handleTestConnection(): void
    {
        $this->authorizeRequest('pack_and_go_test_connection');

        $url = $this->plugin->endpoints()->registrationUrl();
        $redirect = admin_url('admin.php?page=' . self::SLUG);

        $response = wp_remote_post($url, array(
            'timeout' => 15,
            'headers' => array('Accept' => 'application/json', 'Content-Type' => 'application/json'),
            'body' => wp_json_encode(array('client_name' => 'Pack & Go connection test', 'redirect_uris' => array($redirect))),
        ));

        if (is_wp_error($response)) {
            $result = array('ok' => false, 'message' => sprintf(
                /* translators: 1: URL, 2: error detail */
                __('Couldn’t reach %1$s. This site may be unable to make outbound HTTPS requests, or the address is wrong. Details: %2$s', 'pack-and-go'),
                $url,
                $response->get_error_message(),
            ));
        } else {
            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status === 201) {
                $result = array('ok' => true, 'message' => sprintf(
                    /* translators: %s: URL */
                    __('This site reached NoTrouble and registration works at %s. You should be able to connect.', 'pack-and-go'),
                    $url,
                ));
            } elseif ($status === 405) {
                $result = array('ok' => false, 'message' => sprintf(
                    /* translators: %s: URL */
                    __('Reached the server, but %s returned 405 — that’s the wrong address. Registration must go to api.notrouble.com. Check for a domain override in wp-config.php or a leftover dev drop-in.', 'pack-and-go'),
                    $url,
                ));
            } else {
                $result = array('ok' => false, 'message' => sprintf(
                    /* translators: 1: URL, 2: HTTP status */
                    __('Reached %1$s but got HTTP %2$d instead of the expected success. If this persists, NoTrouble may be briefly unavailable — try again shortly.', 'pack-and-go'),
                    $url,
                    $status,
                ));
            }
        }

        set_transient('pack_and_go_test_result', $result, 60);

        wp_safe_redirect(admin_url('admin.php?page=' . self::TROUBLESHOOT_SLUG));
        exit;
    }

    private function renderTroubleshootingBody(): void
    {
        $settings = $this->plugin->settings();

        echo '<div class="pag-panel pag-tools__body">';

        echo '<div class="pag-tool-row">';
        echo '<div class="pag-tool-row__text">';
        echo '<h4>' . esc_html__('Import speed', 'pack-and-go') . '</h4>';
        echo '<p>' . esc_html__('How many items move per step. Most sites can leave this alone — only lower it if an import times out or runs out of memory on your host.', 'pack-and-go') . '</p>';
        echo '</div>';
        echo '<div class="pag-tool-row__action">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="pag-inline-form">';
        wp_nonce_field('pack_and_go_save_settings');
        echo '<input type="hidden" name="action" value="pack_and_go_save_settings" />';
        printf('<label class="screen-reader-text" for="pag-batch-size">%s</label>', esc_html__('Items per batch', 'pack-and-go'));
        printf(
            '<input type="number" id="pag-batch-size" name="batch_size" value="%1$d" min="%2$d" max="%3$d" step="1" class="small-text" /> ',
            $settings->batchSize(),
            $settings->minBatchSize(),
            $settings->maxBatchSize(),
        );
        submit_button(__('Save', 'pack-and-go'), 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';
        echo '</div>';

        $this->renderResetRow(
            'pack_and_go_clear_progress',
            __('Clear a stuck import', 'pack-and-go'),
            __('If an import seems frozen or was interrupted, this frees it up so you can push again. Nothing you\'ve already moved is lost.', 'pack-and-go'),
            __('Clear', 'pack-and-go'),
            __('Clear the current import progress so you can start a fresh push?', 'pack-and-go'),
        );
        $this->renderResetRow(
            'pack_and_go_reset_sync',
            __('Reset sync history', 'pack-and-go'),
            __('Pack & Go forgets what it has already moved, so your next push sends every item again. Your setup stays put. Use this if you deleted content in NoTrouble or want a clean re-import.', 'pack-and-go'),
            __('Reset history', 'pack-and-go'),
            __('Forget everything Pack & Go has moved? Your next push will send all items again. This can\'t be undone.', 'pack-and-go'),
        );
        $this->renderResetRow(
            'pack_and_go_reset_mappings',
            __('Reset setup', 'pack-and-go'),
            __('Clears how your content is matched to NoTrouble so you can set it up from scratch. Keeps your connection and your sync history.', 'pack-and-go'),
            __('Reset setup', 'pack-and-go'),
            __('Clear all your field mappings and start setup over? This can\'t be undone.', 'pack-and-go'),
        );

        echo '<div class="pag-tool-row">';
        echo '<div class="pag-tool-row__text">';
        echo '<h4>' . esc_html__('Still stuck?', 'pack-and-go') . '</h4>';
        echo '<p>' . wp_kses_post(sprintf(
            /* translators: %s: link to the help centre */
            __('Have a look through the %s, or share the details below with support so they can help faster.', 'pack-and-go'),
            View::helpLink('', __('NoTrouble help centre', 'pack-and-go')),
        )) . '</p>';
        echo '<p class="pag-diag">' . esc_html($this->diagnostics()) . '</p>';
        echo '</div>';
        printf(
            '<div class="pag-tool-row__action"><a class="button" href="%s" target="_blank" rel="noopener noreferrer">%s</a></div>',
            esc_url(View::helpUrl()),
            esc_html__('Open help centre', 'pack-and-go'),
        );
        echo '</div>';

        echo '</div>';
    }

    private function renderResetRow(string $action, string $title, string $description, string $button, string $confirm): void
    {
        echo '<div class="pag-tool-row">';
        echo '<div class="pag-tool-row__text">';
        echo '<h4>' . esc_html($title) . '</h4>';
        echo '<p>' . esc_html($description) . '</p>';
        echo '</div>';
        echo '<div class="pag-tool-row__action">';
        printf(
            '<form method="post" action="%s" data-pag-confirm="%s">',
            esc_url(admin_url('admin-post.php')),
            esc_attr($confirm),
        );
        wp_nonce_field($action);
        printf('<input type="hidden" name="action" value="%s" />', esc_attr($action));
        printf('<button type="submit" class="button button-link-delete">%s</button>', esc_html($button));
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    private function diagnostics(): string
    {
        $store = $this->plugin->store();
        $handle = (string) $store->get('handle', '');

        return sprintf(
            'Pack & Go %1$s · WordPress %2$s · PHP %3$s · %4$s',
            VERSION,
            get_bloginfo('version'),
            PHP_VERSION,
            $handle !== '' ? '@' . $handle : __('profile connected', 'pack-and-go'),
        );
    }

    public function handleSaveSettings(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'pack-and-go'));
        }

        check_admin_referer('pack_and_go_save_settings');

        $batchSize = isset($_POST['batch_size']) ? (int) wp_unslash($_POST['batch_size']) : 0;
        if ($batchSize > 0) {
            $this->plugin->settings()->setBatchSize($batchSize);
        }

        $this->redirectWithNotice('success', __('Import speed saved.', 'pack-and-go'));
    }

    public function handleClearProgress(): void
    {
        $this->authorizeRequest('pack_and_go_clear_progress');

        ImportState::clearAll();

        $this->redirectWithNotice('success', __('Import progress cleared. You can start a fresh push whenever you\'re ready.', 'pack-and-go'));
    }

    public function handleResetSync(): void
    {
        $this->authorizeRequest('pack_and_go_reset_sync');

        $ledger = $this->plugin->ledger();
        $ledger->forgetAll();
        $ledger->persist();
        ImportState::clearAll();

        $this->redirectWithNotice('success', __('Sync history cleared. Your next push will send everything again.', 'pack-and-go'));
    }

    public function handleResetMappings(): void
    {
        $this->authorizeRequest('pack_and_go_reset_mappings');

        $this->plugin->mappingStore()->clearAll();

        $this->redirectWithNotice('success', __('Setup cleared. Choose how to match your content again below.', 'pack-and-go'));
    }

    private function renderActionForm(string $action, string $label, string $buttonType, bool $wrap = true): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
        wp_nonce_field($action);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';
        submit_button($label, $buttonType, 'submit', $wrap);
        echo '</form>';
    }

    private function renderNotice(): void
    {
        if (! isset($_GET['pag_notice'], $_GET['pag_message'])) {
            return;
        }

        $type = sanitize_key(wp_unslash($_GET['pag_notice']));
        $class = $type === 'error' ? 'notice-error' : 'notice-success';
        $message = sanitize_text_field(wp_unslash($_GET['pag_message']));

        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private function authorizeRequest(string $nonceAction): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'pack-and-go'));
        }

        check_admin_referer($nonceAction);
    }

    private function redirectWithNotice(string $type, string $message): never
    {
        wp_safe_redirect(add_query_arg(array(
            'pag_notice' => $type,
            'pag_message' => rawurlencode($message),
        ), $this->pageUrl()));

        exit;
    }
}
