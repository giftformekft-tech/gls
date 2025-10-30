(function($) {
    'use strict';

    var settings = window.myglsCustomCheckout || {};
    var loadingMessage = settings.loadingMessage || '';
    var placeholderMarkup = settings.placeholderMarkup || '';

    var sameAsBillingFieldMap = {
        billing_first_name: 'shipping_first_name',
        billing_last_name: 'shipping_last_name',
        billing_company: 'shipping_company',
        billing_address_1: 'shipping_address_1',
        billing_address_2: 'shipping_address_2',
        billing_city: 'shipping_city',
        billing_postcode: 'shipping_postcode',
        billing_phone: 'shipping_phone',
        billing_email: 'shipping_email'
    };

    var shippingFieldSnapshot = null;
    var checkoutRefreshTimeout = null;

    function highlightSelectedShippingMethod() {
        var $lists = $('.mygls-section-shipping-method .woocommerce-shipping-methods');

        if (!$lists.length) {
            return;
        }

        $lists.each(function() {
            var $list = $(this);
            $list.find('li').removeClass('woocommerce-shipping-method-selected');
            $list.find('input[type="radio"]:checked').closest('li').addClass('woocommerce-shipping-method-selected');
        });
    }

    function ensurePlaceholder($wrapper) {
        if (!$wrapper.children().length && placeholderMarkup) {
            $wrapper.html(placeholderMarkup);
            $wrapper.find('.mygls-loading-message').text(loadingMessage);
            $wrapper.addClass('mygls-section-wrapper--loading');
        }
    }

    function toggleWrapper($wrapper, shouldHide) {
        if (!$wrapper.length) {
            return;
        }

        $wrapper.attr('aria-hidden', shouldHide ? 'true' : 'false');

        if (shouldHide) {
            $wrapper.addClass('mygls-section-wrapper--hidden');
            $wrapper.removeClass('mygls-section-wrapper--loading');
        } else {
            $wrapper.removeClass('mygls-section-wrapper--hidden mygls-section-wrapper--empty');
            ensurePlaceholder($wrapper);
        }
    }

    function isShippingSectionVisible() {
        var $wrapper = $('#mygls-section-wrapper-shipping');
        return $wrapper.length && $wrapper.attr('aria-hidden') !== 'true';
    }

    function updateShipToDifferentAddressFlag(shipsToDifferent) {
        var $hiddenField = $('#ship_to_different_address');

        if ($hiddenField.length) {
            $hiddenField.val(shipsToDifferent ? '1' : '0');
        }

        var $defaultCheckbox = $('#ship-to-different-address-checkbox');
        if ($defaultCheckbox.length) {
            $defaultCheckbox.prop('checked', shipsToDifferent);
        }
    }

    function setSectionVisibility() {
        var $selected = $('.mygls-section-shipping-method input[type="radio"]:checked');
        var isParcelshop = false;

        if ($selected.length) {
            var dataValue = $selected.data('parcelshop');
            isParcelshop = dataValue === 1 || dataValue === '1';
        }

        toggleWrapper($('#mygls-section-wrapper-shipping'), isParcelshop);
        toggleWrapper($('#mygls-section-wrapper-parcelshop'), !isParcelshop);

        if (isParcelshop) {
            shippingFieldSnapshot = null;
        }

        updateShipToDifferentAddressFlag(!isParcelshop && !$('#mygls_same_as_billing').is(':checked'));
    }

    function requestCheckoutRefresh() {
        $('body').trigger('update_checkout');
    }

    function cancelScheduledCheckoutRefresh() {
        if (checkoutRefreshTimeout) {
            window.clearTimeout(checkoutRefreshTimeout);
            checkoutRefreshTimeout = null;
        }
    }

    function scheduleCheckoutRefresh() {
        cancelScheduledCheckoutRefresh();

        checkoutRefreshTimeout = window.setTimeout(function() {
            requestCheckoutRefresh();
            checkoutRefreshTimeout = null;
        }, 180);
    }

    function setFieldValue($field, value) {
        if (!$field.length) {
            return false;
        }

        if ($field.val() === value) {
            return false;
        }

        $field.val(value);

        if ($field.hasClass('select2-hidden-accessible')) {
            $field.trigger('change.select2');
        }

        return true;
    }

    function saveCurrentShippingValues() {
        if (shippingFieldSnapshot) {
            return;
        }

        var snapshot = {};
        var hasValues = false;

        $.each(sameAsBillingFieldMap, function(_, shippingField) {
            var $shippingInput = $('#' + shippingField);
            if ($shippingInput.length) {
                snapshot[shippingField] = $shippingInput.val();
                hasValues = true;
            }
        });

        var $shippingCountry = $('#shipping_country');
        if ($shippingCountry.length) {
            snapshot.shipping_country = $shippingCountry.val();
            hasValues = true;
        }

        var $shippingState = $('#shipping_state');
        if ($shippingState.length) {
            snapshot.shipping_state = $shippingState.val();
            hasValues = true;
        }

        shippingFieldSnapshot = hasValues ? snapshot : null;
    }

    function restoreShippingValues() {
        if (!shippingFieldSnapshot) {
            return;
        }

        var restored = false;

        $.each(shippingFieldSnapshot, function(fieldId, value) {
            var $field;

            if (fieldId === 'shipping_country') {
                $field = $('#shipping_country');
            } else if (fieldId === 'shipping_state') {
                $field = $('#shipping_state');
            } else {
                $field = $('#' + fieldId);
            }

            if ($field.length && $field.val() !== value) {
                $field.val(value);

                if ($field.hasClass('select2-hidden-accessible')) {
                    $field.trigger('change.select2');
                }

                restored = true;
            }
        });

        shippingFieldSnapshot = null;

        if (restored) {
            scheduleCheckoutRefresh();
        }
    }

    function syncShippingFieldsWithBilling() {
        var pendingRefresh = false;

        $.each(sameAsBillingFieldMap, function(billingField, shippingField) {
            var $billingInput = $('#' + billingField);
            var $shippingInput = $('#' + shippingField);

            if ($billingInput.length && $shippingInput.length) {
                if (setFieldValue($shippingInput, $billingInput.val())) {
                    pendingRefresh = true;
                }
            }
        });

        var $billingCountry = $('#billing_country');
        var $shippingCountry = $('#shipping_country');
        var countryChanged = false;

        if ($billingCountry.length && $shippingCountry.length) {
            countryChanged = setFieldValue($shippingCountry, $billingCountry.val());
            if (countryChanged) {
                pendingRefresh = true;
            }
        }

        var $billingState = $('#billing_state');
        var $shippingState = $('#shipping_state');

        if ($billingState.length && $shippingState.length) {
            var billingStateValue = $billingState.val();
            var immediateStateChange = setFieldValue($shippingState, billingStateValue);

            if (immediateStateChange) {
                pendingRefresh = true;
            }

            window.setTimeout(function() {
                var deferredStateChange = setFieldValue($shippingState, billingStateValue);

                if (deferredStateChange || immediateStateChange || countryChanged || pendingRefresh) {
                    scheduleCheckoutRefresh();
                }
            }, countryChanged ? 200 : 120);

            return;
        }

        if (pendingRefresh) {
            scheduleCheckoutRefresh();
        }
    }

    function toggleShippingFieldsDisabled(disable) {
        var $shippingWrap = $('.mygls-shipping-fields-wrap');

        if (!$shippingWrap.length) {
            return;
        }

        $shippingWrap.attr('aria-disabled', disable ? 'true' : 'false');

        var $textInputs = $shippingWrap.find('input, textarea').not(':button, :submit, :reset, [type=hidden]');
        $textInputs.each(function() {
            var $field = $(this);

            if (disable) {
                $field.attr('readonly', 'readonly').attr('aria-readonly', 'true');
            } else {
                $field.removeAttr('readonly').removeAttr('aria-readonly');
            }
        });

        var $selects = $shippingWrap.find('select');
        $selects.each(function() {
            var $field = $(this);

            if (disable) {
                $field.attr('data-mygls-locked', '1').attr('aria-disabled', 'true');
                if (typeof $field.data('mygls-tabindex') === 'undefined') {
                    $field.data('mygls-tabindex', $field.attr('tabindex'));
                }
                $field.attr('tabindex', '-1');
            } else {
                $field.removeAttr('data-mygls-locked').removeAttr('aria-disabled');
                var originalTabIndex = $field.data('mygls-tabindex');

                if (typeof originalTabIndex !== 'undefined') {
                    if (originalTabIndex === null || originalTabIndex === undefined || originalTabIndex === '') {
                        $field.removeAttr('tabindex');
                    } else {
                        $field.attr('tabindex', originalTabIndex);
                    }
                    $field.removeData('mygls-tabindex');
                } else {
                    $field.removeAttr('tabindex');
                }
            }

            if ($field.hasClass('select2-hidden-accessible')) {
                $field.trigger('change.select2');
            }
        });

        $shippingWrap.toggleClass('mygls-disabled', !!disable);
    }

    function handleSameAsBillingCheckbox() {
        var $checkbox = $('#mygls_same_as_billing');

        if (!$checkbox.length) {
            return;
        }

        var isChecked = $checkbox.is(':checked');
        var shippingVisible = isShippingSectionVisible();

        if (isChecked && shippingVisible) {
            saveCurrentShippingValues();
            syncShippingFieldsWithBilling();
        }

        toggleShippingFieldsDisabled(isChecked && shippingVisible);

        if (!isChecked && shippingVisible) {
            restoreShippingValues();
        }

        if (!shippingVisible) {
            shippingFieldSnapshot = null;
        }

        updateShipToDifferentAddressFlag(shippingVisible && !isChecked);
    }

    function movePrivacyCheckboxBeforeOrderButton() {
        var $privacyCheckbox = $('.mygls-privacy-checkbox-wrapper');
        var $placeOrderButton = $('.mygls-section-payment #place_order');

        if ($privacyCheckbox.length && $placeOrderButton.length && $privacyCheckbox.next().attr('id') !== 'place_order') {
            $privacyCheckbox.insertBefore($placeOrderButton);
        }
    }

    function maybeSyncBillingFields() {
        if ($('#mygls_same_as_billing').is(':checked') && isShippingSectionVisible()) {
            syncShippingFieldsWithBilling();
        }
    }

    $(function() {
        highlightSelectedShippingMethod();
        setSectionVisibility();
        movePrivacyCheckboxBeforeOrderButton();

        // Initialize checkbox state immediately and after delay
        handleSameAsBillingCheckbox();

        // Also run after delay to ensure all fields are loaded
        setTimeout(function() {
            handleSameAsBillingCheckbox();
        }, 300);

        $(document.body).on('change', 'input[name^="shipping_method"]', function() {
            highlightSelectedShippingMethod();
            setSectionVisibility();
            handleSameAsBillingCheckbox();
            cancelScheduledCheckoutRefresh();
            requestCheckoutRefresh();
        });

        $(document.body).on('updated_checkout', function() {
            highlightSelectedShippingMethod();
            setSectionVisibility();
            movePrivacyCheckboxBeforeOrderButton();
            handleSameAsBillingCheckbox();
        });

        $(document).on('change', '#mygls_same_as_billing', handleSameAsBillingCheckbox);

        $(document).on(
            'input change',
            '#billing_first_name, #billing_last_name, #billing_company, #billing_address_1, #billing_address_2, #billing_city, #billing_postcode, #billing_country, #billing_state, #billing_phone, #billing_email',
            maybeSyncBillingFields
        );

        $(window).on('resize', movePrivacyCheckboxBeforeOrderButton);
    });
})(jQuery);
