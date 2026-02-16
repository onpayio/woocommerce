// OnPay declined payment in blocks checkout:
(function() {
    'use strict';

    const NOTICE_ID = 'onpay-declined-error';
    const NOTICE_CONTEXT = 'wc/checkout';
    const INIT_DELAY = 1000;

    function init() {
        // Only run on blocks checkout
        if (!document.querySelector('.wc-block-checkout')) {
            return;
        }

        // Wait for WordPress data stores
        if (typeof window.wp === 'undefined' || typeof window.wp.data === 'undefined') {
            setTimeout(init, 100);
            return;
        }

        const dispatch = window.wp.data.dispatch;
        const select = window.wp.data.select;

        try {
            const noticesStore = select('core/notices');
            if (!noticesStore || typeof noticesStore.getNotices !== 'function') {
                setTimeout(init, 200);
                return;
            }

            const notices = noticesStore.getNotices(NOTICE_CONTEXT) || [];
            const existingNotice = notices.find(n => n.id === NOTICE_ID);
            const noticesDispatch = dispatch && dispatch('core/notices');

            // Check if we should show the notice
            const shouldShowNotice = typeof wc_onpay_ajax !== 'undefined' &&
                wc_onpay_ajax.hasDeclined &&
                wc_onpay_ajax.errorMessage;

            if (shouldShowNotice && !existingNotice && noticesDispatch) {
                // Use createNotice with 'warning' type - visible but non-blocking
                if (typeof noticesDispatch.createNotice === 'function') {
                    noticesDispatch.createNotice('warning', wc_onpay_ajax.errorMessage, {
                        id: NOTICE_ID,
                        isDismissible: true,
                        context: NOTICE_CONTEXT
                    });
                } else if (typeof noticesDispatch.createWarningNotice === 'function') {
                    noticesDispatch.createWarningNotice(wc_onpay_ajax.errorMessage, {
                        id: NOTICE_ID,
                        isDismissible: true,
                        context: NOTICE_CONTEXT
                    });
                } else if (typeof noticesDispatch.createErrorNotice === 'function') {
                    noticesDispatch.createErrorNotice(wc_onpay_ajax.errorMessage, {
                        id: NOTICE_ID,
                        isDismissible: true,
                        context: NOTICE_CONTEXT
                    });
                }
            }

            setupDismissalHandler(select);
            setupErrorSuppression(select, dispatch);
            setupPlaceOrderHandler();
        } catch (e) {
            setTimeout(init, 500);
        }
    }

    function setupPlaceOrderHandler() {
        if (window.onpayPlaceOrderHandlerSetup) {
            return;
        }
        window.onpayPlaceOrderHandlerSetup = true;

        const clearFlagOnPlaceOrder = (e) => {
            const target = e.target;
            let button = target.closest?.('.wc-block-components-checkout-place-order-button');
            if (!button && target.classList?.contains('wc-block-components-checkout-place-order-button')) {
                button = target;
            }

            if (button && typeof wc_onpay_ajax !== 'undefined' && wc_onpay_ajax.hasDeclined) {
                // Clear flag synchronously via XHR before Blocks processes the payment
                try {
                    const xhr = new XMLHttpRequest();
                    const formData = new FormData();
                    formData.append('action', 'onpay_clear_declined_flag');
                    formData.append('nonce', wc_onpay_ajax.nonce);

                    xhr.open('POST', wc_onpay_ajax.ajax_url, false);
                    xhr.send(formData);

                    if (xhr.status === 200 && wc_onpay_ajax) {
                        wc_onpay_ajax.hasDeclined = false;
                    }
                } catch (e) {
                    // Ignore errors
                }
            }
        };

        document.addEventListener('click', clearFlagOnPlaceOrder, true);
    }

    function setupErrorSuppression(select, dispatch) {
        if (window.onpayErrorSuppressionSetup) {
            return;
        }
        window.onpayErrorSuppressionSetup = true;

        const removeRedErrors = () => {
            try {
                if (typeof wc_onpay_ajax === 'undefined' || !wc_onpay_ajax.hasDeclined) {
                    return;
                }

                const noticesStore = select('core/notices');
                const noticesDispatch = dispatch?.('core/notices');
                if (noticesStore?.getNotices && noticesDispatch?.removeNotice) {
                    const notices = noticesStore.getNotices(NOTICE_CONTEXT) || [];
                    notices.forEach(notice => {
                        if (notice.type === 'error' && notice.id !== NOTICE_ID) {
                            noticesDispatch.removeNotice(notice.id, NOTICE_CONTEXT);
                        }
                    });
                }
            } catch (e) {
                // Ignore errors
            }
        };

        if (typeof window.wp.data.subscribe === 'function') {
            window.wp.data.subscribe(removeRedErrors);
        }
        setInterval(removeRedErrors, 50);
    }

    function setupDismissalHandler(select) {
        if (window.onpayDismissalHandlerSetup) {
            return;
        }
        window.onpayDismissalHandlerSetup = true;

        if (typeof window.wp.data.subscribe === 'function') {
            const unsubscribe = window.wp.data.subscribe(() => {
                try {
                    const noticesStore = select('core/notices');
                    if (noticesStore?.getNotices) {
                        const notices = noticesStore.getNotices(NOTICE_CONTEXT) || [];
                        const onpayNotice = notices.find(n => n.id === NOTICE_ID);

                        if (!onpayNotice && typeof wc_onpay_ajax !== 'undefined' && wc_onpay_ajax.hasDeclined) {
                            clearSessionFlag();
                            if (typeof unsubscribe === 'function') {
                                unsubscribe();
                            }
                        }
                    }
                } catch (e) {
                    // Ignore
                }
            });
        }
    }

    async function clearSessionFlag() {
        if (typeof wc_onpay_ajax === 'undefined') {
            return;
        }

        if (window.onpayFlagCleared) {
            return;
        }
        window.onpayFlagCleared = true;

        try {
            const formData = new FormData();
            formData.append('action', 'onpay_clear_declined_flag');
            formData.append('nonce', wc_onpay_ajax.nonce);

            const response = await fetch(wc_onpay_ajax.ajax_url, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();
            if (data.success && wc_onpay_ajax) {
                wc_onpay_ajax.hasDeclined = false;
            } else {
                window.onpayFlagCleared = false;
            }
        } catch (error) {
            window.onpayFlagCleared = false;
        }
    }

    // Wait for DOM and WordPress to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            setTimeout(init, INIT_DELAY);
        });
    } else {
        setTimeout(init, INIT_DELAY);
    }
})();
