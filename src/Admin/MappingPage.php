<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Admin;

defined('ABSPATH') || exit;

use NoTrouble\PackAndGo\Discovery\DiscoveredPostType;
use NoTrouble\PackAndGo\Plugin;
use NoTrouble\PackAndGo\Sync\ImportState;
use Throwable;

final class MappingPage
{
    private const SLUG = 'pack-and-go-mapping';

    private const PARENT = 'pack-and-go';

    private const CAPABILITY = 'manage_options';

    public function __construct(private readonly Plugin $plugin) {}

    public function register(): void
    {
        add_action('admin_menu', array($this, 'addMenu'));
        add_action('admin_head', array($this, 'hideFromMenu'));
        add_action('admin_post_pack_and_go_save_mapping', array($this, 'handleSave'));
        add_action('admin_post_pack_and_go_choose_type', array($this, 'handleChooseType'));
        add_action('admin_post_pack_and_go_select_all', array($this, 'handleSelectAll'));
        add_action('admin_post_pack_and_go_select_items', array($this, 'handleSelectItems'));
        add_action('admin_post_pack_and_go_reset_type', array($this, 'handleResetType'));
    }

    public function addMenu(): void
    {
        add_submenu_page(
            self::PARENT,
            __('Configure import', 'pack-and-go'),
            __('Configure import', 'pack-and-go'),
            self::CAPABILITY,
            self::SLUG,
            array($this, 'render'),
        );
    }

    public function hideFromMenu(): void
    {
        echo '<style>#adminmenu .wp-submenu a[href*="page=' . esc_attr(self::SLUG) . '"]{display:none;}</style>';
    }

    private function pageUrl(string $wpType): string
    {
        return admin_url('admin.php?page=' . self::SLUG . '&type=' . rawurlencode($wpType));
    }

    public function handleChooseType(): void
    {
        $this->assertCapability();
        check_admin_referer('pack_and_go_choose_type');

        $wpType = isset($_POST['wp_type']) ? sanitize_key(wp_unslash($_POST['wp_type'])) : '';
        $sectionType = isset($_POST['section_type']) ? sanitize_key(wp_unslash($_POST['section_type'])) : '';

        if ($wpType === '') {
            wp_safe_redirect(admin_url('admin.php?page=' . self::PARENT));
            exit;
        }

        $store = $this->plugin->mappingStore();
        $existing = $store->forType($wpType) ?? array();

        if ($sectionType === '') {
            $existing['enabled'] = false;
            $store->save($wpType, $existing);
            wp_safe_redirect(admin_url('admin.php?page=' . self::PARENT));
            exit;
        }

        if (($existing['sectionType'] ?? null) !== $sectionType) {
            unset($existing['map']);
            $ledger = $this->plugin->ledger();
            $ledger->forgetType($wpType);
            $ledger->persist();
        }
        $existing['enabled'] = true;
        $existing['sectionType'] = $sectionType;
        $store->save($wpType, $existing);

        wp_safe_redirect($this->pageUrl($wpType));
        exit;
    }

    public function render(): void
    {
        $this->assertCapability();

        echo '<div class="wrap pag-shell">';
        View::header();

        $store = $this->plugin->store();
        if (! $store->isConnected() || ! $store->hasProfile()) {
            echo '<h1>' . esc_html__('Configure import', 'pack-and-go') . '</h1>';
            echo '<p>' . esc_html__('Connect to NoTrouble and choose a profile first.', 'pack-and-go') . '</p></div>';

            return;
        }

        $wpType = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '';
        $postType = $this->findPostType($wpType);

        if ($postType === null) {
            echo '<h1>' . esc_html__('Configure import', 'pack-and-go') . '</h1>';
            echo '<p>' . esc_html__('Pick a content type to set up from the Pack & Go page.', 'pack-and-go') . '</p>';
            printf('<p><a class="button" href="%s">%s</a></p>', esc_url(admin_url('admin.php?page=' . self::PARENT)), esc_html__('← Back to Pack & Go', 'pack-and-go'));
            echo '</div>';

            return;
        }

        try {
            $contentTypes = $this->plugin->client()->contentTypes();
        } catch (Throwable $e) {
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div></div>';

            return;
        }

        $step = isset($_GET['step']) ? sanitize_key(wp_unslash($_GET['step'])) : '';

        if ($step === 'select') {
            View::stepper('configure', array('connect', 'profile'));
            $this->renderNotice();
            $this->renderSelect($postType);
        } elseif ($step === 'push') {
            View::stepper('push', array('connect', 'profile', 'configure'));
            $this->renderNotice();
            $this->renderPush($postType);
        } else {
            View::stepper('configure', array('connect', 'profile'));
            $this->renderNotice();
            $this->renderConfigurator($postType, $contentTypes);
        }

        echo '</div>';
    }

