/**
 * MyGLS Parcelshop Block Integration for WooCommerce Blocks Checkout
 */

const { registerCheckoutFilters } = window.wc.blocksCheckout;
const { getSetting } = window.wc.wcSettings;
const { createElement, Fragment } = window.wp.element;

// Get settings from PHP
const settings = getSetting('mygls-parcelshop_data', {});

/**
 * Add parcelshop selector after shipping options
 */
if (typeof registerCheckoutFilters === 'function') {
    registerCheckoutFilters('mygls-parcelshop', {
        // Add additional checkout fields
        additionalCheckoutFields: (fields) => {
            return {
                ...fields,
                'mygls/parcelshop-selector': {
                    location: 'contact',
                    hidden: false,
                },
            };
        },
    });
}

// Initialize parcelshop selector when blocks checkout loads
window.addEventListener('DOMContentLoaded', () => {
    // Check if we're on blocks checkout
    const checkoutBlock = document.querySelector('.wp-block-woocommerce-checkout');
    if (!checkoutBlock) {
        return; // Not blocks checkout
    }

    // Wait for shipping methods to load
    const initializeParcelshopSelector = () => {
        const shippingSection = document.querySelector('.wc-block-components-shipping-rates-control');
        if (!shippingSection) {
            setTimeout(initializeParcelshopSelector, 500);
            return;
        }

        // Add parcelshop selector to DOM if not already present
        if (!document.getElementById('mygls-parcelshop-blocks-container')) {
            const container = document.createElement('div');
            container.id = 'mygls-parcelshop-blocks-container';
            container.className = 'mygls-parcelshop-blocks-wrapper';

            // Insert after shipping section
            shippingSection.parentNode.insertBefore(container, shippingSection.nextSibling);

            // The actual parcelshop selector UI will be rendered by parcelshop-map.js
            // which is already enqueued and handles both classic and blocks checkout
            if (typeof window.myglsInitParcelshopSelector === 'function') {
                window.myglsInitParcelshopSelector(container);
            }
        }

        // Listen for shipping method changes
        document.addEventListener('change', (e) => {
            if (e.target && e.target.name && e.target.name.includes('radio-control-wc-shipping')) {
                handleShippingMethodChange(e.target);
            }
        });
    };

    // Start initialization
    initializeParcelshopSelector();
});

/**
 * Handle shipping method selection changes
 */
function handleShippingMethodChange(radio) {
    const selectedValue = radio.value;
    const container = document.getElementById('mygls-parcelshop-blocks-container');

    if (!container) return;

    // Check if selected shipping method requires parcelshop selection
    const enabledMethods = settings.enabled_methods || [];
    const shouldShow = enabledMethods.some(method => selectedValue.includes(method));

    if (shouldShow) {
        container.style.display = 'block';
        // Trigger parcelshop selector visibility
        const event = new CustomEvent('mygls-show-parcelshop', { detail: { methodId: selectedValue } });
        document.dispatchEvent(event);
    } else {
        container.style.display = 'none';
    }
}
