<?php
/**
 * Admin Settings Page
 */

namespace MyGLS\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Settings {
    private array $default_checkout_fields = ['billing', 'shipping_method', 'shipping', 'parcelshop', 'order_notes', 'payment'];

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
        register_setting('mygls_settings_group', 'expressone_settings', [
            'sanitize_callback' => [$this, 'sanitize_expressone_settings']
        ]);
    }

    public function sanitize_expressone_settings($input) {
        $sanitized = [];
        $sanitized['company_id'] = sanitize_text_field($input['company_id'] ?? '');
        $sanitized['user_name'] = sanitize_text_field($input['user_name'] ?? '');
        $sanitized['password'] = sanitize_text_field($input['password'] ?? '');
        return $sanitized;
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
        $sanitized['language'] = sanitize_text_field($input['language'] ?? 'hu');
        $sanitized['map_display_mode'] = sanitize_text_field($input['map_display_mode'] ?? 'modal');
        $sanitized['map_button_style'] = sanitize_text_field($input['map_button_style'] ?? 'primary');
        $sanitized['map_position'] = sanitize_text_field($input['map_position'] ?? 'after_shipping');

        // Shipping Methods - Parcelshop Selector
        $sanitized['parcelshop_enabled_methods'] = isset($input['parcelshop_enabled_methods']) && is_array($input['parcelshop_enabled_methods'])
            ? array_map('sanitize_text_field', $input['parcelshop_enabled_methods'])
            : [];

        // Shipping Methods - Hide if free
        $sanitized['hide_if_free_methods'] = isset($input['hide_if_free_methods']) && is_array($input['hide_if_free_methods'])
            ? array_map('sanitize_text_field', $input['hide_if_free_methods'])
            : [];

        // Shipping Method Logos
        $sanitized['shipping_method_logos'] = [];
        if (isset($input['shipping_method_logos']) && is_array($input['shipping_method_logos'])) {
            foreach ($input['shipping_method_logos'] as $method_id => $logo_url) {
                $sanitized['shipping_method_logos'][sanitize_text_field($method_id)] = esc_url_raw($logo_url);
            }
        }

        // Shipping Method Logo Size
        $sanitized['shipping_logo_size'] = absint($input['shipping_logo_size'] ?? 40);
        if ($sanitized['shipping_logo_size'] < 20) {
            $sanitized['shipping_logo_size'] = 20;
        }
        if ($sanitized['shipping_logo_size'] > 100) {
            $sanitized['shipping_logo_size'] = 100;
        }

        // Custom Checkout & Cart Settings
        $sanitized['disable_cart_shipping'] = isset($input['disable_cart_shipping']) ? '1' : '0';
        $sanitized['enable_custom_checkout'] = isset($input['enable_custom_checkout']) ? '1' : '0';
        if (isset($input['checkout_field_order']) && is_array($input['checkout_field_order'])) {
            $order = array_map('sanitize_text_field', $input['checkout_field_order']);
            $sanitized['checkout_field_order'] = $this->normalize_checkout_field_order($order);
        } else {
            $sanitized['checkout_field_order'] = $this->default_checkout_fields;
        }

        // Payment Surcharge Settings
        $sanitized['cod_fee_enabled'] = isset($input['cod_fee_enabled']) ? '1' : '0';
        $sanitized['cod_fee_amount'] = isset($input['cod_fee_amount'])
            ? wc_format_decimal($input['cod_fee_amount'])
            : '0';
        $sanitized['cod_fee_label'] = sanitize_text_field($input['cod_fee_label'] ?? __('Cash on Delivery fee', 'mygls-woocommerce'));
        $sanitized['cod_fee_taxable'] = isset($input['cod_fee_taxable']) ? '1' : '0';

        return $sanitized;
    }
    
    public function render_settings_page() {
        $settings = get_option('mygls_settings', []);
        $eo_settings = get_option('expressone_settings', []);
        ?>
        <div class="wrap mygls-settings-wrap">
            <h1>
                <span class="dashicons dashicons-location-alt"></span>
                <?php _e('GLS & Express One Beállítások', 'mygls-woocommerce'); ?>
            </h1>
            
            <div class="mygls-settings-container">
                <form method="post" action="options.php" class="mygls-settings-form">
                    <?php settings_fields('mygls_settings_group'); ?>
                    
                    <h2 class="nav-tab-wrapper mygls-tabs" style="margin-bottom: 20px;">
                        <a href="#tab-general" class="nav-tab nav-tab-active" data-tab="#tab-general"><?php _e('Általános Beállítások', 'mygls-woocommerce'); ?></a>
                        <a href="#tab-gls" class="nav-tab" data-tab="#tab-gls"><?php _e('GLS Integráció', 'mygls-woocommerce'); ?></a>
                        <a href="#tab-expressone" class="nav-tab" data-tab="#tab-expressone"><?php _e('Express One Integráció', 'mygls-woocommerce'); ?></a>
                    </h2>

                    <!-- GLS TAB START -->
                    <div id="tab-gls" class="mygls-tab-content" style="display:none;">

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
                    </div> <!-- GLS TAB END -->

                    <!-- GENERAL TAB START -->
                    <div id="tab-general" class="mygls-tab-content" style="display:block;">

                    <!-- Custom Checkout & Cart Settings -->
                    <div class="mygls-card">
                        <div class="mygls-card-header">
                            <h2><span class="dashicons dashicons-cart"></span> <?php _e('Checkout & Cart Settings', 'mygls-woocommerce'); ?></h2>
                        </div>
                        <div class="mygls-card-body">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="disable_cart_shipping"><?php _e('Disable Cart Shipping Metrics', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <label class="mygls-toggle">
                                            <input type="checkbox" name="mygls_settings[disable_cart_shipping]" id="disable_cart_shipping" value="1" <?php checked($settings['disable_cart_shipping'] ?? '0', '1'); ?>>
                                            <span class="mygls-toggle-slider"></span>
                                        </label>
                                        <p class="description"><?php _e('Hide shipping costs and calculator on the cart page. Shipping will only be calculated at checkout.', 'mygls-woocommerce'); ?></p>
                                    </td>
                                </tr>
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
                                    $field_order_setting = $settings['checkout_field_order'] ?? $this->default_checkout_fields;
                                    $field_order = is_array($field_order_setting)
                                        ? $this->normalize_checkout_field_order($field_order_setting)
                                        : $this->default_checkout_fields;
                                    $field_labels = $this->get_checkout_field_labels();

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

                    <!-- Payment Surcharges -->
                    <div class="mygls-card">
                        <div class="mygls-card-header">
                            <h2><span class="dashicons dashicons-money-alt"></span> <?php _e('Payment Surcharges', 'mygls-woocommerce'); ?></h2>
                        </div>
                        <div class="mygls-card-body">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="cod_fee_enabled"><?php _e('Enable COD surcharge', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <label class="mygls-toggle">
                                            <input type="checkbox" name="mygls_settings[cod_fee_enabled]" id="cod_fee_enabled" value="1" <?php checked($settings['cod_fee_enabled'] ?? '0', '1'); ?>>
                                            <span class="mygls-toggle-slider"></span>
                                        </label>
                                        <p class="description"><?php _e('Add a surcharge when Cash on Delivery is selected.', 'mygls-woocommerce'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="cod_fee_amount"><?php _e('COD surcharge amount', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <input type="number" step="0.01" min="0" name="mygls_settings[cod_fee_amount]" id="cod_fee_amount" value="<?php echo esc_attr($settings['cod_fee_amount'] ?? '0'); ?>" class="regular-text">
                                        <p class="description"><?php _e('Surcharge amount added to the order total.', 'mygls-woocommerce'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="cod_fee_label"><?php _e('COD surcharge label', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" name="mygls_settings[cod_fee_label]" id="cod_fee_label" value="<?php echo esc_attr($settings['cod_fee_label'] ?? __('Cash on Delivery fee', 'mygls-woocommerce')); ?>" class="regular-text">
                                        <p class="description"><?php _e('Label displayed in the checkout order summary.', 'mygls-woocommerce'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="cod_fee_taxable"><?php _e('COD surcharge taxable', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="mygls_settings[cod_fee_taxable]" id="cod_fee_taxable" value="1" <?php checked($settings['cod_fee_taxable'] ?? '0', '1'); ?>>
                                            <?php _e('Apply tax to the surcharge.', 'mygls-woocommerce'); ?>
                                        </label>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    </div> <!-- GENERAL TAB END -->

                    <script>
                    jQuery(document).ready(function($) {
                        $('.mygls-tabs .nav-tab').on('click', function(e) {
                            e.preventDefault();
                            $('.mygls-tabs .nav-tab').removeClass('nav-tab-active');
                            $(this).addClass('nav-tab-active');
                            $('.mygls-tab-content').hide();
                            $($(this).data('tab')).show();
                            localStorage.setItem('mygls_active_tab', $(this).data('tab'));
                        });
                        
                        var activeTab = localStorage.getItem('mygls_active_tab');
                        if (activeTab && $(activeTab).length) {
                            $('.mygls-tabs .nav-tab[data-tab="' + activeTab + '"]').click();
                        }
                    });
                    </script>

                    <!-- EXPRESS ONE TAB START -->
                    <div id="tab-expressone" class="mygls-tab-content" style="display:none;">
                        <div class="mygls-card">
                            <div class="mygls-card-header">
                                <h2><span class="dashicons dashicons-admin-network"></span> <?php _e('Express One API Kapcsolat', 'mygls-woocommerce'); ?></h2>
                            </div>
                            <div class="mygls-card-body">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">
                                            <label for="eo_company_id"><?php _e('Company ID', 'mygls-woocommerce'); ?></label>
                                        </th>
                                        <td>
                                            <input type="text" name="expressone_settings[company_id]" id="eo_company_id" value="<?php echo esc_attr($eo_settings['company_id'] ?? ''); ?>" class="regular-text">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="eo_user_name"><?php _e('Felhasználónév', 'mygls-woocommerce'); ?></label>
                                        </th>
                                        <td>
                                            <input type="text" name="expressone_settings[user_name]" id="eo_user_name" value="<?php echo esc_attr($eo_settings['user_name'] ?? ''); ?>" class="regular-text">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="eo_password"><?php _e('Jelszó', 'mygls-woocommerce'); ?></label>
                                        </th>
                                        <td>
                                            <input type="password" name="expressone_settings[password]" id="eo_password" value="<?php echo esc_attr($eo_settings['password'] ?? ''); ?>" class="regular-text">
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div> <!-- EXPRESS ONE TAB END -->

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
                                        <?php
                                        $button_preview_style = $settings['map_button_style'] ?? 'primary';
                                        $allowed_preview_styles = ['primary', 'secondary', 'success'];
                                        if (!in_array($button_preview_style, $allowed_preview_styles, true)) {
                                            $button_preview_style = 'primary';
                                        }
                                        ?>
                                        <select name="mygls_settings[map_button_style]" id="map_button_style" class="regular-text">
                                            <option value="primary" <?php selected($settings['map_button_style'] ?? 'primary', 'primary'); ?>><?php _e('Primary (blue)', 'mygls-woocommerce'); ?></option>
                                            <option value="secondary" <?php selected($settings['map_button_style'] ?? 'primary', 'secondary'); ?>><?php _e('Secondary (gray)', 'mygls-woocommerce'); ?></option>
                                            <option value="success" <?php selected($settings['map_button_style'] ?? 'primary', 'success'); ?>><?php _e('Success (green)', 'mygls-woocommerce'); ?></option>
                                        </select>
                                        <p class="description"><?php _e('Color scheme for the "Select Parcelshop" button', 'mygls-woocommerce'); ?></p>
                                        <div class="mygls-button-preview-panel" data-mygls-button-preview>
                                            <button type="button" class="button mygls-button-preview mygls-button-preview--<?php echo esc_attr($button_preview_style); ?>">
                                                <?php _e('Minta', 'mygls-woocommerce'); ?>
                                            </button>
                                            <button type="button" class="button mygls-button-preview mygls-button-preview--<?php echo esc_attr($button_preview_style); ?> mygls-button-preview--large">
                                                <?php _e('Minta nagyban', 'mygls-woocommerce'); ?>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="shipping_logo_size"><?php _e('Shipping Method Logo Size', 'mygls-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <input type="number"
                                               name="mygls_settings[shipping_logo_size]"
                                               id="shipping_logo_size"
                                               value="<?php echo esc_attr($settings['shipping_logo_size'] ?? 40); ?>"
                                               class="small-text"
                                               min="20"
                                               max="100"
                                               step="5">
                                        <span>px</span>
                                        <p class="description"><?php _e('Size of shipping method logos in checkout (20-100px, default: 40px)', 'mygls-woocommerce'); ?></p>
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
                                $hide_if_free_methods = $settings['hide_if_free_methods'] ?? [];
                                $method_logos = $settings['shipping_method_logos'] ?? [];

                                foreach ($shipping_zones as $zone) {
                                    $zone_obj = new \WC_Shipping_Zone($zone['id']);
                                    $shipping_methods = $zone_obj->get_shipping_methods(true);

                                    if (!empty($shipping_methods)) {
                                        ?>
                                        <tr>
                                            <td colspan="4">
                                                <h4 style="margin-top: 10px; margin-bottom: 5px;"><?php echo esc_html($zone['zone_name']); ?></h4>
                                            </td>
                                        </tr>
                                        <?php
                                        foreach ($shipping_methods as $method) {
                                            $method_id = $method->get_rate_id();
                                            $method_title = $method->get_title();
                                            $logo_url = $method_logos[$method_id] ?? '';
                                            ?>
                                            <tr>
                                                <th scope="row" style="padding-left: 20px; width: 25%;">
                                                    <?php echo esc_html($method_title); ?>
                                                </th>
                                                <td style="width: 15%;">
                                                    <label class="mygls-toggle">
                                                        <input type="checkbox"
                                                               name="mygls_settings[parcelshop_enabled_methods][]"
                                                               value="<?php echo esc_attr($method_id); ?>"
                                                               <?php checked(in_array($method_id, $enabled_methods), true); ?>>
                                                        <span class="mygls-toggle-slider"></span>
                                                    </label>
                                                    <p class="description"><?php _e('Enable parcelshop selector', 'mygls-woocommerce'); ?></p>
                                                </td>
                                                <td style="width: 15%;">
                                                    <label class="mygls-toggle">
                                                        <input type="checkbox"
                                                               name="mygls_settings[hide_if_free_methods][]"
                                                               value="<?php echo esc_attr($method_id); ?>"
                                                               <?php checked(in_array($method_id, $hide_if_free_methods), true); ?>>
                                                        <span class="mygls-toggle-slider"></span>
                                                    </label>
                                                    <p class="description"><?php _e('Hide if free shipping is available', 'mygls-woocommerce'); ?></p>
                                                </td>
                                                <td style="width: 45%;">
                                                    <input type="url"
                                                           name="mygls_settings[shipping_method_logos][<?php echo esc_attr($method_id); ?>]"
                                                           value="<?php echo esc_attr($logo_url); ?>"
                                                           class="regular-text"
                                                           placeholder="https://example.com/logo.png">
                                                    <p class="description"><?php _e('Logo URL (PNG, max 40x40px recommended)', 'mygls-woocommerce'); ?></p>
                                                    <?php if (!empty($logo_url)): ?>
                                                        <img src="<?php echo esc_url($logo_url); ?>" alt="Logo" style="max-width: 40px; max-height: 40px; margin-top: 5px; border: 1px solid #ddd; padding: 2px;">
                                                    <?php endif; ?>
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

    private function get_checkout_field_labels(): array {
        return [
            'billing' => __('Billing Details', 'mygls-woocommerce'),
            'shipping_method' => __('Shipping Method', 'mygls-woocommerce'),
            'shipping' => __('Shipping Details', 'mygls-woocommerce'),
            'parcelshop' => __('Parcelshop Selection', 'mygls-woocommerce'),
            'order_notes' => __('Order Notes', 'mygls-woocommerce'),
            'payment' => __('Payment Method', 'mygls-woocommerce')
        ];
    }

    private function normalize_checkout_field_order(array $order): array {
        $normalized = array_values(array_intersect($order, $this->default_checkout_fields));

        foreach ($this->default_checkout_fields as $field) {
            if (!in_array($field, $normalized, true)) {
                $normalized[] = $field;
            }
        }

        return $normalized;
    }
}
