<?php

/**
 * Handles Bulk Orders
 *
 * @since     1.2.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class GLS_Shipping_Bulk
{
    private $order_handler;

    public function __construct()
    {
        // Initialize order handler
        $this->order_handler = new GLS_Shipping_Order();

        // Add bulk actions for GLS label generation
        add_filter('bulk_actions-edit-shop_order', array($this, 'register_gls_bulk_actions'));
        add_filter('bulk_actions-woocommerce_page_wc-orders', array($this, 'register_gls_bulk_actions'));

        // Handle bulk action for GLS label generation
        add_filter('handle_bulk_actions-edit-shop_order', array($this, 'process_bulk_gls_label_generation'), 10, 3);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array($this, 'process_bulk_gls_label_generation'), 10, 3);

        // Display admin notice after bulk action
        add_action('admin_notices', array($this, 'gls_bulk_action_admin_notice'));

        // Add GLS order actions
        add_filter('woocommerce_admin_order_actions', array($this, 'add_gls_order_actions'), 10, 2);

        // Enqueue admin styles
        add_action('admin_print_styles', array($this, 'admin_enqueue_styles'));

        // Add GLS Tracking Number column to orders list (both standard and HPOS)
        add_filter('manage_edit-shop_order_columns', array($this, 'add_gls_parcel_id_column'));
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_gls_parcel_id_column'));
        
        // Column content for standard WooCommerce
        add_action('manage_shop_order_posts_custom_column', array($this, 'populate_gls_parcel_id_column'), 10, 2);
        
        // Column content for HPOS - unified approach
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'populate_gls_parcel_id_column'), 10, 2);
    }

    // Add GLS-specific order actions
    public function add_gls_order_actions($actions, $order) {
        $order_id = $order->get_id();
        $gls_print_label = $order->get_meta('_gls_print_label', true);
    
        if ($gls_print_label) {
            // Action to download existing GLS label
            $actions['gls_download_label'] = array(
                'url'    => $gls_print_label,
                'target' => '_blank',
                'name'   => __('Download GLS Label', 'gls-shipping-for-woocommerce'),
                'action' => 'gls-download-label',
            );
        } else {
            // Action to generate new GLS label
            $actions['gls_generate_label'] = array(
                'url'    => '#',
                'name'   => __('Generate GLS Label', 'gls-shipping-for-woocommerce'),
                'action' => 'gls-generate-label',
            );
        }
    
        return $actions;
    }

    // Register GLS bulk action
    public function register_gls_bulk_actions($bulk_actions) {
        $bulk_actions['generate_gls_labels'] = __('Bulk Generate GLS Labels', 'gls-shipping-for-woocommerce');
        $bulk_actions['print_gls_labels'] = __('Bulk Print GLS Labels', 'gls-shipping-for-woocommerce');
        return $bulk_actions;
    }
    
    // Process bulk GLS label generation
    public function process_bulk_gls_label_generation($redirect, $doaction, $order_ids)
    {
        if ('generate_gls_labels' === $doaction) {
            $processed = 0;
            $failed_orders = array();
            foreach ($order_ids as $order_id) {
                // Use centralized label generation method
                $result = $this->order_handler->generate_single_order_label($order_id);
                
                if ($result['success']) {
                    $processed++;
                } else {
                    $failed_orders[] = $order_id;
                }
            }
    
            // Add query args to URL for displaying notices
            $redirect = add_query_arg(
                array(
                    'bulk_action' => 'generate_gls_labels',
                    'gls_labels_generated' => $processed,
                    'gls_labels_failed' => count($failed_orders),
                    'failed_orders' => implode(',', $failed_orders),
                    'changed' => count($order_ids),
                ),
                $redirect
            );
        }
        // Bulk print labels, dont generate for each but just print in single PDF
        if ('print_gls_labels' === $doaction) {
            $prepare_data = new GLS_Shipping_API_Data($order_ids);
            $data = $prepare_data->generate_post_fields_multi();
    
            // Send order to GLS API
            $is_multi = true;
            $api = new GLS_Shipping_API_Service();
            $result = $api->send_order($data, $is_multi);

            $body = $result['body'];
            $failed_orders = $result['failed_orders'];
    
            $pdf_url = $this->bulk_create_print_labels($body);
    
            if ($pdf_url) {
                // Save tracking numbers to order meta
                if (!empty($body['PrintLabelsInfoList'])) {
                    // Group tracking codes by order ID to handle multiple parcels per order
                    $orders_data = array();
                    
                    foreach ($body['PrintLabelsInfoList'] as $labelInfo) {
                        if (isset($labelInfo['ClientReference'])) {
                            $order_id = str_replace('Order:', '', $labelInfo['ClientReference']);
                            
                            if (!isset($orders_data[$order_id])) {
                                $orders_data[$order_id] = array(
                                    'tracking_codes' => array(),
                                    'parcel_ids' => array()
                                );
                            }
                            
                            if (isset($labelInfo['ParcelNumber'])) {
                                $orders_data[$order_id]['tracking_codes'][] = $labelInfo['ParcelNumber'];
                            }
                            if (isset($labelInfo['ParcelId'])) {
                                $orders_data[$order_id]['parcel_ids'][] = $labelInfo['ParcelId'];
                            }
                        }
                    }
                    
                    // Now save all tracking codes for each order
                    foreach ($orders_data as $order_id => $data) {
                        $order = wc_get_order($order_id);
                        if ($order) {
                            if (!empty($data['tracking_codes'])) {
                                $order->update_meta_data('_gls_tracking_codes', $data['tracking_codes']);
                            }
                            if (!empty($data['parcel_ids'])) {
                                $order->update_meta_data('_gls_parcel_ids', $data['parcel_ids']);
                            }
                            
                            // Save bulk PDF URL to individual orders so tracking button appears
                            $order->update_meta_data('_gls_print_label', $pdf_url);
                            $order->save();
                        }
                    }
                }

                // Add query args to URL for displaying notices and providing PDF link
                $redirect = add_query_arg(
                    array(
                        'bulk_action' => 'print_gls_labels',
                        'gls_labels_printed' => count($order_ids) - count($failed_orders),
                        'gls_labels_failed' => count($failed_orders),
                        'gls_pdf_url' => urlencode($pdf_url),
                        'failed_orders' => implode(',', array_column($failed_orders, 'order_id')),
                    ),
                    $redirect
                );
            } else {
                // Handle error case
                $redirect = add_query_arg(
                    array(
                        'bulk_action' => 'print_gls_labels',
                        'gls_labels_printed_error' => 'true',
                    ),
                    $redirect
                );
            }
        }
    
        return $redirect;
    }

    public function bulk_create_print_labels($body)
    {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
    
        WP_Filesystem();
        global $wp_filesystem;
    
        $label_print = implode(array_map('chr', $body['Labels']));
        $upload_dir = wp_upload_dir();
        $timestamp = current_time('YmdHis');
        $file_name = 'shipping_label_bulk_' . $timestamp . '.pdf';
        $file_url = $upload_dir['url'] . '/' . $file_name;
        $file_path = $upload_dir['path'] . '/' . $file_name;
        
        if ($wp_filesystem->put_contents($file_path, $label_print)) {
            return $file_url;
        }
        return false;
    }

    // Display admin notice after bulk action
    public function gls_bulk_action_admin_notice() {
        if (isset($_REQUEST['bulk_action'])) {
            if ('generate_gls_labels' == $_REQUEST['bulk_action']) {
                $generated = intval($_REQUEST['gls_labels_generated']);
                $failed = intval($_REQUEST['gls_labels_failed']);
                $failed_orders = isset($_REQUEST['failed_orders']) ? explode(',', $_REQUEST['failed_orders']) : [];

                // Prepare success message
                $message = sprintf(
                    _n(
                        /* translators: %s: number of generated labels */
                        '%s GLS label was successfully generated.',
                        '%s GLS labels were successfully generated.',
                        $generated,
                        'gls-shipping-for-woocommerce'
                    ),
                    number_format_i18n($generated)
                );
                
                // Add failure message if any labels failed to generate
                if ($failed > 0) {
                    $message .= ' ' . sprintf(
                        _n(
                            /* translators: %s: number of failed labels */
                            '%s label failed to generate.',
                            '%s labels failed to generate.',
                            $failed,
                            'gls-shipping-for-woocommerce'
                        ),
                        number_format_i18n($failed)
                    );
                    $message .= ' ' . sprintf(
                        /* translators: %s: comma-separated list of order IDs that failed */
                        __('Failed order IDs: %s', 'gls-shipping-for-woocommerce'),
                        implode(', ', $failed_orders)
                    );
                }

                // Display the notice
                printf('<div id="message" class="updated notice is-dismissible"><p>' . $message . '</p></div>');
            } elseif ('print_gls_labels' == $_REQUEST['bulk_action']) {
                if (isset($_REQUEST['gls_labels_printed']) && isset($_REQUEST['gls_pdf_url'])) {
                    $printed = intval($_REQUEST['gls_labels_printed']);
                    $failed = intval($_REQUEST['gls_labels_failed']);
                    $pdf_url = urldecode($_REQUEST['gls_pdf_url']);
                    $failed_orders = isset($_REQUEST['failed_orders']) ? explode(',', $_REQUEST['failed_orders']) : [];
                    
                    // Prepare success message
                    $message = sprintf(
                        _n(
                            /* translators: %s: number of orders processed */
                            'GLS label for %s order has been generated. ',
                            'GLS labels for %s orders have been generated. ',
                            $printed,
                            'gls-shipping-for-woocommerce'
                        ),
                        number_format_i18n($printed)
                    );

                    // Add failure message if any labels failed to generate
                    if ($failed > 0) {
                        $message .= sprintf(
                            _n(
                                /* translators: %s: number of failed labels */
                                '%s label failed to generate. ',
                                '%s labels failed to generate. ',
                                $failed,
                                'gls-shipping-for-woocommerce'
                            ),
                            number_format_i18n($failed)
                        );
                        $message .= sprintf(
                            __('Failed order IDs: %s', 'gls-shipping-for-woocommerce'),
                            implode(', ', $failed_orders)
                        );
                    }
                    
                    $message .= sprintf(
                        /* translators: %s: URL to download the PDF file */
                        __('<br><a href="%s" target="_blank">Click here to download the PDF</a>', 'gls-shipping-for-woocommerce'),
                        esc_url($pdf_url)
                    );

                    // Display the notice
                    printf('<div id="message" class="updated notice is-dismissible"><p>' . $message . '</p></div>');
                } elseif (isset($_REQUEST['gls_labels_printed_error'])) {
                    $message = __('An error occurred while generating the GLS labels PDF.', 'gls-shipping-for-woocommerce');
                    printf('<div id="message" class="error notice is-dismissible"><p>' . $message . '</p></div>');
                }
            }
        }
    }

    // Enqueue bulk styles
    public function admin_enqueue_styles()
    {
        $currentScreen = get_current_screen();
        $screenID = $currentScreen->id;
        if ($screenID === "shop_order" || $screenID === "woocommerce_page_wc-orders" || $screenID === "edit-shop_order") {
             // Add inline CSS for GLS buttons
             $custom_css = "
                a.button.gls-download-label::after {
                    content: '\\f316';
                }
                a.button.gls-generate-label::after {
                    content: '\\f502';
                }
				.wc-action-button-gls-download-label {
					background: #c0e2ad !important;
					color: #2c4700 !important;
					border-color: #2c4700 !important;
				}

				.wc-action-button-gls-generate-label {
					background: #c8e7f2 !important;
					color: #2c4700 !important;
					border-color: #2c4700 !important;
				}
            ";
            wp_add_inline_style('woocommerce_admin_styles', $custom_css);
        }
    }

    /**
     * Add GLS Tracking Number column to orders list
     */
    public function add_gls_parcel_id_column($columns)
    {
        // Insert the GLS Tracking Number column after the order status column
        $new_columns = array();
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'order_status') {
                $new_columns['gls_parcel_id'] = __('GLS Tracking Number', 'gls-shipping-for-woocommerce');
            }
        }
        return $new_columns;
    }

    /**
     * Populate GLS Tracking Number column content (works for both standard and HPOS)
     */
    public function populate_gls_parcel_id_column($column, $order_data)
    {
        if ($column === 'gls_parcel_id') {
            // Handle different parameter types for standard vs HPOS
            if (is_object($order_data)) {
                // HPOS passes order object
                $order_id = $order_data->get_id();
            } else {
                // Standard WooCommerce passes post ID
                $order_id = $order_data;
            }
            $this->display_parcel_ids($order_id);
        }
    }

    /**
     * Display tracking numbers for an order
     */
    private function display_parcel_ids($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            echo '-';
            return;
        }

        $tracking_numbers = array();

        // Get tracking numbers from _gls_tracking_codes meta (preferred)
        $stored_tracking_codes = $order->get_meta('_gls_tracking_codes', true);
        if (!empty($stored_tracking_codes)) {
            if (is_array($stored_tracking_codes)) {
                foreach ($stored_tracking_codes as $tracking_code) {
                    if (!in_array($tracking_code, $tracking_numbers)) {
                        $tracking_numbers[] = esc_html($tracking_code);
                    }
                }
            } else {
                if (!in_array($stored_tracking_codes, $tracking_numbers)) {
                    $tracking_numbers[] = esc_html($stored_tracking_codes);
                }
            }
        } else {
            // Legacy support - check for single tracking code
            $legacy_tracking_code = $order->get_meta('_gls_tracking_code', true);
            if (!empty($legacy_tracking_code)) {
                $tracking_numbers[] = esc_html($legacy_tracking_code);
            }
        }

        // Display the tracking numbers
        if (!empty($tracking_numbers)) {
            echo implode(' ', $tracking_numbers);
        } else {
            echo '-';
        }
    }
}

new GLS_Shipping_Bulk();
