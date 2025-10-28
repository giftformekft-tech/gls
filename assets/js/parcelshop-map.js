/**
 * MyGLS Parcelshop Selector using Official GLS Map Widget
 * Documentation: https://map.gls-hungary.com/widget/
 */
(function($) {
    'use strict';

    let widgetElement = null;
    let selectedParcelshop = null;

    $(document).ready(function() {
        initGLSWidget();
    });

    /**
     * Initialize GLS Map Widget
     */
    function initGLSWidget() {
        // Wait for the GLS widget custom element to be defined
        if (typeof customElements !== 'undefined') {
            customElements.whenDefined('gls-dpm-dialog').then(function() {
                setupWidget();
            });
        } else {
            // Fallback: wait a bit and try to setup
            setTimeout(setupWidget, 1000);
        }
    }

    /**
     * Setup widget event listeners
     */
    function setupWidget() {
        widgetElement = document.getElementById('mygls-parcelshop-widget');

        if (!widgetElement) {
            console.error('GLS widget element not found');
            return;
        }

        // Listen for parcelshop selection change event
        widgetElement.addEventListener('change', function(event) {
            handleParcelshopSelection(event.detail);
        });

        // Open widget modal when button is clicked
        $(document).on('click', '.mygls-select-parcelshop', function(e) {
            e.preventDefault();
            openWidget();
        });
    }

    /**
     * Open the GLS widget modal
     */
    function openWidget() {
        if (!widgetElement) {
            console.error('Widget not initialized');
            return;
        }

        try {
            // Call the showModal() method as per GLS documentation
            widgetElement.showModal();
        } catch (error) {
            console.error('Error opening GLS widget:', error);
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

        // Update hidden fields
        $('#mygls_parcelshop_id').val(selectedParcelshop.id);
        $('#mygls_parcelshop_data').val(JSON.stringify(selectedParcelshop));

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

})(jQuery);
