<?php
/**
 * MIT License
 *
 * Copyright (c) 2019 OnPay.io
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
    private $apiAuthorized;
    
    public function admin_options() {
        // Redirect to general plugin settings page
        wp_redirect(wc_onpay_query_helper::generate_url(['page' => 'wc-settings','tab' => WC_OnPay::WC_ONPAY_ID]));
        exit;
    }

    public function __construct() {
        add_action('woocommerce_receipt_' . $this->id, [$this, 'checkout']);
    }

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

    public function process_payment($order_id) {
        $order = new WC_Order($order_id);
        $redirectUrl = $order->get_checkout_payment_url(true);
        if (class_exists('WC_Subscriptions_Change_Payment_Gateway') && WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment) {
            $redirectUrl = add_query_arg('update_method', true, $redirectUrl);
        }
        return [
            'result'   => 'success',
            'redirect' => $redirectUrl
        ];
    }

    /**
     * Gets payment link and redirects browser to newly created payment
     */
    public function checkout($order_id) {
        $order = new WC_Order($order_id);
        $updateMethod = wc_onpay_query_helper::get_query_value('update_method') !== null ? true : false;
        try {
            $paymentWindow = self::get_payment_window($order, $updateMethod);
            wp_redirect(self::getPaymentLink($paymentWindow));
            exit;
        } catch (InvalidArgumentException $e) {
            echo 'Invalid data provided(' . $e->getMessage() . '). Unable to create OnPay payment.';
        } catch (WoocommerceOnpay\OnPay\API\Exception\TokenException $e) {
            echo 'Authorized connection to OnPay failed.';
        }
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
        $orderData = $order->get_data();

        // We'll need to find out details about the currency, and format the order total amount accordingly
        $CurrencyHelper = new wc_onpay_currency_helper();
        $isoCurrency = $CurrencyHelper->fromAlpha3($orderData['currency']);
        $paymentWindow = new \OnPay\API\PaymentWindow();

        // Check if we're dealing with a subscription order
        if ((function_exists('wcs_is_subscription') && wcs_is_subscription($orderData['id'])) || (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($orderData['id']))) {
            $orderTotal = 0; // Set order total to zero when we have a subscription.
            $paymentWindow->setType("subscription");
        } else {
            $orderTotal = number_format($this->get_order_total(), $isoCurrency->exp, '', '');
            $paymentWindow->setType("transaction");
        }

        $paymentWindow->setAmount($orderTotal);

        // Sanitize reference field
        $reference = $order->get_order_number();
        if (!preg_match("/^[a-zA-Z0-9\-\.]{1,36}$/", $reference)) {
            throw new InvalidArgumentException('reference/order number');
        }
        $reference = $this->sanitizeFieldValue($reference);
    
        // Generate decline URL
        $declineUrl = get_permalink(wc_get_page_id('checkout'));
        $declineUrl = add_query_arg('declined_from_onpay', '1', $declineUrl);

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
        } else if($order->get_payment_method() === 'onpay_viabill') {
            $paymentWindow->setMethod($paymentWindow::METHOD_VIABILL);
        } else if($order->get_payment_method() === 'onpay_anyday') {
            $paymentWindow->setMethod($paymentWindow::METHOD_ANYDAY);
        } else if($order->get_payment_method() === 'onpay_vipps') {
            $paymentWindow->setMethod($paymentWindow::METHOD_VIPPS);
        } else if($order->get_payment_method() === 'onpay_swish') {
            $paymentWindow->setMethod($paymentWindow::METHOD_SWISH);
        }

        if($this->get_option(WC_OnPay::SETTING_ONPAY_PAYMENTWINDOW_DESIGN) && $this->get_option(WC_OnPay::SETTING_ONPAY_PAYMENTWINDOW_DESIGN) !== 'ONPAY_DEFAULT_WINDOW') {
            $paymentWindow->setDesign($this->get_option(WC_OnPay::SETTING_ONPAY_PAYMENTWINDOW_DESIGN));
        }
        if($this->get_option(WC_OnPay::SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE)) {
            $paymentWindow->setLanguage($this->get_option(WC_OnPay::SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE));
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
        $this->setPaymentInfoParameter($paymentInfo, 'BillingAddressCountry', $this->sanitizeFieldValue($customer->get_billing_country()));
        $this->setPaymentInfoParameter($paymentInfo, 'BillingAddressLine1', $this->sanitizeFieldValue($customer->get_billing_address_1()));
        $this->setPaymentInfoParameter($paymentInfo, 'BillingAddressLine2', $this->sanitizeFieldValue($customer->get_billing_address_2()));
        $this->setPaymentInfoParameter($paymentInfo, 'BillingAddressPostalCode', $this->sanitizeFieldValue($customer->get_billing_postcode()));
        $this->setPaymentInfoParameter($paymentInfo, 'BillingAddressState', $this->sanitizeFieldValue($customer->get_billing_state()));

        $this->setPaymentInfoParameter($paymentInfo, 'ShippingAddressCity', $this->sanitizeFieldValue($customer->get_shipping_city()));
        $this->setPaymentInfoParameter($paymentInfo, 'ShippingAddressCountry', $this->sanitizeFieldValue($customer->get_shipping_country()));
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

    // WooCommerce function indicating available state of method.
    public function is_available() {
        if ($this->enabled === 'yes' && $this->isApiAuthorized()) {
            return true;
        }
        return false;
    }

    // Function that checks if connection to OnPay APi is authorized. Caches result by default.
    private function isApiAuthorized($refresh = false) {
        if(isset($this->isApiAuthorized) && !$refresh) {
            return $this->isApiAuthorized;
        }
        $onpayApi = $this->getOnPayClient();
        try {
            $this->isApiAuthorized = $onpayApi->isAuthorized();
        } catch (OnPay\API\Exception\ConnectionException $e) {
            $this->isApiAuthorized = false;
        } catch (OnPay\API\Exception\TokenException $e) {
            $this->isApiAuthorized = false;
        }
        return $this->isApiAuthorized;
    }

    private function sanitizeFieldValue($value) {
        $value = strval($value);
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
        $value = htmlentities($value);
        return $value;
    }
}