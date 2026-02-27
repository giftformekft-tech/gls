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
            .expressone-search-input { width: 100%; padding: 8px; margin-bottom: 10px; border: 1px solid #ddd; }
            .expressone-shop-list { max-height: 300px; overflow-y: auto; border: 1px solid #eee; }
            .expressone-shop-item { padding: 10px; border-bottom: 1px solid #f5f5f5; cursor: pointer; transition: background 0.2s; }
            .expressone-shop-item:hover, .expressone-shop-item.selected { background: #f0f4ff; }
            .expressone-shop-name { font-weight: bold; display: block; }
            .expressone-shop-address { font-size: 0.9em; color: #666; }
            #expressone_selected_shop_display { margin-top: 10px; padding: 10px; background: #e8f5e9; border-radius: 4px; display: none; }
        ";
        wp_add_inline_style('woocommerce-inline', $css);
        
        // Add JS logic
        $js = "
        jQuery(document).ready(function($) {
            let shops = [];
            
            function loadShops() {
                $.ajax({
                    url: wc_checkout_params.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'expressone_get_parcelshops',
                        nonce: wc_checkout_params.update_order_review_nonce || ''
                    },
                    success: function(response) {
                        if (response.success && response.data.shops) {
                            shops = response.data.shops;
                            renderShops(shops);
                        }
                    }
                });
            }
            
            function renderShops(shopsToRender) {
                let html = '';
                shopsToRender.forEach(function(shop) {
                    html += '<div class=\"expressone-shop-item\" data-id=\"' + shop.id + '\" data-name=\"' + shop.name + '\" data-address=\"' + shop.address + '\" data-city=\"' + shop.city + '\" data-zip=\"' + shop.zip + '\">';
                    html += '<span class=\"expressone-shop-name\">' + shop.name + '</span>';
                    html += '<span class=\"expressone-shop-address\">' + shop.zip + ' ' + shop.city + ', ' + shop.address + '</span>';
                    html += '</div>';
                });
                $('#expressone_shop_list').html(html);
            }
            
            $(document).on('keyup', '#expressone_shop_search', function() {
                let term = $(this).val().toLowerCase();
                if (term.length < 2) {
                    renderShops(shops);
                    return;
                }
                let filtered = shops.filter(function(shop) {
                    return shop.name.toLowerCase().indexOf(term) > -1 || 
                           shop.city.toLowerCase().indexOf(term) > -1 || 
                           shop.address.toLowerCase().indexOf(term) > -1;
                });
                renderShops(filtered);
            });
            
            $(document).on('click', '.expressone-shop-item', function() {
                $('.expressone-shop-item').removeClass('selected');
                $(this).addClass('selected');
                
                let data = {
                    id: $(this).data('id'),
                    name: $(this).data('name'),
                    address: $(this).data('address'),
                    city: $(this).data('city'),
                    zip: $(this).data('zip')
                };
                
                $('#expressone_parcelshop_id').val(data.id);
                $('#expressone_parcelshop_data').val(JSON.stringify(data));
                
                $('#expressone_selected_shop_display').html('<strong>Kiválasztva:</strong> ' + data.name + ' (' + data.address + ')').show();
            });
            
            // Initiate load if selector is present
            if ($('#expressone_shop_list').length > 0) {
                // If we don't have shops yet, load them
                loadShops();
                
                // Show previously selected if exists
                let presetId = $('#expressone_parcelshop_id').val();
                if (presetId) {
                    let presetData = JSON.parse($('#expressone_parcelshop_data').val() || '{}');
                    if (presetData.name) {
                        $('#expressone_selected_shop_display').html('<strong>Kiválasztva:</strong> ' + presetData.name + ' (' + presetData.address + ')').show();
                    }
                }
            }
        });
        ";
        wp_add_inline_script('wc-checkout', $js);
    }

    /**
     * Shortcode for parcelshop selector
     */
    public function parcelshop_selector_shortcode($atts) {
        $selected_parcelshop = null;
        if (function_exists('WC') && WC()->session) {
            $selected_parcelshop = WC()->session->get('expressone_selected_parcelshop');
        }

        ob_start();
        ?>
        <div class="expressone-parcelshop-wrapper">
            <input type="text" id="expressone_shop_search" class="expressone-search-input" placeholder="<?php esc_attr_e('Keresés város, cím vagy név alapján...', 'mygls-woocommerce'); ?>">
            <div id="expressone_shop_list" class="expressone-shop-list">
                <!-- Populated via AJAX -->
                <p style="padding: 10px;"><?php _e('Csomagpontok betöltése...', 'mygls-woocommerce'); ?></p>
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
