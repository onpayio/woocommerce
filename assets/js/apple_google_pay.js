jQuery(function($) {
    // Run initially
    initGAPay();

    // Run again upon updated_checkout trigger
    $('body').on('updated_checkout', function() {
        initGAPay();
    });

    function initGAPay() {
        // Run logic that disables Apple Pay and Google Pay for selection.
        disableGAPay();
        // Renable supported methods.
        renableSupportedGAPay();
        // Select the method at the top of the list.
        selectFirstMethod();
    }

    function disableGAPay() {
        // Disable Apple Pay method for usage
        let applePayMethod = $('#payment .wc_payment_methods li.payment_method_onpay_applepay');
        applePayMethod.find('input').attr('disabled', true).attr('checked', false).addClass('disabled');

        // Disable Google Pay method for usage
        let googlePayMethod = $('#payment .wc_payment_methods li.payment_method_onpay_googlepay');
        googlePayMethod.find('input').attr('disabled', true).attr('checked', false).addClass('disabled');
    }

    function selectFirstMethod() {
        // Get list of methods that are not disabled.
        let enabledMethodsList = $('#payment .wc_payment_methods li.wc_payment_method input:not(.disabled)');
        // Loop through enabled methods, selecting and showing the first in the list.
        enabledMethodsList.each(function(index) {
            if (index === 0) {
                $(this).attr('checked', true);
                $(this).parent().find('.payment_box').show();
            } else {
                $(this).parent().find('.payment_box').hide();
            }
        });
    }

    function renableSupportedGAPay() {
        if (typeof window['Promise'] === 'function') {
            // Check if Apple Pay is supported, and renable method if so.
            let applePayAvailablePromise = OnPayIO.applePay.available();
            applePayAvailablePromise.then(function(result) {
                if (result) {
                    let applePayMethod = $('#payment .wc_payment_methods li.payment_method_onpay_applepay');
                    applePayMethod.find('input').attr('disabled', false).removeClass('disabled');
                    applePayMethod.addClass('show');
                    // Run select of first method, incase Apple Pay in on top
                    selectFirstMethod();
                }
            });

            // Check if Google Pay is supported, and renable method if so.
            let googlePayAvailablePromise = OnPayIO.googlePay.available();
            googlePayAvailablePromise.then(function(result) {
                if (result) {
                    let googlePayMethod = $('#payment .wc_payment_methods li.payment_method_onpay_googlepay');
                    googlePayMethod.find('input').attr('disabled', false).removeClass('disabled');
                    googlePayMethod.addClass('show');
                    // Run select of first method, incase Google Pay in on top
                    selectFirstMethod();
                }
            });
        }
    }
});