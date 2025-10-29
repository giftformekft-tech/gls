/**
 * MyGLS Parcelshop Selector using Official GLS Map Widget
 * Documentation: https://map.gls-hungary.com/widget/
 */
(function($) {
    'use strict';

    let widgetElement = null;
    let selectedParcelshop = null;
    let widgetInitialized = false;
    let widgetInitAttempts = 0;
    const MAX_INIT_ATTEMPTS = 10;

    $(document).ready(function() {
        initGLSWidget();
        initShippingAddressToggle();

        // Re-initialize widget on checkout update (WooCommerce AJAX)
        $(document.body).on('updated_checkout', function() {
            if (!widgetInitialized) {
                console.log('Checkout updated, re-initializing GLS widget...');
                widgetInitAttempts = 0;
                initGLSWidget();
            }
        });
    });

    /**
     * Initialize GLS Map Widget with retry logic
     */
    function initGLSWidget() {
        console.log('Initializing GLS widget...');

        // Wait for the GLS widget custom element to be defined
        if (typeof customElements !== 'undefined' && customElements.whenDefined) {
            customElements.whenDefined('gls-dpm-dialog').then(function() {
                console.log('GLS custom element defined');
                setupWidget();
            }).catch(function(error) {
                console.error('Error waiting for GLS custom element:', error);
                // Fallback to retry with timeout
                retrySetupWidget();
            });
        } else {
            // Fallback: wait and retry
            retrySetupWidget();
        }
    }

    /**
     * Retry widget setup with exponential backoff
     */
    function retrySetupWidget() {
        widgetInitAttempts++;

        if (widgetInitAttempts > MAX_INIT_ATTEMPTS) {
            console.error('Failed to initialize GLS widget after', MAX_INIT_ATTEMPTS, 'attempts');
            return;
        }

        const delay = Math.min(1000 * Math.pow(1.5, widgetInitAttempts - 1), 5000);
        console.log('Retrying widget setup in', delay, 'ms (attempt', widgetInitAttempts, ')');

        setTimeout(function() {
            setupWidget();
        }, delay);
    }

    /**
     * Setup widget event listeners
     */
    function setupWidget() {
        console.log('setupWidget called, attempt:', widgetInitAttempts);

        // Try multiple widget IDs (for classic checkout, blocks, etc.)
        const widgetIds = ['mygls-parcelshop-widget', 'mygls-parcelshop-widget-classic', 'mygls-checkout-widget'];

        for (const id of widgetIds) {
            const element = document.getElementById(id);
            if (element) {
                widgetElement = element;
                console.log('Found GLS widget element:', id, element);
                break;
            }
        }

        // Also try querySelector as fallback
        if (!widgetElement) {
            widgetElement = document.querySelector('gls-dpm-dialog');
            if (widgetElement) {
                console.log('Found GLS widget element via querySelector:', widgetElement);
            }
        }

        if (!widgetElement) {
            console.warn('GLS widget element not found yet, will retry...');
            retrySetupWidget();
            return;
        }

        // Check if the widget element has the required showModal method
        if (typeof widgetElement.showModal !== 'function') {
            console.warn('Widget element found but showModal method not available yet, will retry...', 'Element:', widgetElement);
            console.log('Available methods on element:', Object.getOwnPropertyNames(Object.getPrototypeOf(widgetElement)));
            retrySetupWidget();
            return;
        }

        // Widget is ready!
        widgetInitialized = true;
        console.log('GLS widget initialized successfully!');

        // Listen for parcelshop selection change event
        widgetElement.addEventListener('change', function(event) {
            console.log('Parcelshop selected:', event.detail);
            handleParcelshopSelection(event.detail);
        });

        // Open widget modal when button is clicked (using event delegation)
        $(document).off('click.mygls-parcelshop').on('click.mygls-parcelshop', '.mygls-select-parcelshop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Open parcelshop button clicked');
            openWidget();
        });
    }

    /**
     * Open the GLS widget modal
     */
    function openWidget() {
        console.log('openWidget called, widgetElement:', widgetElement);

        if (!widgetElement) {
            console.error('Widget not initialized - attempting to re-initialize...');
            widgetInitialized = false;
            widgetInitAttempts = 0;

            // Show loading message
            if (typeof myglsCheckout !== 'undefined' && myglsCheckout.i18n && myglsCheckout.i18n.mapLoading) {
                alert(myglsCheckout.i18n.mapLoading);
            }

            initGLSWidget();

            // Try again after a short delay
            setTimeout(function() {
                if (widgetElement && typeof widgetElement.showModal === 'function') {
                    console.log('Retrying to open widget after initialization...');
                    widgetElement.showModal();
                } else {
                    console.error('Still no widget element after retry');
                }
            }, 1500);
            return;
        }

        // Verify showModal method exists
        if (typeof widgetElement.showModal !== 'function') {
            console.error('showModal method not available on widget element. Available methods:', Object.keys(widgetElement));

            // Try to re-initialize
            widgetInitialized = false;
            widgetInitAttempts = 0;

            if (typeof myglsCheckout !== 'undefined' && myglsCheckout.i18n && myglsCheckout.i18n.mapError) {
                alert(myglsCheckout.i18n.mapError);
            }

            initGLSWidget();
            return;
        }

        try {
            console.log('Opening GLS widget modal...');
            // Call the showModal() method as per GLS documentation
            widgetElement.showModal();
            console.log('showModal() called successfully');
        } catch (error) {
            console.error('Error opening GLS widget:', error);
            if (typeof myglsCheckout !== 'undefined' && myglsCheckout.i18n && myglsCheckout.i18n.mapError) {
                alert(myglsCheckout.i18n.mapError);
            }
        }
    }

    /**
     * Handle parcelshop selection from widget
     * @param {Object} detail - Selected parcelshop data from event.detail
     *
     * Expected structure based on GLS documentation:
     * {
     *   "id": "1011-ALPHAZOOKF",
     *   "name": "Alpha Zoo Batthyány tér",
     *   "contact": {
     *     "countryCode": "HU",
     *     "postalCode": "1011",
     *     "city": "Budapest I. kerület",
     *     "address": "Batthyány tér 5-6.",
     *     "name": "Ügyfélszolgálat",
     *     "email": "ugyfelszolgalat@alphazoo.hu"
     *   }
     * }
     */
    function handleParcelshopSelection(detail) {
        if (!detail || !detail.id) {
            console.error('Invalid parcelshop data received');
            return;
        }

        // Transform GLS widget data format to our internal format
        selectedParcelshop = {
            id: detail.id,
            name: detail.name,
            address: formatAddress(detail.contact),
            city: detail.contact.city || '',
            zip: detail.contact.postalCode || '',
            countryCode: detail.contact.countryCode || '',
            contactName: detail.contact.name || '',
            contactEmail: detail.contact.email || ''
        };

        // Update hidden fields (both specific ID and class-based for multiple instances)
        $('#mygls_parcelshop_id, .mygls-parcelshop-id-field').val(selectedParcelshop.id);
        $('#mygls_parcelshop_data, .mygls-parcelshop-data-field').val(JSON.stringify(selectedParcelshop));

        // Update display
        updateSelectedDisplay(selectedParcelshop);

        // Save to session via AJAX
        saveToSession(selectedParcelshop);

        // Trigger checkout update
        $('body').trigger('update_checkout');
    }

    /**
     * Format address from contact object
     * @param {Object} contact - Contact information from GLS widget
     * @returns {string} Formatted address
     */
    function formatAddress(contact) {
        const parts = [];

        if (contact.address) {
            parts.push(contact.address);
        }
        if (contact.postalCode && contact.city) {
            parts.push(contact.postalCode + ' ' + contact.city);
        } else if (contact.city) {
            parts.push(contact.city);
        } else if (contact.postalCode) {
            parts.push(contact.postalCode);
        }

        return parts.join(', ');
    }

    /**
     * Update the selected parcelshop display
     * @param {Object} parcelshop - Selected parcelshop data
     */
    function updateSelectedDisplay(parcelshop) {
        const $selector = $('.mygls-parcelshop-selector');
        let $display = $selector.find('.mygls-selected-parcelshop');
        let $helpText = $selector.find('.mygls-help-text');

        // Remove help text if present
        if ($helpText.length > 0) {
            $helpText.fadeOut(200, function() {
                $(this).remove();
            });
        }

        if ($display.length === 0) {
            const displayHtml =
                '<div class="mygls-selected-parcelshop mygls-slide-in">' +
                    '<div class="mygls-selected-icon">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                            '<polyline points="20 6 9 17 4 12"></polyline>' +
                        '</svg>' +
                    '</div>' +
                    '<div class="mygls-selected-info">' +
                        '<strong>' + escapeHtml(parcelshop.name) + '</strong>' +
                        '<small>' + escapeHtml(parcelshop.address) + '</small>' +
                    '</div>' +
                '</div>';

            $selector.find('.mygls-parcelshop-trigger').append(displayHtml);
        } else {
            // Update existing display with animation
            $display.fadeOut(150, function() {
                $display.find('.mygls-selected-info strong').text(parcelshop.name);
                $display.find('.mygls-selected-info small').text(parcelshop.address);
                $display.addClass('mygls-slide-in').fadeIn(200);

                setTimeout(function() {
                    $display.removeClass('mygls-slide-in');
                }, 400);
            });
        }
    }

    /**
     * Save selected parcelshop to session
     * @param {Object} parcelshop - Parcelshop data
     */
    function saveToSession(parcelshop) {
        $.ajax({
            url: myglsCheckout.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mygls_save_parcelshop',
                nonce: myglsCheckout.nonce,
                parcelshop: JSON.stringify(parcelshop)
            },
            success: function(response) {
                if (response.success) {
                    console.log('Parcelshop saved to session');
                } else {
                    console.error('Failed to save parcelshop to session');
                }
            },
            error: function() {
                console.error('AJAX error saving parcelshop');
            }
        });
    }

    /**
     * Escape HTML to prevent XSS
     * @param {string} text - Text to escape
     * @returns {string} Escaped text
     */
    function escapeHtml(text) {
        if (!text) return '';

        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, function(m) {
            return map[m];
        });
    }

    /**
     * Initialize shipping address toggle
     * Hides shipping address fields when parcelshop delivery is selected
     */
    function initShippingAddressToggle() {
        // For classic checkout
        $(document.body).on('change', 'input[name^="shipping_method"]', function() {
            toggleShippingAddress();
        });

        // Also check on page load
        $(document.body).on('updated_checkout', function() {
            toggleShippingAddress();
        });

        // Initial check
        toggleShippingAddress();
    }

    /**
     * Toggle shipping address visibility based on selected shipping method
     */
    function toggleShippingAddress() {
        // Get enabled parcelshop methods from localized data
        const enabledMethods = myglsCheckout.enabledMethods || [];

        if (!enabledMethods || enabledMethods.length === 0) {
            return;
        }

        // Check if a parcelshop-enabled method is selected
        let isParcelshopSelected = false;
        $('input[name^="shipping_method"]:checked').each(function() {
            const selectedMethod = $(this).val();

            enabledMethods.forEach(function(method) {
                if (selectedMethod.indexOf(method) !== -1) {
                    isParcelshopSelected = true;
                }
            });
        });

        // Hide/show shipping address fields
        const $shippingAddressFields = $('.woocommerce-shipping-fields, .shipping_address, #ship-to-different-address-checkbox');

        if (isParcelshopSelected) {
            // Hide shipping address fields for parcelshop delivery
            $shippingAddressFields.slideUp(300);

            // Uncheck "ship to different address" if applicable
            $('#ship-to-different-address-checkbox input').prop('checked', false).trigger('change');

            // Add a notice explaining why fields are hidden
            if ($('.mygls-shipping-notice').length === 0) {
                $('.woocommerce-shipping-fields').before(
                    '<div class="mygls-shipping-notice woocommerce-info">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 5px;">' +
                    '<circle cx="12" cy="12" r="10"></circle>' +
                    '<line x1="12" y1="16" x2="12" y2="12"></line>' +
                    '<line x1="12" y1="8" x2="12.01" y2="8"></line>' +
                    '</svg>' +
                    'A rendelését a kiválasztott GLS csomagpontba szállítjuk. Szállítási cím megadása nem szükséges.' +
                    '</div>'
                );
            }
        } else {
            // Show shipping address fields for normal delivery
            $shippingAddressFields.slideDown(300);

            // Remove notice
            $('.mygls-shipping-notice').remove();
        }
    }

})(jQuery);
