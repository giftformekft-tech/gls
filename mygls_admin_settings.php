<?php
/**
 * Admin Settings Page
 */

namespace MyGLS\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Settings {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_mygls_test_connection', [$this, 'test_connection']);
    }
    
    public function add_menu() {
        add_menu_page(
            __('MyGLS Settings', 'mygls-woocommerce'),
            __('MyGLS', 'mygls-woocommerce'),
            'manage_woocommerce',
            'mygls-settings',
            [$this, 'render_settings_page'],
            'dashicons-location-alt',
            56
        );
        
        add_submenu_page(
            'mygls-settings',
            __('Settings', 'mygls-woocommerce'),
            __('Settings', 'mygls-woocommerce'),
            'manage_woocommerce',
            'mygls-settings'
        );
        
        add_submenu_page(
            'mygls-settings',
            __('Labels', 'mygls-woocommerce'),
            __('Labels', 'mygls-woocommerce'),
            'manage_woocommerce',
            'edit.php?post_type=shop_order&mygls_filter=1'
        );
    }
    
    public function register_settings() {
        register_setting('mygls_settings_group', 'mygls_settings', [
            'sanitize_callback' => [$this, 'sanitize_settings']
        ]);
    }
    
    public function sanitize_settings($input) {
        $sanitized = [];
        
        // API Settings
        $sanitized['country'] = sanitize_text_field($input['country'] ?? 'hu');
        $sanitized['username'] = sanitize_email($input['username'] ?? '');
        $sanitized['password'] = $input['password'] ?? '';
        $sanitized['client_number'] = absint($input['client_number'] ?? 0);
        $sanitized['test_mode'] = isset($input['test_mode']) ? '1' : '0';
        
        // Sender Address
        $sanitized['sender_name'] = sanitize_text_field($input['sender_name'] ?? '');
        $sanitized['sender_street'] = sanitize_text_field($input['sender_street'] ?? '');
        $sanitized['sender_house_number'] = sanitize_text_field($input['sender_house_number'] ?? '');
        $sanitized['sender_house_info'] = sanitize_text_field($input['sender_house_info'] ?? '');
        $sanitized['sender_city'] = sanitize_text_field($input['sender_city'] ?? '');
        $sanitized['sender_zip'] = sanitize_text_field($input['sender_zip'] ?? '');
        $sanitized['sender_country'] = sanitize_text_field($input['sender_country'] ?? 'HU');
        $sanitized['sender_contact_name'] = sanitize_text_field($input['sender_contact_name'] ?? '');
        $sanitized['sender_contact_phone'] = sanitize_text_field($input['sender_contact_phone'] ?? '');
        $sanitized['sender_contact_email'] = sanitize_email($input['sender_contact_email'] ?? '');
        
        // Label Settings
        $sanitized['printer_type'] = sanitize_text_field($input['printer_type'] ?? 'A4_2x2');
        $sanitized['auto_generate_labels'] = isset($input['auto_generate_labels']) ? '1' : '0';
        $sanitized['auto_status_sync'] = isset($input['auto_status_sync']) ? '1' : '0';
        $sanitized['status_sync_interval'] = absint($input['status_sync_interval'] ?? 60);
        
        return $sanitized;
    }
    
    public function render_settings_page() {
        $settings = get_option('mygls_settings', []);
        ?>
        <div class="wrap mygls-settings-wrap">
            <h1>
                <span class="dashicons dashicons-location-alt"></span>
                <?php _e('MyGLS Settings', 'mygls-woocommerce'); ?>
            </h1>
            
            <div class="mygls-settings-container">
                <form method="post" action="options.php" class="mygls-settings-form">
                    <?php settings_fields('mygls_settings_group'); ?>
                    
                    <!-- API Settings Tab -->
                    <div class="mygls-card">
                        <div class="mygls-card-header">
                            <h2><span class="dashicons dashicons-admin-network"></span> <?php _e('API Connection', 'mygls-woocommerce'); ?></h2>
                        </div>
                        <div class="mygls-card-body">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="country"><?php _e('Country', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <select name="mygls_settings[country]" id="country" class="regular-text">
                                            <option value="hu" <?php selected($settings['country'] ?? 'hu', 'hu'); ?>>🇭🇺 Hungary (HU)</option>
                                            <option value="hr" <?php selected($settings['country'] ?? 'hu', 'hr'); ?>>🇭🇷 Croatia (HR)</option>
                                            <option value="cz" <?php selected($settings['country'] ?? 'hu', 'cz'); ?>>🇨🇿 Czechia (CZ)</option>
                                            <option value="ro" <?php selected($settings['country'] ?? 'hu', 'ro'); ?>>🇷🇴 Romania (RO)</option>
                                            <option value="si" <?php selected($settings['country'] ?? 'hu', 'si'); ?>>🇸🇮 Slovenia (SI)</option>
                                            <option value="sk" <?php selected($settings['country'] ?? 'hu', 'sk'); ?>>🇸🇰 Slovakia (SK)</option>
                                            <option value="rs" <?php selected($settings['country'] ?? 'hu', 'rs'); ?>>🇷🇸 Serbia (RS)</option>
                                        </select>
                                        <p class="description"><?php _e('Select your GLS country domain', 'mygls-woocommerce'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="username"><?php _e('Username (Email)', 'mygls-woocommerce'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="email" name="mygls_settings[username]" id="username" value="<?php echo esc_attr($settings['username'] ?? ''); ?>" class="regular-text" required>
                                        <p class="description"><?php _e('Your MyGLS account email', 'mygls-woocommerce'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="password"><?php _e('Password', 'mygls-woocommerce'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="password" name="mygls_settings[password]" id="password" value="<?php echo esc_attr($settings['password'] ?? ''); ?>" class="regular-text" required autocomplete="new-password">
                                        <p class="description"><?php _e('Your MyGLS account password', 'mygls-woocommerce'); ?></p>
                                    </td>
                                </tr>
                