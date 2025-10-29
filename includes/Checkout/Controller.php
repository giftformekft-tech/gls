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

    public function __construct() {
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

        // Enqueue custom checkout styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_custom_styles']);

        // Enqueue custom checkout scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_custom_scripts']);
    }

    /**
     * Reorder checkout fields based on admin settings
     */
    public function reorder_checkout_fields($fields) {
        $field_order = $this->settings['checkout_field_order'] ?? ['billing', 'shipping_method', 'shipping', 'parcelshop', 'order_notes', 'payment'];

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

        if (in_array($template_name, $templates_to_override)) {
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
        $field_order = $this->settings['checkout_field_order'] ?? ['billing', 'shipping_method', 'shipping', 'parcelshop', 'order_notes', 'payment'];

        foreach ($field_order as $section) {
            $this->render_section($section);
        }
    }

    /**
     * Render individual checkout section
     */
    private function render_section($section) {
        $checkout = WC()->checkout();

        switch ($section) {
            case 'billing':
                ?>
                <div class="mygls-checkout-section mygls-section-billing">
                    <h3 class="mygls-section-title">
                        <span class="dashicons dashicons-id"></span>
                        <?php _e('Számlázási adatok', 'mygls-woocommerce'); ?>
                    </h3>
                    <div class="mygls-section-content">
                        <?php
                        foreach ($checkout->get_checkout_fields('billing') as $key => $field) {
                            woocommerce_form_field($key, $field, $checkout->get_value($key));
                        }
                        ?>
                    </div>
                </div>
                <?php
                break;

            case 'shipping_method':
                // Only show if cart needs shipping
                if (WC()->cart->needs_shipping() && WC()->cart->show_shipping()) {
                    ?>
                    <div class="mygls-checkout-section mygls-section-shipping-method">
                        <h3 class="mygls-section-title">
                            <span class="dashicons dashicons-car"></span>
                            <?php _e('Szállítási mód', 'mygls-woocommerce'); ?>
                        </h3>
                        <div class="mygls-section-content">
                            <?php $this->render_shipping_methods(); ?>
                        </div>
                    </div>
                    <?php
                }
                break;

            case 'shipping':
                // Check if parcelshop is selected
                $enabled_methods = $this->settings['parcelshop_enabled_methods'] ?? [];
                $chosen_methods = WC()->session->get('chosen_shipping_methods', []);

                $is_parcelshop = false;
                foreach ($chosen_methods as $chosen_method) {
                    if (in_array($chosen_method, $enabled_methods)) {
                        $is_parcelshop = true;
                        break;
                    }
                    // Check partial matches
                    foreach ($enabled_methods as $enabled_method) {
                        if (strpos($chosen_method, $enabled_method) === 0) {
                            $is_parcelshop = true;
                            break 2;
                        }
                    }
                }

                // Only show shipping address if NOT parcelshop AND shipping is needed
                if (!$is_parcelshop && WC()->cart->needs_shipping() && !wc_ship_to_billing_address_only()) {
                    ?>
                    <div class="mygls-checkout-section mygls-section-shipping">
                        <h3 class="mygls-section-title">
                            <span class="dashicons dashicons-location"></span>
                            <?php _e('Szállítási adatok', 'mygls-woocommerce'); ?>
                        </h3>
                        <div class="mygls-section-content">
                            <?php
                            foreach ($checkout->get_checkout_fields('shipping') as $key => $field) {
                                woocommerce_form_field($key, $field, $checkout->get_value($key));
                            }
                            ?>
                        </div>
                    </div>
                    <?php
                }
                break;

            case 'parcelshop':
                // Check if parcelshop is enabled for current shipping method
                $enabled_methods = $this->settings['parcelshop_enabled_methods'] ?? [];
                $chosen_methods = WC()->session->get('chosen_shipping_methods', []);

                $show_parcelshop = false;
                foreach ($chosen_methods as $chosen_method) {
                    if (in_array($chosen_method, $enabled_methods)) {
                        $show_parcelshop = true;
                        break;
                    }
                    // Check partial matches
                    foreach ($enabled_methods as $enabled_method) {
                        if (strpos($chosen_method, $enabled_method) === 0) {
                            $show_parcelshop = true;
                            break 2;
                        }
                    }
                }

                if ($show_parcelshop) {
                    ?>
                    <div class="mygls-checkout-section mygls-section-parcelshop">
                        <h3 class="mygls-section-title">
                            <span class="dashicons dashicons-location-alt"></span>
                            <?php _e('Csomagpont kiválasztása', 'mygls-woocommerce'); ?>
                        </h3>
                        <div class="mygls-section-content">
                            <?php
                            // Use the parcelshop selector shortcode
                            echo do_shortcode('[mygls_parcelshop_selector]');
                            ?>
                        </div>
                    </div>
                    <?php
                }
                break;

            case 'order_notes':
                if (apply_filters('woocommerce_enable_order_notes_field', 'yes' === get_option('woocommerce_enable_order_comments', 'yes'))) {
                    ?>
                    <div class="mygls-checkout-section mygls-section-notes">
                        <h3 class="mygls-section-title">
                            <span class="dashicons dashicons-edit"></span>
                            <?php _e('Megjegyzések a rendeléshez', 'mygls-woocommerce'); ?>
                        </h3>
                        <div class="mygls-section-content">
                            <?php
                            foreach ($checkout->get_checkout_fields('order') as $key => $field) {
                                woocommerce_form_field($key, $field, $checkout->get_value($key));
                            }
                            ?>
                        </div>
                    </div>
                    <?php
                }
                break;

            case 'payment':
                ?>
                <div class="mygls-checkout-section mygls-section-payment">
                    <h3 class="mygls-section-title">
                        <span class="dashicons dashicons-money-alt"></span>
                        <?php _e('Fizetési mód', 'mygls-woocommerce'); ?>
                    </h3>
                    <div class="mygls-section-content">
                        <?php woocommerce_checkout_payment(); ?>
                    </div>
                </div>
                <?php
                break;
        }
    }

    /**
     * Render shipping methods selection
     */
    private function render_shipping_methods() {
        $packages = WC()->shipping()->get_packages();

        if (empty($packages)) {
            return;
        }

        foreach ($packages as $i => $package) {
            $available_methods = $package['rates'];
            $chosen_method = isset(WC()->session->chosen_shipping_methods[$i]) ? WC()->session->chosen_shipping_methods[$i] : '';

            if (empty($available_methods)) {
                echo '<p>' . __('Jelenleg nincsenek elérhető szállítási módok.', 'mygls-woocommerce') . '</p>';
                continue;
            }

            ?>
            <ul class="woocommerce-shipping-methods" id="shipping_method">
                <?php foreach ($available_methods as $method) : ?>
                    <li>
                        <input
                            type="radio"
                            name="shipping_method[<?php echo esc_attr($i); ?>]"
                            data-index="<?php echo esc_attr($i); ?>"
                            id="shipping_method_<?php echo esc_attr($i); ?>_<?php echo esc_attr(sanitize_title($method->id)); ?>"
                            value="<?php echo esc_attr($method->id); ?>"
                            class="shipping_method"
                            <?php checked($method->id, $chosen_method); ?>
                        />
                        <label for="shipping_method_<?php echo esc_attr($i); ?>_<?php echo esc_attr(sanitize_title($method->id)); ?>">
                            <?php echo wp_kses_post($method->get_label()); ?>
                            <?php if ($method->cost > 0) : ?>
                                <span class="amount"><?php echo wc_price($method->cost); ?></span>
                            <?php else : ?>
                                <span class="amount"><?php _e('Ingyenes', 'mygls-woocommerce'); ?></span>
                            <?php endif; ?>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php
        }
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

            .mygls-section-shipping-method .woocommerce-shipping-methods li input[type="radio"] {
                margin-right: 10px;
            }

            .mygls-section-shipping-method .woocommerce-shipping-methods li input[type="radio"]:checked + label {
                font-weight: 600;
                color: #667eea;
            }

            .mygls-section-shipping-method .woocommerce-shipping-methods li.woocommerce-shipping-method-selected {
                background: #f0f4ff;
                border-color: #667eea;
                box-shadow: 0 2px 4px rgba(102, 126, 234, 0.1);
            }

            .mygls-section-shipping-method .woocommerce-shipping-methods label {
                cursor: pointer;
                display: inline-block;
                width: calc(100% - 30px);
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
}
