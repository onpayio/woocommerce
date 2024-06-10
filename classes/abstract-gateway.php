<?php
/**
 * MIT License
 *
 * Copyright (c) 2023 OnPay.io
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

abstract class wc_onpay_gateway_abstract extends WC_Payment_Gateway {
    public function admin_options() {
        // Redirect to general plugin settings page
        wp_redirect(wc_onpay_query_helper::generate_url(['page' => 'wc-settings','tab' => WC_OnPay::WC_ONPAY_ID, 'section' => 'methods']));
        exit;
    }

    public function __construct() {}

    public function getMethodTitle() {
        if (is_admin()) {
            if (function_exists('get_current_screen')) {
                $currentScreen = get_current_screen();
                if (null !== $currentScreen && $currentScreen->base === 'woocommerce_page_wc-settings') {
                    return __('OnPay.io', 'wc-onpay');
                }
            }
            return $this->method_title . ' - ' . __('OnPay.io', 'wc-onpay');
        }
        return $this->method_title;
    }

    /**
     * Gets payment link and redirects browser to newly created payment
     */
    public function process_payment($order_id) {
        $order = new WC_Order($order_id);
        $updateMethod = class_exists('WC_Subscriptions_Change_Payment_Gateway') && WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
        $error = '';

        try {
            $paymentWindow = self::get_payment_window($order, $updateMethod);

            $redirect = self::getPaymentLink($paymentWindow);

            if ($updateMethod) {
                // If we're doing an update of method, do a manual redirect.
                wp_redirect($redirect);
                exit;
            }

            return [
                'result' => 'success',
                'redirect' => $redirect
            ];
        } catch (InvalidArgumentException $e) {
            $error = __('Invalid data provided. Unable to create OnPay payment', 'wc-onpay') . ' (' . $e->getMessage() . ')';
        } catch (WoocommerceOnpay\OnPay\API\Exception\TokenException $e) {
            $error = __('Authorized connection to OnPay failed', 'wc-onpay');
        }

        if ($updateMethod) {
            // If we're doing an update of method, manually echo error.
            echo $error;
            exit;
        }
    
        wc_add_notice($error, 'error');
        return [];
    }

    /**
     * Returns a payment link provided by the OnPay API
     */
    protected function getPaymentLink($paymentWindow) {
        $onpayApi = $this->getOnPayClient();
        $payment = $onpayApi->payment()->createNewPayment($paymentWindow);
        return $payment->getPaymentWindowLink();
    }

    /**
     * Returns an instantiated OnPay API client
     *
     * @return \OnPay\OnPayAPI
     */
    private function getOnPayClient() {
        $tokenStorage = new wc_onpay_token_storage();
        $url = wc_onpay_query_helper::generate_url([]);
        $onPayAPI = new \OnPay\OnPayAPI($tokenStorage, [
            'client_id' => 'Onpay WooCommerce',
            'redirect_uri' => $url,
            'platform' => WC_OnPay::WC_ONPAY_PLATFORM_STRING,
        ]);
        return $onPayAPI;
    }

    /**
     * Returns instance of PaymentWindow based on WC_Order
     */
    protected function get_payment_window($order, $updateMethod = false) {
        if (!$order instanceof WC_Order) {
            throw new InvalidArgumentException('WC_Order');
        }
        $orderHelper = new wc_onpay_order_helper();
        $currencyHelper = new wc_onpay_currency_helper();
        $countryHelper = new wc_onpay_country_helper();
        $orderData = $order->get_data();

        // Check for subscription presence
        $isSubscription = false;
        if ($this->supports('subscriptions')) { // No need to perform subscription related checks, if methods does not support it.
            $isSubscription = $orderHelper->isOrderSubscription($order);
            // Enforce method update if order is subscription renewal
            if (!$updateMethod) { // If not already instructed to update method, find out if we need to
                $updateMethod = $orderHelper->isOrderSubscriptionEarlyRenewal($order) || $orderHelper->isOrderSubscriptionRenewal($order);
            }
        }

        // We'll need to find out details about the currency, and format the order total amount accordingly
        
        $isoCurrency = $currencyHelper->fromAlpha3($orderData['currency']);
        $paymentWindow = new \OnPay\API\PaymentWindow();

        // Check if we're dealing with a subscription order
        if ($isSubscription) {
            $paymentWindow->setType("subscription");
            // If we're not updating method
            if (!$updateMethod) {
                // Instruct to create initial transaction with amount supplied.
                // The Callback from OnPay will contain information about created transaction.
                $paymentWindow->setSubscriptionWithTransaction(true);
            }
            
        } else {
            $paymentWindow->setType("transaction");
        }

        // Calculate amounts
        $orderTotal = number_format($this->get_order_total(), $isoCurrency->exp, '', '');
        $paymentWindow->setAmount($orderTotal);
        $shippingTax = $order->get_shipping_tax();
        $shippingTotal = $shippingTax + $order->get_shipping_total();
        $discountTotal = $order->get_discount_total() + $order->get_discount_tax();

        // Construct cart object that we're going to send to OnPay
        $cart = new \OnPay\API\PaymentWindow\Cart();
        $cart->setShipping(
            intval(number_format($shippingTotal, $isoCurrency->exp, '', '')),
            intval(number_format($shippingTax, $isoCurrency->exp, '', ''))
        );
        $cart->setDiscount(intval(number_format($discountTotal, $isoCurrency->exp, '', '')));
        // Loop through cart items, adding them to the cart object.
        foreach($order->get_items() as $item) {
            $itemTax = $item->get_total_tax() / $item->get_quantity(); // Tax is taxTotal divided by quantity
            $itemTotal = $itemTax + ($item->get_total() / $item->get_quantity()); // Total is total divided by quantity plus tax total from above
            $cartItem = new \OnPay\API\PaymentWindow\CartItem(
                $item->get_name(),
                intval(number_format($itemTotal, $isoCurrency->exp, '', '')),
                $item->get_quantity(),
                intval(number_format($itemTax, $isoCurrency->exp, '', ''))
            );
            $cart->addItem($cartItem);
        }

        // Try to add the cart object to payment window
        try {
            $cart->throwOnInvalid($orderTotal); // First we check if the cart calculation will fail, if it does, an InvalidCartException will be thrown.
            $paymentWindow->setCart($cart);
        } catch (\OnPay\API\Exception\InvalidCartException $e) {
            // The cart object failed calculation. In this case we will simply not add the cart object to the paymentWindow then.
        }

        // Get reference to be used with OnPay
        $reference = $orderHelper->getOrderReference($order);

        // Sanitize reference field
        if (!preg_match("/^[a-zA-Z0-9\-\.]{1,36}$/", $reference)) {
            throw new InvalidArgumentException('reference/order number');
        }
        $reference = $this->sanitizeFieldValue($reference);
    
        // Generate decline URL
        $declineUrl = get_permalink(wc_get_page_id('checkout'));
        $declineUrl = add_query_arg('declined_from_onpay', '1', $declineUrl);
        $declineUrl = add_query_arg('order_key', $order->get_order_key(), $declineUrl);

        // Add parameters to callback URL
        $callbackUrl = WC()->api_request_url('wc_onpay' . '_callback');
        $callbackUrl = add_query_arg('order_key', $order->get_order_key(), $callbackUrl);
        if ($updateMethod) {
            $callbackUrl = add_query_arg('update_method', true, $callbackUrl);
        }

        $paymentWindow->setGatewayId($this->get_option(WC_OnPay::SETTING_ONPAY_GATEWAY_ID));
        $paymentWindow->setSecret($this->get_option(WC_OnPay::SETTING_ONPAY_SECRET));
        $paymentWindow->setCurrency($isoCurrency->alpha3);
        $paymentWindow->setReference($reference);
        $paymentWindow->setAcceptUrl($order->get_checkout_order_received_url());
        $paymentWindow->setDeclineUrl($declineUrl);
        $paymentWindow->setCallbackUrl($callbackUrl);
        $paymentWindow->setWebsite(get_site_url());
        $paymentWindow->setPlatform('woocommerce', WC_OnPay::PLUGIN_VERSION, WC_VERSION);

        if($order->get_payment_method() === 'onpay_card') {
            $paymentWindow->setMethod($paymentWindow::METHOD_CARD);
        } else if($order->get_payment_method() === 'onpay_mobilepay') {
            $paymentWindow->setMethod($paymentWindow::METHOD_MOBILEPAY);
        } else if($order->get_payment_method() === 'onpay_applepay') {
            $paymentWindow->setMethod($paymentWindow::METHOD_APPLEPAY);
        } else if($order->get_payment_method() === 'onpay_googlepay') {
            $paymentWindow->setMethod($paymentWindow::METHOD_GOOGLEPAY);
        } else if($order->get_payment_method() === 'onpay_viabill') {
            $paymentWindow->setMethod($paymentWindow::METHOD_VIABILL);
        } else if($order->get_payment_method() === 'onpay_anyday') {
            $paymentWindow->setMethod($paymentWindow::METHOD_ANYDAY);
        } else if($order->get_payment_method() === 'onpay_vipps') {
            $paymentWindow->setMethod($paymentWindow::METHOD_VIPPS);
        } else if($order->get_payment_method() === 'onpay_swish') {
            $paymentWindow->setMethod($paymentWindow::METHOD_SWISH);
        } else if($order->get_payment_method() === 'onpay_paypal') {
            $paymentWindow->setMethod($paymentWindow::METHOD_PAYPAL);
        } else if($order->get_payment_method() === 'onpay_klarna') {
            $paymentWindow->setMethod($paymentWindow::METHOD_KLARNA);
        }

        if($this->get_option(WC_OnPay::SETTING_ONPAY_PAYMENTWINDOW_DESIGN) && $this->get_option(WC_OnPay::SETTING_ONPAY_PAYMENTWINDOW_DESIGN) !== 'ONPAY_DEFAULT_WINDOW') {
            $paymentWindow->setDesign($this->get_option(WC_OnPay::SETTING_ONPAY_PAYMENTWINDOW_DESIGN));
        }

        $language = $this->getLanguage();
        if(null !== $language) {
            $paymentWindow->setLanguage($language);
        }

        $customer = new WC_Customer($orderData['customer_id']);

        // Adding available info fields
        $paymentInfo = new \OnPay\API\PaymentWindow\PaymentInfo();

        $this->setPaymentInfoParameter($paymentInfo, 'AccountId', $this->sanitizeFieldValue($customer->get_id()));
        $this->setPaymentInfoParameter($paymentInfo, 'AccountDateCreated',  wc_format_datetime($customer->get_date_created(), 'Y-m-d'));
        $this->setPaymentInfoParameter($paymentInfo, 'AccountDateChange', wc_format_datetime($customer->get_date_modified(), 'Y-m-d'));

        $billingName = $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name();
        $shippingName = $customer->get_shipping_first_name() . ' ' . $customer->get_shipping_last_name();

        if ($billingName === $shippingName) {
            $this->setPaymentInfoParameter($paymentInfo, 'AccountShippingIdenticalName', 'Y');
        } else {
            $this->setPaymentInfoParameter($paymentInfo, 'AccountShippingIdenticalName', 'N');
        }

        if ($this->isAddressesIdentical($customer->get_billing(), $customer->get_shipping())) {
            $this->setPaymentInfoParameter($paymentInfo, 'AddressIdenticalShipping', 'Y');
        } else {
            $this->setPaymentInfoParameter($paymentInfo, 'AddressIdenticalShipping', 'N');
        }

        $this->setPaymentInfoParameter($paymentInfo, 'BillingAddressCity', $this->sanitizeFieldValue($customer->get_billing_city()));
        $this->setPaymentInfoParameter($paymentInfo, 'BillingAddressCountry', $this->sanitizeFieldValue($countryHelper->alpha2toNumeric($customer->get_billing_country())));
        $this->setPaymentInfoParameter($paymentInfo, 'BillingAddressLine1', $this->sanitizeFieldValue($customer->get_billing_address_1()));
        $this->setPaymentInfoParameter($paymentInfo, 'BillingAddressLine2', $this->sanitizeFieldValue($customer->get_billing_address_2()));
        $this->setPaymentInfoParameter($paymentInfo, 'BillingAddressPostalCode', $this->sanitizeFieldValue($customer->get_billing_postcode()));
        $this->setPaymentInfoParameter($paymentInfo, 'BillingAddressState', $this->sanitizeFieldValue($customer->get_billing_state()));

        $this->setPaymentInfoParameter($paymentInfo, 'ShippingAddressCity', $this->sanitizeFieldValue($customer->get_shipping_city()));
        $this->setPaymentInfoParameter($paymentInfo, 'ShippingAddressCountry', $this->sanitizeFieldValue($countryHelper->alpha2toNumeric($customer->get_shipping_country())));
        $this->setPaymentInfoParameter($paymentInfo, 'ShippingAddressLine1', $this->sanitizeFieldValue($customer->get_shipping_address_1()));
        $this->setPaymentInfoParameter($paymentInfo, 'ShippingAddressLine2', $this->sanitizeFieldValue($customer->get_shipping_address_2()));
        $this->setPaymentInfoParameter($paymentInfo, 'ShippingAddressPostalCode', $this->sanitizeFieldValue($customer->get_shipping_postcode()));
        $this->setPaymentInfoParameter($paymentInfo, 'ShippingAddressState', $this->sanitizeFieldValue($customer->get_shipping_state()));

        $this->setPaymentInfoParameter($paymentInfo, 'Name', $this->sanitizeFieldValue($billingName));
        $this->setPaymentInfoParameter($paymentInfo, 'Email', $this->sanitizeFieldValue($customer->get_billing_email()));
        $this->setPaymentInfoParameter($paymentInfo, 'PhoneHome',  [null, $this->sanitizeFieldValue($customer->get_billing_phone())]);
        $this->setPaymentInfoParameter($paymentInfo, 'DeliveryEmail', $this->sanitizeFieldValue($customer->get_billing_email()));

        $paymentWindow->setInfo($paymentInfo);

        // Enable testmode
        if($this->get_option(WC_OnPay::SETTING_ONPAY_TESTMODE) === 'yes') {
            $paymentWindow->setTestMode(1);
        } else {
            $paymentWindow->setTestMode(0);
        }
        return $paymentWindow;
    }

    public function getMethodLogos() {
        return [];
    }

    /**
     * Method used for setting a payment info parameter. The value is attempted set, if this fails we'll ignore the value and do nothing.
     * $value can be a single value or an array of values passed on as arguments.
     * Validation of value happens directly in the SDK.
     *
     * @param $paymentInfo
     * @param $parameter
     * @param $value
     */
    protected function setPaymentInfoParameter($paymentInfo, $parameter, $value) {
        if ($paymentInfo instanceof \OnPay\API\PaymentWindow\PaymentInfo) {
            $method = 'set'.$parameter;
            if (method_exists($paymentInfo, $method)) {
                try {
                    if (is_array($value)) {
                        call_user_func_array([$paymentInfo, $method], $value);
                    } else {
                        call_user_func([$paymentInfo, $method], $value);
                    }
                } catch (\OnPay\API\Exception\InvalidFormatException $e) {
                    // No need to do anything. If the value fails, we'll simply ignore the value.
                }
            }
        }
    }

    /**
     * Compares fields of two woocommerce addresses, and determines whether they are the same.
     *
     * @param $address1
     * @param $address2
     * @return bool
     */
    protected function isAddressesIdentical($address1, $address2) {
        $comparisonFields = [
            'first_name',
            'last_name',
            'company',
            'address_1',
            'address_2',
            'city',
            'postcode',
            'country',
            'state'
        ];
        foreach ($comparisonFields as $field) {
            if ($address1[$field] !== $address2[$field]) {
                return false;
            }
        }
        return true;
    }

    // Get language based on configuration or frontoffice language
    protected function getLanguage() {
        if ($this->get_option(WC_OnPay::SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE_AUTO) === 'yes') {
            $languageIso = substr(get_locale(), 0, 2);
            $languageRelations = [
                'en' => 'en',
                'es' => 'es',
                'da' => 'da',
                'de' => 'de',
                'fo' => 'fo',
                'fr' => 'fr',
                'is' => 'is',
                'it' => 'it',
                'nl' => 'nl',
                'no' => 'no',
                'pl' => 'pl',
                'sv' => 'sv',
            ];
            if (array_key_exists($languageIso, $languageRelations)) {
                return $languageRelations[$languageIso];
            }
        } else if ($this->get_option(WC_OnPay::SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE)) {
            return $this->get_option(WC_OnPay::SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE);
        }
        return 'en';
    }

    // WooCommerce function indicating available state of method.
    public function is_available() {
        if ($this->enabled === 'yes') {
            return true;
        }
        return false;
    }

    private function sanitizeFieldValue($value) {
        $value = strval($value);
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
        $value = htmlentities($value);
        return $value;
    }
}