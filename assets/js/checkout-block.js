/**
 * MyGLS Checkout Block Integration
 * Implements WooCommerce Checkout Block Extensibility API
 */

const { registerPlugin } = wp.plugins;
const { ExperimentalOrderShippingPackages } = wc.blocksCheckout;
const { useState, useEffect } = wp.element;
const { __ } = wp.i18n;
const { useSelect, useDispatch } = wp.data;
const { CHECKOUT_STORE_KEY } = wc.wcBlocksData;

/**
 * Parcelshop Selector Component
 */
const ParcelshopSelector = () => {
    const [selectedParcelshop, setSelectedParcelshop] = useState(null);
    const [widgetReady, setWidgetReady] = useState(false);

    // Get chosen shipping method
    const { shippingRates } = useSelect((select) => {
        const store = select(CHECKOUT_STORE_KEY);
        return {
            shippingRates: store.getShippingRates(),
        };
    });

    // Get extension data dispatch
    const { setExtensionData } = useDispatch(CHECKOUT_STORE_KEY);

    // Check if parcelshop selector should be shown
    const shouldShowSelector = () => {
        if (!shippingRates || !shippingRates.length) {
            return false;
        }

        const enabledMethods = myglsCheckoutBlock.enabledMethods || [];
        if (!enabledMethods.length) {
            return false;
        }

        // Check if any selected shipping method is in enabled methods
        for (const rateGroup of shippingRates) {
            const selectedRate = rateGroup.shipping_rates.find(rate => rate.selected);
            if (selectedRate) {
                // Check if this method is enabled for parcelshop
                const isEnabled = enabledMethods.some(method =>
                    selectedRate.rate_id.includes(method) || selectedRate.method_id.includes(method)
                );
                if (isEnabled) {
                    return true;
                }
            }
        }

        return false;
    };

    const showSelector = shouldShowSelector();

    // Initialize GLS widget
    useEffect(() => {
        if (!showSelector) {
            return;
        }

        // Wait for custom element to be defined
        if (typeof customElements !== 'undefined') {
            customElements.whenDefined('gls-dpm-dialog').then(() => {
                setWidgetReady(true);
                setupWidget();
            }).catch(err => {
                console.error('GLS widget loading error:', err);
            });
        } else {
            // Fallback: wait and try again
            setTimeout(() => {
                setWidgetReady(true);
                setupWidget();
            }, 1500);
        }
    }, [showSelector]);

    // Setup widget event listeners
    const setupWidget = () => {
        const widgetElement = document.getElementById('mygls-checkout-widget');

        if (!widgetElement) {
            console.error('GLS widget element not found');
            return;
        }

        // Listen for parcelshop selection
        widgetElement.addEventListener('change', (event) => {
            handleParcelshopSelection(event.detail);
        });
    };

    // Handle parcelshop selection
    const handleParcelshopSelection = (detail) => {
        if (!detail || !detail.id) {
            console.error('Invalid parcelshop data');
            return;
        }

        // Transform GLS widget data
        const parcelshop = {
            id: detail.id,
            name: detail.name,
            address: formatAddress(detail.contact),
            city: detail.contact?.city || '',
            zip: detail.contact?.postalCode || '',
            countryCode: detail.contact?.countryCode || '',
            contactName: detail.contact?.name || '',
            contactEmail: detail.contact?.email || ''
        };

        setSelectedParcelshop(parcelshop);

        // Update extension data (will be sent to Store API)
        setExtensionData('mygls', {
            parcelshop: parcelshop
        });

        // Also save to session via AJAX for classic checkout compatibility
        saveToSession(parcelshop);
    };

    // Format address from contact object
    const formatAddress = (contact) => {
        if (!contact) return '';

        const parts = [];
        if (contact.address) parts.push(contact.address);
        if (contact.postalCode && contact.city) {
            parts.push(`${contact.postalCode} ${contact.city}`);
        } else if (contact.city) {
            parts.push(contact.city);
        } else if (contact.postalCode) {
            parts.push(contact.postalCode);
        }

        return parts.join(', ');
    };

    // Save to session via AJAX
    const saveToSession = (parcelshop) => {
        fetch(myglsCheckoutBlock.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'mygls_save_parcelshop',
                nonce: myglsCheckoutBlock.nonce,
                parcelshop: JSON.stringify(parcelshop)
            })
        }).then(response => response.json())
          .then(data => {
              if (data.success) {
                  console.log('Parcelshop saved to session');
              }
          })
          .catch(err => console.error('Error saving parcelshop:', err));
    };

    // Open widget modal
    const openWidget = () => {
        const widgetElement = document.getElementById('mygls-checkout-widget');
        if (widgetElement && typeof widgetElement.showModal === 'function') {
            try {
                widgetElement.showModal();
            } catch (error) {
                console.error('Error opening GLS widget:', error);
            }
        }
    };

    // Don't render if selector shouldn't be shown
    if (!showSelector) {
        return null;
    }

    return (
        <div className="wc-block-components-checkout-step__container">
            <div className="mygls-parcelshop-block-wrapper">
                <div className="mygls-parcelshop-header">
                    <svg className="mygls-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                        <circle cx="12" cy="10" r="3"></circle>
                    </svg>
                    <h3>{myglsCheckoutBlock.i18n.parcelshopDelivery}</h3>
                </div>

                <div className="mygls-parcelshop-content">
                    {selectedParcelshop ? (
                        <div className="mygls-selected-parcelshop-block">
                            <div className="mygls-selected-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <polyline points="20 6 9 17 4 12"></polyline>
                                </svg>
                            </div>
                            <div className="mygls-selected-info">
                                <strong>{selectedParcelshop.name}</strong>
                                <small>{selectedParcelshop.address}</small>
                            </div>
                            <button
                                type="button"
                                className="mygls-change-button"
                                onClick={openWidget}
                            >
                                {myglsCheckoutBlock.i18n.changeParcelshop}
                            </button>
                        </div>
                    ) : (
                        <div className="mygls-no-selection">
                            <p className="mygls-help-text">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="12" y1="16" x2="12" y2="12"></line>
                                    <line x1="12" y1="8" x2="12.01" y2="8"></line>
                                </svg>
                                {myglsCheckoutBlock.i18n.parcelshopRequired}
                            </p>
                            <button
                                type="button"
                                className="wc-block-components-button wp-element-button mygls-select-button"
                                onClick={openWidget}
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                    <circle cx="12" cy="10" r="3"></circle>
                                </svg>
                                {myglsCheckoutBlock.i18n.selectParcelshop}
                            </button>
                        </div>
                    )}
                </div>

                {/* GLS Official Map Widget Dialog */}
                {widgetReady && (
                    <gls-dpm-dialog
                        country={myglsCheckoutBlock.country}
                        language={myglsCheckoutBlock.language || undefined}
                        id="mygls-checkout-widget"
                    ></gls-dpm-dialog>
                )}
            </div>
        </div>
    );
};

/**
 * Register the plugin/component with WooCommerce Blocks
 */
const MyGLSCheckoutPlugin = () => {
    return (
        <ExperimentalOrderShippingPackages>
            <ParcelshopSelector />
        </ExperimentalOrderShippingPackages>
    );
};

// Register plugin
registerPlugin('mygls-parcelshop-checkout', {
    render: MyGLSCheckoutPlugin,
    scope: 'woocommerce-checkout',
});