    /**
     * @param array<int, array<string, mixed>> $contentTypes
     */
    private function renderConfigurator(DiscoveredPostType $postType, array $contentTypes): void
    {
        $saved = $this->plugin->mappingStore()->forType($postType->name);

        $sectionType = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';
        if (! $this->isKnownType($sectionType, $contentTypes)) {
            $sectionType = is_string($saved['sectionType'] ?? null) && $this->isKnownType($saved['sectionType'], $contentTypes)
                ? $saved['sectionType']
                : $this->plugin->suggester()->suggestSectionType($postType, $contentTypes);
        }

        $sectionSchema = $this->findType($sectionType, $contentTypes);
        $targets = $this->plugin->targetCatalog()->forSectionType($sectionSchema);
        $wpItems = $this->wpItems($postType);

        $targetMap = is_array($saved['map'] ?? null)
            ? $saved['map']
            : $this->plugin->suggester()->suggestTargetMap($targets, $wpItems);

        $contentFields = is_array($saved['contentFields'] ?? null)
            ? array_values(array_filter($saved['contentFields'], 'is_string'))
            : $this->plugin->suggester()->suggestContentFields($wpItems);

        $sectionTitle = is_string($saved['sectionTitle'] ?? null) ? $saved['sectionTitle'] : $postType->label;

        $samples = $this->plugin->sampler()->samplePosts($postType->name);
        $previewId = isset($_GET['preview_post']) ? (int) $_GET['preview_post'] : (int) ($samples[0]['id'] ?? 0);
        $sampleValues = $previewId > 0 ? $this->plugin->sampler()->values($previewId, $wpItems) : array();

        printf(
            '<h1>%s <span style="color:#787c82;">→ %s</span></h1>',
            esc_html(sprintf(/* translators: %s: post type */ __('Configure %s', 'pack-and-go'), $postType->label)),
            esc_html(is_string($sectionSchema['label'] ?? null) ? $sectionSchema['label'] : $sectionType),
        );
        printf('<p><a href="%s">%s</a></p>', esc_url(admin_url('admin.php?page=' . self::PARENT)), esc_html__('← Back to content list', 'pack-and-go'));

        echo '<div style="display:flex;gap:24px;align-items:flex-end;flex-wrap:wrap;margin:12px 0;">';
        echo '<label>' . esc_html__('Import as', 'pack-and-go') . '<br />';
        printf('<select onchange="location.href=this.value">');
        foreach ($contentTypes as $type) {
            $value = is_string($type['type'] ?? null) ? $type['type'] : '';
            $label = is_string($type['label'] ?? null) ? $type['label'] : $value;
            $url = add_query_arg(array('page' => self::SLUG, 'type' => $postType->name, 'section' => $value), admin_url('admin.php'));
            printf('<option value="%s" %s>%s</option>', esc_url($url), selected($value, $sectionType, false), esc_html($label));
        }
        echo '</select></label>';

        if ($samples !== array()) {
            echo '<label>' . esc_html__('Preview using', 'pack-and-go') . '<br />';
            echo '<select onchange="location.href=this.value">';
            foreach ($samples as $sample) {
                $url = add_query_arg(array('page' => self::SLUG, 'type' => $postType->name, 'section' => $sectionType, 'preview_post' => $sample['id']), admin_url('admin.php'));
                printf('<option value="%s" %s>%s</option>', esc_url($url), selected($sample['id'], $previewId, false), esc_html($sample['title']));
            }
            echo '</select></label>';
        } else {
            echo '<p><em>' . esc_html__('No published items yet — mapping can still be set up.', 'pack-and-go') . '</em></p>';
        }
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('pack_and_go_save_mapping');
        echo '<input type="hidden" name="action" value="pack_and_go_save_mapping" />';
        printf('<input type="hidden" name="wp_type" value="%s" />', esc_attr($postType->name));
        printf('<input type="hidden" name="section_type" value="%s" />', esc_attr($sectionType));

        $this->renderImportInto($postType, $sectionType, $saved, $sectionTitle);

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th style="width:28%;">' . esc_html__('NoTrouble field', 'pack-and-go') . '</th>';
        echo '<th style="width:32%;">' . esc_html__('WordPress field', 'pack-and-go') . '</th>';
        echo '<th>' . esc_html__('Preview', 'pack-and-go') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($targets as $target) {
            if ($target['value'] === 'skip') {
                continue;
            }
            if ($target['value'] === 'content') {
                $this->renderContentRow($target, $wpItems, $contentFields, $sampleValues);

                continue;
            }
            $selectedWp = is_string($targetMap[$target['value']] ?? null) ? $targetMap[$target['value']] : '';
            $this->renderTargetRow($target, $wpItems, $selectedWp, $sampleValues);
        }

        echo '</tbody></table>';

        $this->renderTaxonomies($postType, $saved);

        echo '<p style="margin-top:16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
        submit_button(__('Save mapping', 'pack-and-go'), 'secondary', 'save', false);
        submit_button(__('Save & choose what to move →', 'pack-and-go'), 'primary', 'save_continue', false);
        echo '</p></form>';

        echo '<p class="description">' . wp_kses_post(sprintf(
            /* translators: %s: contextual help link */
            __('Not sure how to match a field? %s explains each one.', 'pack-and-go'),
            View::helpLink('mapping-fields', __('Mapping your fields', 'pack-and-go')),
        )) . '</p>';

        wp_localize_script(Assets::HANDLE, 'PAG', array('sample' => $sampleValues));
    }

