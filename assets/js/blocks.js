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

