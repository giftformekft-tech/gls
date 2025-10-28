/**
 * GLS Parcelshop Selector Block
 * Gutenberg block for placing parcelshop selector anywhere in checkout
 */

(function(wp) {
    const { registerBlockType } = wp.blocks;
    const { InspectorControls } = wp.blockEditor || wp.editor;
    const { PanelBody, SelectControl, ToggleControl } = wp.components;
    const { __ } = wp.i18n;
    const { createElement: el } = wp.element;

    registerBlockType('mygls/parcelshop-selector', {
        title: __('GLS Parcelshop Selector', 'mygls-woocommerce'),
        description: __('Display GLS parcelshop map selector for checkout', 'mygls-woocommerce'),
        icon: 'location-alt',
        category: 'woocommerce',
        keywords: [__('gls', 'mygls-woocommerce'), __('parcelshop', 'mygls-woocommerce'), __('map', 'mygls-woocommerce')],

        attributes: {
            displayMode: {
                type: 'string',
                default: 'auto'
            },
            buttonStyle: {
                type: 'string',
                default: 'primary'
            },
            showInlineMap: {
                type: 'boolean',
                default: false
            }
        },

        edit: function(props) {
            const { attributes, setAttributes } = props;
            const { displayMode, buttonStyle, showInlineMap } = attributes;

            return el('div', { className: 'mygls-block-editor' },
                // Inspector Controls (sidebar settings)
                el(InspectorControls, {},
                    el(PanelBody, {
                        title: __('Display Settings', 'mygls-woocommerce'),
                        initialOpen: true
                    },
                        el(SelectControl, {
                            label: __('Display Mode', 'mygls-woocommerce'),
                            value: displayMode,
                            options: [
                                { label: __('Auto (from plugin settings)', 'mygls-woocommerce'), value: 'auto' },
                                { label: __('Button (opens modal)', 'mygls-woocommerce'), value: 'button' },
                                { label: __('Inline Map', 'mygls-woocommerce'), value: 'inline' }
                            ],
                            onChange: function(value) {
                                setAttributes({ displayMode: value });
                            }
                        }),
                        el(SelectControl, {
                            label: __('Button Style', 'mygls-woocommerce'),
                            value: buttonStyle,
                            options: [
                                { label: __('Primary (Blue)', 'mygls-woocommerce'), value: 'primary' },
                                { label: __('Secondary (Gray)', 'mygls-woocommerce'), value: 'secondary' },
                                { label: __('Success (Green)', 'mygls-woocommerce'), value: 'success' }
                            ],
                            onChange: function(value) {
                                setAttributes({ buttonStyle: value });
                            },
                            help: __('Only applies in button/modal mode', 'mygls-woocommerce')
                        }),
                        displayMode === 'inline' && el(ToggleControl, {
                            label: __('Show Inline Map', 'mygls-woocommerce'),
                            checked: showInlineMap,
                            onChange: function(value) {
                                setAttributes({ showInlineMap: value });
                            },
                            help: __('Display map directly in the page (inline mode only)', 'mygls-woocommerce')
                        })
                    )
                ),

                // Block preview in editor
                el('div', {
                    className: 'mygls-block-preview',
                    style: {
                        padding: '20px',
                        border: '2px dashed #ccc',
                        borderRadius: '8px',
                        background: '#f9f9f9',
                        textAlign: 'center'
                    }
                },
                    el('div', {
                        style: {
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            marginBottom: '10px'
                        }
                    },
                        el('span', {
                            className: 'dashicons dashicons-location-alt',
                            style: {
                                fontSize: '48px',
                                width: '48px',
                                height: '48px',
                                color: '#2271b1'
                            }
                        })
                    ),
                    el('h3', {
                        style: {
                            margin: '10px 0',
                            color: '#333'
                        }
                    }, __('GLS Parcelshop Selector', 'mygls-woocommerce')),
                    el('p', {
                        style: {
                            color: '#666',
                            fontSize: '14px',
                            margin: '5px 0'
                        }
                    },
                        displayMode === 'auto'
                            ? __('Display mode: Auto (from plugin settings)', 'mygls-woocommerce')
                            : displayMode === 'inline'
                                ? __('Display mode: Inline Map', 'mygls-woocommerce')
                                : __('Display mode: Button with Modal', 'mygls-woocommerce')
                    ),
                    el('p', {
                        style: {
                            color: '#999',
                            fontSize: '12px',
                            marginTop: '10px'
                        }
                    }, __('This block will display the GLS parcelshop selector on the checkout page when a parcelshop shipping method is selected.', 'mygls-woocommerce'))
                )
            );
        },

        save: function() {
            // Dynamic block - rendered server-side
            return null;
        }
    });

})(window.wp);
