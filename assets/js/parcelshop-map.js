/**
 * MyGLS Parcelshop Map Selector
 */
(function($) {
    'use strict';
    
    let map = null;
    let markers = [];
    let selectedParcelshop = null;
    let parcelshops = [];
    
    $(document).ready(function() {
        initParcelshopSelector();
    });
    
    function initParcelshopSelector() {
        // Open modal
        $(document).on('click', '.mygls-select-parcelshop', function(e) {
            e.preventDefault();
            openModal();
        });
        
        // Close modal
        $(document).on('click', '.mygls-modal-close', function() {
            closeModal();
        });
        
        // Close on outside click
        $(document).on('click', '.mygls-modal', function(e) {
            if ($(e.target).hasClass('mygls-modal')) {
                closeModal();
            }
        });
        
        // Search button
        $(document).on('click', '#mygls-search-btn', function() {
            const searchTerm = $('#mygls-parcelshop-search').val();
            if (searchTerm) {
                searchParcelshops(searchTerm);
            }
        });
        
        // Enter key in search
        $(document).on('keypress', '#mygls-parcelshop-search', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#mygls-search-btn').click();
            }
        });
        
        // Locate button
        $(document).on('click', '#mygls-locate-btn', function() {
            getUserLocation();
        });
        
        // Select parcelshop
        $(document).on('click', '.mygls-parcelshop-item', function() {
            const index = $(this).data('index');
            selectParcelshop(index);
        });
        
        // Confirm selection
        $(document).on('click', '#mygls-confirm-parcelshop', function() {
            confirmSelection();
        });
    }
    
    function openModal() {
        $('#mygls-parcelshop-modal').fadeIn(300);
        
        // Initialize map if not already
        if (!map) {
            setTimeout(initMap, 100);
        }
        
        // Auto-search based on billing city/zip
        const billingCity = $('#billing_city').val();
        const billingPostcode = $('#billing_postcode').val();
        
        if (billingCity || billingPostcode) {
            $('#mygls-parcelshop-search').val(billingPostcode || billingCity);
            setTimeout(function() {
                $('#mygls-search-btn').click();
            }, 300);
        }
    }
    
    function closeModal() {
        $('#mygls-parcelshop-modal').fadeOut(300);
    }
    
    function initMap() {
        if (typeof L === 'undefined') {
            console.error('Leaflet not loaded');
            return;
        }
        
        // Default center (Budapest)
        const defaultLat = 47.4979;
        const defaultLng = 19.0402;
        
        map = L.map('mygls-map').setView([defaultLat, defaultLng], 13);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxZoom: 18
        }).addTo(map);
        
        // Fix map size after modal animation
        setTimeout(function() {
            map.invalidateSize();
        }, 400);
    }
    
    function searchParcelshops(searchTerm) {
        showLoading();
        
        $.ajax({
            url: myglsCheckout.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mygls_get_parcelshops',
                nonce: myglsCheckout.nonce,
                search: searchTerm
            },
            success: function(response) {
                if (response.success && response.data.parcelshops) {
                    parcelshops = response.data.parcelshops;
                    displayParcelshops(parcelshops);
                    
                    // Center map on first result
                    if (parcelshops.length > 0) {
                        const first = parcelshops[0];
                        map.setView([first.lat, first.lng], 14);
                    }
                } else {
                    showError(myglsCheckout.i18n.noResults);
                }
            },
            error: function() {
                showError('Error loading parcelshops');
            }
        });
    }
    
    function getUserLocation() {
        if (!navigator.geolocation) {
            alert('Geolocation is not supported by your browser');
            return;
        }
        
        showLoading();
        
        navigator.geolocation.getCurrentPosition(
            function(position) {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                
                $.ajax({
                    url: myglsCheckout.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'mygls_get_parcelshops',
                        nonce: myglsCheckout.nonce,
                        lat: lat,
                        lng: lng
                    },
                    success: function(response) {
                        if (response.success && response.data.parcelshops) {
                            parcelshops = response.data.parcelshops;
                            displayParcelshops(parcelshops);
                            map.setView([lat, lng], 14);
                        }
                    }
                });
            },
            function() {
                alert('Unable to retrieve your location');
                hideLoading();
            }
        );
    }
    
    function displayParcelshops(parcelshops) {
        const $results = $('#mygls-parcelshop-results');
        $results.empty();
        
        // Clear existing markers
        markers.forEach(marker => map.removeLayer(marker));
        markers = [];
        
        if (parcelshops.length === 0) {
            $results.html('<p class="mygls-no-results">' + myglsCheckout.i18n.noResults + '</p>');
            return;
        }
        
        parcelshops.forEach((parcelshop, index) => {
            // Add to list
            const $item = $(`
                <div class="mygls-parcelshop-item" data-index="${index}">
                    <h4>${escapeHtml(parcelshop.name)}</h4>
                    <p>
                        <span class="dashicons dashicons-location"></span>
                        ${escapeHtml(parcelshop.address)}
                    </p>
                    ${parcelshop.phone ? `
                        <p>
                            <span class="dashicons dashicons-phone"></span>
                            ${escapeHtml(parcelshop.phone)}
                        </p>
                    ` : ''}
                    ${parcelshop.hours ? `
                        <p>
                            <span class="dashicons dashicons-clock"></span>
                            ${escapeHtml(parcelshop.hours)}
                        </p>
                    ` : ''}
                    ${parcelshop.distance ? `
                        <span class="mygls-distance">
                            ${parcelshop.distance} km
                        </span>
                    ` : ''}
                </div>
            `);
            
            $results.append($item);
            
            // Add marker to map
            const marker = L.marker([parcelshop.lat, parcelshop.lng], {
                icon: L.divIcon({
                    className: 'mygls-marker',
                    iconSize: [30, 30],
                    iconAnchor: [15, 15]
                })
            }).addTo(map);
            
            marker.bindPopup(`
                <strong>${escapeHtml(parcelshop.name)}</strong><br>
                ${escapeHtml(parcelshop.address)}<br>
                ${parcelshop.phone ? escapeHtml(parcelshop.phone) + '<br>' : ''}
                <button class="button button-small" onclick="selectParcelshopFromMap(${index})">
                    Select
                </button>
            `);
            
            marker.on('click', function() {
                selectParcelshop(index);
            });
            
            markers.push(marker);
        });
        
        hideLoading();
    }
    
    function selectParcelshop(index) {
        selectedParcelshop = parcelshops[index];
        
        // Update UI
        $('.mygls-parcelshop-item').removeClass('selected');
        $(`.mygls-parcelshop-item[data-index="${index}"]`).addClass('selected');
        
        // Update markers
        markers.forEach((marker, i) => {
            const icon = L.divIcon({
                className: i === index ? 'mygls-marker selected' : 'mygls-marker',
                iconSize: i === index ? [36, 36] : [30, 30],
                iconAnchor: i === index ? [18, 18] : [15, 15]
            });
            marker.setIcon(icon);
        });
        
        // Center map on selected
        map.setView([selectedParcelshop.lat, selectedParcelshop.lng], 15);
        
        // Enable confirm button
        $('#mygls-confirm-parcelshop').prop('disabled', false);
        
        // Scroll to selected item
        const $selected = $(`.mygls-parcelshop-item[data-index="${index}"]`);
        $('.mygls-parcelshop-list').animate({
            scrollTop: $selected.offset().top - $('.mygls-parcelshop-list').offset().top + $('.mygls-parcelshop-list').scrollTop()
        }, 300);
    }
    
    // Make selectParcelshopFromMap available globally for map popup
    window.selectParcelshopFromMap = function(index) {
        selectParcelshop(index);
    };
    
    function confirmSelection() {
        if (!selectedParcelshop) {
            return;
        }
        
        // Update hidden fields
        $('#mygls_parcelshop_id').val(selectedParcelshop.id);
        $('#mygls_parcelshop_data').val(JSON.stringify(selectedParcelshop));
        
        // Update display
        const $selector = $('.mygls-parcelshop-selector');
        let $display = $selector.find('.mygls-selected-parcelshop');
        
        if ($display.length === 0) {
            $display = $('<div class="mygls-selected-parcelshop"></div>');
            $selector.find('.mygls-parcelshop-trigger').append($display);
        }
        
        $display.html(`
            <strong>${escapeHtml(selectedParcelshop.name)}</strong><br>
            <small>${escapeHtml(selectedParcelshop.address)}</small>
        `);
        
        // Save to session
        $.ajax({
            url: myglsCheckout.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mygls_save_parcelshop',
                nonce: myglsCheckout.nonce,
                parcelshop: selectedParcelshop
            }
        });
        
        closeModal();
        
        // Trigger checkout update
        $('body').trigger('update_checkout');
    }
    
    function showLoading() {
        $('#mygls-parcelshop-results').html('<p class="mygls-loading">' + myglsCheckout.i18n.searching + '</p>');
    }
    
    function hideLoading() {
        // Loading is replaced by results
    }
    
    function showError(message) {
        $('#mygls-parcelshop-results').html('<p class="mygls-error">' + escapeHtml(message) + '</p>');
    }
    
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }
    
})(jQuery);