<?php
/**
 * Bulk Actions
 * Bulk label generation for multiple orders
 */

namespace MyGLS\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class BulkActions {
    public function __construct() {
        add_filter('bulk_actions-edit-shop_order', [$this, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'handle_bulk_actions'], 10, 3);
        add_action('admin_notices', [$this, 'bulk_action_notices']);
    }
    
    /**
     * Add bulk actions to orders list
     */
    public function add_bulk_actions($actions) {
        $actions['mygls_generate_labels'] = __('Generate GLS Labels', 'mygls-woocommerce');
        $actions['mygls_download_labels'] = __('Download GLS Labels', 'mygls-woocommerce');
        $actions['mygls_delete_labels'] = __('Delete GLS Labels', 'mygls-woocommerce');
        
        return $actions;
    }
    
    /**
     * Handle bulk actions
     */
    public function handle_bulk_actions($redirect_to, $action, $post_ids) {
        if (!in_array($action, ['mygls_generate_labels', 'mygls_download_labels', 'mygls_delete_labels'])) {
            return $redirect_to;
        }
        
        $processed = 0;
        $errors = [];
        
        switch ($action) {
            case 'mygls_generate_labels':
                $result = $this->bulk_generate_labels($post_ids);
                $processed = $result['success'];
                $errors = $result['errors'];
                break;
                
            case 'mygls_download_labels':
                $this->bulk_download_labels($post_ids);
                return $redirect_to; // Exit after download
                
            case 'mygls_delete_labels':
                $result = $this->bulk_delete_labels($post_ids);
                $processed = $result['success'];
                $errors = $result['errors'];
                break;
        }
        
        $redirect_to = add_query_arg([
            'mygls_bulk_action' => $action,
            'mygls_processed' => $processed,
            'mygls_errors' => count($errors)
        ], $redirect_to);
        
        if (!empty($errors)) {
            set_transient('mygls_bulk_errors', $errors, 30);
        }
        
        return $redirect_to;
    }
    
    /**
     * Bulk generate labels
     */
    private function bulk_generate_labels($order_ids) {
        $success = 0;
        $errors = [];
        
        $api = mygls_get_api_client();
        $settings = mygls_get_settings();
        $printer_type = $settings['printer_type'] ?? 'A4_2x2';
        
        global $wpdb;
        
        foreach ($order_ids as $order_id) {
            try {
                // Check if label already exists
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}mygls_labels WHERE order_id = %d",
                    $order_id
                ));
                
                if ($existing > 0) {
                    $errors[] = sprintf(__('Order #%d already has a label', 'mygls-woocommerce'), $order_id);
                    continue;
                }
                
                $parcel = $api->buildParcelFromOrder($order_id);
                
                if (!$parcel) {
                    $errors[] = sprintf(__('Failed to build parcel for order #%d', 'mygls-woocommerce'), $order_id);
                    continue;
                }
                
                $result = $api->printLabels([$parcel], $printer_type);
                
                if (isset($result['error']) || !empty($result['PrintLabelsErrorList'])) {
                    $error_msg = $result['error'] ?? $result['PrintLabelsErrorList'][0]['ErrorDescription'] ?? 'Unknown error';
                    $errors[] = sprintf(__('Order #%d: %s', 'mygls-woocommerce'), $order_id, $error_msg);
                    continue;
                }
                
