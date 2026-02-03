let element = window.wp.element;
let register = window.wc.wcBlocksRegistry;

for (let key in wc_onpay_methods) {
    // Set method
    let method = wc_onpay_methods[key];

    if ('onpay_googlepay' === method.id && typeof window['Promise'] === 'function') {
        // Check if Google Pay is supported, and renable method if so.
        let googlePayAvailablePromise = OnPayIO.googlePay.available();
        googlePayAvailablePromise.then(function(result) {
            if (result) {
                registerMethod(method);
            }
        });
    } else if ('onpay_applepay' === method.id && typeof window['Promise'] === 'function') {
        // Check if Apple Pay is supported, and renable method if so.
        let applePayAvailablePromise = OnPayIO.applePay.available();
        applePayAvailablePromise.then(function(result) {
            if (result) {
                registerMethod(method);
            }
        });
    } else {
        registerMethod(method);
    }
}

function registerMethod(method) {
    // Construct label
    let labelElements = [];
    if (undefined !== method.icon) {
        labelElements.push(element.createElement('img', {'src': method.icon, 'className': 'icon', 'key': method.id + '_icon'}));
    }
    labelElements.push(element.createElement('span', {'className': 'wc-block-components-payment-method-label', 'key': method.id + '_label'}, method.title));
    let label = () => Object(element.createElement)('span', {className: 'onpay-block-method'}, labelElements);

    // Construct content
    let logos = [];
    if (undefined !== method.logos) {
        for (let logo in method.logos) {
            logos.push(element.createElement('img', {'src': method.logos[logo], 'key': logo}));
        }
    }
    let content = () => Object(element.createElement)('div', {'className': 'payment_method_' + method.id, 'billing': null}, method.description, element.createElement('div', {'className': 'onpay_card_logos'}, logos));

    // Construct method
    let payMethod = {
        name: method.id,
        label: Object(element.createElement)(label, null),
        content: Object(element.createElement)(content, null),
        edit: element.createElement('div', {}, method.description),
        canMakePayment: () => true,
        ariaLabel: method.id,
        supports: {
            features: method.supports,
        }
    };
    
    // Register method
    register.registerPaymentMethod(payMethod);
}

(function() {
    'use strict';

    if (!window.wc_onpay_inline_settings) {
        return;
    }

    if (!window.wc_onpay_inline_settings.inlineEnabled) {
        return;
    }

    let sdkInstance = null;
    let checkoutFormElement = null;

    function getCheckoutForm() {
        return document.querySelector('.wc-block-checkout__form') ||
            document.querySelector('.wc-block-checkout') ||
            document.querySelector('form.wc-block-components-checkout-form') ||
            document.querySelector('.wp-block-woocommerce-checkout');
    }

    function showPaymentWindowDirect(paymentUuid) {
        if (document.querySelector('.onpay-inline-payment-container')) return;

        const checkoutForm = getCheckoutForm();
        if (!checkoutForm) return;

        checkoutFormElement = checkoutForm;
        checkoutForm.style.display = 'none';

        const container = document.createElement('div');
        container.className = 'onpay-inline-payment-container';
        const mount = document.createElement('div');
        mount.id = 'onpay-inline-payment';
        mount.style.cssText = 'margin-top:12px;min-height:400px;';

        const backLink = document.createElement('a');
        backLink.href = '#';
        backLink.className = 'onpay-back-to-billing';
        backLink.textContent = '← Back to billing details';
        backLink.onclick = function(e) {
            e.preventDefault();
            if (sdkInstance && sdkInstance.dismount) {
                sdkInstance.dismount();
            }
            document.querySelectorAll('[data-inline-frame-type]').forEach(function(el) {
                el.remove();
            });
            document.querySelectorAll('#onpay-inline-payment, .onpay-inline-payment-container').forEach(function(el) {
                el.remove();
            });

            sdkInstance = null;
            checkoutFormElement = null;
            isIntercepting = false;

            // Reset checkout page
            window.location.reload();
        };

        container.appendChild(mount);
        container.appendChild(backLink);

        if (checkoutForm.parentNode) {
            checkoutForm.parentNode.insertBefore(container, checkoutForm);
        } else {
            checkoutForm.insertBefore(container, checkoutForm.firstChild);
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
        }
    }

    let originalFetch = window.fetch;
    let isIntercepting = false;

    window.fetch = function() {
        const args = Array.prototype.slice.call(arguments);
        const url = args[0];

        if (typeof url === 'string' && (url.indexOf('/wc/store/v1/checkout') !== -1 || url.indexOf('/wp-json/wc/store/v1/checkout') !== -1)) {

            // Don't intercept if already intercepting
            if (isIntercepting) {
                return originalFetch.apply(this, arguments);
            }

            return originalFetch.apply(this, arguments).then(function(response) {
                if (!response.ok) {
                    return response;
                }

                return response.clone().json().then(function(data) {
                    let paymentUuid = null;

                    if (data && data.payment_result && data.payment_result.payment_details && Array.isArray(data.payment_result.payment_details)) {
                        const uuidEntry = data.payment_result.payment_details.find(function(item) {
                            return item && item.key === 'payment_uuid';
                        });
                        if (uuidEntry && uuidEntry.value) {
                            paymentUuid = uuidEntry.value;
                        }
                    }

                    if (paymentUuid) {
                        isIntercepting = true;
                        setTimeout(function() {
                            showPaymentWindowDirect(paymentUuid);
                        }, 100);
                        const modifiedData = Object.assign({}, data);
                        modifiedData.redirect = false;
                        if (modifiedData.payment_result) {
                            modifiedData.payment_result.redirect = false;
                        }
                        return new Response(JSON.stringify(modifiedData), {
                            status: response.status,
                            statusText: response.statusText,
                            headers: {
                                'Content-Type': 'application/json'
                            }
                        });
                    }

                    return new Response(JSON.stringify(data), {
                        status: response.status,
                        statusText: response.statusText,
                        headers: {
                            'Content-Type': 'application/json'
                        }
                    });
                }).catch(function(err) {
                    console.error('Error parsing response:', err);
                    return response;
                });
            }).catch(function(err) {
                console.error('Error in fetch interception:', err);
                return originalFetch.apply(this, args);
            });
        }

        return originalFetch.apply(this, arguments);
    };
})();
