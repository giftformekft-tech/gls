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
    private array $field_priorities = [];
    private array $default_field_order = ['billing', 'shipping_method', 'shipping', 'parcelshop', 'order_notes', 'payment'];
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

        // Ensure the shipping method selector is always shown when shipping is required.
        if (function_exists('WC') && WC()->cart && WC()->cart->needs_shipping()) {
            if (!in_array('shipping_method', $field_order, true)) {
                $shipping_position = array_search('shipping', $field_order, true);

                if ($shipping_position === false) {
                    $field_order[] = 'shipping_method';
                } else {
                    array_splice($field_order, $shipping_position, 0, 'shipping_method');
                }
            }
        }

        foreach ($field_order as $section) {
            $this->render_section($section);
        }
    }

    /**
     * Render individual checkout section
     */
    private function render_section($section) {
        if (!function_exists('WC')) {
            return;
        }

        $checkout = WC()->checkout();

        switch ($section) {
            case 'billing':
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
                break;

            case 'shipping_method':
                // Safety check: Ensure WooCommerce is available
                if (!WC()->cart) {
                    return;
                }

                // Only show if cart needs shipping
                if (WC()->cart->needs_shipping() && WC()->cart->show_shipping()) {
                    echo '<div class="mygls-checkout-section mygls-section-shipping-method">';
                    echo '<h3 class="mygls-section-title">';
                    echo '<span class="dashicons dashicons-car"></span>';
                    echo esc_html__('Szállítási mód', 'mygls-woocommerce');
                    echo '</h3>';
                    echo '<div class="mygls-section-content">';
                    $this->render_shipping_methods();
                    echo '</div>';
                    echo '</div>';
                }
                break;

            case 'shipping':
                // Safety check: Ensure WooCommerce session is available
                if (!WC()->session) {
                    return;
                }

                // Only show shipping address if NOT parcelshop AND shipping is needed
                if (!$this->is_parcelshop_delivery_selected() && WC()->cart->needs_shipping() && !wc_ship_to_billing_address_only()) {
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
                }
                break;

            case 'parcelshop':
                // Safety check: Ensure WooCommerce session is available
                if (!WC()->session) {
                    return;
                }

                if ($this->is_parcelshop_delivery_selected()) {
                    echo '<div class="mygls-checkout-section mygls-section-parcelshop">';
                    echo '<h3 class="mygls-section-title">';
                    echo '<span class="dashicons dashicons-location-alt"></span>';
                    echo esc_html__('Csomagpont kiválasztása', 'mygls-woocommerce');
                    echo '</h3>';
                    echo '<div class="mygls-section-content">';
                    echo do_shortcode('[mygls_parcelshop_selector]');
                    echo '</div>';
                    echo '</div>';
                }
                break;

            case 'order_notes':
                if (apply_filters('woocommerce_enable_order_notes_field', 'yes' === get_option('woocommerce_enable_order_comments', 'yes'))) {
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
                }
                break;

            case 'payment':
                echo '<div class="mygls-checkout-section mygls-section-payment">';
                echo '<h3 class="mygls-section-title">';
                echo '<span class="dashicons dashicons-money-alt"></span>';
                echo esc_html__('Fizetési mód', 'mygls-woocommerce');
                echo '</h3>';
                echo '<div class="mygls-section-content">';
                woocommerce_checkout_payment();
                echo '</div>';
                echo '</div>';
                break;
        }
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

                if ('' === $chosen_for_package && $available_count === 1) {
                    $is_checked = 'checked="checked"';
                }

                echo '<li>';
                printf(
                    '<input type="radio" name="shipping_method[%1$s]" data-index="%1$s" id="%2$s" value="%3$s" class="shipping_method" %4$s />',
                    esc_attr((string) $package_index),
                    esc_attr($input_id),
                    esc_attr($method_id),
                    $is_checked
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

            .mygls-checkout-section {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 0;
                overflow: hidden;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
                transition: all 0.3s ease;
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

            /* Hide place order button from payment section in main area (keep it in sidebar) */
            .mygls-section-payment #place_order {
                display: none !important;
            }

            /* Responsive adjustments */
            @media (max-width: 992px) {
                .mygls-custom-checkout-container {
                    grid-template-columns: 1fr;
                }

                .mygls-order-review-sidebar {
                    position: relative;
                    top: 0;
                    order: -1;
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
        $inline_js = "
        jQuery(function($) {
            // Function to highlight selected shipping method
            function highlightSelectedShippingMethod() {
                $('.mygls-section-shipping-method .woocommerce-shipping-methods li').removeClass('woocommerce-shipping-method-selected');
                $('.mygls-section-shipping-method .woocommerce-shipping-methods input[type=\"radio\"]:checked').closest('li').addClass('woocommerce-shipping-method-selected');
            }

            // Function to toggle shipping/parcelshop sections
            function toggleShippingSections() {
                // Trigger checkout update to re-render sections
                $('body').trigger('update_checkout');
            }

            // Listen for shipping method changes
            $(document.body).on('change', 'input[name^=\"shipping_method\"]', function() {
                highlightSelectedShippingMethod();
                toggleShippingSections();
            });

            // Initial check on page load
            $(document.body).on('updated_checkout', function() {
                highlightSelectedShippingMethod();
                console.log('Checkout updated - sections refreshed');
            });

            // Initial highlight
            highlightSelectedShippingMethod();
        });
        ";

        wp_add_inline_script('mygls-parcelshop-map', $inline_js);
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
