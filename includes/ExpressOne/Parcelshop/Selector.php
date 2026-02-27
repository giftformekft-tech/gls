<?php
/**
 * Express One Parcelshop Selector
 * Adds parcelshop selection to checkout
 */

namespace ExpressOne\Parcelshop;

if (!defined('ABSPATH')) {
    exit;
}

class Selector {
    public function __construct() {
        // Shortcode for manual or dynamic placement
        add_shortcode('expressone_parcelshop_selector', [$this, 'parcelshop_selector_shortcode']);

        // Validate parcelshop selection
        add_action('woocommerce_checkout_process', [$this, 'validate_parcelshop_selection']);

        // Save parcelshop selection
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_parcelshop_selection']);

        // Display in admin
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'display_parcelshop_in_admin']);

        // AJAX endpoints
        add_action('wp_ajax_expressone_get_parcelshops', [$this, 'ajax_get_parcelshops']);
        add_action('wp_ajax_nopriv_expressone_get_parcelshops', [$this, 'ajax_get_parcelshops']);
        add_action('wp_ajax_expressone_save_parcelshop', [$this, 'ajax_save_parcelshop']);
        add_action('wp_ajax_nopriv_expressone_save_parcelshop', [$this, 'ajax_save_parcelshop']);
        
        // Frontend scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    public function enqueue_scripts() {
        if (!is_checkout()) {
            return;
        }
        
        // Add minimal CSS for the selector
        $css = "
            .expressone-parcelshop-wrapper { margin-top: 15px; }
            .expressone-iframe-container { width: 100%; height: 500px; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
            .expressone-iframe { width: 100%; height: 100%; border: none; }
            #expressone_selected_shop_display { margin-top: 10px; padding: 15px; background: #e8f5e9; border: 1px solid #c8e6c9; border-radius: 4px; display: none; }
            .expressone-selected-title { font-weight: bold; margin-bottom: 5px; color: #2e7d32; display: flex; align-items: center; gap: 8px; }
            .expressone-selected-details { font-size: 14px; color: #333; line-height: 1.5; }
        ";
        wp_add_inline_style('woocommerce-inline', $css);
        
        // Add JS logic for postMessage
        $js = "
        jQuery(document).ready(function($) {
            // Function to update the display
            function updateSelectedShopDisplay(data) {
                if (!data || !data.id || !data.name) return;
                
                $('#expressone_parcelshop_id').val(data.id);
                $('#expressone_parcelshop_data').val(JSON.stringify(data));
                
                let html = '<div class=\"expressone-selected-title\">';
                html += '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"20\" height=\"20\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M22 11.08V12a10 10 0 1 1-5.93-9.14\"></path><polyline points=\"22 4 12 14.01 9 11.01\"></polyline></svg>';
                html += 'Kiválasztott Express One Csomagpont:</div>';
                html += '<div class=\"expressone-selected-details\">';
                html += '<strong>' + data.name + '</strong><br>';
                html += data.zip_code + ' ' + data.city + ', ' + data.street;
                html += '</div>';
                
                $('#expressone_selected_shop_display').html(html).slideDown();
            }

            // Listen for iframe messages from Express One tracking map
            window.addEventListener('message', function(e) {
                // Ensure message contains necessary data properties
                if (e.data && e.data.name && e.data.zip_code) {
                    
                    let shopData = {
                        id: e.data.id || e.data.tof_shop_id || '',
                        name: e.data.name,
                        address: e.data.street,
                        city: e.data.city,
                        zip: e.data.zip_code,
                        gis_x: e.data.gis_x || '',
                        gis_y: e.data.gis_y || ''
                    };
                    
                    updateSelectedShopDisplay(shopData);
                    
                    // Optionally scroll to selected shop display
                    $('html, body').animate({
                        scrollTop: $('#expressone_selected_shop_display').offset().top - 100
                    }, 500);

                    // Trigger WooCommerce checkout update so shipping gets recalculated (if needed)
                    $('body').trigger('update_checkout');
                }
            });
            
            // Check for pre-selected shop on load
            let presetId = $('#expressone_parcelshop_id').val();
            if (presetId) {
                try {
                    let presetData = JSON.parse($('#expressone_parcelshop_data').val() || '{}');
                    if (presetData.name) {
                        // Re-map to match the expected format of updateSelectedShopDisplay
                        let mappedData = {
                            id: presetData.id,
                            name: presetData.name,
                            street: presetData.address,
                            city: presetData.city,
                            zip_code: presetData.zip
                        };
                        updateSelectedShopDisplay(mappedData);
                    }
                } catch(e) {}
            }
        });
        ";
        wp_add_inline_script('wc-checkout', $js);
    }

    public function parcelshop_selector_shortcode($atts) {
        $selected_parcelshop = null;
        if (function_exists('WC') && WC()->session) {
            $selected_parcelshop = WC()->session->get('expressone_selected_parcelshop');
        }

        // Get language setting (hu/en)
        $lang = get_locale();
        $iframe_lang = (strpos($lang, 'en_') === 0) ? 'en' : 'hu';

        ob_start();
        ?>
        <div class="expressone-parcelshop-wrapper">
            <div class="expressone-iframe-container">
                <iframe class="expressone-iframe" src="https://tracking.expressone.hu/pickup/points?lang=<?php echo esc_attr($iframe_lang); ?>&nearby=1" title="Express One Csomagpont Kereső"></iframe>
            </div>
            
            <div id="expressone_selected_shop_display"></div>
            
            <input type="hidden" name="expressone_parcelshop_id" id="expressone_parcelshop_id" value="<?php echo esc_attr($selected_parcelshop['id'] ?? ''); ?>">
            <input type="hidden" name="expressone_parcelshop_data" id="expressone_parcelshop_data" value="<?php echo esc_attr(json_encode($selected_parcelshop ?? [])); ?>">
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: Get parcelshops
     */
    public function ajax_get_parcelshops() {
        if (!function_exists('expressone_get_api_client')) {
            function expressone_get_api_client() {
                if (!class_exists('ExpressOne\\API\\Client')) { return null; }
                return new \ExpressOne\API\Client();
            }
        }
        
        $api = expressone_get_api_client();
        if (!$api) {
            wp_send_json_error(['message' => 'API nem elérhető']);
        }
        
        // Cache API call to avoid slow checkouts
        $transient_name = 'expressone_parcelshops_v1';
        $shops = get_transient($transient_name);
        
        if (false === $shops) {
            $result = $api->getParcelShops();
            $shops = [];
            
            if (isset($result['shops']) && is_array($result['shops'])) {
                foreach ($result['shops'] as $shop) {
                    $shops[] = [
                        'id' => $shop['shop_id'] ?? '',
                        'name' => $shop['shop_name'] ?? '',
                        'address' => $shop['street'] ?? '',
                        'city' => $shop['city'] ?? '',
                        'zip' => $shop['zip'] ?? ''
                    ];
                }
                set_transient($transient_name, $shops, 12 * HOUR_IN_SECONDS);
            }
        }
        
        wp_send_json_success(['shops' => $shops]);
    }

    /**
     * AJAX: Save selected parcelshop to session
     */
    public function ajax_save_parcelshop() {
        if (!function_exists('WC') || !WC()->session) {
            wp_send_json_error(['message' => __('WooCommerce session nem elérhető', 'mygls-woocommerce')]);
            return;
        }

        $parcelshop = isset($_POST['parcelshop']) ? json_decode(stripslashes($_POST['parcelshop']), true) : [];

        if (!empty($parcelshop)) {
            WC()->session->set('expressone_selected_parcelshop', $parcelshop);
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => __('Érvénytelen csomagpont adat', 'mygls-woocommerce')]);
        }
    }
    
    /**
     * Validate that a parcelshop is selected when ExpressOne parcelshop method is chosen
     */
    public function validate_parcelshop_selection() {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        $chosen_methods = WC()->session->get('chosen_shipping_methods', []);
        
        if (empty($chosen_methods)) {
            return;
        }

        $requires_parcelshop = false;
        foreach ($chosen_methods as $chosen_method) {
            // Check if the shipping method is expressone and is a parcelshop type
            if (strpos($chosen_method, 'expressone') !== false) {
                // Here we would ideally check if it's the parcelshop method.
                // Assuming it might have a _parcelshop suffix or comparing against settings
                $settings = get_option('expressone_settings', []);
                $shipping_type = $settings['shipping_type'] ?? 'home';
                
                // Just as a generic check
                $global_settings = get_option('mygls_settings', []);
                $enabled_methods = $global_settings['parcelshop_enabled_methods'] ?? [];
                
                if (in_array($chosen_method, $enabled_methods) || strpos($chosen_method, 'expressone_parcelshop') !== false) {
                    $requires_parcelshop = true;
                    break;
                }
            }
        }

        if ($requires_parcelshop) {
            $parcelshop_id = isset($_POST['expressone_parcelshop_id']) ? sanitize_text_field($_POST['expressone_parcelshop_id']) : '';
            
            if (empty($parcelshop_id)) {
                wc_add_notice(
                    __('Kérjük, válasszon egy Express One csomagpontot a szállításhoz.', 'mygls-woocommerce'),
                    'error'
                );
            }
        }
    }
    
    /**
     * Save parcelshop selection to order
     */
    public function save_parcelshop_selection($order_id) {
        if (isset($_POST['expressone_parcelshop_id']) && !empty($_POST['expressone_parcelshop_id'])) {
            $parcelshop_id = sanitize_text_field($_POST['expressone_parcelshop_id']);
            $parcelshop_data = json_decode(stripslashes($_POST['expressone_parcelshop_data']), true);
            
            update_post_meta($order_id, '_expressone_parcelshop_id', $parcelshop_id);
            update_post_meta($order_id, '_expressone_parcelshop_data', $parcelshop_data);
            
            $order = wc_get_order($order_id);
            
            if ($order && !empty($parcelshop_data)) {
                // Update shipping address with pickup point data
                $order->set_shipping_company(sprintf('Express One Csomagpont: %s', $parcelshop_data['name'] ?? ''));
                $order->set_shipping_address_1($parcelshop_data['address'] ?? '');
                $order->set_shipping_address_2('');
                $order->set_shipping_city($parcelshop_data['city'] ?? '');
                $order->set_shipping_postcode($parcelshop_data['zip'] ?? '');
                
                $order->save();
                
                // Add order note
                $order->add_order_note(
                    sprintf(
                        __('Express One Csomagpont kiválasztva: %s - %s', 'mygls-woocommerce'),
                        $parcelshop_data['name'] ?? '',
                        $parcelshop_data['address'] ?? ''
                    )
                );
            }
        }
    }
    
    /**
     * Display selected parcelshop in admin
     */
    public function display_parcelshop_in_admin($order) {
        $parcelshop_data = get_post_meta($order->get_id(), '_expressone_parcelshop_data', true);

        if (!empty($parcelshop_data)) {
            ?>
            <div class="expressone-admin-parcelshop">
                <h3><?php _e('Express One Csomagpont', 'mygls-woocommerce'); ?></h3>
                <p>
                    <strong><?php echo esc_html($parcelshop_data['name']); ?></strong><br>
                    <?php echo esc_html($parcelshop_data['address']); ?><br>
                    <small><?php _e('Azonosító:', 'mygls-woocommerce'); ?> <?php echo esc_html($parcelshop_data['id']); ?></small>
                </p>
            </div>
            <?php
        }
    }
}
