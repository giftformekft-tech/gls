<?php
/**
 * Parcelshop Map Gutenberg Block
 * Allows manual placement of parcelshop selector in block editor
 */

namespace MyGLS\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

class ParcelshopBlock {
    public function __construct() {
        add_action('init', [$this, 'register_block']);
    }

    /**
     * Register the Gutenberg block
     */
    public function register_block() {
        // Only register if Gutenberg is available
        if (!function_exists('register_block_type')) {
            return;
        }

        // Register block script
        wp_register_script(
            'mygls-parcelshop-block',
            plugins_url('assets/js/parcelshop-block.js', dirname(__DIR__, 1)),
            ['wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n'],
            filemtime(plugin_dir_path(dirname(__DIR__, 1)) . 'assets/js/parcelshop-block.js')
        );

        // Register block
        register_block_type('mygls/parcelshop-selector', [
            'editor_script' => 'mygls-parcelshop-block',
            'render_callback' => [$this, 'render_block'],
            'attributes' => [
                'displayMode' => [
                    'type' => 'string',
                    'default' => 'auto'
                ],
                'buttonStyle' => [
                    'type' => 'string',
                    'default' => 'primary'
                ],
                'showInlineMap' => [
                    'type' => 'boolean',
                    'default' => false
                ]
            ]
        ]);
    }

    /**
     * Render block on frontend
     */
    public function render_block($attributes) {
        // Check if WooCommerce is active and we're on checkout
        if (!function_exists('is_checkout') || !is_checkout()) {
            return '';
        }

        // Check if a parcelshop-enabled shipping method is selected
        $settings = get_option('mygls_settings', []);
        $enabled_methods = $settings['parcelshop_enabled_methods'] ?? [];

        if (empty($enabled_methods)) {
            return '';
        }

        // Get chosen shipping methods
        $chosen_methods = WC()->session->get('chosen_shipping_methods', []);
        if (empty($chosen_methods)) {
            return '';
        }

        // Check if any chosen method requires parcelshop
        $show_selector = false;
        foreach ($chosen_methods as $chosen_method) {
            if (in_array($chosen_method, $enabled_methods)) {
                $show_selector = true;
                break;
            }
            // Check partial matches
            foreach ($enabled_methods as $enabled_method) {
                if (strpos($chosen_method, $enabled_method) === 0) {
                    $show_selector = true;
                    break 2;
                }
            }
        }

        if (!$show_selector) {
            return '';
        }

        // Determine display mode
        $display_mode = $attributes['displayMode'] ?? 'auto';
        if ($display_mode === 'auto') {
            $display_mode = $settings['map_display_mode'] ?? 'modal';
        }

        // Button style
        $button_style = $attributes['buttonStyle'] ?? 'primary';
        $show_inline = $attributes['showInlineMap'] ?? false;

        // Render the selector
        ob_start();
        $this->render_selector_html($display_mode, $button_style, $show_inline);
        return ob_get_clean();
    }

    /**
     * Render the parcelshop selector HTML
     */
    private function render_selector_html($display_mode, $button_style, $show_inline) {
        $selected_parcelshop = WC()->session->get('mygls_selected_parcelshop');
        $settings = get_option('mygls_settings', []);
        $country = strtolower($settings['country'] ?? 'hu');
        $language = strtolower($settings['language'] ?? '');

        // Button style classes
        $button_class_map = [
            'primary' => 'mygls-btn-primary',
            'secondary' => 'mygls-btn-secondary',
            'success' => 'mygls-btn-success'
        ];
        $button_class = $button_class_map[$button_style] ?? 'mygls-btn-primary';

        ?>
        <div class="mygls-parcelshop-block mygls-parcelshop-selector-wrapper mygls-modern" data-display-mode="<?php echo esc_attr($display_mode); ?>">
            <div class="mygls-parcelshop-header">
                <svg class="mygls-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                    <circle cx="12" cy="10" r="3"></circle>
                </svg>
                <h3><?php _e('GLS Parcelshop Selection', 'mygls-woocommerce'); ?></h3>
            </div>

            <div class="mygls-parcelshop-selector" data-shipping-method="block">
                <?php if ($display_mode === 'button' || !$show_inline): ?>
                    <!-- Button mode -->
                    <div class="mygls-parcelshop-trigger">
                        <button type="button" class="mygls-select-parcelshop <?php echo esc_attr($button_class); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                <circle cx="12" cy="10" r="3"></circle>
                            </svg>
                            <?php _e('Select Parcelshop', 'mygls-woocommerce'); ?>
                        </button>

                        <?php if ($selected_parcelshop): ?>
                            <div class="mygls-selected-parcelshop mygls-slide-in">
                                <div class="mygls-selected-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="20 6 9 17 4 12"></polyline>
                                    </svg>
                                </div>
                                <div class="mygls-selected-info">
                                    <strong><?php echo esc_html($selected_parcelshop['name']); ?></strong>
                                    <small><?php echo esc_html($selected_parcelshop['address']); ?></small>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="mygls-help-text">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="12" y1="16" x2="12" y2="12"></line>
                                    <line x1="12" y1="8" x2="12.01" y2="8"></line>
                                </svg>
                                <?php _e('Please select a GLS parcelshop for delivery', 'mygls-woocommerce'); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($show_inline && $display_mode === 'inline'): ?>
                    <!-- Inline map mode -->
                    <div class="mygls-inline-map-container">
                        <gls-dpm-widget
                            country="<?php echo esc_attr($country); ?>"
                            <?php if ($language): ?>language="<?php echo esc_attr($language); ?>"<?php endif; ?>
                            id="mygls-inline-widget"
                            class="mygls-inline-widget">
                        </gls-dpm-widget>
                    </div>

                    <?php if ($selected_parcelshop): ?>
                        <div class="mygls-selected-parcelshop mygls-inline">
                            <strong><?php _e('Selected:', 'mygls-woocommerce'); ?></strong>
                            <?php echo esc_html($selected_parcelshop['name']); ?> -
                            <?php echo esc_html($selected_parcelshop['address']); ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <input type="hidden" name="mygls_parcelshop_id" id="mygls_parcelshop_id" value="<?php echo esc_attr($selected_parcelshop['id'] ?? ''); ?>">
                <input type="hidden" name="mygls_parcelshop_data" id="mygls_parcelshop_data" value="<?php echo esc_attr(json_encode($selected_parcelshop ?? [])); ?>">
            </div>

            <!-- GLS Official Map Widget Dialog (for modal/button mode) -->
            <?php if ($display_mode !== 'inline' || !$show_inline): ?>
                <gls-dpm-dialog
                    country="<?php echo esc_attr($country); ?>"
                    <?php if ($language): ?>language="<?php echo esc_attr($language); ?>"<?php endif; ?>
                    id="mygls-parcelshop-widget-block"
                    class="mygls-widget-<?php echo esc_attr($display_mode); ?>">
                </gls-dpm-dialog>
            <?php endif; ?>
        </div>
        <?php
    }
}
