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
    }

    public function add_cod_fee($cart): void {
        if (is_admin() && !defined('DOING_AJAX')) {
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
        if ($payment_method !== 'cod') {
            return;
        }

        $label = $settings['cod_fee_label'] ?? __('Cash on Delivery fee', 'mygls-woocommerce');
        $taxable = ($settings['cod_fee_taxable'] ?? '0') === '1';

        $cart->add_fee($label, $amount, $taxable);
    }
}
