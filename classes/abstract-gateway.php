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
    public function admin_options() {
        // Redirect to general plugin settings page
        wp_redirect(wc_onpay_query_helper::generate_url(['page' => 'wc-settings','tab' => 'checkout','section' => WC_OnPay::WC_ONPAY_ID]));
        exit;
    }

    public function __construct() {
        add_action('woocommerce_receipt_' . $this->id, [$this, 'checkout']);
    }

    public function process_payment($order_id) {
        $order = new WC_Order($order_id);
        return [
            'result'   => 'success',
            'redirect' => $redirect = $order->get_checkout_payment_url(true),
        ];
    }

    /**
     * Method for injecting payment window form into receipt page, and automatically posting form to OnPay.
     */
    public function checkout($order_id) {
        $order = new WC_Order($order_id);
        $paymentWindow = self::get_payment_window($order);

        echo '<p>' . __( 'Redirecting to payment window', 'wc-onpay' ) . '</p>';
        wc_enqueue_js('document.getElementById("onpay_form").submit();');

        echo '<form action="' . $paymentWindow->getActionUrl() . '" method="post" target="_top" id="onpay_form">';
        foreach($paymentWindow->getFormFields() as $key => $formField) {
            echo '<input type="hidden" name="' . $key . '" value="' . $formField . '">';
        }
        echo '</form>';
    }

    /**
     * Returns instance of PaymentWindow based on WC_Order
     */
    protected function get_payment_window($order) {
        if (!$order instanceof WC_Order) {
            return null;
        }

        $CurrencyHelper = new wc_onpay_currency_helper();

        // We'll need to find out details about the currency, and format the order total amount accordingly
        $isoCurrency = $CurrencyHelper->fromAlpha3($order->get_data()['currency']);
        $orderTotal = number_format($this->get_order_total(), $isoCurrency->exp, '', '');
        $declineUrl = get_permalink(wc_get_page_id('checkout'));
        $declineUrl = add_query_arg('declined_from_onpay', '1', $declineUrl);

        $paymentWindow = new \OnPay\API\PaymentWindow();
        $paymentWindow->setGatewayId($this->get_option(WC_OnPay::SETTING_ONPAY_GATEWAY_ID));
        $paymentWindow->setSecret($this->get_option(WC_OnPay::SETTING_ONPAY_SECRET));
        $paymentWindow->setCurrency($isoCurrency->alpha3);
        $paymentWindow->setAmount($orderTotal);
        $paymentWindow->setReference($order->get_data()['id']);
        $paymentWindow->setType("payment");
        $paymentWindow->setAcceptUrl($order->get_checkout_order_received_url());
        $paymentWindow->setDeclineUrl($declineUrl);
        $paymentWindow->setCallbackUrl(WC()->api_request_url('wc_onpay' . '_callback'));
        $paymentWindow->setWebsite(get_bloginfo('wpurl'));
        $paymentWindow->setPlatform('woocommerce', WC_OnPay::PLUGIN_VERSION, WC_VERSION);

        if($order->get_payment_method() === 'onpay_card') {
            $paymentWindow->setMethod($paymentWindow::METHOD_CARD);
        } else if($order->get_payment_method() === 'onpay_mobilepay') {
            $paymentWindow->setMethod($paymentWindow::METHOD_MOBILEPAY);
        } else if($order->get_payment_method() === 'onpay_viabill') {
            $paymentWindow->setMethod($paymentWindow::METHOD_VIABILL);
        } else if($order->get_payment_method() === 'onpay_anyday') {
            $paymentWindow->setMethod($paymentWindow::METHOD_ANYDAY);
        }

        if($this->get_option(WC_OnPay::SETTING_ONPAY_PAYMENTWINDOW_DESIGN) && $this->get_option(WC_OnPay::SETTING_ONPAY_PAYMENTWINDOW_DESIGN) !== 'ONPAY_DEFAULT_WINDOW') {
            $paymentWindow->setDesign($this->get_option(WC_OnPay::SETTING_ONPAY_PAYMENTWINDOW_DESIGN));
        }
        if($this->get_option(WC_OnPay::SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE)) {
            $paymentWindow->setLanguage($this->get_option(WC_OnPay::SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE));
        }

        $customer = new WC_Customer($order->get_data()['customer_id']);

        // Adding available info fields
        $paymentInfo = new \OnPay\API\PaymentWindow\PaymentInfo();

        $this->setPaymentInfoParameter($paymentInfo, 'AccountId', $customer->get_id());
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

        $this->setPaymentInfoParameter($paymentInfo, 'BillingAddressCity', $customer->get_billing_city());
        $this->setPaymentInfoParameter($paymentInfo, 'BillingAddressCountry', $customer->get_billing_country());
        $this->setPaymentInfoParameter($paymentInfo, 'BillingAddressLine1', $customer->get_billing_address_1());
        $this->setPaymentInfoParameter($paymentInfo, 'BillingAddressLine2', $customer->get_billing_address_2());
        $this->setPaymentInfoParameter($paymentInfo, 'BillingAddressPostalCode', $customer->get_billing_postcode());
        $this->setPaymentInfoParameter($paymentInfo, 'BillingAddressState', $customer->get_billing_state());

        $this->setPaymentInfoParameter($paymentInfo, 'ShippingAddressCity', $customer->get_shipping_city());
        $this->setPaymentInfoParameter($paymentInfo, 'ShippingAddressCountry', $customer->get_shipping_country());
        $this->setPaymentInfoParameter($paymentInfo, 'ShippingAddressLine1', $customer->get_shipping_address_1());
        $this->setPaymentInfoParameter($paymentInfo, 'ShippingAddressLine2', $customer->get_shipping_address_2());
        $this->setPaymentInfoParameter($paymentInfo, 'ShippingAddressPostalCode', $customer->get_shipping_postcode());
        $this->setPaymentInfoParameter($paymentInfo, 'ShippingAddressState', $customer->get_shipping_state());

        $this->setPaymentInfoParameter($paymentInfo, 'Name', $billingName);
        $this->setPaymentInfoParameter($paymentInfo, 'Email', $customer->get_billing_email());
        $this->setPaymentInfoParameter($paymentInfo, 'PhoneHome',  [null, $customer->get_billing_phone()]);
        $this->setPaymentInfoParameter($paymentInfo, 'DeliveryEmail', $customer->get_billing_email());

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
}