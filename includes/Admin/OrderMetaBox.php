<?php
/**
 * Order MetaBox
 * Shipping label generation and management in order edit screen
 */

namespace MyGLS\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class OrderMetaBox {
    public function __construct() {
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('wp_ajax_mygls_generate_label', [$this, 'ajax_generate_label']);
        add_action('wp_ajax_mygls_download_label', [$this, 'ajax_download_label']);
        add_action('wp_ajax_mygls_delete_label', [$this, 'ajax_delete_label']);
        add_action('wp_ajax_mygls_refresh_status', [$this, 'ajax_refresh_status']);
        
        // Auto-generate on order status change
        add_action('woocommerce_order_status_changed', [$this, 'auto_generate_label'], 10, 3);
    }
    
    public function add_meta_box() {
        // Traditional post-based orders
        add_meta_box(
            'mygls_shipping_label',
            __('GLS Shipping Label', 'mygls-woocommerce'),
            [$this, 'render_meta_box'],
            'shop_order',
            'side',
            'high'
        );

        // HPOS (High-Performance Order Storage) - modern WooCommerce
        add_meta_box(
            'mygls_shipping_label',
            __('GLS Shipping Label', 'mygls-woocommerce'),
            [$this, 'render_meta_box'],
            'woocommerce_page_wc-orders',
            'side',
            'high'
        );
    }
    
