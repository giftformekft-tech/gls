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
                foreach ($checkout->get_checkout_fields('shipping') as $key => $field) {
                    woocommerce_form_field($key, $field, $checkout->get_value($key));
                }
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
                echo '</div>';
                echo '</div>';
                return ob_get_clean();
        }

        return '';
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

                printf(
                    '<label for="%1$s">%2$s</label>',
                    esc_attr($input_id),
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
                padding: 12px 15px;
                margin-bottom: 10px;
                background: #f9f9f9;
                border: 2px solid #e0e0e0;
                border-radius: 6px;
                transition: all 0.2s ease;
                cursor: pointer;
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

            /* Order Review Sidebar */
            .mygls-order-review-sidebar {
                position: sticky;
                top: 20px;
                height: fit-content;
            }

            .mygls-order-review {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            }

            .mygls-order-review-title {
                margin: 0;
                padding: 15px 20px;
                background: linear-gradient(135deg, #2271b1 0%, #135e96 100%);
                color: #fff;
                font-size: 16px;
                font-weight: 600;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .mygls-order-review-content {
                padding: 20px;
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

            /* Hide shipping and payment methods from order review sidebar */
            .mygls-custom-checkout-active .mygls-order-review-sidebar #shipping_method,
            .mygls-custom-checkout-active .mygls-order-review-sidebar .woocommerce-shipping-methods,
            .mygls-custom-checkout-active .mygls-order-review-sidebar .wc_payment_methods {
                display: none !important;
            }

            /* Hide place order button from payment section on desktop (keep it in sidebar) */
            .mygls-section-payment #place_order {
                display: none !important;
            }

            /* Responsive adjustments */
            @media (max-width: 992px) {
                .mygls-custom-checkout-container {
                    grid-template-columns: 1fr;
                    display: flex;
                    flex-direction: column;
                }

                .mygls-checkout-main {
                    order: 1;
                }

                /* Order summary goes to bottom on mobile */
                .mygls-order-review-sidebar {
                    position: relative;
                    top: 0;
                    order: 2;
                    margin-top: 20px;
                    margin-bottom: 0;
                }

                /* Show place order button in payment section on mobile */
                .mygls-section-payment #place_order {
                    display: block !important;
                }

                /* Hide place order button in order review sidebar on mobile */
                .mygls-order-review-sidebar #place_order,
                .mygls-order-review-sidebar .place-order {
                    display: none !important;
                }

                .mygls-order-review-content {
                    display: block !important;
                    visibility: visible !important;
                    opacity: 1 !important;
                }

                .woocommerce-checkout-review-order-table {
                    display: table !important;
                    width: 100%;
                    table-layout: auto !important;
                }

                .woocommerce-checkout-review-order-table thead,
                .woocommerce-checkout-review-order-table tbody,
                .woocommerce-checkout-review-order-table tfoot,
                .woocommerce-checkout-review-order-table tr,
                .woocommerce-checkout-review-order-table th,
                .woocommerce-checkout-review-order-table td {
                    display: table-cell !important;
                    visibility: visible !important;
                    opacity: 1 !important;
                }

                .woocommerce-checkout-review-order-table thead {
                    display: table-header-group !important;
                }

                .woocommerce-checkout-review-order-table tbody {
                    display: table-row-group !important;
                }

                .woocommerce-checkout-review-order-table tfoot {
                    display: table-footer-group !important;
                }

                .woocommerce-checkout-review-order-table tr {
                    display: table-row !important;
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

            /* Product thumbnails in order review */
            .woocommerce-checkout-review-order-table .product-thumbnail {
                width: 80px;
                text-align: center;
            }

            .woocommerce-checkout-review-order-table .product-thumbnail img {
                width: 60px;
                height: 60px;
                object-fit: cover;
                border-radius: 4px;
                border: 1px solid #e0e0e0;
            }

            .woocommerce-checkout-review-order-table .product-name {
                padding-left: 10px;
            }

            .woocommerce-checkout-review-order-table .product-quantity {
                color: #666;
                font-weight: normal;
            }

            @media (max-width: 768px) {
                .woocommerce-checkout-review-order-table .product-thumbnail {
                    width: 60px;
                }

                .woocommerce-checkout-review-order-table .product-thumbnail img {
                    width: 50px;
                    height: 50px;
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

        // Add inline script to handle dynamic shipping/parcelshop toggle
        $loading_message = esc_js(__('Betöltés...', 'mygls-woocommerce'));
        $placeholder_markup = wp_json_encode('<div class="mygls-checkout-section mygls-section-loading"><div class="mygls-section-content"><p class="mygls-loading-message"></p></div></div>');
        $inline_js = "
        jQuery(function($) {
            var loadingMessage = '{$loading_message}';
            var placeholderMarkup = {$placeholder_markup};

            function highlightSelectedShippingMethod() {
                var $lists = $('.mygls-section-shipping-method .woocommerce-shipping-methods');
                $lists.find('li').removeClass('woocommerce-shipping-method-selected');
                $lists.find('input[type=\"radio\"]:checked').closest('li').addClass('woocommerce-shipping-method-selected');
            }

            function toggleWrapper($wrapper, shouldHide) {
                if (!$wrapper.length) {
                    return;
                }

                if (!shouldHide) {
                    $wrapper.removeClass('mygls-section-wrapper--empty');

                    if (!$wrapper.children().length) {
                        $wrapper.html(placeholderMarkup);
                        $wrapper.find('.mygls-loading-message').text(loadingMessage);
                        $wrapper.addClass('mygls-section-wrapper--loading');
                    }
                } else {
                    $wrapper.removeClass('mygls-section-wrapper--loading');
                }

                $wrapper.toggleClass('mygls-section-wrapper--hidden', shouldHide);
            }

            function setSectionVisibility() {
                var $selected = $('.mygls-section-shipping-method input[type=\"radio\"]:checked');
                var isParcelshop = false;

                if ($selected.length) {
                    var dataValue = $selected.data('parcelshop');
                    isParcelshop = dataValue === 1 || dataValue === '1';
                }

                var $shippingWrapper = $('#mygls-section-wrapper-shipping');
                var $parcelshopWrapper = $('#mygls-section-wrapper-parcelshop');

                toggleWrapper($shippingWrapper, isParcelshop);
                toggleWrapper($parcelshopWrapper, !isParcelshop);
            }

            function requestCheckoutRefresh() {
                $('body').trigger('update_checkout');
            }

            $(document.body).on('change', 'input[name^=\"shipping_method\"]', function() {
                highlightSelectedShippingMethod();
                setSectionVisibility();
                requestCheckoutRefresh();
            });

            $(document.body).on('updated_checkout', function() {
                highlightSelectedShippingMethod();
                setSectionVisibility();
                ensureOrderReviewVisible();
            });

            function ensureOrderReviewVisible() {
                // Ensure order review is visible on mobile
                if ($(window).width() <= 992) {
                    $('.mygls-order-review-content').css({
                        'display': 'block',
                        'visibility': 'visible',
                        'opacity': '1'
                    });
                    $('.woocommerce-checkout-review-order-table').css('display', 'table');
                }
            }

            highlightSelectedShippingMethod();
            setSectionVisibility();
            ensureOrderReviewVisible();

            // Re-check on window resize
            $(window).on('resize', function() {
                ensureOrderReviewVisible();
            });
        });
        ";

        wp_add_inline_script('wc-checkout', $inline_js);
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
}