    /**
     * @param array<string, mixed>|null $saved
     */
    private function renderImportInto(DiscoveredPostType $postType, string $sectionType, ?array $saved, string $sectionTitle): void
    {
        $savedTarget = is_string($saved['targetSectionId'] ?? null) ? $saved['targetSectionId'] : '';
        $existing = $this->existingSectionsOfType($sectionType);
        $hasExisting = $existing !== array();
        $useExisting = $savedTarget !== '' && $hasExisting;

        echo '<fieldset class="pag-import-into" style="border:1px solid #dcdcde;border-radius:4px;padding:10px 14px;margin:12px 0;max-width:640px;">';
        echo '<legend style="font-weight:600;padding:0 4px;">' . esc_html__('Import into', 'pack-and-go') . '</legend>';

        echo '<p style="margin:.4em 0;"><label>';
        printf('<input type="radio" name="target_mode" value="new" data-pag-target-mode %s /> ', checked($useExisting, false, false));
        echo esc_html__('A new section', 'pack-and-go') . '</label></p>';
        echo '<p style="margin:.2em 0 .6em 26px;" data-pag-target-new><label>' . esc_html__('Section heading', 'pack-and-go') . ' ';
        printf('<input type="text" name="section_title" value="%s" class="regular-text" />', esc_attr($sectionTitle));
        echo '</label></p>';

        if ($hasExisting) {
            echo '<p style="margin:.4em 0;"><label>';
            printf('<input type="radio" name="target_mode" value="existing" data-pag-target-mode %s /> ', checked($useExisting, true, false));
            echo esc_html__('An existing section', 'pack-and-go') . '</label></p>';
            echo '<p style="margin:.2em 0 .2em 26px;" data-pag-target-existing><select name="target_section_id">';
            foreach ($existing as $section) {
                $id = is_string($section['id'] ?? null) ? $section['id'] : '';
                $attrs = is_array($section['attributes'] ?? null) ? $section['attributes'] : array();
                $name = is_string($attrs['name'] ?? null) && $attrs['name'] !== '' ? $attrs['name'] : $id;
                if ($id === '') {
                    continue;
                }
                printf('<option value="%s" %s>%s</option>', esc_attr($id), selected($id, $savedTarget, false), esc_html($name));
            }
            echo '</select></p>';
        } else {
            echo '<p style="margin:.2em 0 0 26px;color:#787c82;">' . esc_html__('No existing sections of this type yet — a new one will be created.', 'pack-and-go') . '</p>';
        }

        echo '</fieldset>';
        ?>
<script>
(function(){
  var fs=document.querySelector('.pag-import-into'); if(!fs)return;
  function sync(){
    var checked=fs.querySelector('[data-pag-target-mode]:checked');
    var mode=checked?checked.value:'new';
    var nw=fs.querySelector('[data-pag-target-new]'), ex=fs.querySelector('[data-pag-target-existing]');
    if(nw)nw.style.display=(mode==='new')?'':'none';
    if(ex)ex.style.display=(mode==='existing')?'':'none';
  }
  fs.querySelectorAll('[data-pag-target-mode]').forEach(function(r){r.addEventListener('change',sync);});
  sync();
})();
</script>
        <?php
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function existingSectionsOfType(string $sectionType): array
    {
        $account = (string) $this->plugin->store()->get('account_id', '');
        $profile = (string) $this->plugin->store()->get('profile_id', '');
        if ($account === '' || $profile === '') {
            return array();
        }

        try {
            $sections = $this->plugin->client()->listSections($account, $profile);
        } catch (Throwable $e) {
            return array();
        }

        return array_values(array_filter(
            $sections,
            static fn ($section): bool => is_array($section) && (($section['attributes']['type'] ?? null) === $sectionType),
        ));
    }

    /**
     * @param array{value: string, label: string, group: string, multi: bool} $target
     * @param array<int, array{key: string, label: string, type: string, isMedia: bool, source: string}> $wpItems
     * @param array<string, string> $sampleValues
     */
    private function renderTargetRow(array $target, array $wpItems, string $selectedWp, array $sampleValues): void
    {
        echo '<tr>';
        echo '<td><strong>' . esc_html($target['label']) . '</strong>';
        if ($target['group'] !== '') {
            echo ' <span style="color:#888;font-size:11px;">' . esc_html($target['group']) . '</span>';
        }
        echo '</td>';

        $mediaByKey = array();
        echo '<td>';
        printf('<select name="map[%s]" data-pag-map style="width:100%%;">', esc_attr($target['value']));
        printf('<option value="">%s</option>', esc_html__('— None —', 'pack-and-go'));
        foreach ($wpItems as $item) {
            $mediaByKey[$item['key']] = $item['isMedia'];
            printf(
                '<option value="%s" %s data-media="%s">%s%s</option>',
                esc_attr($item['key']),
                selected($item['key'], $selectedWp, false),
                $item['isMedia'] ? '1' : '0',
                esc_html($item['label']),
                $item['source'] !== '' ? esc_html(' (' . $item['source'] . ')') : '',
            );
        }
        echo '</select></td>';

        $previewValue = $selectedWp !== '' && isset($sampleValues[$selectedWp]) ? $sampleValues[$selectedWp] : '';
        $isImage = $selectedWp !== '' && ($mediaByKey[$selectedWp] ?? false);
        echo '<td data-pag-preview-cell>' . $this->previewMarkup($previewValue, $isImage) . '</td>';

        echo '</tr>';
    }

    /**
     * @param array{value: string, label: string, group: string, multi: bool} $target
     * @param array<int, array{key: string, label: string, type: string, isMedia: bool, source: string}> $wpItems
     * @param array<int, string> $contentFields
     * @param array<string, string> $sampleValues
     */
    private function renderContentRow(array $target, array $wpItems, array $contentFields, array $sampleValues): void
    {
        $textItems = array_values(array_filter($wpItems, static fn (array $item): bool => ! $item['isMedia']));
        $chosen = $contentFields !== array() ? $contentFields : array('');

        echo '<tr>';
        echo '<td><strong>' . esc_html($target['label']) . '</strong>';
        if ($target['group'] !== '') {
            echo ' <span style="color:#888;font-size:11px;">' . esc_html($target['group']) . '</span>';
        }
        echo '<br /><span style="color:#787c82;font-size:11px;">'
            . esc_html__('Pick one or more fields. They are joined into the body in this order, with a blank line between them.', 'pack-and-go')
            . '</span></td>';

        echo '<td data-pag-content-fields>';
        foreach ($chosen as $key) {
            $this->renderContentSelect($textItems, is_string($key) ? $key : '');
        }
        printf(
            '<p class="pag-content-add" style="margin:6px 0 0;"><button type="button" class="button button-small" data-pag-add-content>%s</button></p>',
            esc_html__('+ Add another field', 'pack-and-go'),
        );
        echo '</td>';

        $parts = array();
        foreach ($contentFields as $key) {
            if (is_string($key) && $key !== '' && isset($sampleValues[$key]) && $sampleValues[$key] !== '') {
                $parts[] = $sampleValues[$key];
            }
        }
        echo '<td>' . $this->previewMarkup(implode("\n\n", $parts), false) . '</td>';

        echo '</tr>';
        ?>
<script>
(function(){
  var cell=document.querySelector('[data-pag-content-fields]'); if(!cell)return;
  var btn=cell.querySelector('[data-pag-add-content]'); if(!btn)return;
  var addWrap=btn.closest('.pag-content-add');
  btn.addEventListener('click',function(){
    var rows=cell.querySelectorAll('.pag-content-field');
    if(!rows.length)return;
    var clone=rows[rows.length-1].cloneNode(true);
    var sel=clone.querySelector('select'); if(sel)sel.value='';
    cell.insertBefore(clone,addWrap);
  });
})();
</script>
        <?php
    }

    /**
     * @param array<int, array{key: string, label: string, type: string, isMedia: bool, source: string}> $textItems
     */
    private function renderContentSelect(array $textItems, string $selectedWp): void
    {
        echo '<p class="pag-content-field" style="margin:0 0 6px;">';
        echo '<select name="content_fields[]" style="width:100%;">';
        printf('<option value="">%s</option>', esc_html__('— None —', 'pack-and-go'));
        foreach ($textItems as $item) {
            printf(
                '<option value="%s" %s>%s%s</option>',
                esc_attr($item['key']),
                selected($item['key'], $selectedWp, false),
                esc_html($item['label']),
                $item['source'] !== '' ? esc_html(' (' . $item['source'] . ')') : '',
            );
        }
        echo '</select></p>';
    }

    private function previewMarkup(string $value, bool $isImage): string
    {
        if ($isImage) {
            if ($value === '') {
                return '<span class="pag-preview-empty">' . esc_html__('(no image)', 'pack-and-go') . '</span>';
            }

            return '<img class="pag-preview-img" src="' . esc_url($value) . '" alt="" />';
        }

        return '<code class="pag-preview-code">' . esc_html($value) . '</code>';
    }

    /**
     * @param array<string, mixed>|null $saved
     */
    private function renderTaxonomies(DiscoveredPostType $postType, ?array $saved): void
    {
        if ($postType->taxonomies === array()) {
            return;
        }

        echo '<h2 style="margin-top:20px;">' . esc_html__('Taxonomies', 'pack-and-go') . '</h2>';
        foreach ($postType->taxonomies as $taxonomy) {
            $taxSelected = is_string(($saved['taxonomies'][$taxonomy['name']] ?? null)) ? $saved['taxonomies'][$taxonomy['name']] : 'tags';
            printf('<label style="display:inline-block;margin-right:16px;">%s ', esc_html($taxonomy['label']));
            printf('<select name="taxonomies[%s]">', esc_attr($taxonomy['name']));
            printf('<option value="tags" %s>%s</option>', selected($taxSelected, 'tags', false), esc_html__('Import as tags', 'pack-and-go'));
            printf('<option value="skip" %s>%s</option>', selected($taxSelected, 'skip', false), esc_html__('Skip', 'pack-and-go'));
            echo '</select></label>';
        }
    }

    public function handleSave(): void
    {
        $this->assertCapability();
        check_admin_referer('pack_and_go_save_mapping');

        $wpType = isset($_POST['wp_type']) ? sanitize_key(wp_unslash($_POST['wp_type'])) : '';
        if ($wpType === '') {
            wp_safe_redirect(admin_url('admin.php?page=' . self::PARENT));
            exit;
        }

        /** @var array<string, string> $rawMap */
        $rawMap = isset($_POST['map']) && is_array($_POST['map']) ? wp_unslash($_POST['map']) : array();

        /** @var array<int, mixed> $rawContentFields */
        $rawContentFields = isset($_POST['content_fields']) && is_array($_POST['content_fields']) ? wp_unslash($_POST['content_fields']) : array();
        $contentFields = array();
        foreach ($rawContentFields as $key) {
            if (! is_string($key)) {
                continue;
            }
            $clean = sanitize_text_field($key);
            if ($clean !== '' && ! in_array($clean, $contentFields, true)) {
                $contentFields[] = $clean;
            }
        }

        $sectionType = isset($_POST['section_type']) ? sanitize_key(wp_unslash($_POST['section_type'])) : '';

        $targetMode = isset($_POST['target_mode']) ? sanitize_key(wp_unslash($_POST['target_mode'])) : 'new';
        $targetSectionId = ($targetMode === 'existing' && isset($_POST['target_section_id']))
            ? sanitize_text_field(wp_unslash($_POST['target_section_id']))
            : '';

        $previous = $this->plugin->mappingStore()->forType($wpType);
        $destinationChanged = is_array($previous)
            && (($previous['sectionType'] ?? null) !== $sectionType
                || (string) ($previous['targetSectionId'] ?? '') !== $targetSectionId);
        if ($destinationChanged) {
            $ledger = $this->plugin->ledger();
            $ledger->forgetType($wpType);
            $ledger->persist();
        }

        $this->plugin->mappingStore()->save($wpType, array(
            'enabled' => true,
            'sectionType' => $sectionType,
            'sectionTitle' => isset($_POST['section_title']) ? sanitize_text_field(wp_unslash($_POST['section_title'])) : '',
            'targetSectionId' => $targetSectionId,
            'map' => $this->sanitizeMap($rawMap),
            'contentFields' => $contentFields,
            'taxonomies' => $this->sanitizeMap(isset($_POST['taxonomies']) ? wp_unslash($_POST['taxonomies']) : null),
        ));

        if (isset($_POST['save_continue'])) {
            wp_safe_redirect(add_query_arg('step', 'select', $this->pageUrl($wpType)));
            exit;
        }

        wp_safe_redirect(add_query_arg(array(
            'pag_notice' => 'success',
            'pag_message' => rawurlencode(__('Mapping saved.', 'pack-and-go')),
        ), $this->pageUrl($wpType)));

        exit;
    }

    private function renderSelect(DiscoveredPostType $postType): void
    {
        $ledger = $this->plugin->ledger();
        $table = new SelectPostsTable($postType, $ledger);
        $table->prepare_items();

        printf(
            '<h1>%s</h1>',
            esc_html(sprintf(/* translators: %s: post type label */ __('What should we move from %s?', 'pack-and-go'), $postType->label)),
        );
        printf(
            '<p><a href="%s">%s</a></p>',
            esc_url($this->pageUrl($postType->name)),
            esc_html__('← Back to field mapping', 'pack-and-go'),
        );

        echo '<div class="pag-panel">';
        echo '<h2 style="margin-top:0;">' . esc_html__('Move everything', 'pack-and-go') . '</h2>';
        echo '<p>' . esc_html(sprintf(
            /* translators: %s: number of items */
            _n('Send all %s published item across. Already-synced items are skipped automatically, so it\'s safe to run again.', 'Send all %s published items across. Already-synced items are skipped automatically, so it\'s safe to run again.', (int) $postType->publishedCount, 'pack-and-go'),
            number_format_i18n((int) $postType->publishedCount),
        )) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('pack_and_go_select_all');
        echo '<input type="hidden" name="action" value="pack_and_go_select_all" />';
        printf('<input type="hidden" name="wp_type" value="%s" />', esc_attr($postType->name));
        submit_button(__('Move everything →', 'pack-and-go'), 'primary', 'submit', false);
        echo '</form>';
        echo '</div>';

        echo '<div class="pag-panel">';
        echo '<h2 style="margin-top:0;">' . esc_html__('Or pick exactly what to move', 'pack-and-go') . '</h2>';
        echo '<p class="description">' . esc_html__('New and changed items are ticked for you. Untick anything you want to leave behind.', 'pack-and-go') . '</p>';

        if ($table->isTruncated()) {
            echo '<div class="notice notice-info inline"><p>' . esc_html(sprintf(
                /* translators: 1: number shown, 2: total */
                __('Showing the %1$s most recent of %2$s items. To move more than this in one go, use “Move everything” above.', 'pack-and-go'),
                number_format_i18n(SelectPostsTable::MAX_ROWS),
                number_format_i18n($table->totalPublished()),
            )) . '</p></div>';
        }

        echo '<div class="pag-select-toolbar">';
        echo '<span>' . esc_html__('Select:', 'pack-and-go') . '</span> ';
        echo '<button type="button" class="button button-small" data-pag-select="new">' . esc_html__('New only', 'pack-and-go') . '</button> ';
        echo '<button type="button" class="button button-small" data-pag-select="changed">' . esc_html__('Changed only', 'pack-and-go') . '</button> ';
        echo '<button type="button" class="button button-small" data-pag-select="all">' . esc_html__('All', 'pack-and-go') . '</button> ';
        echo '<button type="button" class="button button-small" data-pag-select="none">' . esc_html__('None', 'pack-and-go') . '</button>';
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-pag-select-form>';
        wp_nonce_field('pack_and_go_select_items');
        echo '<input type="hidden" name="action" value="pack_and_go_select_items" />';
        printf('<input type="hidden" name="wp_type" value="%s" />', esc_attr($postType->name));
        $table->display();
        echo '<p>';
        submit_button(__('Move selected →', 'pack-and-go'), 'primary', 'submit', false);
        echo '</p>';
        echo '</form>';
        echo '</div>';
    }

    private function renderPush(DiscoveredPostType $postType): void
    {
        $state = new ImportState($postType->name);
        $selection = $state->selection();
        $count = $selection !== null ? count($selection) : (int) $postType->publishedCount;

        printf(
            '<h1>%s</h1>',
            esc_html(sprintf(/* translators: %s: post type label */ __('Move %s to NoTrouble', 'pack-and-go'), $postType->label)),
        );
        printf(
            '<p><a href="%s">%s</a></p>',
            esc_url(add_query_arg('step', 'select', $this->pageUrl($postType->name))),
            esc_html__('← Change what to move', 'pack-and-go'),
        );

        echo '<div class="pag-panel">';
        if ($state->isResumable()) {
            echo '<p><strong>' . esc_html__('You have an import in progress.', 'pack-and-go') . '</strong> '
                . esc_html__('Pick up where you left off, or start it again.', 'pack-and-go') . '</p>';
            printf(
                '<p><button type="button" class="button button-primary" id="pag-resume">%s</button> '
                . '<button type="button" class="button" id="pag-push">%s</button></p>',
                esc_html__('Resume import', 'pack-and-go'),
                esc_html__('Start over', 'pack-and-go'),
            );
        } else {
            echo '<p>' . esc_html(sprintf(
                /* translators: %s: number of items */
                _n('%s item is queued to move. It\'ll arrive as a draft in NoTrouble so you can review before publishing.', '%s items are queued to move. They\'ll arrive as drafts in NoTrouble so you can review before publishing.', $count, 'pack-and-go'),
                number_format_i18n($count),
            )) . '</p>';
            printf(
                '<p><button type="button" class="button button-primary button-hero" id="pag-push">%s</button></p>',
                esc_html__('Push to NoTrouble', 'pack-and-go'),
            );
        }
        echo '</div>';

        $syncedForType = $this->plugin->ledger()->syncedCountForType($postType->name);
        if ($syncedForType > 0) {
            echo '<p class="pag-danger-note">';
            printf(
                '<form method="post" action="%s" data-pag-confirm="%s" style="display:inline;">',
                esc_url(admin_url('admin-post.php')),
                esc_attr__('Forget what\'s been moved for this content type? Your next push will send all of its items again.', 'pack-and-go'),
            );
            wp_nonce_field('pack_and_go_reset_type');
            echo '<input type="hidden" name="action" value="pack_and_go_reset_type" />';
            printf('<input type="hidden" name="wp_type" value="%s" />', esc_attr($postType->name));
            printf(
                '<button type="submit" class="button-link" style="color:var(--pag-danger);">%s</button>',
                esc_html(sprintf(
                    /* translators: %s: post type label */
                    __('Start %s over — forget its sync history and re-import from scratch', 'pack-and-go'),
                    $postType->label,
                )),
            );
            echo '</form></p>';
        }

        View::progressPanel();

        wp_localize_script(Assets::HANDLE, 'PAG', array(
            'sync' => array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'saveUrl' => admin_url('admin-post.php'),
                'nonce' => wp_create_nonce(SyncController::nonceAction()),
                'wpType' => $postType->name,
                'resumable' => $state->isResumable(),
                'i18n' => Assets::i18n(),
            ),
        ));
    }

