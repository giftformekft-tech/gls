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

        // Parcelshop Map Settings
        $sanitized['language'] = sanitize_text_field($input['language'] ?? '');
        $sanitized['map_display_mode'] = sanitize_text_field($input['map_display_mode'] ?? 'modal');
        $sanitized['map_button_style'] = sanitize_text_field($input['map_button_style'] ?? 'primary');
        $sanitized['map_position'] = sanitize_text_field($input['map_position'] ?? 'after_shipping');

        // Shipping Methods - Parcelshop Selector
        $sanitized['parcelshop_enabled_methods'] = isset($input['parcelshop_enabled_methods']) && is_array($input['parcelshop_enabled_methods'])
            ? array_map('sanitize_text_field', $input['parcelshop_enabled_methods'])
            : [];

        // Custom Checkout Settings
        $sanitized['enable_custom_checkout'] = isset($input['enable_custom_checkout']) ? '1' : '0';
        $sanitized['checkout_field_order'] = isset($input['checkout_field_order']) && is_array($input['checkout_field_order'])
            ? array_map('sanitize_text_field', $input['checkout_field_order'])
            : ['billing', 'shipping', 'parcelshop', 'order_notes', 'payment'];

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
                                <tr>
                                    <th scope="row">
                                        <label for="client_number"><?php _e('Client Number', 'mygls-woocommerce'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="number" name="mygls_settings[client_number]" id="client_number" value="<?php echo esc_attr($settings['client_number'] ?? ''); ?>" class="regular-text" required>
                                        <p class="description"><?php _e('Your unique GLS client number', 'mygls-woocommerce'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="test_mode"><?php _e('Test Mode', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <label class="mygls-toggle">
                                            <input type="checkbox" name="mygls_settings[test_mode]" id="test_mode" value="1" <?php checked($settings['test_mode'] ?? '0', '1'); ?>>
                                            <span class="mygls-toggle-slider"></span>
                                        </label>
                                        <p class="description"><?php _e('Use test API endpoint (api.test.mygls.*)', 'mygls-woocommerce'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"></th>
                                    <td>
                                        <button type="button" id="test-connection" class="button button-secondary">
                                            <span class="dashicons dashicons-admin-plugins"></span>
                                            <?php _e('Test Connection', 'mygls-woocommerce'); ?>
                                        </button>
                                        <span id="connection-status"></span>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Sender Address -->
                    <div class="mygls-card">
                        <div class="mygls-card-header">
                            <h2><span class="dashicons dashicons-store"></span> <?php _e('Sender Address (Pickup)', 'mygls-woocommerce'); ?></h2>
                        </div>
                        <div class="mygls-card-body">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="sender_name"><?php _e('Company Name', 'mygls-woocommerce'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="text" name="mygls_settings[sender_name]" id="sender_name" value="<?php echo esc_attr($settings['sender_name'] ?? ''); ?>" class="regular-text" required>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sender_street"><?php _e('Street', 'mygls-woocommerce'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="text" name="mygls_settings[sender_street]" id="sender_street" value="<?php echo esc_attr($settings['sender_street'] ?? ''); ?>" class="regular-text" required>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sender_house_number"><?php _e('House Number', 'mygls-woocommerce'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="text" name="mygls_settings[sender_house_number]" id="sender_house_number" value="<?php echo esc_attr($settings['sender_house_number'] ?? ''); ?>" class="small-text" required>
                                        <input type="text" name="mygls_settings[sender_house_info]" placeholder="<?php esc_attr_e('Building, floor, door', 'mygls-woocommerce'); ?>" value="<?php echo esc_attr($settings['sender_house_info'] ?? ''); ?>" class="regular-text">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sender_city"><?php _e('City', 'mygls-woocommerce'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="text" name="mygls_settings[sender_city]" id="sender_city" value="<?php echo esc_attr($settings['sender_city'] ?? ''); ?>" class="regular-text" required>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sender_zip"><?php _e('ZIP Code', 'mygls-woocommerce'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="text" name="mygls_settings[sender_zip]" id="sender_zip" value="<?php echo esc_attr($settings['sender_zip'] ?? ''); ?>" class="small-text" required>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sender_country"><?php _e('Country Code', 'mygls-woocommerce'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="text" name="mygls_settings[sender_country]" id="sender_country" value="<?php echo esc_attr($settings['sender_country'] ?? 'HU'); ?>" class="small-text" required maxlength="2" placeholder="HU">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sender_contact_name"><?php _e('Contact Name', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" name="mygls_settings[sender_contact_name]" id="sender_contact_name" value="<?php echo esc_attr($settings['sender_contact_name'] ?? ''); ?>" class="regular-text">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sender_contact_phone"><?php _e('Contact Phone', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" name="mygls_settings[sender_contact_phone]" id="sender_contact_phone" value="<?php echo esc_attr($settings['sender_contact_phone'] ?? ''); ?>" class="regular-text" placeholder="+36301234567">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sender_contact_email"><?php _e('Contact Email', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <input type="email" name="mygls_settings[sender_contact_email]" id="sender_contact_email" value="<?php echo esc_attr($settings['sender_contact_email'] ?? ''); ?>" class="regular-text">
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Label Settings -->
                    <div class="mygls-card">
                        <div class="mygls-card-header">
                            <h2><span class="dashicons dashicons-media-document"></span> <?php _e('Label Settings', 'mygls-woocommerce'); ?></h2>
                        </div>
                        <div class="mygls-card-body">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="printer_type"><?php _e('Printer Type', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <select name="mygls_settings[printer_type]" id="printer_type" class="regular-text">
                                            <option value="A4_2x2" <?php selected($settings['printer_type'] ?? 'A4_2x2', 'A4_2x2'); ?>>A4 - 2x2 (4 labels per page)</option>
                                            <option value="A4_4x1" <?php selected($settings['printer_type'] ?? 'A4_2x2', 'A4_4x1'); ?>>A4 - 4x1 (4 labels per page)</option>
                                            <option value="Connect" <?php selected($settings['printer_type'] ?? 'A4_2x2', 'Connect'); ?>>GLS Connect</option>
                                            <option value="Thermo" <?php selected($settings['printer_type'] ?? 'A4_2x2', 'Thermo'); ?>>Thermal Printer</option>
                                            <option value="ThermoZPL" <?php selected($settings['printer_type'] ?? 'A4_2x2', 'ThermoZPL'); ?>>Thermal ZPL</option>
                                            <option value="ThermoZPL_300DPI" <?php selected($settings['printer_type'] ?? 'A4_2x2', 'ThermoZPL_300DPI'); ?>>Thermal ZPL 300 DPI</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="auto_generate_labels"><?php _e('Auto-generate Labels', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <label class="mygls-toggle">
                                            <input type="checkbox" name="mygls_settings[auto_generate_labels]" id="auto_generate_labels" value="1" <?php checked($settings['auto_generate_labels'] ?? '0', '1'); ?>>
                                            <span class="mygls-toggle-slider"></span>
                                        </label>
                                        <p class="description"><?php _e('Automatically generate shipping labels when order status changes to Processing', 'mygls-woocommerce'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="auto_status_sync"><?php _e('Auto Status Sync', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <label class="mygls-toggle">
                                            <input type="checkbox" name="mygls_settings[auto_status_sync]" id="auto_status_sync" value="1" <?php checked($settings['auto_status_sync'] ?? '0', '1'); ?>>
                                            <span class="mygls-toggle-slider"></span>
                                        </label>
                                        <p class="description"><?php _e('Automatically sync parcel status with GLS', 'mygls-woocommerce'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="status_sync_interval"><?php _e('Sync Interval (minutes)', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <input type="number" name="mygls_settings[status_sync_interval]" id="status_sync_interval" value="<?php echo esc_attr($settings['status_sync_interval'] ?? '60'); ?>" class="small-text" min="15" max="1440">
                                        <p class="description"><?php _e('How often to check for status updates (15-1440 minutes)', 'mygls-woocommerce'); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Custom Checkout Settings -->
                    <div class="mygls-card">
                        <div class="mygls-card-header">
                            <h2><span class="dashicons dashicons-cart"></span> <?php _e('Custom Checkout Settings', 'mygls-woocommerce'); ?></h2>
                        </div>
                        <div class="mygls-card-body">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="enable_custom_checkout"><?php _e('Enable GLS Custom Checkout', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <label class="mygls-toggle">
                                            <input type="checkbox" name="mygls_settings[enable_custom_checkout]" id="enable_custom_checkout" value="1" <?php checked($settings['enable_custom_checkout'] ?? '0', '1'); ?>>
                                            <span class="mygls-toggle-slider"></span>
                                        </label>
                                        <p class="description"><?php _e('When enabled, the GLS plugin will control the checkout page layout and fields. When disabled, standard WooCommerce checkout will be used.', 'mygls-woocommerce'); ?></p>
                                    </td>
                                </tr>
                            </table>

                            <div id="checkout-field-order-settings" style="<?php echo ($settings['enable_custom_checkout'] ?? '0') === '1' ? '' : 'display:none;'; ?>">
                                <hr style="margin: 20px 0;">
                                <h3><?php _e('Checkout Field Order', 'mygls-woocommerce'); ?></h3>
                                <p class="description" style="margin-bottom: 15px;">
                                    <?php _e('Drag and drop to reorder the checkout sections. This controls the order in which sections appear on the checkout page.', 'mygls-woocommerce'); ?>
                                </p>

                                <div id="mygls-field-order-sortable" class="mygls-sortable-list">
                                    <?php
                                    $field_order = $settings['checkout_field_order'] ?? ['billing', 'shipping', 'parcelshop', 'order_notes', 'payment'];
                                    $field_labels = [
                                        'billing' => __('Billing Details', 'mygls-woocommerce'),
                                        'shipping' => __('Shipping Details', 'mygls-woocommerce'),
                                        'parcelshop' => __('Parcelshop Selection', 'mygls-woocommerce'),
                                        'order_notes' => __('Order Notes', 'mygls-woocommerce'),
                                        'payment' => __('Payment Method', 'mygls-woocommerce')
                                    ];

                                    foreach ($field_order as $index => $field):
                                    ?>
                                        <div class="mygls-sortable-item" data-field="<?php echo esc_attr($field); ?>">
                                            <span class="dashicons dashicons-menu"></span>
                                            <span class="mygls-sortable-label"><?php echo esc_html($field_labels[$field] ?? $field); ?></span>
                                            <span class="mygls-sortable-order">#<?php echo ($index + 1); ?></span>
                                            <input type="hidden" name="mygls_settings[checkout_field_order][]" value="<?php echo esc_attr($field); ?>">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <script>
                            jQuery(document).ready(function($) {
                                // Toggle field order settings visibility
                                $('#enable_custom_checkout').on('change', function() {
                                    if ($(this).is(':checked')) {
                                        $('#checkout-field-order-settings').slideDown();
                                    } else {
                                        $('#checkout-field-order-settings').slideUp();
                                    }
                                });

                                // Initialize sortable
                                if (typeof $.fn.sortable !== 'undefined') {
                                    $('#mygls-field-order-sortable').sortable({
                                        handle: '.dashicons-menu',
                                        placeholder: 'mygls-sortable-placeholder',
                                        update: function(event, ui) {
                                            // Update order numbers
                                            $('#mygls-field-order-sortable .mygls-sortable-item').each(function(index) {
                                                $(this).find('.mygls-sortable-order').text('#' + (index + 1));
                                            });
                                        }
                                    });
                                }
                            });
                            </script>
                        </div>
                    </div>

                    <!-- Shipping Methods - Parcelshop Selector -->
                    <div class="mygls-card">
                        <div class="mygls-card-header">
                            <h2><span class="dashicons dashicons-location"></span> <?php _e('Parcelshop Selector Settings', 'mygls-woocommerce'); ?></h2>
                        </div>
                        <div class="mygls-card-body">
                            <p class="description" style="margin-bottom: 15px;">
                                <?php _e('Enable parcelshop selector (interactive map) for specific shipping methods. Customers will be able to choose a GLS parcelshop during checkout.', 'mygls-woocommerce'); ?>
                            </p>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="map_language"><?php _e('Map Language', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <select name="mygls_settings[language]" id="map_language" class="regular-text">
                                            <option value="" <?php selected($settings['language'] ?? '', ''); ?>><?php _e('Auto (based on country)', 'mygls-woocommerce'); ?></option>
                                            <option value="hu" <?php selected($settings['language'] ?? '', 'hu'); ?>>Magyar</option>
                                            <option value="en" <?php selected($settings['language'] ?? '', 'en'); ?>>English</option>
                                            <option value="hr" <?php selected($settings['language'] ?? '', 'hr'); ?>>Hrvatski</option>
                                            <option value="cs" <?php selected($settings['language'] ?? '', 'cs'); ?>>Čeština</option>
                                            <option value="ro" <?php selected($settings['language'] ?? '', 'ro'); ?>>Română</option>
                                            <option value="sl" <?php selected($settings['language'] ?? '', 'sl'); ?>>Slovenščina</option>
                                            <option value="sk" <?php selected($settings['language'] ?? '', 'sk'); ?>>Slovenčina</option>
                                            <option value="sr" <?php selected($settings['language'] ?? '', 'sr'); ?>>Srpski</option>
                                        </select>
                                        <p class="description"><?php _e('Language for the parcelshop map widget interface', 'mygls-woocommerce'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="map_display_mode"><?php _e('Display Mode', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <select name="mygls_settings[map_display_mode]" id="map_display_mode" class="regular-text">
                                            <option value="modal" <?php selected($settings['map_display_mode'] ?? 'modal', 'modal'); ?>><?php _e('Modal (popup overlay)', 'mygls-woocommerce'); ?></option>
                                            <option value="inline" <?php selected($settings['map_display_mode'] ?? 'modal', 'inline'); ?>><?php _e('Inline (embedded in page)', 'mygls-woocommerce'); ?></option>
                                        </select>
                                        <p class="description"><?php _e('How the parcelshop map should be displayed to customers', 'mygls-woocommerce'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="map_button_style"><?php _e('Button Style', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <select name="mygls_settings[map_button_style]" id="map_button_style" class="regular-text">
                                            <option value="primary" <?php selected($settings['map_button_style'] ?? 'primary', 'primary'); ?>><?php _e('Primary (blue)', 'mygls-woocommerce'); ?></option>
                                            <option value="secondary" <?php selected($settings['map_button_style'] ?? 'primary', 'secondary'); ?>><?php _e('Secondary (gray)', 'mygls-woocommerce'); ?></option>
                                            <option value="success" <?php selected($settings['map_button_style'] ?? 'primary', 'success'); ?>><?php _e('Success (green)', 'mygls-woocommerce'); ?></option>
                                        </select>
                                        <p class="description"><?php _e('Color scheme for the "Select Parcelshop" button', 'mygls-woocommerce'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="map_position"><?php _e('Map Position (Block Checkout)', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <select name="mygls_settings[map_position]" id="map_position" class="regular-text">
                                            <option value="after_shipping" <?php selected($settings['map_position'] ?? 'after_shipping', 'after_shipping'); ?>><?php _e('After shipping methods', 'mygls-woocommerce'); ?></option>
                                            <option value="before_payment" <?php selected($settings['map_position'] ?? 'after_shipping', 'before_payment'); ?>><?php _e('Before payment methods', 'mygls-woocommerce'); ?></option>
                                            <option value="after_billing" <?php selected($settings['map_position'] ?? 'after_shipping', 'after_billing'); ?>><?php _e('After billing address', 'mygls-woocommerce'); ?></option>
                                        </select>
                                        <p class="description"><?php _e('Where to display the parcelshop selector on block-based checkout pages', 'mygls-woocommerce'); ?></p>
                                    </td>
                                </tr>
                            </table>
                            <hr style="margin: 20px 0;">
                            <h3 style="margin-top: 20px; margin-bottom: 10px;"><?php _e('Enable for Shipping Methods', 'mygls-woocommerce'); ?></h3>
                            <table class="form-table">
                                <?php
                                // Get all available shipping methods
                                $shipping_zones = \WC_Shipping_Zones::get_zones();
                                $enabled_methods = $settings['parcelshop_enabled_methods'] ?? [];

                                foreach ($shipping_zones as $zone) {
                                    $zone_obj = new \WC_Shipping_Zone($zone['id']);
                                    $shipping_methods = $zone_obj->get_shipping_methods(true);

                                    if (!empty($shipping_methods)) {
                                        ?>
                                        <tr>
                                            <td colspan="2">
                                                <h4 style="margin-top: 10px; margin-bottom: 5px;"><?php echo esc_html($zone['zone_name']); ?></h4>
                                            </td>
                                        </tr>
                                        <?php
                                        foreach ($shipping_methods as $method) {
                                            $method_id = $method->get_rate_id();
                                            $method_title = $method->get_title();
                                            ?>
                                            <tr>
                                                <th scope="row" style="padding-left: 20px;">
                                                    <?php echo esc_html($method_title); ?>
                                                </th>
                                                <td>
                                                    <label class="mygls-toggle">
                                                        <input type="checkbox"
                                                               name="mygls_settings[parcelshop_enabled_methods][]"
                                                               value="<?php echo esc_attr($method_id); ?>"
                                                               <?php checked(in_array($method_id, $enabled_methods), true); ?>>
                                                        <span class="mygls-toggle-slider"></span>
                                                    </label>
                                                    <p class="description"><?php _e('Enable parcelshop selector for this method', 'mygls-woocommerce'); ?></p>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    }
                                }
                                ?>
                            </table>
                        </div>
                    </div>
                    
                    <?php submit_button(__('Save Settings', 'mygls-woocommerce'), 'primary large'); ?>
                </form>
                
                <!-- Sidebar -->
                <div class="mygls-sidebar">
                    <div class="mygls-card">
                        <div class="mygls-card-header">
                            <h3><?php _e('Quick Info', 'mygls-woocommerce'); ?></h3>
                        </div>
                        <div class="mygls-card-body">
                            <p><strong><?php _e('Version:', 'mygls-woocommerce'); ?></strong> <?php echo MYGLS_VERSION; ?></p>
                            <p><strong><?php _e('PHP:', 'mygls-woocommerce'); ?></strong> <?php echo PHP_VERSION; ?></p>
                            <p><strong><?php _e('WordPress:', 'mygls-woocommerce'); ?></strong> <?php echo get_bloginfo('version'); ?></p>
                            <p><strong><?php _e('WooCommerce:', 'mygls-woocommerce'); ?></strong> <?php echo WC()->version; ?></p>
                        </div>
                    </div>
                    
                    <div class="mygls-card">
                        <div class="mygls-card-header">
                            <h3><?php _e('Available Services', 'mygls-woocommerce'); ?></h3>
                        </div>
                        <div class="mygls-card-body">
                            <ul class="mygls-service-list">
                                <li><span class="dashicons dashicons-yes"></span> PSD - Parcel Shop Delivery</li>
                                <li><span class="dashicons dashicons-yes"></span> COD - Cash on Delivery</li>
                                <li><span class="dashicons dashicons-yes"></span> FDS - Flexible Delivery</li>
                                <li><span class="dashicons dashicons-yes"></span> SM2 - SMS Pre-advice</li>
                                <li><span class="dashicons dashicons-yes"></span> INS - Insurance</li>
                                <li><span class="dashicons dashicons-yes"></span> DDS - Day Definite</li>
                                <li><span class="dashicons dashicons-yes"></span> SDS - Scheduled Delivery</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="mygls-card">
                        <div class="mygls-card-header">
                            <h3><?php _e('Support', 'mygls-woocommerce'); ?></h3>
                        </div>
                        <div class="mygls-card-body">
                            <p><?php _e('Need help? Check the documentation or contact support.', 'mygls-woocommerce'); ?></p>
                            <a href="https://api.mygls.hu/" target="_blank" class="button button-secondary">
                                <span class="dashicons dashicons-book"></span>
                                <?php _e('API Documentation', 'mygls-woocommerce'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function test_connection() {
        check_ajax_referer('mygls_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'mygls-woocommerce')]);
        }

        // Get test credentials from POST (current form values)
        $test_settings = [
            'country' => sanitize_text_field($_POST['country'] ?? ''),
            'username' => sanitize_email($_POST['username'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'client_number' => absint($_POST['client_number'] ?? 0),
            'test_mode' => ($_POST['test_mode'] ?? '0') === '1'
        ];

        // Validate required fields
        if (empty($test_settings['username'])) {
            wp_send_json_error(['message' => __('Please enter your username (email)', 'mygls-woocommerce')]);
        }

        if (empty($test_settings['password'])) {
            wp_send_json_error(['message' => __('Please enter your password', 'mygls-woocommerce')]);
        }

        if (empty($test_settings['client_number'])) {
            wp_send_json_error(['message' => __('Please enter your client number', 'mygls-woocommerce')]);
        }

        try {
            // Temporarily save test settings for API client
            $original_settings = get_option('mygls_settings', []);
            update_option('mygls_settings', array_merge($original_settings, $test_settings), false);

            $api = mygls_get_api_client();

            if (!$api) {
                // Restore original settings
                update_option('mygls_settings', $original_settings, false);
                wp_send_json_error(['message' => __('Failed to initialize API client', 'mygls-woocommerce')]);
            }

            // Use PrepareLabels endpoint with minimal valid data to test authentication
            // This is simpler than GetParcelList and doesn't require date parsing
            $test_parcel = [
                'ClientNumber' => (int)$test_settings['client_number'],
                'ClientReference' => 'TEST-CONNECTION-' . time(),
                'CODAmount' => 0,
                'Count' => 1,
                'Content' => 'Test Connection',
                'PickupAddress' => [
                    'Name' => 'Test',
                    'Street' => 'Test',
                    'HouseNumber' => '1',
                    'City' => 'Test',
                    'ZipCode' => '1000',
                    'CountryIsoCode' => strtoupper($test_settings['country'])
                ],
                'DeliveryAddress' => [
                    'Name' => 'Test',
                    'Street' => 'Test',
                    'HouseNumber' => '1',
                    'City' => 'Test',
                    'ZipCode' => '1000',
                    'CountryIsoCode' => strtoupper($test_settings['country'])
                ]
            ];

            $result = $api->prepareLabels([$test_parcel]);

            // Restore original settings after test
            update_option('mygls_settings', $original_settings, false);

            // Check for explicit errors from API client wrapper
            if (isset($result['error'])) {
                $error_message = $result['error'];

                // Parse HTTP errors
                if (strpos($error_message, 'HTTP 401') !== false || strpos($error_message, 'Unauthorized') !== false) {
                    wp_send_json_error(['message' => __('Authentication failed - Invalid username or password', 'mygls-woocommerce')]);
                } elseif (strpos($error_message, 'HTTP 403') !== false || strpos($error_message, 'Forbidden') !== false) {
                    wp_send_json_error(['message' => __('Access denied - Please check your client number', 'mygls-woocommerce')]);
                } elseif (strpos($error_message, 'HTTP 404') !== false) {
                    wp_send_json_error(['message' => __('API endpoint not found - Please check your country selection', 'mygls-woocommerce')]);
                } elseif (strpos($error_message, 'HTTP 500') !== false || strpos($error_message, 'HTTP 502') !== false || strpos($error_message, 'HTTP 503') !== false) {
                    wp_send_json_error(['message' => __('GLS API server error - Please try again later', 'mygls-woocommerce')]);
                } else {
                    wp_send_json_error(['message' => $error_message]);
                }
                return;
            }

            // PrepareLabels response structure check
            // A successful authentication should either:
            // 1. Return PrepareLabelsErrorList with validation errors (auth succeeded, but data was invalid - this is OK for test)
            // 2. Return LabelsInfoList with success
            $auth_successful = false;

            if (isset($result['PrepareLabelsErrorList']) && is_array($result['PrepareLabelsErrorList'])) {
                // Errors in the response mean authentication worked, but data validation failed
                // This is actually success for connection test purposes!
                $auth_successful = true;
            }

            if (isset($result['LabelsInfoList']) && is_array($result['LabelsInfoList'])) {
                // Success response with labels info
                $auth_successful = true;
            }

            // Check for 'd' wrapper (WCF services format)
            if (isset($result['d'])) {
                if (isset($result['d']['PrepareLabelsErrorList']) || isset($result['d']['LabelsInfoList'])) {
                    $auth_successful = true;
                }
            }

            if (!$auth_successful) {
                // If response structure is completely unexpected, auth likely failed
                if (empty($result) || !is_array($result)) {
                    wp_send_json_error(['message' => __('Authentication failed - Please verify your credentials', 'mygls-woocommerce')]);
                } else {
                    wp_send_json_error(['message' => __('Unexpected API response - Connection may have succeeded but response format is unrecognized', 'mygls-woocommerce')]);
                }
                return;
            }

            // If we got here, authentication is successful
            wp_send_json_success(['message' => __('Connection successful! API credentials verified.', 'mygls-woocommerce')]);

        } catch (\Exception $e) {
            // Restore original settings in case of exception
            if (isset($original_settings)) {
                update_option('mygls_settings', $original_settings, false);
            }

            $error_msg = $e->getMessage();

            // Try to provide more helpful error messages
            if (strpos($error_msg, 'cURL error') !== false) {
                wp_send_json_error(['message' => __('Connection error - Please check your internet connection and firewall settings', 'mygls-woocommerce')]);
            } else {
                wp_send_json_error(['message' => sprintf(__('Error: %s', 'mygls-woocommerce'), $error_msg)]);
            }
        }
    }
}