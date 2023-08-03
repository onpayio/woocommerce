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
    }

    function disableGAPay() {
        // Disable Apple Pay method for usage
        let applePayMethod = $('#payment .wc_payment_methods li.payment_method_onpay_applepay');
        applePayMethod.find('input').attr('disabled', true).attr('checked', false).addClass('disabled');

        // Disable Google Pay method for usage
        let googlePayMethod = $('#payment .wc_payment_methods li.payment_method_onpay_googlepay');
        googlePayMethod.find('input').attr('disabled', true).attr('checked', false).addClass('disabled');
    }

    function renableSupportedGAPay() {
        if (typeof window['Promise'] === 'function') {
            // Check if Apple Pay is supported, and renable method if so.
            let applePayAvailablePromise = OnPayIO.applePay.available();
            applePayAvailablePromise.then(function(result) {
                let applePayMethod = $('#payment .wc_payment_methods li.payment_method_onpay_applepay');
                if (result) {
                    applePayMethod.find('input').attr('disabled', false).removeClass('disabled');
                    applePayMethod.addClass('show');
                } else {
                    applePayMethod.remove();
                }
            });

            // Check if Google Pay is supported, and renable method if so.
            let googlePayAvailablePromise = OnPayIO.googlePay.available();
            googlePayAvailablePromise.then(function(result) {
                let googlePayMethod = $('#payment .wc_payment_methods li.payment_method_onpay_googlepay');
                if (result) {
                    googlePayMethod.find('input').attr('disabled', false).removeClass('disabled');
                    googlePayMethod.addClass('show');
                } else {
                    googlePayMethod.remove();
                }
            });
        }
    }
});