/**
 * MyGLS Admin JavaScript
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        initTestConnection();
        initOrderListEnhancements();
        initButtonPreview();
    });
    
    /**
     * Test API Connection
     */
    function initTestConnection() {
        $('#test-connection').on('click', function() {
            const $btn = $(this);
            const $status = $('#connection-status');

            // Get current form values
            const formData = {
                action: 'mygls_test_connection',
                nonce: myglsAdmin.nonce,
                country: $('#country').val(),
                username: $('#username').val(),
                password: $('#password').val(),
                client_number: $('#client_number').val(),
                test_mode: $('#test_mode').is(':checked') ? '1' : '0'
            };

            // Validate required fields
            if (!formData.username || !formData.password || !formData.client_number) {
                $status.html('<span class="dashicons dashicons-no"></span> ' + 'Kérlek töltsd ki az összes kötelező mezőt')
                       .addClass('error')
                       .removeClass('success');
                return;
            }

            $btn.prop('disabled', true)
                .html('<span class="dashicons dashicons-update spin"></span> ' + myglsAdmin.i18n.processing);

            $.ajax({
                url: myglsAdmin.ajaxurl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        $status.html('<span class="dashicons dashicons-yes"></span> ' + response.data.message)
                               .addClass('success')
                               .removeClass('error');
                    } else {
                        $status.html('<span class="dashicons dashicons-no"></span> ' + response.data.message)
                               .addClass('error')
                               .removeClass('success');
                    }
                },
                error: function() {
                    $status.html('<span class="dashicons dashicons-no"></span> ' + myglsAdmin.i18n.error)
                           .addClass('error')
                           .removeClass('success');
                },
                complete: function() {
                    $btn.prop('disabled', false)
                        .html('<span class="dashicons dashicons-admin-plugins"></span> Test Connection');
                }
            });
        });
    }
    
    /**
     * Order List Enhancements
     */
    function initOrderListEnhancements() {
        // Add GLS label indicator to order list
        $('.wp-list-table tr').each(function() {
            const $row = $(this);
            const orderId = $row.attr('id');
            
            if (orderId && orderId.indexOf('post-') === 0) {
                checkOrderLabel($row, orderId.replace('post-', ''));
            }
        });
    }
    
    function checkOrderLabel($row, orderId) {
        // Check if order has GLS label (this could be enhanced with AJAX)
        const $orderNumber = $row.find('.order_number');
        
        // This is a placeholder - in production, you'd check via AJAX or add data attributes
        // For now, we'll just add the UI structure
    }

    function initButtonPreview() {
        const $styleSelect = $('#map_button_style');
        const $previewPanel = $('[data-mygls-button-preview]');

        if (!$styleSelect.length || !$previewPanel.length) {
            return;
        }

        const allowedStyles = ['primary', 'secondary', 'success'];

        const updatePreview = () => {
            const style = $styleSelect.val();
            const safeStyle = allowedStyles.includes(style) ? style : 'primary';
            const $buttons = $previewPanel.find('.mygls-button-preview');

            $buttons.removeClass('mygls-button-preview--primary mygls-button-preview--secondary mygls-button-preview--success');
            $buttons.addClass('mygls-button-preview--' + safeStyle);
        };

        updatePreview();
        $styleSelect.on('change', updatePreview);
    }
    
})(jQuery);
