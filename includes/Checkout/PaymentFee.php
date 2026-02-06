<?php
/**
 * Adds payment method surcharges (e.g., Cash on Delivery).
 */

namespace MyGLS\Checkout;

if (!defined('ABSPATH')) {
    exit;
}

class PaymentFee {
    public function __construct() {
        add_action('woocommerce_cart_calculate_fees', [$this, 'add_cod_fee'], 20);
        add_filter('woocommerce_gateway_title', [$this, 'append_cod_fee_to_label'], 10, 2);
    }

    public function add_cod_fee($cart): void {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        if (function_exists('is_cart') && is_cart() && !is_checkout()) {
            return;
        }

        if (!$cart instanceof \WC_Cart) {
            return;
        }

        $settings = get_option('mygls_settings', []);
        $enabled = ($settings['cod_fee_enabled'] ?? '0') === '1';
        $amount = isset($settings['cod_fee_amount']) ? (float) $settings['cod_fee_amount'] : 0.0;

        if (!$enabled || $amount <= 0) {
            return;
        }

        $payment_method = WC()->session ? WC()->session->get('chosen_payment_method') : null;
        if (isset($_POST['payment_method'])) {
            $payment_method = sanitize_text_field(wp_unslash($_POST['payment_method']));
            if (WC()->session) {
                WC()->session->set('chosen_payment_method', $payment_method);
            }
        }
        if ($payment_method !== 'cod') {
            return;
        }

        $label = $settings['cod_fee_label'] ?? __('Cash on Delivery fee', 'mygls-woocommerce');
        $taxable = ($settings['cod_fee_taxable'] ?? '0') === '1';

        $cart->add_fee($label, $amount, $taxable);
    }

    public function append_cod_fee_to_label($title, $payment_id): string {
        if ($payment_id !== 'cod') {
            return $title;
        }

        if (function_exists('is_checkout') && !is_checkout()) {
            return $title;
        }

        if (function_exists('is_order_received_page') && is_order_received_page()) {
            return $title;
        }

        $settings = get_option('mygls_settings', []);
        $enabled = ($settings['cod_fee_enabled'] ?? '0') === '1';
        $amount = isset($settings['cod_fee_amount']) ? (float) $settings['cod_fee_amount'] : 0.0;

        if (!$enabled || $amount <= 0) {
            return $title;
        }

        $formatted = wc_price($amount);
        return sprintf('%s <span class="mygls-cod-fee-label">+%s</span>', $title, $formatted);
    }
}
