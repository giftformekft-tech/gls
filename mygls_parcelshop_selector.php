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
        // Add parcelshop field to checkout
        add_action('woocommerce_after_shipping_rate', [$this, 'add_parcelshop_selector'], 10, 2);
        
        // Save parcelshop selection
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_parcelshop_selection']);
        
        // Display in admin
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'display_parcelshop_in_admin']);
        
        // AJAX endpoints
        add_action('wp_ajax_mygls_get_parcelshops', [$this, 'ajax_get_parcelshops']);
        add_action('wp_ajax_nopriv_mygls_get_parcelshops', [$this, 'ajax_get_parcelshops']);
    }
    
    /**
     * Add parcelshop selector after shipping rate
     */
    public function add_parcelshop_selector($method, $index) {
        // Only show for MyGLS shipping methods with parcelshop support
        if (strpos($method->get_id(), 'mygls') === false) {
            return;
        }
        
        // Check if this rate should show parcelshop selector
        $show_parcelshop = apply_filters('mygls_show_parcelshop_selector', true, $method);
        
        if (!$show_parcelshop) {
            return;
        }
        
        $selected_parcelshop = WC()->session->get('mygls_selected_parcelshop');
        
        ?>
        <div class="mygls-parcelshop-selector" data-shipping-method="<?php echo esc_attr($method->get_id()); ?>" style="display: none;">
            <div class="mygls-parcelshop-trigger">
                <button type="button" class="button mygls-select-parcelshop">
                    <span class="dashicons dashicons-location-alt"></span>
                    <?php _e('Select Parcelshop', 'mygls-woocommerce'); ?>
                </button>
                
                <?php if ($selected_parcelshop): ?>
                    <div class="mygls-selected-parcelshop">
                        <strong><?php echo esc_html($selected_parcelshop['name']); ?></strong><br>
                        <small><?php echo esc_html($selected_parcelshop['address']); ?></small>
                    </div>
                <?php endif; ?>
            </div>
            
            <input type="hidden" name="mygls_parcelshop_id" id="mygls_parcelshop_id" value="<?php echo esc_attr($selected_parcelshop['id'] ?? ''); ?>">
            <input type="hidden" name="mygls_parcelshop_data" id="mygls_parcelshop_data" value="<?php echo esc_attr(json_encode($selected_parcelshop ?? [])); ?>">
        </div>
        
        <!-- Parcelshop Modal -->
        <div id="mygls-parcelshop-modal" class="mygls-modal" style="display: none;">
            <div class="mygls-modal-content">
                <div class="mygls-modal-header">
                    <h2><?php _e('Select Parcelshop', 'mygls-woocommerce'); ?></h2>
                    <button type="button" class="mygls-modal-close">&times;</button>
                </div>
                
                <div class="mygls-modal-body">
                    <div class="mygls-parcelshop-search">
                        <input type="text" id="mygls-parcelshop-search" placeholder="<?php esc_attr_e('Enter city or ZIP code...', 'mygls-woocommerce'); ?>" class="input-text">
                        <button type="button" id="mygls-search-btn" class="button">
                            <span class="dashicons dashicons-search"></span>
                            <?php _e('Search', 'mygls-woocommerce'); ?>
                        </button>
                        <button type="button" id="mygls-locate-btn" class="button">
                            <span class="dashicons dashicons-location"></span>
                            <?php _e('Use My Location', 'mygls-woocommerce'); ?>
                        </button>
                    </div>
                    
                    <div class="mygls-parcelshop-container">
                        <div class="mygls-parcelshop-list">
                            <div id="mygls-parcelshop-results">
                                <p class="mygls-no-results"><?php _e('Enter a location to find nearby parcelshops', 'mygls-woocommerce'); ?></p>
                            </div>
                        </div>
                        
                        <div class="mygls-parcelshop-map">
                            <div id="mygls-map" style="width: 100%; height: 500px;"></div>
                        </div>
                    </div>
                </div>
                
                <div class="mygls-modal-footer">
                    <button type="button" class="button button-primary" id="mygls-confirm-parcelshop" disabled>
                        <?php _e('Confirm Selection', 'mygls-woocommerce'); ?>
                    </button>
                    <button type="button" class="button mygls-modal-close">
                        <?php _e('Cancel', 'mygls-woocommerce'); ?>
                    </button>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(function($) {
            // Show parcelshop selector when this shipping method is selected
            $('input[name="shipping_method[<?php echo $index; ?>]"]').on('change', function() {
                if ($(this).val() === '<?php echo esc_js($method->get_id()); ?>') {
                    $('.mygls-parcelshop-selector[data-shipping-method="<?php echo esc_js($method->get_id()); ?>"]').slideDown();
                } else {
                    $('.mygls-parcelshop-selector[data-shipping-method="<?php echo esc_js($method->get_id()); ?>"]').slideUp();
                }
            });
            
            // Check if already selected
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
                    __('GLS Parcelshop selected: %s - %s', 'mygls-woocommerce'),
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
                <h3><?php _e('GLS Parcelshop', 'mygls-woocommerce'); ?></h3>
                <p>
                    <strong><?php echo esc_html($parcelshop_data['name']); ?></strong><br>
                    <?php echo esc_html($parcelshop_data['address']); ?><br>
                    <small><?php _e('ID:', 'mygls-woocommerce'); ?> <?php echo esc_html($parcelshop_data['id']); ?></small>
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
        
        $zip = sanitize_text_field($_POST['zip'] ?? '');
        $city = sanitize_text_field($_POST['city'] ?? '');
        $lat = floatval($_POST['lat'] ?? 0);
        $lng = floatval($_POST['lng'] ?? 0);
        
        // In a real implementation, this would call GLS ParcelShop API
        // For now, return mock data
        $parcelshops = $this->get_mock_parcelshops($zip, $city, $lat, $lng);
        
        wp_send_json_success(['parcelshops' => $parcelshops]);
    }
    
    /**
     * Get mock parcelshops for demonstration
     * In production, integrate with GLS ParcelShop API
     */
    private function get_mock_parcelshops($zip, $city, $lat, $lng) {
        // Mock data - replace with actual GLS API call
        return [
            [
                'id' => '2351-CSOMAGPONT',
                'name' => 'Szerencse Sziget Lottózó',
                'address' => 'Fő út 4/a, 2351 Alsónémedi',
                'city' => 'Alsónémedi',
                'zip' => '2351',
                'lat' => 47.3234,
                'lng' => 19.1567,
                'phone' => '+36301234567',
                'hours' => 'H-P: 8:00-18:00, Szo: 8:00-13:00'
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
                'hours' => 'H-P: 9:00-17:00'
            ]
        ];
    }
}