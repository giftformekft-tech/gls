<?php
/**
 * Parcelshop Selector
 * Adds parcelshop selection to checkout with interactive map
 */

namespace MyGLS\Parcelshop;

if (!defined('ABSPATH')) {
    exit;
}

class Selector {
    private $parcelshops_cache = [];
    
    public function __construct() {
        // Add parcelshop field to checkout (classic checkout)
        add_action('woocommerce_after_shipping_rate', [$this, 'add_parcelshop_selector'], 10, 2);

        // Block-based checkout compatibility - dynamic hook based on settings
        add_action('init', [$this, 'register_block_checkout_hooks']);

        // Shortcode for manual placement
        add_shortcode('mygls_parcelshop_selector', [$this, 'parcelshop_selector_shortcode']);

        // Save parcelshop selection
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_parcelshop_selection']);

        // Display in admin
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'display_parcelshop_in_admin']);

        // AJAX endpoints
        add_action('wp_ajax_mygls_get_parcelshops', [$this, 'ajax_get_parcelshops']);
        add_action('wp_ajax_nopriv_mygls_get_parcelshops', [$this, 'ajax_get_parcelshops']);
        add_action('wp_ajax_mygls_save_parcelshop', [$this, 'ajax_save_parcelshop']);
        add_action('wp_ajax_nopriv_mygls_save_parcelshop', [$this, 'ajax_save_parcelshop']);
    }
    
    /**
     * Register block checkout hooks based on settings
     */
    public function register_block_checkout_hooks() {
        $settings = get_option('mygls_settings', []);
        $position = $settings['map_position'] ?? 'after_shipping';

        // Map position setting to WooCommerce hook
        $hook_map = [
            'after_shipping' => 'woocommerce_review_order_after_shipping',
            'before_payment' => 'woocommerce_review_order_before_payment',
            'after_billing' => 'woocommerce_after_checkout_billing_form'
        ];

        $hook = $hook_map[$position] ?? 'woocommerce_review_order_after_shipping';
        add_action($hook, [$this, 'add_parcelshop_selector_fallback']);
    }

    /**
     * Add parcelshop selector after shipping rate
     */
    public function add_parcelshop_selector($method, $index) {
        // Get plugin settings
        $settings = get_option('mygls_settings', []);
        $enabled_methods = $settings['parcelshop_enabled_methods'] ?? [];

        // Check if parcelshop selector is enabled for this shipping method
        $method_id = $method->get_id();
        $is_enabled = false;

        // First check if it's explicitly enabled in settings
        if (in_array($method_id, $enabled_methods)) {
            $is_enabled = true;
        }
        // Also check for MyGLS methods with parcelshop type (backward compatibility)
        elseif (strpos($method_id, 'mygls') !== false) {
            if (method_exists($method, 'get_option')) {
                $shipping_type = $method->get_option('shipping_type', 'home');
                if ($shipping_type === 'parcelshop') {
                    $is_enabled = true;
                }
            }
        }

        // Allow filtering for additional control
        $show_parcelshop = apply_filters('mygls_show_parcelshop_selector', $is_enabled, $method);

        if (!$show_parcelshop) {
            return;
        }

        // Only render widget once (check if already rendered)
        static $widget_rendered = false;

        $selected_parcelshop = WC()->session->get('mygls_selected_parcelshop');
        $country = strtolower($settings['country'] ?? 'hu');
        $language = strtolower($settings['language'] ?? 'hu');

        ?>
        <div class="mygls-parcelshop-selector" data-shipping-method="<?php echo esc_attr($method->get_id()); ?>" style="display: none;">
            <div class="mygls-parcelshop-trigger">
                <button type="button" class="button mygls-select-parcelshop" data-method-id="<?php echo esc_attr($method->get_id()); ?>">
                    <span class="dashicons dashicons-location-alt"></span>
                    <?php _e('Csomagpont kiválasztása', 'mygls-woocommerce'); ?>
                </button>

                <?php if ($selected_parcelshop): ?>
                    <div class="mygls-selected-parcelshop">
                        <strong><?php echo esc_html($selected_parcelshop['name']); ?></strong><br>
                        <small><?php echo esc_html($selected_parcelshop['address']); ?></small>
                    </div>
                <?php endif; ?>
            </div>

            <input type="hidden" name="mygls_parcelshop_id" class="mygls-parcelshop-id-field" value="<?php echo esc_attr($selected_parcelshop['id'] ?? ''); ?>">
            <input type="hidden" name="mygls_parcelshop_data" class="mygls-parcelshop-data-field" value="<?php echo esc_attr(json_encode($selected_parcelshop ?? [])); ?>">
        </div>

        <?php
        // Only render the GLS widget dialog once per page
        if (!$widget_rendered):
            $widget_rendered = true;
        ?>
        <!-- GLS Official Map Widget Dialog (rendered once) -->
        <gls-dpm-dialog
            country="<?php echo esc_attr($country); ?>"
            language="<?php echo esc_attr($language); ?>"
            id="mygls-parcelshop-widget-classic"
            style="display: none;">
        </gls-dpm-dialog>
        <?php endif; ?>

        <script>
        jQuery(function($) {
            // Show parcelshop selector when this shipping method is selected
            $('input[name="shipping_method[<?php echo $index; ?>]"]').on('change', function() {
                const selectedValue = $(this).val();
                const targetSelector = $('.mygls-parcelshop-selector[data-shipping-method="<?php echo esc_js($method->get_id()); ?>"]');

                if (selectedValue === '<?php echo esc_js($method->get_id()); ?>') {
                    // Hide all other parcelshop selectors first
                    $('.mygls-parcelshop-selector').not(targetSelector).slideUp();
                    // Show this one
                    targetSelector.slideDown();
                } else {
                    targetSelector.slideUp();
                }
            });

            // Check if already selected on page load
            if ($('input[name="shipping_method[<?php echo $index; ?>]"]:checked').val() === '<?php echo esc_js($method->get_id()); ?>') {
                $('.mygls-parcelshop-selector[data-shipping-method="<?php echo esc_js($method->get_id()); ?>"]').show();
            }
        });
        </script>
        <?php
    }
    
    /**
     * Save parcelshop selection to order
     */
    public function save_parcelshop_selection($order_id) {
        if (isset($_POST['mygls_parcelshop_id']) && !empty($_POST['mygls_parcelshop_id'])) {
            $parcelshop_id = sanitize_text_field($_POST['mygls_parcelshop_id']);
            $parcelshop_data = json_decode(stripslashes($_POST['mygls_parcelshop_data']), true);
            
            update_post_meta($order_id, '_mygls_parcelshop_id', $parcelshop_id);
            update_post_meta($order_id, '_mygls_parcelshop_data', $parcelshop_data);
            
            // Add order note
            $order = wc_get_order($order_id);
            $order->add_order_note(
                sprintf(
                    __('GLS Csomagpont kiválasztva: %s - %s', 'mygls-woocommerce'),
                    $parcelshop_data['name'] ?? '',
                    $parcelshop_data['address'] ?? ''
                )
            );
        }
    }
    
    /**
     * Display selected parcelshop in admin
     */
    public function display_parcelshop_in_admin($order) {
        $parcelshop_data = get_post_meta($order->get_id(), '_mygls_parcelshop_data', true);

        if (!empty($parcelshop_data)) {
            ?>
            <div class="mygls-admin-parcelshop">
                <h3><?php _e('GLS Csomagpont', 'mygls-woocommerce'); ?></h3>
                <p>
                    <strong><?php echo esc_html($parcelshop_data['name']); ?></strong><br>
                    <?php echo esc_html($parcelshop_data['address']); ?><br>
                    <small><?php _e('Azonosító:', 'mygls-woocommerce'); ?> <?php echo esc_html($parcelshop_data['id']); ?></small>
                </p>
            </div>
            <?php
        }
    }
    
    /**
     * AJAX: Get parcelshops
     */
    public function ajax_get_parcelshops() {
        check_ajax_referer('mygls_checkout_nonce', 'nonce');

        $search = sanitize_text_field($_POST['search'] ?? '');
        $lat = floatval($_POST['lat'] ?? 0);
        $lng = floatval($_POST['lng'] ?? 0);

        // In a real implementation, this would call GLS ParcelShop API
        // For now, return mock data
        $parcelshops = $this->get_mock_parcelshops($search, $lat, $lng);

        wp_send_json_success(['parcelshops' => $parcelshops]);
    }

    /**
     * AJAX: Save selected parcelshop to session
     */
    public function ajax_save_parcelshop() {
        check_ajax_referer('mygls_checkout_nonce', 'nonce');

        $parcelshop = isset($_POST['parcelshop']) ? json_decode(stripslashes($_POST['parcelshop']), true) : [];

        if (!empty($parcelshop)) {
            WC()->session->set('mygls_selected_parcelshop', $parcelshop);
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => __('Érvénytelen csomagpont adat', 'mygls-woocommerce')]);
        }
    }
    
    /**
     * Get mock parcelshops for demonstration
     * In production, integrate with GLS ParcelShop API
     */
    private function get_mock_parcelshops($search, $lat, $lng) {
        // Mock data - replace with actual GLS API call
        // If coordinates provided, use them; otherwise use Budapest default
        $results = [
            [
                'id' => '2351-CSOMAGPONT',
                'name' => 'Szerencse Sziget Lottózó',
                'address' => 'Fő út 4/a, 2351 Alsónémedi',
                'city' => 'Alsónémedi',
                'zip' => '2351',
                'lat' => 47.3234,
                'lng' => 19.1567,
                'phone' => '+36301234567',
                'hours' => 'H-P: 8:00-18:00, Szo: 8:00-13:00',
                'distance' => '5.2'
            ],
            [
                'id' => '1111-TESTPOINT',
                'name' => 'GLS ParcelShop Budapest',
                'address' => 'Test utca 1, 1111 Budapest',
                'city' => 'Budapest',
                'zip' => '1111',
                'lat' => 47.5000,
                'lng' => 19.0500,
                'phone' => '+36301234568',
                'hours' => 'H-P: 9:00-17:00',
                'distance' => '8.3'
            ],
            [
                'id' => '1234-CENTRUM',
                'name' => 'GLS ParcelShop Centrum',
                'address' => 'Kossuth utca 10, 1053 Budapest',
                'city' => 'Budapest',
                'zip' => '1053',
                'lat' => 47.4960,
                'lng' => 19.0535,
                'phone' => '+36301234569',
                'hours' => 'H-P: 8:00-20:00, Szo: 9:00-15:00',
                'distance' => '2.1'
            ]
        ];

        // Filter results based on search term if provided
        if (!empty($search)) {
            $results = array_filter($results, function($parcelshop) use ($search) {
                return stripos($parcelshop['city'], $search) !== false ||
                       stripos($parcelshop['zip'], $search) !== false ||
                       stripos($parcelshop['address'], $search) !== false;
            });

            // Reset array keys
            $results = array_values($results);
        }

        return $results;
    }

    /**
     * Fallback parcelshop selector for block-based checkout
     * Displays after shipping in order review
     */
    public function add_parcelshop_selector_fallback() {
        // Check if we should show the selector
        $settings = get_option('mygls_settings', []);
        $enabled_methods = $settings['parcelshop_enabled_methods'] ?? [];

        if (empty($enabled_methods)) {
            return;
        }

        // Get chosen shipping methods
        $chosen_methods = WC()->session->get('chosen_shipping_methods', []);

        if (empty($chosen_methods)) {
            return;
        }

        // Check if any chosen method requires parcelshop selection
        $show_selector = false;
        foreach ($chosen_methods as $chosen_method) {
            if (in_array($chosen_method, $enabled_methods)) {
                $show_selector = true;
                break;
            }
            // Also check for partial matches (e.g., "mygls_shipping:1" matches "mygls_shipping")
            foreach ($enabled_methods as $enabled_method) {
                if (strpos($chosen_method, $enabled_method) === 0) {
                    $show_selector = true;
                    break 2;
                }
            }
        }

        if (!$show_selector) {
            return;
        }

        // Render the selector
        $this->render_parcelshop_selector_html();
    }

    /**
     * Shortcode for parcelshop selector
     * Usage: [mygls_parcelshop_selector]
     */
    public function parcelshop_selector_shortcode($atts) {
        ob_start();
        $this->render_parcelshop_selector_html();
        return ob_get_clean();
    }

    /**
     * Render the parcelshop selector HTML
     * Common method for all display locations
     */
    private function render_parcelshop_selector_html() {
        $selected_parcelshop = WC()->session->get('mygls_selected_parcelshop');
        $settings = get_option('mygls_settings', []);
        $country = strtolower($settings['country'] ?? 'hu');
        $language = strtolower($settings['language'] ?? 'hu');
        $button_style = $settings['map_button_style'] ?? 'primary';
        $display_mode = $settings['map_display_mode'] ?? 'modal';

        // Button style classes
        $button_class_map = [
            'primary' => 'mygls-btn-primary',
            'secondary' => 'mygls-btn-secondary',
            'success' => 'mygls-btn-success'
        ];
        $button_class = $button_class_map[$button_style] ?? 'mygls-btn-primary';

        ?>
        <div class="mygls-parcelshop-selector-wrapper mygls-modern">
            <div class="mygls-parcelshop-selector" data-shipping-method="all">
                <div class="mygls-parcelshop-trigger">
                    <button type="button" class="mygls-select-parcelshop <?php echo esc_attr($button_class); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                            <circle cx="12" cy="10" r="3"></circle>
                        </svg>
                        <?php _e('Csomagpont kiválasztása térképen', 'mygls-woocommerce'); ?>
                    </button>

                    <?php if ($selected_parcelshop): ?>
                        <div class="mygls-selected-parcelshop mygls-slide-in">
                            <div class="mygls-selected-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="20 6 9 17 4 12"></polyline>
                                </svg>
                            </div>
                            <div class="mygls-selected-info">
                                <strong><?php echo esc_html($selected_parcelshop['name']); ?></strong>
                                <small><?php echo esc_html($selected_parcelshop['address']); ?></small>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="mygls-help-text">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="16" x2="12" y2="12"></line>
                                <line x1="12" y1="8" x2="12.01" y2="8"></line>
                            </svg>
                            <?php _e('Kérjük, válasszon egy GLS csomagpontot a kézbesítéshez', 'mygls-woocommerce'); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <input type="hidden" name="mygls_parcelshop_id" id="mygls_parcelshop_id" value="<?php echo esc_attr($selected_parcelshop['id'] ?? ''); ?>">
                <input type="hidden" name="mygls_parcelshop_data" id="mygls_parcelshop_data" value="<?php echo esc_attr(json_encode($selected_parcelshop ?? [])); ?>">
            </div>

            <!-- GLS Official Map Widget Dialog -->
            <gls-dpm-dialog
                country="<?php echo esc_attr($country); ?>"
                language="<?php echo esc_attr($language); ?>"
                id="mygls-parcelshop-widget"
                class="mygls-widget-<?php echo esc_attr($display_mode); ?>">
            </gls-dpm-dialog>
        </div>
        <?php
    }
}