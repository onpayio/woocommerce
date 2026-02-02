(function($) {
    'use strict';

    if (typeof $ === 'undefined' || typeof jQuery === 'undefined' || !$('body').hasClass('woocommerce-checkout')) {
        return;
    }

    const $checkoutForm = $('form.checkout');
    if (!$checkoutForm.length) {
        return;
    }

    let isProcessing = false;

    $checkoutForm.on('submit checkout_place_order', function(e) {
        const selectedMethod = $('input[name="payment_method"]:checked').val();
        if (!selectedMethod || selectedMethod.indexOf('onpay_') !== 0) {
            return true;
        }

        if (isProcessing) {
            e.preventDefault();
            return false;
        }

        e.preventDefault();
        e.stopImmediatePropagation();
        e.stopPropagation();
        isProcessing = true;

        const $submitButton = $checkoutForm.find('button[type="submit"], #place_order');
        const originalButtonText = $submitButton.text() || $submitButton.val();
        $submitButton.data('original-text', originalButtonText);
        $submitButton.prop('disabled', true).text('Processing...').val('Processing...');

        $.ajax({
            type: 'POST',
            url: wc_checkout_params.checkout_url,
            data: $checkoutForm.serialize(),
            dataType: 'json',
            success: function(responseData) {
                if (responseData && responseData.result === 'success' && responseData.payment_uuid) {
                    showPaymentWindow(responseData.payment_uuid);
                } else if (responseData && responseData.redirect) {
                    window.location = responseData.redirect;
                } else {
                    resetButton($submitButton, originalButtonText);
                }
            },
            error: function() {
                resetButton($submitButton, originalButtonText);
            }
        });

        return false;
    });

    function resetButton($button, originalText) {
        isProcessing = false;
        $button.prop('disabled', false);
        if ($button.is('button')) {
            $button.text(originalText);
        } else {
            $button.val(originalText);
        }
    }

    function showPaymentWindow(paymentUuid) {
        isProcessing = false;

        const $billingFields = $('.woocommerce-billing-fields, #customer_details .woocommerce-billing-fields__field-wrapper');
        const $shippingFields = $('.woocommerce-shipping-fields');
        const $checkoutFields = $('.woocommerce-checkout-payment');
        const $additionalFields = $('.woocommerce-additional-fields');
        const $checkoutButton = $checkoutForm.find('button[type="submit"], #place_order');

        $billingFields.hide();
        $shippingFields.hide();
        $checkoutFields.hide();
        $additionalFields.hide();
        $checkoutButton.hide();

        const $container = $('<div class="onpay-inline-payment-container"></div>');
        const $mount = $('<div id="onpay-inline-payment" style="margin-top:12px;min-height:400px;"></div>');
        const $backLink = $('<a href="#" class="onpay-back-to-billing">← Back to billing details</a>');

        let sdkInstance = null;

        $backLink.on('click', function(e) {
            e.preventDefault();
            if (sdkInstance && sdkInstance.dismount) {
                sdkInstance.dismount();
            }
            $('[data-inline-frame-type]').remove();
            $('#onpay-inline-payment').remove();
            $('.onpay-inline-payment-container').remove();
            $billingFields.show();
            $shippingFields.show();
            $checkoutFields.show();
            $additionalFields.show();

            isProcessing = false;
            sdkInstance = null;

            window.location.reload();
        });

        $container.append($mount).append($backLink);


        if ($billingFields.length) {
            $billingFields.before($container);
        } else {
            const $customerDetails = $('#customer_details');
            if ($customerDetails.length) {
                $customerDetails.after($container);
            } else {
                $checkoutForm.prepend($container);
            }
        }

        initSDK(paymentUuid, '#onpay-inline-payment', function(sdk) {
            sdkInstance = sdk;
        });
    }

    function initSDK(paymentUuid, selector, callback) {
        if (window.OnPaySDK) {
            try {
                const sdk = new window.OnPaySDK(paymentUuid, { container: selector });
                sdk.init();
                if (callback) callback(sdk);
                window.addEventListener('pagehide', function() {
                    if (sdk && sdk.dismount) {
                        sdk.dismount();
                    }
                });
            } catch (e) {}
            return;
        }
    }

})(jQuery);