    public function handleSelectAll(): void
    {
        $this->assertCapability();
        check_admin_referer('pack_and_go_select_all');

        $wpType = isset($_POST['wp_type']) ? sanitize_key(wp_unslash($_POST['wp_type'])) : '';
        if ($wpType === '') {
            wp_safe_redirect(admin_url('admin.php?page=' . self::PARENT));
            exit;
        }

        $state = new ImportState($wpType);
        $state->setSelection(null);

        wp_safe_redirect(add_query_arg('step', 'push', $this->pageUrl($wpType)));
        exit;
    }

    public function handleSelectItems(): void
    {
        $this->assertCapability();
        check_admin_referer('pack_and_go_select_items');

        $wpType = isset($_POST['wp_type']) ? sanitize_key(wp_unslash($_POST['wp_type'])) : '';
        if ($wpType === '') {
            wp_safe_redirect(admin_url('admin.php?page=' . self::PARENT));
            exit;
        }

        /** @var array<int, mixed> $rawItems */
        $rawItems = isset($_POST['items']) && is_array($_POST['items']) ? wp_unslash($_POST['items']) : array();
        $ids = array_values(array_unique(array_filter(array_map('intval', $rawItems), static fn (int $id): bool => $id > 0)));

        if ($ids === array()) {
            wp_safe_redirect(add_query_arg(array(
                'step' => 'select',
                'pag_notice' => 'error',
                'pag_message' => rawurlencode(__('Pick at least one item to move, or use “Move everything”.', 'pack-and-go')),
            ), $this->pageUrl($wpType)));
            exit;
        }

        $state = new ImportState($wpType);
        $state->setSelection($ids);

        wp_safe_redirect(add_query_arg('step', 'push', $this->pageUrl($wpType)));
        exit;
    }

