<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Discovery\FieldAdapters;

defined('ABSPATH') || exit;

use NoTrouble\PackAndGo\Discovery\DiscoveredField;
use NoTrouble\PackAndGo\Discovery\FieldType;

/**
 * Surfaces WooCommerce (and WooCommerce Subscriptions) pricing for the `product` post type. Woo
 * stores prices in fixed core meta keys (`_regular_price`, `_sale_price`, `_price`) as decimal
 * dollar strings, so they're never picked up by the ACF/Toolset/Meta Box definition readers.
 */
final class WooCommerceFieldAdapter implements FieldAdapter
{
    public function source(): string
    {
        return 'woocommerce';
    }

    public function isActive(): bool
    {
        return defined('WC_VERSION')
            || function_exists('WC')
            || class_exists('WooCommerce');
    }

    public function fieldsFor(string $postType): array
    {
        if ($postType !== 'product') {
            return array();
        }

        $fields = array(
            new DiscoveredField('_regular_price', __('Regular price (WooCommerce)', 'pack-and-go'), FieldType::Number, $this->source()),
            new DiscoveredField('_sale_price', __('Sale price (WooCommerce)', 'pack-and-go'), FieldType::Number, $this->source()),
            new DiscoveredField('_price', __('Active price (WooCommerce)', 'pack-and-go'), FieldType::Number, $this->source()),
            new DiscoveredField('_sku', __('SKU (WooCommerce)', 'pack-and-go'), FieldType::Text, $this->source()),
        );

        if ($this->subscriptionsActive()) {
            $fields[] = new DiscoveredField('_subscription_price', __('Subscription price (WooCommerce)', 'pack-and-go'), FieldType::Number, $this->source());
            $fields[] = new DiscoveredField(
                '_subscription_period',
                __('Billing period (WooCommerce)', 'pack-and-go'),
                FieldType::Select,
                $this->source(),
                array('day' => __('Day', 'pack-and-go'), 'week' => __('Week', 'pack-and-go'), 'month' => __('Month', 'pack-and-go'), 'year' => __('Year', 'pack-and-go')),
            );
            $fields[] = new DiscoveredField('_subscription_period_interval', __('Billing interval (WooCommerce)', 'pack-and-go'), FieldType::Number, $this->source());
            $fields[] = new DiscoveredField('_subscription_sign_up_fee', __('Sign-up fee (WooCommerce)', 'pack-and-go'), FieldType::Number, $this->source());
        }

        return $fields;
    }

    private function subscriptionsActive(): bool
    {
        return class_exists('WC_Subscriptions')
            || class_exists('WC_Subscriptions_Product');
    }
}
