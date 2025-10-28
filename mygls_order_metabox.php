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
        ad