    public function render_meta_box($post_or_order_object) {
        // Handle both traditional post and HPOS order object
        $order = ($post_or_order_object instanceof \WC_Order)
            ? $post_or_order_object
            : wc_get_order($post_or_order_object->ID);

        if (!$order) {
            return;
        }
        
        global $wpdb;
        $label = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mygls_labels WHERE order_id = %d ORDER BY created_at DESC LIMIT 1",
            $order->get_id()
        ));
        
        $parcelshop_data = get_post_meta($order->get_id(), '_mygls_parcelshop_data', true);
        if (!$parcelshop_data) {
            $parcelshop_data = get_post_meta($order->get_id(), '_expressone_parcelshop_data', true);
        }

        $carrier = $label ? $label->carrier : $this->get_carrier_for_order($order);
        
        wp_nonce_field('mygls_order_meta_box', 'mygls_order_meta_box_nonce');
        ?>
        
        <div class="mygls-order-metabox">
            <?php if ($label): ?>
                <!-- Label exists -->
                <div class="mygls-label-info">
                    <div class="mygls-status-badge mygls-status-<?php echo esc_attr($label->status); ?>">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php echo esc_html(ucfirst($label->status)); ?>
                    </div>
                    
                    <div class="mygls-label-details">
                        <p>
                            <strong><?php _e('Parcel Number:', 'mygls-woocommerce'); ?></strong><br>
                            <code class="mygls-parcel-number"><?php echo esc_html($label->parcel_number); ?></code>
                        </p>
                        
                        <?php if ($label->tracking_url): ?>
                            <p>
                                <a href="<?php echo esc_url($label->tracking_url); ?>" target="_blank" class="button button-small">
                                    <span class="dashicons dashicons-visibility"></span>
                                    <?php _e('Track Parcel', 'mygls-woocommerce'); ?>
                                </a>
                            </p>
                        <?php endif; ?>
                        
                        <p class="mygls-meta-info">
                            <small>
                                <?php _e('Created:', 'mygls-woocommerce'); ?> 
                                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($label->created_at))); ?>
                            </small>
                        </p>
                    </div>
                    
                    <div class="mygls-label-actions">
                        <button type="button" class="button button-primary mygls-download-label" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                            <span class="dashicons dashicons-download"></span>
                            <?php _e('Download Label', 'mygls-woocommerce'); ?>
                        </button>
                        
                        <button type="button" class="button mygls-refresh-status" data-order-id="<?php echo esc_attr($order->get_id()); ?>" data-parcel-number="<?php echo esc_attr($label->parcel_number); ?>">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Refresh Status', 'mygls-woocommerce'); ?>
                        </button>
                        
                        <button type="button" class="button button-link-delete mygls-delete-label" data-order-id="<?php echo esc_attr($order->get_id()); ?>" data-parcel-id="<?php echo esc_attr($label->parcel_id); ?>">
                            <span class="dashicons dashicons-trash"></span>
                            <?php _e('Delete Label', 'mygls-woocommerce'); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Parcel Status History -->
                <div class="mygls-status-history">
                    <h4><?php _e('Status History', 'mygls-woocommerce'); ?></h4>
                    <div id="mygls-status-list" class="mygls-timeline">
                        <p class="mygls-loading"><?php _e('Loading...', 'mygls-woocommerce'); ?></p>
                    </div>
                </div>
                
            <?php else: ?>
                <!-- No label yet -->
                <div class="mygls-no-label">
                    <p>
                        <span class="dashicons dashicons-info"></span>
                        <?php _e('No shipping label generated yet.', 'mygls-woocommerce'); ?>
                    </p>
                    
                    <?php if ($parcelshop_data): ?>
                        <div class="mygls-parcelshop-info">
                            <strong><?php _e('Selected Parcelshop:', 'mygls-woocommerce'); ?></strong><br>
                            <small>
                                <?php echo esc_html($parcelshop_data['name']); ?><br>
                                <?php echo esc_html($parcelshop_data['address']); ?>
                            </small>
                        </div>
                    <?php endif; ?>
                    
                    <button type="button" class="button button-primary button-large mygls-generate-label" data-order-id="<?php echo esc_attr($order->get_id()); ?>" data-carrier="<?php echo esc_attr($carrier); ?>">
                        <span class="dashicons dashicons-media-document"></span>
                        <?php echo $carrier === 'expressone' ? __('Express One Címke Létrehozása', 'mygls-woocommerce') : __('GLS Címke Létrehozása', 'mygls-woocommerce'); ?>
                    </button>
                </div>
            <?php endif; ?>
            
            <!-- Shipping Details -->
            <div class="mygls-shipping-details">
                <h4><?php _e('Shipping Information', 'mygls-woocommerce'); ?></h4>
                <table class="mygls-details-table">
                    <tr>
                        <td><strong><?php _e('Method:', 'mygls-woocommerce'); ?></strong></td>
                        <td><?php echo esc_html($order->get_shipping_method()); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('Total:', 'mygls-woocommerce'); ?></strong></td>
                        <td><?php echo wc_price($order->get_total()); ?></td>
                    </tr>
                    <?php if ($order->get_payment_method() === 'cod'): ?>
                        <tr>
                            <td><strong><?php _e('COD Amount:', 'mygls-woocommerce'); ?></strong></td>
                            <td><?php echo wc_price($order->get_total()); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td><strong><?php _e('Destination:', 'mygls-woocommerce'); ?></strong></td>
                        <td>
                            <?php echo esc_html($order->get_shipping_city()); ?>, 
                            <?php echo esc_html($order->get_shipping_postcode()); ?><br>
                            <?php echo esc_html(WC()->countries->countries[$order->get_shipping_country()] ?? $order->get_shipping_country()); ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <style>
        .mygls-order-metabox {
            font-size: 13px;
        }
        .mygls-status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 4px;
            font-weight: 600;
            margin-bottom: 15px;
        }
        .mygls-status-badge .dashicons {
            font-size: 16px;
            vertical-align: middle;
            margin-right: 4px;
        }
        .mygls-status-delivered {
            background: #d4edda;
            color: #155724;
        }
        .mygls-status-pending {
            background: #fff3cd;
            color: #856404;
        }
        .mygls-status-in_transit {
            background: #d1ecf1;
            color: #0c5460;
        }
        .mygls-parcel-number {
            font-size: 14px;
            font-weight: bold;
            background: #f5f5f5;
            padding: 5px 10px;
            border-radius: 3px;
            display: inline-block;
        }
        .mygls-label-actions {
            margin: 15px 0;
        }
        .mygls-label-actions .button {
            width: 100%;
            margin-bottom: 8px;
            text-align: center;
        }
        .mygls-no-label {
            text-align: center;
            padding: 20px 0;
        }
        .mygls-no-label .dashicons {
            font-size: 48px;
            width: 48px;
            height: 48px;
            color: #999;
        }
        .mygls-parcelshop-info {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin: 15px 0;
            text-align: left;
        }
        .mygls-meta-info {
            color: #666;
            font-size: 12px;
        }
        .mygls-details-table {
            width: 100%;
            margin-top: 10px;
        }
        .mygls-details-table td {
            padding: 5px 0;
            border-bottom: 1px solid #eee;
        }
        .mygls-details-table td:first-child {
            width: 40%;
        }
        .mygls-timeline {
            margin-top: 10px;
            border-left: 2px solid #ddd;
            padding-left: 15px;
        }
        .mygls-timeline-item {
            margin-bottom: 15px;
            position: relative;
        }
        .mygls-timeline-item:before {
            content: '';
            position: absolute;
            left: -20px;
            top: 5px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #2271b1;
            border: 2px solid #fff;
        }
        .mygls-timeline-date {
            font-size: 11px;
            color: #666;
        }
        .mygls-loading {
            text-align: center;
            color: #666;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Auto-load status if label exists
            <?php if ($label): ?>
                loadParcelStatus(<?php echo $label->parcel_number; ?>);
            <?php endif; ?>
            
            // Generate label
            $('.mygls-generate-label').on('click', function() {
                var btn = $(this);
                var orderId = btn.data('order-id');
                var carrier = btn.data('carrier');
                
                if (!confirm(myglsAdmin.i18n.confirmGenerate)) {
                    return;
                }
                
                btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + myglsAdmin.i18n.processing);
                
                $.ajax({
                    url: myglsAdmin.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mygls_generate_label',
                        nonce: myglsAdmin.nonce,
                        order_id: orderId,
                        carrier: carrier
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || myglsAdmin.i18n.error);
                            btn.prop('disabled', false).html('<span class="dashicons dashicons-media-document"></span> Újra');
                        }
                    },
                    error: function() {
                        alert(myglsAdmin.i18n.error);
                        btn.prop('disabled', false).html('<span class="dashicons dashicons-media-document"></span> Újra');
                    }
                });
            });
            
            // Download label
            $('.mygls-download-label').on('click', function() {
                var orderId = $(this).data('order-id');
                window.location.href = myglsAdmin.ajaxurl + '?action=mygls_download_label&order_id=' + orderId + '&nonce=' + myglsAdmin.nonce;
            });
            
            // Delete label
            $('.mygls-delete-label').on('click', function() {
                var btn = $(this);
                var orderId = btn.data('order-id');
                var parcelId = btn.data('parcel-id');
                
                if (!confirm(myglsAdmin.i18n.confirmDelete)) {
                    return;
                }
                
                btn.prop('disabled', true);
                
                $.ajax({
                    url: myglsAdmin.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mygls_delete_label',
                        nonce: myglsAdmin.nonce,
                        order_id: orderId,
                        parcel_id: parcelId
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || myglsAdmin.i18n.error);
                            btn.prop('disabled', false);
                        }
                    }
                });
            });
            
            // Refresh status
            $('.mygls-refresh-status').on('click', function() {
                var btn = $(this);
                var parcelNumber = btn.data('parcel-number');
                
                btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> <?php _e('Refreshing...', 'mygls-woocommerce'); ?>');
                
                loadParcelStatus(parcelNumber, function() {
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> <?php _e('Refresh Status', 'mygls-woocommerce'); ?>');
                });
            });
            
            function loadParcelStatus(parcelNumber, callback) {
                $.ajax({
                    url: myglsAdmin.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mygls_refresh_status',
                        nonce: myglsAdmin.nonce,
                        parcel_number: parcelNumber
                    },
                    success: function(response) {
                        if (response.success && response.data.statuses) {
                            var html = '';
                            response.data.statuses.forEach(function(status) {
                                html += '<div class="mygls-timeline-item">';
                                html += '<strong>' + status.StatusDescription + '</strong><br>';
                                if (status.StatusInfo) {
                                    html += '<small>' + status.StatusInfo + '</small><br>';
                                }
                                html += '<span class="mygls-timeline-date">' + status.StatusDate + '</span>';
                                html += '</div>';
                            });
                            $('#mygls-status-list').html(html);
                        }
                        if (callback) callback();
                    },
                    error: function() {
                        $('#mygls-status-list').html('<p><?php _e('Error loading status', 'mygls-woocommerce'); ?></p>');
                        if (callback) callback();
                    }
                });
            }
        });
        </script>
        <?php
    }
    
    /**
     * AJAX: Generate shipping label
     */
    public function ajax_generate_label() {
        check_ajax_referer('mygls_admin_nonce', 'nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('Permission denied', 'mygls-woocommerce')]);
        }
        
        $order_id = absint($_POST['order_id'] ?? 0);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found', 'mygls-woocommerce')]);
        }
        
        $carrier = sanitize_text_field($_POST['carrier'] ?? $this->get_carrier_for_order($order));

        try {
            if ($carrier === 'expressone') {
                if (!function_exists('expressone_get_api_client')) {
                    function expressone_get_api_client() {
                        if (!class_exists('ExpressOne\\API\\Client')) { return null; }
                        return new \ExpressOne\API\Client();
                    }
                }
                $api = expressone_get_api_client();
                if (!$api) {
                    wp_send_json_error(['message' => __('Express One API elérés nem lehetséges.', 'mygls-woocommerce')]);
                }

                $parcel = $api->buildParcelFromOrder($order_id);
                if (!$parcel) {
                    wp_send_json_error(['message' => __('Failed to build parcel data', 'mygls-woocommerce')]);
                }

                $result = $api->createLabels([$parcel]);
                
                if (isset($result['error'])) {
                    wp_send_json_error(['message' => $result['error']]);
                }
                
                $response = $result['response'] ?? [];
                $deliveries = $response['deliveries'] ?? [];
                if (empty($deliveries)) {
                    wp_send_json_error(['message' => __('No label data received', 'mygls-woocommerce')]);
                }
                
                $delivery_result = $deliveries[0];
                if (($delivery_result['code'] ?? '') !== '0') {
                    wp_send_json_error(['message' => $delivery_result['message'] ?? 'Unknown error']);
                }
                
                $data = $delivery_result['data'] ?? [];
                $parcel_numbers = $data['parcel_numbers'] ?? [];
                $parcel_number = $parcel_numbers[0] ?? '';
                $parcel_id = 0; // Express One esetén nincs külön parcel_id, csak szám
                
                $labels = $response['labels'] ?? ($data['labels'] ?? []);
                $label_base64 = '';
                if (!empty($labels) && is_array($labels)) {
                     if (isset($labels[0]['data'])) {
                         $label_base64 = $labels[0]['data'];
                     } elseif (isset($labels['data'])) {
                         $label_base64 = $labels['data'];
                     }
                }

                if (empty($label_base64) || empty($parcel_number)) {
                    wp_send_json_error(['message' => __('Missing label data from Express One API', 'mygls-woocommerce')]);
                }

                $api = mygls_get_api_client();
                $parcel = $api->buildParcelFromOrder($order_id);
                
                if (!$parcel) {
                    wp_send_json_error(['message' => __('Failed to build parcel data', 'mygls-woocommerce')]);
                }
                
                $settings = mygls_get_settings();
                $printer_type = $settings['printer_type'] ?? 'A4_2x2';
                
                $result = $api->printLabels([$parcel], $printer_type);
                
                if (isset($result['error'])) {
                    wp_send_json_error(['message' => $result['error']]);
                }
                
                if (!empty($result['PrintLabelsErrorList'])) {
                    $error = $result['PrintLabelsErrorList'][0];
                    wp_send_json_error(['message' => $error['ErrorDescription'] ?? 'Unknown error']);
                }
                
                if (empty($result['PrintLabelsInfoList']) || empty($result['Labels'])) {
                    wp_send_json_error(['message' => __('No label data received', 'mygls-woocommerce')]);
                }
                
                $label_info = $result['PrintLabelsInfoList'][0];
                $parcel_number = $label_info['ParcelNumber'] ?? '';
                $parcel_id = $label_info['ParcelId'] ?? 0;

                // Convert byte array to PDF binary
                $label_bytes = $result['Labels'];
                if (is_array($label_bytes)) {
                    $label_pdf_binary = implode('', array_map('chr', $label_bytes));
                } else {
                    $label_pdf_binary = $label_bytes;
                }

                $label_base64 = base64_encode($label_pdf_binary);
            } // end if carrier
            
            // Save to database
            global $wpdb;
            $wpdb->insert(
                $wpdb->prefix . 'mygls_labels',
                [
                    'order_id' => $order_id,
                    'parcel_id' => $parcel_id,
                    'parcel_number' => $parcel_number,
                    'carrier' => $carrier,
                    'tracking_url' => $this->get_tracking_url($parcel_number, $carrier),
                    'label_pdf' => $label_base64,
                    'status' => 'pending'
                ],
                ['%d', '%d', '%s', '%s', '%s', '%s', '%s']
            );
            
            // Add order note
            $order->add_order_note(
                sprintf(
                    __('%s szállítási címke generálva. Csomagszám: %s', 'mygls-woocommerce'),
                    strtoupper($carrier),
                    $parcel_number
                )
            );
            
            // Update order meta
            update_post_meta($order_id, '_mygls_parcel_number', $parcel_number);
            update_post_meta($order_id, '_mygls_parcel_id', $parcel_id);
            
            wp_send_json_success([
                'message' => __('Label generated successfully', 'mygls-woocommerce'),
                'parcel_number' => $parcel_number
            ]);
            
        } catch (\Exception $e) {
            mygls_log('Label generation error: ' . $e->getMessage(), 'error');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * AJAX: Download label PDF
     */
    public function ajax_download_label() {
        check_ajax_referer('mygls_admin_nonce', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_die(__('Permission denied', 'mygls-woocommerce'));
        }

        $order_id = absint($_GET['order_id'] ?? 0);

        global $wpdb;
        $label = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mygls_labels WHERE order_id = %d ORDER BY created_at DESC LIMIT 1",
            $order_id
        ));

        if (!$label || empty($label->label_pdf)) {
            wp_die(__('Label not found', 'mygls-woocommerce'));
        }

        // Decode base64 PDF data from API
        $pdf_data = base64_decode($label->label_pdf);

        if ($pdf_data === false) {
            wp_die(__('Invalid label data', 'mygls-woocommerce'));
        }

        // Send headers and PDF data
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="gls-label-' . $label->parcel_number . '.pdf"');

        echo $pdf_data;
        exit;
    }
    
    /**
     * AJAX: Delete label
     */
    public function ajax_delete_label() {
        check_ajax_referer('mygls_admin_nonce', 'nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('Permission denied', 'mygls-woocommerce')]);
        }
        
        $order_id = absint($_POST['order_id'] ?? 0);
        $parcel_id = absint($_POST['parcel_id'] ?? 0);

        global $wpdb;
        $label = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mygls_labels WHERE order_id = %d ORDER BY created_at DESC LIMIT 1",
            $order_id
        ));

        if (!$label) {
            wp_send_json_error(['message' => __('Label not found in database', 'mygls-woocommerce')]);
        }
        
        try {
            if ($label->carrier === 'expressone') {
                if (!function_exists('expressone_get_api_client')) {
                    function expressone_get_api_client() {
                        if (!class_exists('ExpressOne\\API\\Client')) { return null; }
                        return new \ExpressOne\API\Client();
                    }
                }
                $api = expressone_get_api_client();
                $result = $api->deleteLabels([$label->parcel_number]);
            } else {
                $api = mygls_get_api_client();
                $result = $api->deleteLabels([$label->parcel_id]);
            }
            
            if (isset($result['error'])) {
                wp_send_json_error(['message' => $result['error']]);
            }
            
            // Delete from database
            $wpdb->delete(
                $wpdb->prefix . 'mygls_labels',
                ['order_id' => $order_id],
                ['%d']
            );
            
            // Add order note
            $order = wc_get_order($order_id);
            $order->add_order_note(sprintf(__('%s szállítási címke törölve', 'mygls-woocommerce'), strtoupper($label->carrier)));
            
            wp_send_json_success(['message' => __('Label deleted successfully', 'mygls-woocommerce')]);
            
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * AJAX: Refresh parcel status
     */
    public function ajax_refresh_status() {
        check_ajax_referer('mygls_admin_nonce', 'nonce');
        
        $parcel_number = sanitize_text_field($_POST['parcel_number'] ?? '');
        
        global $wpdb;
        $label = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mygls_labels WHERE parcel_number = %s ORDER BY created_at DESC LIMIT 1",
            $parcel_number
        ));

        try {
            if ($label && $label->carrier === 'expressone') {
                if (!function_exists('expressone_get_api_client')) {
                    function expressone_get_api_client() {
                        if (!class_exists('ExpressOne\\API\\Client')) { return null; }
                        return new \ExpressOne\API\Client();
                    }
                }
                $api = expressone_get_api_client();
                $result = $api->getParcelStatus($parcel_number);
                
                if (isset($result['error'])) {
                    wp_send_json_error(['message' => $result['error']]);
                }
                
                // Express One válasz konvertálása ugyanarra a formátumra, mint a GLS
                $response = $result['response'] ?? [];
                $events = $response['events'] ?? ($response['status'] ?? []);
                if (!is_array($events)) $events = [$events];

                $formatted_statuses = [];
                foreach ($events as $event) {
                    if (is_array($event)) {
                        $formatted_statuses[] = [
                            'StatusDescription' => $event['status_name'] ?? ($event['status'] ?? 'Ismeretlen'),
                            'StatusInfo' => $event['reason'] ?? '',
                            'StatusDate' => $event['date'] ?? current_time('mysql')
                        ];
                    }
                }

                wp_send_json_success([
                    'statuses' => !empty($formatted_statuses) ? $formatted_statuses : [['StatusDescription' => 'Nincs státuszinformáció', 'StatusInfo' => '', 'StatusDate' => '']]
                ]);

            } else {
                $api = mygls_get_api_client();
                $result = $api->getParcelStatuses($parcel_number);
                
                if (isset($result['error'])) {
                    wp_send_json_error(['message' => $result['error']]);
                }
                
                wp_send_json_success([
                    'statuses' => $result['ParcelStatusList'] ?? []
                ]);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Auto-generate label on order status change
     */
    public function auto_generate_label($order_id, $old_status, $new_status) {
        $settings = mygls_get_settings();
        
        if (($settings['auto_generate_labels'] ?? '0') !== '1') {
            return;
        }
        
        if ($new_status !== 'processing') {
            return;
        }
        
        // Check if label already exists
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mygls_labels WHERE order_id = %d",
            $order_id
        ));
        
        if ($existing > 0) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $carrier = $this->get_carrier_for_order($order);

        // Generate label
        try {
            if ($carrier === 'expressone') {
                if (!function_exists('expressone_get_api_client')) {
                    function expressone_get_api_client() {
                        if (!class_exists('ExpressOne\\API\\Client')) { return null; }
                        return new \ExpressOne\API\Client();
                    }
                }
                $api = expressone_get_api_client();
                if (!$api) return;

                $parcel = $api->buildParcelFromOrder($order_id);
                if ($parcel) {
                    $result = $api->createLabels([$parcel]);
                    
                    if (!isset($result['error'])) {
                        $response = $result['response'] ?? [];
                        $deliveries = $response['deliveries'] ?? [];
                        if (!empty($deliveries)) {
                            $delivery_result = $deliveries[0];
                            if (($delivery_result['code'] ?? '') === '0') {
                                $data = $delivery_result['data'] ?? [];
                                $parcel_numbers = $data['parcel_numbers'] ?? [];
                                $parcel_number = $parcel_numbers[0] ?? '';
                                $parcel_id = 0;
                                
                                $labels = $response['labels'] ?? ($data['labels'] ?? []);
                                $label_base64 = '';
                                if (!empty($labels) && is_array($labels)) {
                                    if (isset($labels[0]['data'])) {
                                        $label_base64 = $labels[0]['data'];
                                    } elseif (isset($labels['data'])) {
                                        $label_base64 = $labels['data'];
                                    }
                                }

                                if (!empty($label_base64) && !empty($parcel_number)) {
                                    $wpdb->insert(
                                        $wpdb->prefix . 'mygls_labels',
                                        [
                                            'order_id' => $order_id,
                                            'parcel_id' => $parcel_id,
                                            'parcel_number' => $parcel_number,
                                            'carrier' => $carrier,
                                            'tracking_url' => $this->get_tracking_url($parcel_number, $carrier),
                                            'label_pdf' => $label_base64,
                                            'status' => 'pending'
                                        ],
                                        ['%d', '%d', '%s', '%s', '%s', '%s', '%s']
                                    );
                                    
                                    $order->add_order_note(sprintf(__('%s címke automatikusan generálva', 'mygls-woocommerce'), strtoupper($carrier)));
                                }
                            }
                        }
                    }
                }
            } else {
                $api = mygls_get_api_client();
                $parcel = $api->buildParcelFromOrder($order_id);
                
                if ($parcel) {
                    $result = $api->printLabels([$parcel], $settings['printer_type'] ?? 'A4_2x2');

                    if (!empty($result['PrintLabelsInfoList']) && empty($result['PrintLabelsErrorList'])) {
                        $label_info = $result['PrintLabelsInfoList'][0];

                        // Convert byte array to PDF binary
                        $label_bytes = $result['Labels'];
                        if (is_array($label_bytes)) {
                            $label_pdf_binary = implode('', array_map('chr', $label_bytes));
                        } else {
                            $label_pdf_binary = $label_bytes;
                        }
                        $label_pdf_base64 = base64_encode($label_pdf_binary);

                        $wpdb->insert(
                            $wpdb->prefix . 'mygls_labels',
                            [
                                'order_id' => $order_id,
                                'parcel_id' => $label_info['ParcelId'],
                                'parcel_number' => $label_info['ParcelNumber'],
                                'carrier' => 'gls',
                                'tracking_url' => $this->get_tracking_url($label_info['ParcelNumber'], 'gls'),
                                'label_pdf' => $label_pdf_base64,
                                'status' => 'pending'
                            ],
                            ['%d', '%d', '%s', '%s', '%s', '%s', '%s']
                        );
                        
                        $order->add_order_note(__('GLS label auto-generated', 'mygls-woocommerce'));
                    }
                }
            }
        } catch (\Exception $e) {
            mygls_log('Auto-generate error: ' . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Get tracking URL for parcel number
     */
    private function get_tracking_url($parcel_number, $carrier = 'gls') {
        if ($carrier === 'expressone') {
            return 'https://tracking.expressone.hu/?tracking_id=' . $parcel_number;
        }
        return 'https://gls-group.eu/HU/hu/csomagkovetes?match=' . $parcel_number;
    }

    /**
     * Get carrier based on shipping method
     */
    private function get_carrier_for_order($order) {
        if (!$order) return 'gls';
        
        $shipping_methods = $order->get_shipping_methods();
        foreach ($shipping_methods as $method) {
            $method_id = $method->get_method_id();
            if (strpos($method_id, 'expressone') !== false) {
                return 'expressone';
            }
        }
        return 'gls';
    }
}