    public function handleResetType(): void
    {
        $this->assertCapability();
        check_admin_referer('pack_and_go_reset_type');

        $wpType = isset($_POST['wp_type']) ? sanitize_key(wp_unslash($_POST['wp_type'])) : '';
        if ($wpType === '') {
            wp_safe_redirect(admin_url('admin.php?page=' . self::PARENT));
            exit;
        }

        $ledger = $this->plugin->ledger();
        $ledger->forgetType($wpType);
        $ledger->persist();
        (new ImportState($wpType))->clear();

        wp_safe_redirect(add_query_arg(array(
            'step' => 'select',
            'pag_notice' => 'success',
            'pag_message' => rawurlencode(__('Sync history cleared for this content type. Choose what to move and push again.', 'pack-and-go')),
        ), $this->pageUrl($wpType)));
        exit;
    }

    private function findPostType(string $wpType): ?DiscoveredPostType
    {
        if ($wpType === '') {
            return null;
        }

        foreach ($this->plugin->discovery()->all() as $postType) {
            if ($postType->name === $wpType) {
                return $postType;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $contentTypes
     * @return array<string, mixed>
     */
    private function findType(string $sectionType, array $contentTypes): array
    {
        foreach ($contentTypes as $type) {
            if (($type['type'] ?? null) === $sectionType) {
                return $type;
            }
        }

        return array();
    }

    /**
     * @param array<int, array<string, mixed>> $contentTypes
     */
    private function isKnownType(string $sectionType, array $contentTypes): bool
    {
        return $sectionType !== '' && $this->findType($sectionType, $contentTypes) !== array();
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, isMedia: bool, source: string}>
     */
    private function wpItems(DiscoveredPostType $postType): array
    {
        $items = array(
            array('key' => '_title', 'label' => __('Title', 'pack-and-go'), 'type' => 'text', 'isMedia' => false, 'source' => ''),
            array('key' => '_content', 'label' => __('Content (body)', 'pack-and-go'), 'type' => 'rich_text', 'isMedia' => false, 'source' => ''),
            array('key' => '_excerpt', 'label' => __('Excerpt', 'pack-and-go'), 'type' => 'textarea', 'isMedia' => false, 'source' => ''),
            array('key' => '_featured_image', 'label' => __('Featured image', 'pack-and-go'), 'type' => 'image', 'isMedia' => true, 'source' => ''),
        );

        foreach ($postType->fields as $field) {
            $items[] = array(
                'key' => $field->metaKey,
                'label' => $field->label,
                'type' => $field->type->value,
                'isMedia' => $field->type->isMedia(),
                'source' => $field->source,
            );
        }

        return $items;
    }

    /**
     * @param mixed $map
     * @return array<string, string>
     */
    private function sanitizeMap(mixed $map): array
    {
        if (! is_array($map)) {
            return array();
        }

        $clean = array();
        foreach ($map as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $clean[sanitize_text_field($key)] = sanitize_text_field($value);
            }
        }

        return $clean;
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

    private function assertCapability(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'pack-and-go'));
        }
    }
}
