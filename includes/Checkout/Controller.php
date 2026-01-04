<?php
/**
 * Custom Checkout Controller
 * Controls the checkout page layout and field order when GLS Custom Checkout is enabled
 */

namespace MyGLS\Checkout;

if (!defined('ABSPATH')) {
    exit;
}

class Controller {
    private $settings;
    private $enabled;
    private $field_priorities = [];
    private $default_field_order = ['billing', 'shipping_method', 'shipping', 'parcelshop', 'order_notes', 'payment'];
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = get_option('mygls_settings', []);
        $this->enabled = ($this->settings['enable_custom_checkout'] ?? '0') === '1';
        $this->parcelshop_methods = array_filter((array) ($this->settings['parcelshop_enabled_methods'] ?? []));

        if (!$this->enabled) {
            return;
        }

        // Initialize custom checkout hooks
        $this->init_hooks();
    }

    /**
     * Initialize WordPress/WooCommerce hooks
     */
    private function init_hooks() {
        // Override checkout fields order
        add_filter('woocommerce_checkout_fields', [$this, 'reorder_checkout_fields'], 9999);

        // Completely replace default checkout layout
        add_filter('woocommerce_locate_template', [$this, 'custom_checkout_template'], 10, 3);

        // Ensure custom sections stay in sync with checkout fragments
        add_filter('woocommerce_update_order_review_fragments', [$this, 'register_checkout_fragments']);

        // Enqueue custom checkout styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_custom_styles']);

        // Enqueue custom checkout scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_custom_scripts']);

        // Prevent parcelshop selector from rendering inside shipping method list when custom checkout is active
        add_filter('mygls_show_parcelshop_selector', [$this, 'maybe_hide_inline_parcelshop_selector'], 10, 2);

        // Privacy policy checkbox validation
        add_action('woocommerce_checkout_process', [$this, 'validate_privacy_checkbox']);

        // Save privacy policy checkbox value
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_privacy_checkbox']);

        // Format prices as integers (no decimals) on checkout page
        add_filter('wc_price_args', [$this, 'format_checkout_price_args']);

        // Ensure shipping name fields are enabled
        add_filter('woocommerce_checkout_fields', [$this, 'ensure_shipping_name_fields'], 9998);
    }

    /**
     * Ensure shipping name fields are present
     */
    public function ensure_shipping_name_fields($fields) {
        // Ensure shipping first name field exists
        if (!isset($fields['shipping']['shipping_first_name'])) {
            $fields['shipping']['shipping_first_name'] = [
                'label' => __('Keresztnév', 'mygls-woocommerce'),
                'required' => true,
                'class' => ['form-row-first'],
                'priority' => 10,
            ];
        }

        // Ensure shipping last name field exists
        if (!isset($fields['shipping']['shipping_last_name'])) {
            $fields['shipping']['shipping_last_name'] = [
                'label' => __('Vezetéknév', 'mygls-woocommerce'),
                'required' => true,
                'class' => ['form-row-last'],
                'priority' => 20,
            ];
        }

        // Ensure shipping company field exists
        if (!isset($fields['shipping']['shipping_company'])) {
            $fields['shipping']['shipping_company'] = [
                'label' => __('Cégnév', 'mygls-woocommerce'),
                'class' => ['form-row-wide'],
                'required' => false,
                'priority' => 30,
            ];
        }

        return $fields;
    }

    /**
     * Reorder checkout fields based on admin settings
     */
    public function reorder_checkout_fields($fields) {
        $field_order = $this->get_configured_field_order();

        // Set custom priorities for field groups
        $priorities = [];
        foreach ($field_order as $index => $section) {
            $priorities[$section] = ($index + 1) * 10;
        }

        // Store priorities for later use
        $this->field_priorities = $priorities;

        return $fields;
    }

    /**
     * Use custom checkout template
     */
    public function custom_checkout_template($template, $template_name, $template_path) {
        // Override checkout templates
        $templates_to_override = [
            'checkout/form-checkout.php',
            'checkout/review-order.php'
        ];

        if (in_array($template_name, $templates_to_override, true)) {
            $custom_template = MYGLS_PLUGIN_DIR . 'templates/' . $template_name;
            if (file_exists($custom_template)) {
                return $custom_template;
            }
        }

        return $template;
    }

    /**
     * Render checkout sections in custom order
     */
    public function render_checkout_sections() {
        $field_order = $this->get_configured_field_order();

        foreach ($field_order as $section) {
            echo $this->get_section_wrapper_markup($section);
        }
    }

    /**
     * Build wrapper markup for a checkout section so it can be replaced via fragments
     */
    private function get_section_wrapper_markup(string $section): string {
        $content = $this->get_section_markup($section);

        $classes = ['mygls-section-wrapper'];
        if ($content === '') {
            $classes[] = 'mygls-section-wrapper--empty';
        }

        return sprintf(
            '<div id="mygls-section-wrapper-%1$s" class="%2$s">%3$s</div>',
            esc_attr($section),
            esc_attr(implode(' ', $classes)),
            $content
        );
    }

    /**
     * Generate markup for an individual checkout section
     */
    private function get_section_markup(string $section): string {
        if (!function_exists('WC')) {
            return '';
        }

        $checkout = WC()->checkout();

        switch ($section) {
            case 'billing':
                ob_start();
                echo '<div class="mygls-checkout-section mygls-section-billing">';
                echo '<h3 class="mygls-section-title">';
                echo '<span class="dashicons dashicons-id"></span>';
                echo esc_html__('Számlázási adatok', 'mygls-woocommerce');
                echo '</h3>';
                echo '<div class="mygls-section-content">';
                foreach ($checkout->get_checkout_fields('billing') as $key => $field) {
                    woocommerce_form_field($key, $field, $checkout->get_value($key));
                }
                echo '</div>';
                echo '</div>';
                return ob_get_clean();

            case 'shipping_method':
                if (!WC()->cart || !WC()->cart->needs_shipping() || !WC()->cart->show_shipping()) {
                    return '';
                }

                ob_start();
                echo '<div class="mygls-checkout-section mygls-section-shipping-method">';
                echo '<h3 class="mygls-section-title">';
                echo '<span class="dashicons dashicons-car"></span>';
                echo esc_html__('Szállítási mód', 'mygls-woocommerce');
                echo '</h3>';
                echo '<div class="mygls-section-content">';
                echo $this->get_shipping_methods_markup();
                echo '</div>';
                echo '</div>';
                return ob_get_clean();

            case 'shipping':
                if (!WC()->session || $this->is_parcelshop_delivery_selected() || !WC()->cart || !WC()->cart->needs_shipping() || wc_ship_to_billing_address_only()) {
                    return '';
                }

                ob_start();
                echo '<div class="mygls-checkout-section mygls-section-shipping">';
                echo '<h3 class="mygls-section-title">';
                echo '<span class="dashicons dashicons-location"></span>';
                echo esc_html__('Szállítási adatok', 'mygls-woocommerce');
                echo '</h3>';
                echo '<div class="mygls-section-content">';

                // Add checkbox for "same as billing"
                echo '<div class="mygls-same-as-billing-wrapper">';
                echo '<label class="mygls-same-as-billing-label">';
                echo '<input type="checkbox" id="mygls_same_as_billing" name="mygls_same_as_billing" checked="checked" />';
                echo '<span>' . esc_html__('Megegyezik a számlázási adatokkal', 'mygls-woocommerce') . '</span>';
                echo '</label>';
                echo '</div>';

                // Shipping fields wrapper
                echo '<div class="mygls-shipping-fields-wrap">';
                foreach ($checkout->get_checkout_fields('shipping') as $key => $field) {
                    woocommerce_form_field($key, $field, $checkout->get_value($key));
                }
                echo '</div>';

                echo '</div>';
                echo '</div>';
                return ob_get_clean();

            case 'parcelshop':
                if (!WC()->session || !$this->is_parcelshop_delivery_selected()) {
                    return '';
                }

                ob_start();
                echo '<div class="mygls-checkout-section mygls-section-parcelshop">';
                echo '<h3 class="mygls-section-title">';
                echo '<span class="dashicons dashicons-location-alt"></span>';
                echo esc_html__('Csomagpont kiválasztása', 'mygls-woocommerce');
                echo '</h3>';
                echo '<div class="mygls-section-content">';
                echo do_shortcode('[mygls_parcelshop_selector]');
                echo '</div>';
                echo '</div>';
                return ob_get_clean();

            case 'order_notes':
                if (!apply_filters('woocommerce_enable_order_notes_field', 'yes' === get_option('woocommerce_enable_order_comments', 'yes'))) {
                    return '';
                }

                ob_start();
                echo '<div class="mygls-checkout-section mygls-section-notes">';
                echo '<h3 class="mygls-section-title">';
                echo '<span class="dashicons dashicons-edit"></span>';
                echo esc_html__('Megjegyzések a rendeléshez', 'mygls-woocommerce');
                echo '</h3>';
                echo '<div class="mygls-section-content">';
                foreach ($checkout->get_checkout_fields('order') as $key => $field) {
                    woocommerce_form_field($key, $field, $checkout->get_value($key));
                }
                echo '</div>';
                echo '</div>';
                return ob_get_clean();

            case 'payment':
                ob_start();
                echo '<div class="mygls-checkout-section mygls-section-payment">';
                echo '<h3 class="mygls-section-title">';
                echo '<span class="dashicons dashicons-money-alt"></span>';
                echo esc_html__('Fizetési mód', 'mygls-woocommerce');
                echo '</h3>';
                echo '<div class="mygls-section-content">';
                woocommerce_checkout_payment();
                echo '<div class="mygls-mobile-order-summary-anchor"></div>';
                echo $this->get_mobile_order_summary_markup();

                // Add privacy policy checkbox
                echo '<div class="mygls-privacy-checkbox-wrapper">';
                woocommerce_form_field('mygls_privacy_policy', [
                    'type' => 'checkbox',
                    'class' => ['form-row-wide', 'mygls-privacy-policy-checkbox'],
                    'label' => sprintf(
                        __('Elolvastam és elfogadom az %s', 'mygls-woocommerce'),
                        '<a href="' . esc_url(get_privacy_policy_url()) . '" target="_blank">' . __('adatkezelési tájékoztatót', 'mygls-woocommerce') . '</a>'
                    ),
                    'required' => true,
                ], $checkout->get_value('mygls_privacy_policy'));
                echo '</div>';

                echo '</div>';
                echo '</div>';
                return ob_get_clean();
        }

        return '';
    }

    private function get_mobile_order_summary_markup(): string {
        if (!function_exists('WC') || !WC()->cart) {
            return '';
        }

        $cart = WC()->cart;
        $item_count = $cart->get_cart_contents_count();
        $totals = $cart->get_totals();
        $subtotal = $totals['subtotal'] ?? 0;
        $shipping_total = ($totals['shipping_total'] ?? 0) + ($totals['shipping_tax'] ?? 0);
        $grand_total = $totals['total'] ?? 0;
        $fees = $cart->get_fees();

        $popup_id = 'mygls-cart-popup';

        ob_start();
        ?>
        <div class="mygls-mobile-order-summary" aria-hidden="false">
            <div class="mygls-mobile-order-summary-header">
                <span class="mygls-mobile-order-summary-title"><?php echo esc_html__('Rendelés összesítő', 'mygls-woocommerce'); ?></span>
                <div class="mygls-mobile-order-summary-actions">
                    <span class="mygls-mobile-order-summary-count">
                        <?php echo esc_html(sprintf(__('A kosárban %d tétel van.', 'mygls-woocommerce'), $item_count)); ?>
                    </span>
                    <button type="button" class="mygls-mobile-cart-link" data-mygls-cart-popup="<?php echo esc_attr($popup_id); ?>">
                        <?php echo esc_html__('Kosár tartalma', 'mygls-woocommerce'); ?>
                    </button>
                </div>
            </div>
            <div class="mygls-mobile-order-summary-totals">
                <div class="mygls-summary-line">
                    <span><?php echo esc_html__('Részösszeg', 'mygls-woocommerce'); ?></span>
                    <span><?php echo wp_kses_post(wc_price($subtotal)); ?></span>
                </div>
                <div class="mygls-summary-line">
                    <span><?php echo esc_html__('Szállítás', 'mygls-woocommerce'); ?></span>
                    <span><?php echo wp_kses_post(wc_price($shipping_total)); ?></span>
                </div>
                <?php if (!empty($fees)) : ?>
                    <?php foreach ($fees as $fee) : ?>
                        <div class="mygls-summary-line">
                            <span><?php echo esc_html($fee->name); ?></span>
                            <span><?php echo wp_kses_post(wc_price($fee->amount + $fee->tax)); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="mygls-summary-line mygls-summary-total">
                    <span><?php echo esc_html__('Összesen', 'mygls-woocommerce'); ?></span>
                    <span><?php echo wp_kses_post(wc_price($grand_total)); ?></span>
                </div>
            </div>
            <div class="mygls-cart-popup" id="<?php echo esc_attr($popup_id); ?>" aria-hidden="true" role="dialog" aria-modal="true">
                <div class="mygls-cart-popup__overlay" data-mygls-cart-popup-close></div>
                <div class="mygls-cart-popup__content" role="document">
                    <div class="mygls-cart-popup__header">
                        <h4><?php echo esc_html__('Kosár tartalma', 'mygls-woocommerce'); ?></h4>
                        <button type="button" class="mygls-cart-popup__close" data-mygls-cart-popup-close aria-label="<?php echo esc_attr__('Bezárás', 'mygls-woocommerce'); ?>">
                            ×
                        </button>
                    </div>
                    <ul class="mygls-cart-popup__items">
                        <?php foreach ($cart->get_cart() as $cart_item) : ?>
                            <?php
                            $product = $cart_item['data'] ?? null;
                            $quantity = $cart_item['quantity'] ?? 0;
                            if (!$product || $quantity < 1) {
                                continue;
                            }
                            $item_name = $product->get_name();
                            $item_total = $cart->get_product_subtotal($product, $quantity);
                            $item_thumbnail = $product->get_image('thumbnail', [
                                'class' => 'mygls-cart-popup__thumb',
                                'alt' => $item_name,
                            ]);
                            $attribute_values = [];
                            $variation = $cart_item['variation'] ?? [];
                            $attribute_groups = [
                                [
                                    'attribute_pa_termektipus',
                                    'attribute_termektipus',
                                    'pa_termektipus',
                                    'termektipus',
                                    'attribute_pa_type',
                                    'attribute_type',
                                    'pa_type',
                                    'type',
                                ],
                                [
                                    'attribute_pa_szin',
                                    'attribute_szin',
                                    'pa_szin',
                                    'szin',
                                    'attribute_pa_color',
                                    'attribute_color',
                                    'pa_color',
                                    'color',
                                ],
                                [
                                    'attribute_pa_meret',
                                    'attribute_meret',
                                    'pa_meret',
                                    'meret',
                                    'attribute_pa_size',
                                    'attribute_size',
                                    'pa_size',
                                    'size',
                                ],
                            ];

                            foreach ($attribute_groups as $attribute_keys) {
                                $value = '';
                                foreach ($attribute_keys as $attribute_key) {
                                    if (!isset($variation[$attribute_key]) || $variation[$attribute_key] === '') {
                                        continue;
                                    }

                                    $value = (string) $variation[$attribute_key];
                                    $taxonomy = str_replace('attribute_', '', $attribute_key);
                                    if (taxonomy_exists($taxonomy)) {
                                        $term = get_term_by('slug', $value, $taxonomy);
                                        if ($term && !is_wp_error($term)) {
                                            $value = $term->name;
                                        }
                                    }
                                    break;
                                }

                                if ($value !== '') {
                                    $attribute_values[] = $value;
                                }
                            }

                            $bonus_fee = '';
                            $item_data = wc_get_formatted_cart_item_data($cart_item, false);
                            if (is_array($item_data)) {
                                foreach ($item_data as $data) {
                                    $label = $data['key'] ?? '';
                                    if ($label && (stripos($label, 'bónusz') !== false || stripos($label, 'bonus') !== false)) {
                                        $bonus_fee = wp_strip_all_tags($data['display'] ?? '');
                                        break;
                                    }
                                }
                            }
                            ?>
                            <li class="mygls-cart-popup__item">
                                <div class="mygls-cart-popup__item-thumb"><?php echo wp_kses_post($item_thumbnail); ?></div>
                                <div class="mygls-cart-popup__item-details">
                                    <div class="mygls-cart-popup__item-name"><?php echo esc_html($item_name); ?></div>
                                    <div class="mygls-cart-popup__item-meta">
                                        <span class="mygls-cart-popup__item-qty"><?php echo esc_html(sprintf(__('Mennyiség: %d', 'mygls-woocommerce'), $quantity)); ?></span>
                                        <span class="mygls-cart-popup__item-total"><?php echo wp_kses_post($item_total); ?></span>
                                    </div>
                                    <?php if (!empty($attribute_values)) : ?>
                                        <div class="mygls-cart-popup__item-attributes">
                                            <?php echo esc_html(implode(', ', $attribute_values)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($bonus_fee !== '') : ?>
                                        <div class="mygls-cart-popup__item-bonus">
                                            <?php echo esc_html($bonus_fee); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php
                                ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Render shipping methods selection
     */
    private function render_shipping_methods() {
        echo $this->get_shipping_methods_markup();
    }

    private function get_shipping_methods_markup(): string {
        if (!function_exists('WC') || !WC()->cart) {
            return '<div id="mygls-shipping-methods"></div>';
        }

        $needs_shipping = WC()->cart->needs_shipping();
        $show_shipping = WC()->cart->show_shipping();
        $enabled_methods = $this->settings['parcelshop_enabled_methods'] ?? [];

        ob_start();

        echo '<div id="mygls-shipping-methods">';

        if (!$needs_shipping || !$show_shipping) {
            echo '<p class="mygls-no-shipping">' . esc_html__('Ehhez a rendeléshez nincs szükség szállításra.', 'mygls-woocommerce') . '</p>';
            echo '</div>';
            return ob_get_clean();
        }

        $packages = WC()->shipping()->get_packages();
        $chosen_methods = [];

        if (WC()->session) {
            $chosen_methods = (array) WC()->session->get('chosen_shipping_methods', []);
        }

        if (empty($packages)) {
            echo '<p class="mygls-no-shipping">' . esc_html__('Jelenleg nincsenek elérhető szállítási módok.', 'mygls-woocommerce') . '</p>';
            echo '</div>';
            return ob_get_clean();
        }

        $multiple_packages = count($packages) > 1;

        foreach ($packages as $package_index => $package) {
            $available_methods = $package['rates'] ?? [];

            if (empty($available_methods)) {
                echo '<p class="mygls-no-shipping">' . esc_html__('Jelenleg nincsenek elérhető szállítási módok.', 'mygls-woocommerce') . '</p>';
                continue;
            }

            $available_count = count($available_methods);
            $chosen_for_package = $chosen_methods[$package_index] ?? '';

            if ($multiple_packages) {
                $package_name = apply_filters('woocommerce_shipping_package_name', sprintf(__('Csomag %d', 'mygls-woocommerce'), $package_index + 1), $package_index, $package);
                echo '<p class="shipping-package-title">' . esc_html($package_name) . '</p>';
            }

            $list_id = $package_index === 0 ? 'shipping_method' : sprintf('shipping_method_%d', $package_index);

            echo '<ul class="woocommerce-shipping-methods" id="' . esc_attr($list_id) . '" data-package-index="' . esc_attr((string) $package_index) . '">';

            foreach ($available_methods as $rate_id => $method) {
                $method_id = method_exists($method, 'get_id') ? $method->get_id() : $rate_id;
                $sanitized_id = sanitize_title($method_id);
                $input_id = sprintf('shipping_method_%d_%s', $package_index, $sanitized_id);
                $is_checked = checked($method_id, $chosen_for_package, false);
                $is_parcelshop_rate = $this->is_parcelshop_method($method_id, $enabled_methods);

                if ('' === $chosen_for_package && $available_count === 1) {
                    $is_checked = 'checked="checked"';
                }

                $li_classes = [];
                if ($is_parcelshop_rate) {
                    $li_classes[] = 'mygls-shipping-method--parcelshop';
                }

                echo '<li' . (!empty($li_classes) ? ' class="' . esc_attr(implode(' ', $li_classes)) . '"' : '') . '>';
                printf(
                    '<input type="radio" name="shipping_method[%1$s]" data-index="%1$s" id="%2$s" value="%3$s" class="shipping_method" data-parcelshop="%5$s" %4$s />',
                    esc_attr((string) $package_index),
                    esc_attr($input_id),
                    esc_attr($method_id),
                    $is_checked,
                    esc_attr($is_parcelshop_rate ? '1' : '0')
                );

                $label = function_exists('wc_cart_totals_shipping_method_label')
                    ? wc_cart_totals_shipping_method_label($method)
                    : $method->get_label();

                // Get method logo from GLS admin settings
                $logo_html = '';
                $method_logos = $this->settings['shipping_method_logos'] ?? [];
                $logo_url = $method_logos[$method_id] ?? '';
                if (!empty($logo_url)) {
                    $logo_html = sprintf(
                        '<img src="%s" alt="" class="mygls-shipping-method-logo" />',
                        esc_url($logo_url)
                    );
                }

                printf(
                    '<label for="%1$s">%2$s%3$s</label>',
                    esc_attr($input_id),
                    $logo_html,
                    wp_kses_post($label)
                );

                if (method_exists($method, 'get_method_description')) {
                    $description = $method->get_method_description();
                    if (!empty($description)) {
                        echo '<p class="shipping-method-description">' . wp_kses_post($description) . '</p>';
                    }
                }

                do_action('woocommerce_after_shipping_rate', $method, $package_index);
                echo '</li>';
            }

            echo '</ul>';
        }

        echo '</div>';

        return ob_get_clean();
    }

    public function register_checkout_fragments($fragments) {
        $fragments['div#mygls-shipping-methods'] = $this->get_shipping_methods_markup();
        $fragments['#mygls-section-wrapper-shipping'] = $this->get_section_wrapper_markup('shipping');
        $fragments['#mygls-section-wrapper-parcelshop'] = $this->get_section_wrapper_markup('parcelshop');
        $fragments['#mygls-section-wrapper-payment'] = $this->get_section_wrapper_markup('payment');

        return $fragments;
    }

    public function maybe_hide_inline_parcelshop_selector($show, $method) {
        if (!$this->enabled) {
            return $show;
        }

        return false;
    }

    /**
     * Enqueue custom checkout styles
     */
    public function enqueue_custom_styles() {
        if (!is_checkout()) {
            return;
        }

        // Load settings fresh to ensure we get the latest value
        $current_settings = get_option('mygls_settings', []);
        $logo_size = absint($current_settings['shipping_logo_size'] ?? 40);
        if ($logo_size < 20) $logo_size = 20;
        if ($logo_size > 100) $logo_size = 100;

        // Add inline styles for custom checkout
        $custom_css = "
            /* Custom Checkout Layout */
            .mygls-custom-checkout-container {
                display: grid;
                grid-template-columns: 1fr 400px;
                gap: 30px;
                margin: 20px 0;
            }

            .mygls-checkout-sections {
                display: flex;
                flex-direction: column;
                gap: 20px;
            }

            .mygls-section-wrapper {
                display: block;
            }

            .mygls-section-wrapper--empty,
            .mygls-section-wrapper--hidden {
                display: none;
            }

            .mygls-section-loading {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 120px;
                background: #f9f9f9;
            }

            .mygls-section-loading .mygls-section-content {
                width: 100%;
                text-align: center;
            }

            .mygls-loading-message {
                margin: 0;
                font-size: 14px;
                color: #4a5568;
            }

            .mygls-checkout-section {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 0;
                overflow: hidden;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
                transition: all 0.3s ease;
            }

            .mygls-custom-checkout-active .mygls-checkout-section .form-row,
            .mygls-custom-checkout-active .mygls-checkout-section .form-row-first,
            .mygls-custom-checkout-active .mygls-checkout-section .form-row-last {
                float: none;
                width: 100%;
            }

            .mygls-custom-checkout-active .mygls-checkout-section .form-row .woocommerce-input-wrapper,
            .mygls-custom-checkout-active .mygls-checkout-section .form-row input.input-text,
            .mygls-custom-checkout-active .mygls-checkout-section .form-row select,
            .mygls-custom-checkout-active .mygls-checkout-section .form-row textarea {
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }

            .mygls-custom-checkout-active .mygls-checkout-section .form-row[class*="ast-column-"],
            .mygls-custom-checkout-active .mygls-checkout-section .form-row-full {
                width: 100% !important;
                display: block;
                float: none;
            }

            .mygls-section-hidden,
            .mygls-section-disabled {
                display: none;
            }

            .mygls-checkout-section:hover {
                box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            }

            .mygls-section-title {
                margin: 0;
                padding: 15px 20px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #fff;
                font-size: 16px;
                font-weight: 600;
                display: flex;
                align-items: center;
                gap: 10px;
                border-bottom: 2px solid rgba(255,255,255,0.2);
            }

            .mygls-section-title .dashicons {
                font-size: 20px;
                width: 20px;
                height: 20px;
            }

            .mygls-section-content {
                padding: 20px;
            }

            /* Shipping Methods Styling */
            .mygls-section-shipping-method .woocommerce-shipping-methods {
                list-style: none;
                padding: 0;
                margin: 0;
            }

            .mygls-section-shipping-method .woocommerce-shipping-methods li {
                padding: 15px 18px;
                margin-bottom: 20px !important;
                background: #f9f9f9;
                border: 2px solid #e0e0e0;
                border-radius: 6px;
                transition: all 0.2s ease;
                cursor: pointer;
            }

            .mygls-section-shipping-method .woocommerce-shipping-methods li:last-child {
                margin-bottom: 0 !important;
            }

            /* DEBUG: Logo size from DB: {$logo_size}px */
            /* DEBUG: Settings array has shipping_logo_size: " . (isset($current_settings['shipping_logo_size']) ? 'YES' : 'NO') . " */
            .mygls-shipping-method-logo {
                display: inline-block !important;
                max-width: {$logo_size}px !important;
                max-height: {$logo_size}px !important;
                min-width: unset !important;
                min-height: unset !important;
                width: {$logo_size}px !important;
                height: auto !important;
                margin-right: 10px !important;
                vertical-align: middle !important;
                object-fit: contain !important;
            }

            /* Extra specificity for logo size */
            .mygls-section-shipping-method label .mygls-shipping-method-logo,
            .mygls-section-shipping-method .woocommerce-shipping-methods li label img,
            img.mygls-shipping-method-logo {
                max-width: {$logo_size}px !important;
                max-height: {$logo_size}px !important;
                width: {$logo_size}px !important;
            }

            .mygls-section-shipping-method .woocommerce-shipping-methods li:hover {
                background: #f0f0f0;
                border-color: #667eea;
            }

            .mygls-section-shipping-method .woocommerce-shipping-methods li input[type=\"radio\"] {
                margin-right: 10px;
            }

            .mygls-section-shipping-method .woocommerce-shipping-methods li input[type=\"radio\"]:checked + label {
                font-weight: 600;
                color: #667eea;
            }

            .mygls-section-shipping-method .woocommerce-shipping-methods li.woocommerce-shipping-method-selected {
                background: #f0f4ff;
                border-color: #667eea;
                box-shadow: 0 2px 4px rgba(102, 126, 234, 0.1);
            }


            .mygls-section-shipping-method .shipping-package-title {
                margin: 0 0 8px 0;
                font-size: 14px;
                font-weight: 600;
                color: #2d3748;
            }

            .mygls-section-shipping-method .woocommerce-shipping-methods label {
                cursor: pointer;
                display: inline-block;
                width: calc(100% - 30px);
            }

            .mygls-section-shipping-method .shipping-method-description {
                margin: 6px 0 0 32px;
                font-size: 13px;
                color: #4a5568;
            }

            .mygls-section-shipping-method .woocommerce-shipping-methods .amount {
                font-weight: 600;
                color: #2c3e50;
            }

            /* Privacy policy checkbox in payment section */
            .mygls-privacy-checkbox-wrapper {
                margin-top: 20px;
                padding: 15px;
                background: #f0f4ff;
                border: 1px solid #667eea;
                border-radius: 6px;
            }

            .mygls-privacy-policy-checkbox label {
                font-size: 14px;
                line-height: 1.6;
            }

            .mygls-privacy-policy-checkbox a {
                color: #667eea;
                text-decoration: underline;
                font-weight: 600;
            }

            .mygls-privacy-policy-checkbox a:hover {
                color: #764ba2;
            }

            /* Mobile order summary inside payment section */
            .mygls-mobile-order-summary {
                display: none;
                margin-top: 16px;
                padding: 16px;
                background: #f9fafb;
                border: 1px solid #e5e7eb;
                border-radius: 10px;
            }

            .mygls-mobile-order-summary-header {
                display: flex;
                flex-direction: column;
                gap: 6px;
                margin-bottom: 12px;
            }

            .mygls-mobile-order-summary-actions {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                flex-wrap: wrap;
            }

            .mygls-mobile-order-summary-title {
                font-weight: 700;
                font-size: 15px;
                color: #111827;
            }

            .mygls-mobile-order-summary-count {
                font-size: 13px;
                color: #6b7280;
            }

            .mygls-mobile-cart-link {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 8px 12px;
                font-size: 13px;
                font-weight: 600;
                color: #667eea;
                background: transparent;
                border: 1px solid #c7d2fe;
                border-radius: 6px;
                cursor: pointer;
            }

            .mygls-mobile-cart-link:hover {
                background: #eef2ff;
            }

            .mygls-mobile-order-summary-totals {
                margin-top: 14px;
                display: flex;
                flex-direction: column;
                gap: 8px;
            }

            .mygls-summary-line {
                display: flex;
                justify-content: space-between;
                font-size: 13px;
                color: #374151;
            }

            .mygls-summary-total {
                font-weight: 700;
                font-size: 14px;
                color: #111827;
            }

            .mygls-cart-popup {
                position: fixed;
                inset: 0;
                display: none;
                align-items: center;
                justify-content: center;
                z-index: 9999;
            }

            .mygls-cart-popup.is-active {
                display: flex;
            }

            .mygls-cart-popup__overlay {
                position: absolute;
                inset: 0;
                background: rgba(15, 23, 42, 0.55);
            }

            .mygls-cart-popup__content {
                position: relative;
                width: min(92vw, 420px);
                max-height: 80vh;
                overflow: auto;
                background: #fff;
                border-radius: 12px;
                padding: 18px;
                z-index: 1;
                box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            }

            .mygls-cart-popup__header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 12px;
            }

            .mygls-cart-popup__header h4 {
                margin: 0;
                font-size: 16px;
                font-weight: 700;
                color: #111827;
            }

            .mygls-cart-popup__close {
                background: transparent;
                border: none;
                font-size: 22px;
                line-height: 1;
                cursor: pointer;
                color: #6b7280;
            }

            .mygls-cart-popup__items {
                list-style: none;
                margin: 0;
                padding: 0;
                display: flex;
                flex-direction: column;
                gap: 12px;
            }

            .mygls-cart-popup__item {
                display: grid;
                grid-template-columns: 56px 1fr;
                gap: 12px;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                padding: 12px;
            }

            .mygls-cart-popup__thumb {
                width: 56px;
                height: 56px;
                border-radius: 8px;
                object-fit: cover;
                background: #f3f4f6;
            }

            .mygls-cart-popup__item-thumb {
                display: flex;
                align-items: flex-start;
                justify-content: center;
            }

            .mygls-cart-popup__item-details {
                display: flex;
                flex-direction: column;
                gap: 6px;
            }

            .mygls-cart-popup__item-name {
                font-weight: 600;
                color: #111827;
                margin-bottom: 6px;
            }

            .mygls-cart-popup__item-meta {
                display: flex;
                justify-content: space-between;
                font-size: 13px;
                color: #4b5563;
            }

            .mygls-cart-popup__item-attributes {
                font-size: 12px;
                color: #6b7280;
            }

            .mygls-cart-popup__item-bonus {
                font-size: 12px;
                color: #111827;
                font-weight: 600;
            }

            body.mygls-cart-popup-open {
                overflow: hidden;
            }

            /* Same as Billing Checkbox */
            .mygls-same-as-billing-wrapper {
                margin-bottom: 20px;
                padding: 15px;
                background: #f0f4ff;
                border: 2px solid #667eea;
                border-radius: 6px;
            }

            .mygls-same-as-billing-label {
                display: flex;
                align-items: center;
                gap: 10px;
                cursor: pointer;
                font-size: 15px;
                font-weight: 600;
                color: #2d3748;
                margin: 0;
            }

            .mygls-same-as-billing-label input[type=\"checkbox\"] {
                width: 20px;
                height: 20px;
                cursor: pointer;
                margin: 0;
            }

            .mygls-same-as-billing-label span {
                user-select: none;
            }

            /* Disabled shipping fields styling */
            .mygls-shipping-fields-wrap[aria-disabled=\"true\"] {
                opacity: 0.6;
                pointer-events: none;
            }

            .mygls-shipping-fields-wrap.mygls-disabled input,
            .mygls-shipping-fields-wrap.mygls-disabled textarea,
            .mygls-shipping-fields-wrap.mygls-disabled select,
            .mygls-shipping-fields-wrap .mygls-field-disabled,
            .mygls-shipping-fields-wrap .mygls-field-disabled .select2-selection,
            .mygls-shipping-fields-wrap .mygls-field-disabled .select2-selection__rendered,
            .mygls-shipping-fields-wrap .mygls-field-disabled .select2-selection__arrow {
                background-color: #f5f5f5 !important;
                color: #999 !important;
                cursor: not-allowed !important;
                pointer-events: none !important;
            }

            .mygls-shipping-fields-wrap .mygls-field-disabled .select2-selection__rendered {
                color: #999 !important;
            }

            /* Order Review Sidebar - Ultra Modern Clean Design */
            .mygls-order-review-sidebar {
                position: sticky;
                top: 20px;
                height: fit-content;
            }

            .mygls-order-review {
                background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
                border: none;
                border-radius: 20px;
                overflow: hidden;
                box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            }

            .mygls-order-review-title {
                margin: 0;
                padding: 24px 28px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #fff;
                font-size: 18px;
                font-weight: 800;
                display: flex;
                align-items: center;
                gap: 12px;
                letter-spacing: -0.03em;
                text-transform: uppercase;
                font-size: 14px;
            }

            .mygls-order-review-title .dashicons {
                font-size: 24px;
                width: 24px;
                height: 24px;
                opacity: 0.9;
            }

            .mygls-order-review-content {
                padding: 0;
            }

            .mygls-order-review-shipping-line {
                display: flex;
                justify-content: space-between;
                gap: 12px;
                font-size: 14px;
                line-height: 1.6;
            }

            .mygls-order-review-shipping-line + .mygls-order-review-shipping-line {
                margin-top: 6px;
            }

            .mygls-order-review-shipping-line .woocommerce-Price-amount {
                font-weight: 600;
                color: #2d3748;
            }

            /* Modern Order Review Table - No Borders */
            .mygls-order-review-sidebar .shop_table,
            .woocommerce-checkout-review-order-table {
                border: none !important;
                margin: 0 !important;
                background: transparent !important;
            }

            .woocommerce-checkout-review-order-table thead {
                display: none !important;
            }

            .woocommerce-checkout-review-order-table tbody tr {
                border: none !important;
                background: transparent !important;
            }

            .woocommerce-checkout-review-order-table td {
                padding: 18px 28px !important;
                border: none !important;
                background: transparent !important;
            }

            .woocommerce-checkout-review-order-table .product-name {
                font-size: 15px;
                font-weight: 600;
                color: #1f2937;
                line-height: 1.6;
            }

            .woocommerce-checkout-review-order-table .product-name .product-quantity {
                display: inline-block;
                margin-left: 10px;
                color: #9ca3af;
                font-weight: 500;
                font-size: 14px;
            }

            .woocommerce-checkout-review-order-table .product-total {
                text-align: right;
                font-weight: 700;
                color: #111827;
                font-size: 15px;
            }

            /* Modern Footer Rows - Clean, No Borders */
            .woocommerce-checkout-review-order-table tfoot {
                background: transparent !important;
            }

            .woocommerce-checkout-review-order-table tfoot tr {
                border: none !important;
                background: transparent !important;
            }

            .woocommerce-checkout-review-order-table tfoot tr:last-child {
                background: linear-gradient(135deg, rgba(102, 126, 234, 0.08) 0%, rgba(118, 75, 162, 0.08) 100%) !important;
                border-radius: 12px;
                margin: 8px 20px;
                display: table;
                width: calc(100% - 40px) !important;
            }

            .woocommerce-checkout-review-order-table tfoot tr:last-child th,
            .woocommerce-checkout-review-order-table tfoot tr:last-child td {
                display: table-cell !important;
                border: none !important;
                vertical-align: middle;
                white-space: nowrap;
            }

            .woocommerce-checkout-review-order-table tfoot th,
            .woocommerce-checkout-review-order-table tfoot td {
                padding: 16px 28px !important;
                font-size: 14px;
                border: none !important;
                background: transparent !important;
            }

            .woocommerce-checkout-review-order-table tfoot th {
                font-weight: 500;
                color: #6b7280;
                text-align: left;
            }

            .woocommerce-checkout-review-order-table tfoot td {
                text-align: right;
                font-weight: 700;
                color: #374151;
            }

            /* Total Row - Special Ultra Modern Styling */
            .woocommerce-checkout-review-order-table tfoot .order-total th {
                font-size: 17px;
                font-weight: 800;
                color: #111827;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }

            .woocommerce-checkout-review-order-table tfoot .order-total td {
                font-size: 22px;
                font-weight: 800;
                color: #667eea;
                letter-spacing: -0.02em;
            }

            /* Product thumbnails in order review - Completely Hidden */
            .woocommerce-checkout-review-order-table .product-thumbnail {
                display: none !important;
            }

            .mygls-order-review-content #order_review {
                display: block;
                width: 100%;
            }

            .mygls-order-review-content .woocommerce-checkout-review-order {
                display: block;
                width: 100%;
            }

            .woocommerce-checkout-review-order-table {
                width: 100%;
                display: table;
            }

            /* Hide default WooCommerce elements when custom checkout is active */
            .mygls-custom-checkout-active .woocommerce-billing-fields,
            .mygls-custom-checkout-active .woocommerce-shipping-fields,
            .mygls-custom-checkout-active .woocommerce-additional-fields {
                display: none !important;
            }

            /* Hide shipping method selection from order review sidebar (but NOT shipping cost row) */
            .mygls-custom-checkout-active .mygls-order-review-sidebar ul.woocommerce-shipping-methods,
            .mygls-custom-checkout-active .mygls-order-review-sidebar .wc_payment_methods,
            .mygls-custom-checkout-active .mygls-order-review-sidebar .woocommerce-checkout-payment,
            .mygls-custom-checkout-active .mygls-order-review-sidebar .woocommerce-privacy-policy-text,
            .mygls-custom-checkout-active .mygls-order-review-sidebar .woocommerce-terms-and-conditions-wrapper {
                display: none !important;
            }

            /* Ensure shipping cost row is visible */
            .woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals,
            .woocommerce-checkout-review-order-table tr.shipping {
                display: table-row !important;
            }

            /* Hide WooCommerce default privacy policy text in payment section */
            .mygls-section-payment .woocommerce-privacy-policy-text {
                display: none !important;
            }

            /* Ensure order review table is always visible */
            .mygls-order-review-sidebar .shop_table,
            .mygls-order-review-sidebar .woocommerce-checkout-review-order-table,
            .mygls-order-review-content table.shop_table {
                display: table !important;
                width: 100% !important;
                visibility: visible !important;
                opacity: 1 !important;
                border-collapse: collapse !important;
            }

            .mygls-order-review-sidebar .shop_table thead,
            .mygls-order-review-sidebar .shop_table tbody,
            .mygls-order-review-sidebar .shop_table tfoot,
            .mygls-order-review-content table.shop_table thead,
            .mygls-order-review-content table.shop_table tbody,
            .mygls-order-review-content table.shop_table tfoot {
                display: table-row-group !important;
            }

            .mygls-order-review-sidebar .shop_table tr,
            .mygls-order-review-content table.shop_table tr {
                display: table-row !important;
            }

            .mygls-order-review-sidebar .shop_table th,
            .mygls-order-review-sidebar .shop_table td,
            .mygls-order-review-content table.shop_table th,
            .mygls-order-review-content table.shop_table td {
                display: table-cell !important;
                padding: 10px !important;
            }

            /* Place order button visibility */
            /* Show in payment section always */
            .mygls-section-payment #place_order,
            .mygls-section-payment .place-order {
                display: block !important;
                width: 100% !important;
                margin-top: 20px !important;
            }

            /* Hide in order review sidebar */
            .mygls-order-review-sidebar #place_order,
            .mygls-order-review-sidebar .place-order,
            .mygls-order-review-sidebar .woocommerce-checkout-payment #place_order {
                display: none !important;
            }

            /* Hide back to cart link in payment section */
            .mygls-section-payment .woocommerce-button--previous,
            .mygls-section-payment a.woocommerce-button--previous {
                display: none !important;
            }

            /* Responsive adjustments */
            @media (max-width: 992px) {
                .mygls-custom-checkout-container {
                    grid-template-columns: 1fr;
                    margin: 0;
                    width: 100%;
                }

                .mygls-checkout-sections,
                .mygls-checkout-section {
                    width: 100%;
                }

                /* Hide order summary completely on mobile */
                .mygls-order-review-sidebar {
                    display: none !important;
                }

                .mygls-mobile-order-summary {
                    display: block;
                }

                .mygls-mobile-order-summary-totals,
                .mygls-summary-line {
                    font-size: 13px;
                }

                .mygls-cart-popup__content {
                    width: 100vw;
                    max-width: 100vw;
                    max-height: 85vh;
                    border-radius: 0;
                }
            }

            @media (max-width: 768px) {
                .mygls-checkout-section {
                    border-radius: 4px;
                }

                .mygls-section-title {
                    padding: 12px 15px;
                    font-size: 14px;
                }

                .mygls-section-content {
                    padding: 15px;
                }
            }

        ";

        wp_add_inline_style('mygls-frontend-css', $custom_css);
    }

    /**
     * Enqueue custom checkout scripts
     */
    public function enqueue_custom_scripts() {
        if (!is_checkout()) {
            return;
        }

        wp_register_script(
            'mygls-custom-checkout',
            MYGLS_PLUGIN_URL . 'assets/js/custom-checkout.js',
            ['jquery', 'wc-checkout'],
            MYGLS_VERSION,
            true
        );

        $settings = [
            'loadingMessage'    => __('Betöltés...', 'mygls-woocommerce'),
            'placeholderMarkup' => '<div class="mygls-checkout-section mygls-section-loading"><div class="mygls-section-content"><p class="mygls-loading-message"></p></div></div>',
        ];

        wp_localize_script('mygls-custom-checkout', 'myglsCustomCheckout', $settings);
        wp_enqueue_script('mygls-custom-checkout');
    }

    private function get_configured_field_order(): array {
        $configured = $this->settings['checkout_field_order'] ?? $this->default_field_order;

        if (!is_array($configured)) {
            $configured = $this->default_field_order;
        }

        $allowed_sections = $this->default_field_order;
        $normalised_order = array_values(array_intersect($configured, $allowed_sections));

        foreach ($allowed_sections as $section) {
            if (!in_array($section, $normalised_order, true)) {
                $normalised_order[] = $section;
            }
        }

        return $normalised_order;
    }

    private function is_parcelshop_delivery_selected(): bool {
        if (!function_exists('WC') || !WC()->session) {
            return false;
        }

        if (!WC()->cart || !WC()->cart->needs_shipping()) {
            return false;
        }

        $shipping = WC()->shipping();
        if (!$shipping) {
            return false;
        }

        $enabled_methods = $this->settings['parcelshop_enabled_methods'] ?? [];
        if (empty($enabled_methods)) {
            return false;
        }

        $chosen_methods = (array) WC()->session->get('chosen_shipping_methods', []);

        foreach ($chosen_methods as $chosen_method) {
            if ($this->is_parcelshop_method($chosen_method, $enabled_methods)) {
                return true;
            }
        }

        $packages = $shipping->get_packages();
        foreach ($packages as $package) {
            $available_methods = $package['rates'] ?? [];
            if (count($available_methods) !== 1) {
                continue;
            }

            $method = reset($available_methods);
            if (is_object($method) && method_exists($method, 'get_id')) {
                if ($this->is_parcelshop_method($method->get_id(), $enabled_methods)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function is_parcelshop_method(string $method_id, array $enabled_methods): bool {
        foreach ($enabled_methods as $enabled_method) {
            if ($method_id === $enabled_method || strpos($method_id, $enabled_method) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate privacy policy checkbox
     */
    public function validate_privacy_checkbox() {
        if (empty($_POST['mygls_privacy_policy'])) {
            wc_add_notice(__('Kérjük, fogadja el az adatkezelési tájékoztatót a folytatáshoz.', 'mygls-woocommerce'), 'error');
        }
    }

    /**
     * Save privacy policy checkbox value to order meta
     */
    public function save_privacy_checkbox($order_id) {
        if (!empty($_POST['mygls_privacy_policy'])) {
            update_post_meta($order_id, '_mygls_privacy_policy_accepted', '1');
            update_post_meta($order_id, '_mygls_privacy_policy_accepted_date', current_time('mysql'));
        }
    }

    /**
     * Format prices as integers (no decimals) on checkout page
     */
    public function format_checkout_price_args($args) {
        if (is_checkout()) {
            $args['decimals'] = 0;
        }
        return $args;
    }
}
