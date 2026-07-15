<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Admin;

defined('ABSPATH') || exit;

use NoTrouble\PackAndGo\Discovery\DiscoveredPostType;
use NoTrouble\PackAndGo\Sync\SyncLedger;
use WP_List_Table;

if (! class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class SelectPostsTable extends WP_List_Table
{
    public const MAX_ROWS = 300;

    private int $totalPublished = 0;

    public function __construct(
        private readonly DiscoveredPostType $postType,
        private readonly SyncLedger $ledger,
    ) {
        parent::__construct(array(
            'singular' => 'item',
            'plural' => 'items',
            'ajax' => false,
            'screen' => 'pack-and-go-select',
        ));
    }

    public function totalPublished(): int
    {
        return $this->totalPublished;
    }

    public function isTruncated(): bool
    {
        return $this->totalPublished > self::MAX_ROWS;
    }

    /**
     * @return array<string, string>
     */
    public function get_columns()
    {
        return array(
            'cb' => '<input type="checkbox" />',
            'thumb' => '',
            'title' => __('Title', 'pack-and-go'),
            'date' => __('Date', 'pack-and-go'),
            'pag_status' => __('Status', 'pack-and-go'),
        );
    }

    public function prepare_items()
    {
        $this->_column_headers = array($this->get_columns(), array(), array());

        $this->totalPublished = $this->postType->publishedCount;

        $posts = get_posts(array(
            'post_type' => $this->postType->name,
            'post_status' => 'publish',
            'numberposts' => self::MAX_ROWS,
            'orderby' => 'date',
            'order' => 'DESC',
            'suppress_filters' => true,
        ));

        $items = array();
        foreach (is_array($posts) ? $posts : array() as $post) {
            $modified = (int) get_post_modified_time('U', true, $post);
            $items[] = array(
                'id' => (int) $post->ID,
                'title' => $post->post_title !== '' ? $post->post_title : sprintf(/* translators: %d: post id */ __('(untitled #%d)', 'pack-and-go'), (int) $post->ID),
                'date' => $post->post_date,
                'thumb' => get_the_post_thumbnail_url($post->ID, 'thumbnail'),
                'status' => $this->ledger->statusFor($this->postType->name, (int) $post->ID, $modified),
            );
        }

        $this->items = $items;
    }

    /**
     * @param array{id: int, status: string} $item
     */
    public function column_cb($item)
    {
        $checked = $item['status'] !== 'synced' ? ' checked="checked"' : '';

        return sprintf(
            '<input type="checkbox" name="items[]" value="%1$d" data-status="%2$s"%3$s aria-label="%4$s" />',
            (int) $item['id'],
            esc_attr($item['status']),
            $checked,
            esc_attr(sprintf(/* translators: %d: post id */ __('Select item %d', 'pack-and-go'), (int) $item['id'])),
        );
    }

    /**
     * @param array{thumb: string|false} $item
     */
    protected function column_thumb(array $item): string
    {
        if (! empty($item['thumb'])) {
            return sprintf('<img class="pag-thumb" src="%s" alt="" />', esc_url((string) $item['thumb']));
        }

        return '<span class="pag-thumb pag-thumb--empty"><span class="dashicons dashicons-format-image"></span></span>';
    }

    /**
     * @param array{id: int, title: string} $item
     */
    protected function column_title(array $item): string
    {
        $edit = get_edit_post_link((int) $item['id']);
        $title = '<strong>' . esc_html($item['title']) . '</strong>';

        if (is_string($edit) && $edit !== '') {
            return sprintf('<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url($edit), $title);
        }

        return $title;
    }

    /**
     * @param array{date: string} $item
     */
    protected function column_date(array $item): string
    {
        $ts = strtotime((string) $item['date']);

        return $ts ? esc_html(date_i18n(get_option('date_format') ?: 'Y-m-d', $ts)) : '';
    }

    /**
     * @param array{status: string} $item
     */
    protected function column_pag_status(array $item): string
    {
        return View::chip($item['status']);
    }

    /**
     * @param array<string, mixed> $item
     * @param string               $column_name
     */
    public function column_default($item, $column_name)
    {
        return '';
    }

    public function no_items()
    {
        esc_html_e('No published items of this type yet.', 'pack-and-go');
    }

    /**
     * @param string $which
     */
    protected function display_tablenav($which) {}
}
