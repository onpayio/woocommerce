let element = window.wp.element;
let register = window.wc.wcBlocksRegistry;

for (let key in wc_onpay_methods) {
    // Set method
    let method = wc_onpay_methods[key];

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
        ariaLabel: method.id
    };
    
    // Register method
    register.registerPaymentMethod(payMethod);
}