                if (!empty($result['PrintLabelsInfoList'])) {
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
                            'tracking_url' => 'https://gls-group.eu/HU/hu/csomagkovetes?match=' . $label_info['ParcelNumber'],
                            'label_pdf' => $label_pdf_base64,
                            'status' => 'pending'
                        ],
                        ['%d', '%d', '%d', '%s', '%s', '%s']
                    );
                    
                    $order = wc_get_order($order_id);
                    $order->add_order_note(sprintf(__('GLS label generated (bulk): %s', 'mygls-woocommerce'), $label_info['ParcelNumber']));
                    
                    $success++;
                }
                
            } catch (\Exception $e) {
                $errors[] = sprintf(__('Order #%d: %s', 'mygls-woocommerce'), $order_id, $e->getMessage());
            }
        }
        
        return ['success' => $success, 'errors' => $errors];
    }
    
    /**
     * Bulk download labels as ZIP
     */
    private function bulk_download_labels($order_ids) {
        global $wpdb;
        
        $labels = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mygls_labels WHERE order_id IN (" . implode(',', array_map('absint', $order_ids)) . ") AND label_pdf IS NOT NULL"
        ));
        
        if (empty($labels)) {
            wp_die(__('No labels found for selected orders', 'mygls-woocommerce'));
        }
        
        // Create ZIP file
        $zip_filename = 'gls-labels-' . date('Y-m-d-His') . '.zip';
        $zip_path = sys_get_temp_dir() . '/' . $zip_filename;
        
        $zip = new \ZipArchive();
        if ($zip->open($zip_path, \ZipArchive::CREATE) !== TRUE) {
            wp_die(__('Failed to create ZIP file', 'mygls-woocommerce'));
        }
        
        foreach ($labels as $label) {
            $filename = 'label-' . $label->parcel_number . '.pdf';
            $zip->addFromString($filename, $label->label_pdf);
        }
        
        $zip->close();
        
        // Send file
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
        header('Content-Length: ' . filesize($zip_path));
        
        readfile($zip_path);
        unlink($zip_path);
        
        exit;
    }
    
    /**
     * Bulk delete labels
     */
    private function bulk_delete_labels($order_ids) {
        $success = 0;
        $errors = [];
        
        global $wpdb;
        $api = mygls_get_api_client();
        
        foreach ($order_ids as $order_id) {
            try {
                $label = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}mygls_labels WHERE order_id = %d",
                    $order_id
                ));
                
                if (!$label) {
                    continue;
                }
                
                // Delete from GLS
                $result = $api->deleteLabels([$label->parcel_id]);
                
                if (isset($result['error'])) {
                    $errors[] = sprintf(__('Order #%d: %s', 'mygls-woocommerce'), $order_id, $result['error']);
                    continue;
                }
                
                // Delete from database
                $wpdb->delete(
                    $wpdb->prefix . 'mygls_labels',
                    ['order_id' => $order_id],
                    ['%d']
                );
                
                $order = wc_get_order($order_id);
                $order->add_order_note(__('GLS label deleted (bulk)', 'mygls-woocommerce'));
                
                $success++;
                
            } catch (\Exception $e) {
                $errors[] = sprintf(__('Order #%d: %s', 'mygls-woocommerce'), $order_id, $e->getMessage());
            }
        }
        
        return ['success' => $success, 'errors' => $errors];
    }
    
    /**
     * Show admin notices for bulk actions
     */
    public function bulk_action_notices() {
        if (!isset($_GET['mygls_bulk_action'])) {
            return;
        }
        
        $action = sanitize_text_field($_GET['mygls_bulk_action']);
        $processed = absint($_GET['mygls_processed'] ?? 0);
        $error_count = absint($_GET['mygls_errors'] ?? 0);
        
        $messages = [
            'mygls_generate_labels' => __('Generated %d shipping labels', 'mygls-woocommerce'),
            'mygls_delete_labels' => __('Deleted %d shipping labels', 'mygls-woocommerce')
        ];
        
        if (isset($messages[$action])) {
            $class = $error_count > 0 ? 'notice-warning' : 'notice-success';
            
            echo '<div class="notice ' . $class . ' is-dismissible"><p>';
            printf($messages[$action], $processed);
            
            if ($error_count > 0) {
                echo ' ' . sprintf(__('(%d errors)', 'mygls-woocommerce'), $error_count);
                
                $errors = get_transient('mygls_bulk_errors');
                if ($errors) {
                    echo '<ul style="margin-top: 10px;">';
                    foreach (array_slice($errors, 0, 5) as $error) {
                        echo '<li>' . esc_html($error) . '</li>';
                    }
                    if (count($errors) > 5) {
                        echo '<li>' . sprintf(__('...and %d more', 'mygls-woocommerce'), count($errors) - 5) . '</li>';
                    }
                    echo '</ul>';
                    delete_transient('mygls_bulk_errors');
                }
            }
            
            echo '</p></div>';
        }
    }
}