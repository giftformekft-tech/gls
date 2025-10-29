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

        // Custom checkout template hooks
        add_action('woocommerce_checkout_before_customer_details', [$this, 'custom_checkout_wrapper_start'], 5);
        add_action('woocommerce_checkout_after_customer_details', [$this, 'custom_checkout_wrapper_end'], 95);

        // Remove default WooCommerce checkout sections to rebuild them in custom order
        remove_action('woocommerce_checkout_billing', 'woocommerce_checkout_billing', 20);
        remove_action('woocommerce_checkout_shipping', 'woocommerce_checkout_shipping', 20);

        // Add our custom sections in the correct order
        add_action('mygls_custom_checkout_sections', [$this, 'render_checkout_sections'], 10);

        // Enqueue custom checkout styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_custom_styles']);
    }

    /**
     * Reorder checkout fields based on admin settings
     */
    public function reorder_checkout_fields($fields) {
        $field_order = $this->settings['checkout_field_order'] ?? ['billing', 'shipping', 'parcelshop', 'order_notes', 'payment'];

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
     * Start custom checkout wrapper
     */
    public function custom_checkout_wrapper_start() {
        ?>
        <div class="mygls-custom-checkout-wrapper">
            <?php do_action('mygls_custom_checkout_sections'); ?>
        <?php
    }

    /**
     * End custom checkout wrapper
     */
    public function custom_checkout_wrapper_end() {
        ?>
        </div>
        <?php
    }

    /**
     * Render checkout sections in custom order
     */
    public function render_checkout_sections() {
        $field_order = $this->settings['checkout_field_order'] ?? ['billing', 'shipping', 'parcelshop', 'order_notes', 'payment'];

        echo '<div class="mygls-checkout-sections">';

        foreach ($field_order as $section) {
            $this->render_section($section);
        }

        echo '</div>';
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
                        <?php _e('Billing Details', 'mygls-woocommerce'); ?>
                    </h3>
                    <div class="mygls-section-content">
                        <?php do_action('woocommerce_checkout_billing'); ?>
                    </div>
                </div>
                <?php
                break;

            case 'shipping':
                // Only show if shipping is needed
                if (WC()->cart->needs_shipping() && !wc_ship_to_billing_address_only()) {
                    ?>
                    <div class="mygls-checkout-section mygls-section-shipping">
                        <h3 class="mygls-section-title">
                            <span class="dashicons dashicons-location"></span>
                            <?php _e('Shipping Details', 'mygls-woocommerce'); ?>
                        </h3>
                        <div class="mygls-section-content">
                            <?php do_action('woocommerce_checkout_shipping'); ?>
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
                            <?php _e('Parcelshop Selection', 'mygls-woocommerce'); ?>
                        </h3>
                        <div class="mygls-section-content">
                            <?php
                            // Use the parcelshop selector shortcode
                            if (class_exists('MyGLS\\Parcelshop\\Selector')) {
                                $selector = new \MyGLS\Parcelshop\Selector();
                                echo do_shortcode('[mygls_parcelshop_selector]');
                            }
                            ?>
                        </div>
                    </div>
                    <?php
                }
                break;

            case 'order_notes':
                ?>
                <div class="mygls-checkout-section mygls-section-notes">
                    <h3 class="mygls-section-title">
                        <span class="dashicons dashicons-edit"></span>
                        <?php _e('Order Notes', 'mygls-woocommerce'); ?>
                    </h3>
                    <div class="mygls-section-content">
                        <?php
                        if (apply_filters('woocommerce_enable_order_notes_field', 'yes' === get_option('woocommerce_enable_order_comments', 'yes'))) {
                            foreach ($checkout->get_checkout_fields('order') as $key => $field) {
                                woocommerce_form_field($key, $field, $checkout->get_value($key));
                            }
                        }
                        ?>
                    </div>
                </div>
                <?php
                break;

            case 'payment':
                ?>
                <div class="mygls-checkout-section mygls-section-payment">
                    <h3 class="mygls-section-title">
                        <span class="dashicons dashicons-money"></span>
                        <?php _e('Payment Method', 'mygls-woocommerce'); ?>
                    </h3>
                    <div class="mygls-section-content">
                        <?php
                        // This will be rendered by WooCommerce in the order review section
                        // We just add a placeholder here for visual consistency
                        ?>
                        <p class="mygls-payment-placeholder">
                            <?php _e('Payment options will be displayed in the order summary section.', 'mygls-woocommerce'); ?>
                        </p>
                    </div>
                </div>
                <?php
                break;
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
            .mygls-custom-checkout-wrapper {
                width: 100%;
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

            .mygls-payment-placeholder {
                color: #666;
                font-style: italic;
                margin: 0;
            }

            /* Responsive adjustments */
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
}
