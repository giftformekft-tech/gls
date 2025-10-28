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