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

/**
* Plugin Name: WooCommerce OnPay.io
* Plugin URI: https://onpay.io/
* Description: WooCommerce payment plugin for OnPay.io.
* Author: OnPay.io
* Author URI: https://onpay.io/
* Text Domain: wc-onpay
* Version: 1.0
**/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/classes/CurrencyHelper.php';
require_once __DIR__ . '/classes/TokenStorage.php';

add_action('plugins_loaded', 'init_onpay', 0);

function init_onpay() {

    // Make sure that Woocommerce is enabled and that Payment gateway class is available
    if (!defined('WC_VERSION') || !class_exists('WC_Payment_Gateway')) {
		return;
    }
    
    class WC_OnPay extends WC_Payment_Gateway {
        const SETTING_ONPAY_GATEWAY_ID = 'gateway_id';
        const SETTING_ONPAY_SECRET = 'secret';
        const SETTING_ONPAY_EXTRA_PAYMENTS_MOBILEPAY = 'extra_payments_mobilepay';
        const SETTING_ONPAY_EXTRA_PAYMENTS_VIABILL = 'extra_payments_viabill';
        const SETTING_ONPAY_EXTRA_PAYMENTS_CARD = 'extra_payments_card';
        const SETTING_ONPAY_PAYMENTWINDOW_DESIGN = 'paymentwindow_design';
        const SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE = 'paymentwindow_language';
        const SETTING_ONPAY_TESTMODE = 'testmode_enabled';

        /**
         * @var WC_OnPay
         */
        private static $_instance;

		/**
         * @access public
         * @static
         * @return WC_OnPay
         */
		public static function get_instance() {
			if ( ! isset( self::$_instance ) ) {
				self::$_instance = new self();
			}
			return self::$_instance;
        }
        
        public function __construct() {
            $this->id           = 'onpay';
            $this->method_title = 'OnPay';
            $this->has_fields   = false;
            $this->method_description = __('Receive payments with cards and more through OnPay.io', 'wc-onpay');

            $this->init_form_fields();
            $this->init_settings();

            if (is_admin()) {
                $this->title = $this->method_title;
            } else {
                $this->title        = $this->getActiveMethodsString('title');
                $this->description  = $this->getActiveMethodsString('description');
            }
        }

        public function is_available() {
            $onpayApi = $this->getOnpayClient();
            if (!$onpayApi->isAuthorized()) {
                return false;
            }

            if ($this->get_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_CARD) !== 'yes' &&
                $this->get_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_MOBILEPAY) !== 'yes' &&
                $this->get_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_VIABILL) !== 'yes'
                ) {
                return false;
            }

            return true;
        }

        public function needs_setup() {
            $onpayApi = $this->getOnpayClient();
            if (!$onpayApi->isAuthorized()) {
                return true;
            }
            return false;
        }

        public function init_hooks() {
            if (is_admin()) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options'] );
            }
            add_action('woocommerce_receipt_' . $this->id, [$this, 'checkout']);
            add_action('woocommerce_api_'. $this->id . '_callback', [$this, 'callback']);
            add_action('post_updated', [$this, 'order_metabox_post']);
            add_action('add_meta_boxes', [$this, 'meta_boxes']);
        }

        public function process_payment( $order_id ) {
            $order = new WC_Order($order_id);
            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

        public function checkout($order_id) {
            $order = new WC_Order($order_id);
            $paymentWindow = $this->getPaymentWindow($order);
            $formFields = $paymentWindow->getFormFields();

            echo '<p>' . __( 'Redirecting to payment window', 'wc-onpay' ) . '</p>';
            wc_enqueue_js('document.getElementById("onpay_form").submit();');
        
            echo '<form action="' . $paymentWindow->getActionUrl() . '" method="post" target="_top" id="onpay_form">';
            foreach($paymentWindow->getFormFields() as $key => $formField) {
                echo '<input type="hidden" name="' . $key . '" value="' . $formField . '">';
            }
            echo '</form>';
        }

        public function callback() {
            $paymentWindow = new \OnPay\API\PaymentWindow();
            $paymentWindow->setSecret($this->get_option(self::SETTING_ONPAY_SECRET));
            if (!$paymentWindow->validatePayment($_GET)) {
                $this->jsonResponse('Invalid values', true, 400);
            }
            $order = new WC_Order($this->getQueryValue('onpay_reference'));
            if ($order->has_status('pending')) {
                $order->payment_complete($this->getQueryValue('onpay_number'));
                $order->add_order_note( __( 'Transaction authorized in OnPay. Remember to capture amount.', 'wc-onpay' ));
            }
            $this->jsonResponse('Order validated');
        }

        public function init_form_fields() {
            $this->form_fields = [
                self::SETTING_ONPAY_EXTRA_PAYMENTS_CARD => [
                    'title' => __('Card', 'wc-onpay'),
                    'label' => __('Enable card as payment method', 'wc-onpay'),
                    'type' => 'checkbox',
                    'default' => 'yes',
                ],
                self::SETTING_ONPAY_EXTRA_PAYMENTS_MOBILEPAY => [
                    'title' => __('MobilePay Online', 'wc-onpay'),
                    'label' => __('Enable MobilePay Online as payment method', 'wc-onpay'),
                    'type' => 'checkbox',
                    'default' => 'no',
                ],
                self::SETTING_ONPAY_EXTRA_PAYMENTS_VIABILL => [
                    'title' => __('ViaBill', 'wc-onpay'),
                    'label' => __('Enable ViaBill as payment method', 'wc-onpay'),
                    'type' => 'checkbox',
                    'default' => 'no',
                ],
                self::SETTING_ONPAY_PAYMENTWINDOW_DESIGN => [
                    'title' => __('Payment window design', 'wc-onpay'),
                    'type' => 'select',
                    'options' => $this->getPaymentWindowDesignOptions(),
                ],
                self::SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE => [
                    'title' => __('Payment window language', 'wc-onpay'),
                    'type' => 'select',
                    'options' => $this->getPaymentWindowLanguageOptions(),
                ],
                self::SETTING_ONPAY_TESTMODE => [
                    'title' => __('Test Mode', 'wc-onpay'),
                    'label' => ' ',
                    'type' => 'checkbox',
                    'default' => 'no',
                ],
            ];
		}

        public function admin_options() {
            $onpayApi = $this->getOnpayClient(true);

            $this->handleOauthCallback();
            $this->handleDetach();
            
            $html = '';
            $html .=  '<h3>OnPay</h3>';
            $html .=  '<p>' . __('Recieve payments with cards and more through OnPay.io', 'wc-onpay') . '</p>';
            $html .= '<hr />';

            if (!$onpayApi->isAuthorized()) {
                $html .=  '<a href="' . $onpayApi->authorize() . '" class="button-primary">' . __('Log in with OnPay', 'wc-onpay') . '</a>';
                $GLOBALS['hide_save_button'] = true; // We won't be needing the global save settings button right now
            } else {
                $html .= '<table class="form-table">';
                $html .= $this->generate_settings_html([], false);
                $html .= '</table>';
                
                $html .= '<hr />';
                $html .= '<table class="form-table"><tbody>';

                $html .= '<tr valign="top">';
                $html .= '<th class="titledesc" scope="row"><label>' . __('Gateway ID', 'wc-onpay') . '</label></th>';
                $html .= '<td class="forminp"><fieldset><input type="text" readonly="true" value="' . $this->get_option(self::SETTING_ONPAY_GATEWAY_ID) . '"></fieldset></td>';
                $html .= '</tr>';

                $html .= '<tr valign="top">';
                $html .= '<th class="titledesc" scope="row"><label>' . __('Secret', 'wc-onpay') . '</label></th>';
                $html .= '<td class="forminp"><fieldset><input type="text" readonly="true" value="' . $this->get_option(self::SETTING_ONPAY_SECRET) . '"></fieldset></td>';
                $html .= '</tr>';

                $html .= '<tr valign="top">';
                $html .= '<th class="titledesc" scope="row"></th>';
                $html .= '<td><button class="button-secondary" id="button_onpay_apilogout">' . __('Log out from OnPay', 'wc-onpay') . '</button></td>';
                $html .= '</tr>';

                $html .= '</tbody></table>';
                $html .= '<hr />';

                wc_enqueue_js('$("#button_onpay_apilogout").on("click", function(event) {event.preventDefault(); if(confirm(\''. __('Are you sure you want to logout from Onpay?', 'wc-onpay') . '\')) {window.location.href = window.location.href+"&detach=1";}})');
            }
            
            echo ent2ncr($html);
        }

        /**
         * Method for setting meta boxes in admin
         */
        public function meta_boxes() {
            global $post;
            // Determine that we're on the correct controller
            if ($post->post_type === 'shop_order') {
                $order = new WC_Order($post->ID);
                if ($order->get_payment_method() === $this->id) {
                    add_meta_box('mv_other_fields', $this->method_title, [$this,'order_meta_box'], 'shop_order', 'advanced', 'high', ['order' => $order]);
                }
            }
        }

        /**
         * Method that handles postback of OnPay order meta box
         */
        public function order_metabox_post() {
            global $post;
            $postValues = $_POST;

            // Determine that we're on the correct controller
            if ($post->post_type === 'shop_order') {
                $order = new WC_Order($post->ID);
                // Determine that the required data for getting transaction is available.
                if ($order->get_payment_method() === $this->id && $order->get_transaction_id() !== '') {
                    // Get the transaction from API
                    $transaction = $this->getOnpayClient()->transaction()->getTransaction($order->get_transaction_id());
                    $currencyHelper = new CurrencyHelper();

                    if (array_key_exists('onpay_capture', $postValues) && array_key_exists('onpay_capture_amount', $postValues)) { // If transaction is requested captured.
                        $value = str_replace(',', '.', $postValues['onpay_capture_amount']);
                        $amount = $currencyHelper->majorToMinor($value, $transaction->currencyCode, '.');
                        $this->getOnpayClient()->transaction()->captureTransaction($transaction->uuid, $amount);
                        $order->add_order_note( __( 'Amount captured on transaction in OnPay.', 'wc-onpay' ));

                    } else if (array_key_exists('onpay_refund', $postValues) && array_key_exists('onpay_refund_amount', $postValues)) { // If transaction is requested refunded.
                        $value = str_replace('.', ',', $postValues['onpay_refund_amount']);
                        $amount = $currencyHelper->majorToMinor($value, $transaction->currencyCode, ',');
                        $this->getOnpayClient()->transaction()->refundTransaction($transaction->uuid, $amount);
                        $order->add_order_note( __( 'Amount refunded on transaction in OnPay.', 'wc-onpay' ));

                    } else if (array_key_exists('onpay_cancel', $postValues)) { // If transaction is requested cancelled.
                        $this->getOnpayClient()->transaction()->cancelTransaction($transaction->uuid);
                        $order->add_order_note( __( 'Transaction finished/cancelled in OnPay.', 'wc-onpay' ));

                    }
                }
            }
        }

        /**
         * Method that renders the meta box for OnPay transactions on order page.
         */
        public function order_meta_box($post, array $meta) {
            $order = $meta['args']['order'];
            $html = '';

            // If no transaction id is set on order, we can't find the transaction in OnPays API.
            if ($order->get_transaction_id() === '') { 
                if ($order->has_status('pending')) {
                    $html .= __('Pending payment', 'wc-onpay');
                } else {
                    $html .= __('No transaction found in OnPay', 'wc-onpay');
                }
            } else {
                $transaction = $this->getOnpayClient()->transaction()->getTransaction($order->get_transaction_id());
                $currencyHelper = new CurrencyHelper();
                $currency = $currencyHelper->fromNumeric($transaction->currencyCode);

                $amount = $currencyHelper->minorToMajor($transaction->amount, $currency->numeric);
                $availableAmount = $currencyHelper->minorToMajor($transaction->amount - $transaction->charged, $currency->numeric);
                $charged = $currencyHelper->minorToMajor($transaction->charged, $currency->numeric);
                $availableCharged = $currencyHelper->minorToMajor($transaction->charged - $transaction->refunded, $currency->numeric);
                $refunded = $currencyHelper->minorToMajor($transaction->refunded, $currency->numeric);

                if ($transaction->acquirer === 'test') {
                    $html .= '<div style="background: #ffe595; padding: 10px;">';
                    $html .= __('This transaction was performed in testmode.', 'wc-onpay');
                    $html .= '</div>';
                }

                $html .= '<div style="overflow-x: auto;"><table style="width: 100%;"><tr>';
                $html .= '<td style="vertical-align: top;">';
                $html .= '<p><strong>' . __('Transaction details', 'wc-onpay') . ':</strong></p>';
                $html .= '<table class="widefat striped"><tbody>';
                $html .= '<tr><td><strong>' . __('Status', 'wc-onpay') . '</strong></td><td>' . $transaction->status . '</td></tr>';
                $html .= '<tr><td><strong>' . __('Card type', 'wc-onpay') . '</strong></td><td>' . $transaction->cardType . '</td></tr>';
                $html .= '<tr><td><strong>' . __('Transaction number', 'wc-onpay') . '</strong></td><td>' . $transaction->transactionNumber . '</td></tr>';
                $html .= '<tr><td><strong>' . __('Amount', 'wc-onpay') . '</strong></td><td>' . $currency->alpha3 . ' ' . $amount . '</td></tr>';
                $html .= '<tr><td><strong>' . __('Charged', 'wc-onpay') . '</strong></td><td>' . $currency->alpha3 . ' ' . $charged . '</td></tr>';
                $html .= '<tr><td><strong>' . __('Refunded', 'wc-onpay') . '</strong></td><td>' . $currency->alpha3 . ' ' . $refunded . '</td></tr>';
                $html .= '</tbody></table>';
                $html .= '</td>';

                $html .= '<td style="vertical-align: top;">';
                $html .= '<p><strong>' . __('Transaction history', 'wc-onpay') . ':</strong></p>';
                $html .= '<table class="widefat striped"><thead>';
                $html .= '<th>' . __('Date & Time', 'wc-onpay') . '</th>';
                $html .= '<th>' . __('Action', 'wc-onpay') . '</th>';
                $html .= '<th>' . __('Amount', 'wc-onpay') . '</th>';
                $html .= '<th>' . __('User', 'wc-onpay') . '</th>';
                $html .= '<th>' . __('IP', 'wc-onpay') . '</th>';
                $html .= '</thead><tbody>';
                foreach ($transaction->history as $history) {
                   $html .= '<tr>';
                   $html .= '<td>' . $history->dateTime->format('Y-m-d H:i:s') . '</td>';
                   $html .= '<td>' . $history->action . '</td>';
                   $html .= '<td>' . $currency->alpha3 . ' ' . $currencyHelper->minorToMajor($history->amount, $currency->numeric) . '</td>';
                   $html .= '<td>' . $history->author . '</td>';
                   $html .= '<td>' . $history->ip . '</td>';
                   $html .= '</tr>';
                }
                $html .= '</tbody></table>';
                $html .= '</td>';
                $html .= '</tr></table></div>';

                $html .= '<br /><hr />';

                $html .= '<div id="onpay_action_buttons">';
                if ($transaction->charged < $transaction->amount && $transaction->status === 'active') {
                    $html .= '<button class="button-primary" id="button_onpay_capture_reveal">' . __('Capture', 'wc-onpay') . '</button>&nbsp;';
                    wc_enqueue_js('$("#button_onpay_capture_reveal").on("click", function(event) {event.preventDefault(); $("#onpay_action_capture").slideDown(); $("#onpay_action_buttons").slideUp(); })');
                }

                if (0 < $transaction->charged && $transaction->refunded < $transaction->charged) {
                    $html .= '<button class="button-secondary" id="button_onpay_refund_reveal">' . __('Refund', 'wc-onpay') . '</button>&nbsp;';
                    wc_enqueue_js('$("#button_onpay_refund_reveal").on("click", function(event) {event.preventDefault(); $("#onpay_action_refund").slideDown(); $("#onpay_action_buttons").slideUp(); })');
                }

                if ($transaction->status === 'active') {
                    $html .= '<button class="button-secondary" id="button_onpay_cancel_reveal">' . ($transaction->charged === 0 ? __('Cancel transaction', 'wc-onpay') : __('Finish transaction', 'wc-onpay')) . '</button>&nbsp;';
                    wc_enqueue_js('$("#button_onpay_cancel_reveal").on("click", function(event) {event.preventDefault(); $("#onpay_action_cancel").slideDown(); $("#onpay_action_buttons").slideUp(); })');
                }
                $html .= '</div>';

                // Capture
                $html .= '<div id="onpay_action_capture" style="display: none;">';
                $html .= '<p>' . __('Please enter amount to capture', 'wc-onpay') . '</p>';
                $html .= '<input type="text" name="onpay_capture_amount" value="' . $availableAmount . '">';
                $html .= '<hr />';
                $html .= '<input class="button-primary" type="submit" name="onpay_capture" value="' . __('Capture', 'wc-onpay') . '">&nbsp;';
                $html .= '<button class="button-secondary" id="button_onpay_capture_hide">' . __('Cancel', 'wc-onpay') . '</button>';
                $html .= '</div>';
                wc_enqueue_js('$("#button_onpay_capture_hide").on("click", function(event) {event.preventDefault(); $("#onpay_action_capture").slideUp(); $("#onpay_action_buttons").slideDown(); })');
                
                // Refund
                $html .= '<div id="onpay_action_refund" style="display: none;">';
                $html .= '<p>' . __('Please enter amount to refund', 'wc-onpay') . '</p>';
                $html .= '<input type="text" name="onpay_refund_amount" value="' . $availableCharged . '">';
                $html .= '<hr />';
                $html .= '<input class="button-primary" type="submit" name="onpay_refund" value="' . __('Refund', 'wc-onpay') . '">&nbsp;';
                $html .= '<button class="button-secondary" id="button_onpay_refund_hide">' . __('Cancel', 'wc-onpay') . '</button>';
                $html .= '</div>';
                wc_enqueue_js('$("#button_onpay_refund_hide").on("click", function(event) {event.preventDefault(); $("#onpay_action_refund").slideUp(); $("#onpay_action_buttons").slideDown(); })');

                // Cancel/finish
                $html .= '<div id="onpay_action_cancel" style="display: none;">';
                $html .= '<p>' . __('When finishing or cancelling a transaction, no further actions will be possible on transaction.', 'wc-onpay') . '</p>';
                $html .= '<hr />';
                $html .= '<input class="button-primary" type="submit" name="onpay_cancel" value="' . ($transaction->charged === 0 ? __('Cancel transaction', 'wc-onpay') : __('Finish transaction', 'wc-onpay')) . '">&nbsp;';
                $html .= '<button class="button-secondary" id="button_onpay_cancel_hide">' . __('Cancel', 'wc-onpay') . '</button>';
                $html .= '</div>';
                wc_enqueue_js('$("#button_onpay_cancel_hide").on("click", function(event) {event.preventDefault(); $("#onpay_action_cancel").slideUp(); $("#onpay_action_buttons").slideDown(); })');
            }
            echo ent2ncr($html);
        }

        /**
         * Returns formatted string based on active methods.
         */
        private function getActiveMethodsString(string $string) {
            $methods = $this->getActiveMethods();
            $methodsString = '';
            $totalMethods = count($methods);
            if ($totalMethods > 1) {
                $methodsString = implode(', ', array_slice($methods, 0, $totalMethods-1)) . __(' or ', 'wc-onpay') . end($methods);
            } else {
                $methodsString = implode(', ', $methods);
            }

            if ($string === 'title') {
                return __('OnPay - Pay with', 'wc-onpay') . ' ' . $methodsString;
            } else if ($string === 'description') {
                return __('Pay through OnPay using', 'wc-onpay') . ' ' . $methodsString;
            }
            return null;
        }

        /**
         * Returns an array of active payment methods
         */
        private function getActiveMethods() {
            $methods = [];
            if ($this->get_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_CARD) === 'yes') {
                $methods[] = 'Card';
            }
            if ($this->get_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_MOBILEPAY) === 'yes') {
                $methods[] = 'MobilePay';
            }
            if ($this->get_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_VIABILL) === 'yes') {
                $methods[] = 'ViaBill';
            }
            return $methods;
        }
        
        /**
         * Returns an instantiated OnPay API client
         *
         * @return \OnPay\OnPayAPI
         */
        private function getOnpayClient($prepareRedirectUri = false) {
            $tokenStorage = new TokenStorage();
            $params = [];
            // AdminToken cannot be generated on payment pages
            if($prepareRedirectUri) {
                $params['page'] = 'wc-settings';
                $params['tab'] = 'checkout';
                $params['section'] = 'wc_onpay';
            }
            $url = $this->generateUrl($params);
            $onPayAPI = new \OnPay\OnPayAPI($tokenStorage, [
                'client_id' => 'Onpay WooCommerce',
                'redirect_uri' => $url,
            ]);
            return $onPayAPI;
        }

        private function getPaymentWindow($order) {
            if (!$order instanceof WC_Order) {
                return null;
            }

            $CurrencyHelper = new CurrencyHelper();

            // We'll need to find out details about the currency, and format the order total amount accordingly
            $isoCurrency = $CurrencyHelper->fromAlpha3($order->get_data()['currency']);
            $orderTotal = number_format($this->get_order_total(), $isoCurrency->exp, '', '');

            $paymentWindow = new \OnPay\API\PaymentWindow();
            $paymentWindow->setGatewayId($this->get_option(self::SETTING_ONPAY_GATEWAY_ID));
            $paymentWindow->setSecret($this->get_option(self::SETTING_ONPAY_SECRET));
            $paymentWindow->setCurrency($isoCurrency->alpha3);
            $paymentWindow->setAmount($orderTotal);
            $paymentWindow->setReference($order->get_data()['id']);
            $paymentWindow->setType("payment");
            $paymentWindow->setAcceptUrl($order->get_checkout_order_received_url());
            $paymentWindow->setDeclineUrl($order->get_checkout_order_received_url());
            $paymentWindow->setCallbackUrl(WC()->api_request_url($this->id . '_callback'));
            if($this->get_option(self::SETTING_ONPAY_PAYMENTWINDOW_DESIGN)) {
                $paymentWindow->setDesign($this->get_option(self::SETTING_ONPAY_PAYMENTWINDOW_DESIGN));
            }
            if($this->get_option(self::SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE)) {
                $paymentWindow->setLanguage($this->get_option(self::SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE));
            }
            // Enable testmode
            if($this->get_option(self::SETTING_ONPAY_TESTMODE) === 'yes') {
                $paymentWindow->setTestMode(1);
            } else {
                $paymentWindow->setTestMode(0);
            }
            return $paymentWindow;
        }

        /**
         * Handle callback in oauth flow
         */
        private function handleOauthCallback() {
            $onpayApi = $this->getOnpayClient(true);
            if(null !== $this->getQueryValue('code') && !$onpayApi->isAuthorized()) {
                // We're not authorized with the API, and we have a 'code' value at hand. 
                // Let's authorize, and save the gatewayID and secret accordingly.
                $onpayApi->finishAuthorize($this->getQueryValue('code'));
                if ($onpayApi->isAuthorized()) {
                    $this->update_option(self::SETTING_ONPAY_GATEWAY_ID, $onpayApi->gateway()->getInformation()->gatewayId);
                    $this->update_option(self::SETTING_ONPAY_SECRET, $onpayApi->gateway()->getPaymentWindowIntegrationSettings()->secret);
                }
                wp_redirect($this->generateUrl(['page' => 'wc-settings','tab' => 'checkout','section' => 'wc_onpay']));
                exit;
            }
        }

        /**
         * Handles detach request on settings page
         */
        private function handleDetach() {
            $onpayApi = $this->getOnpayClient(true);
            if(null !== $this->getQueryValue('detach') && $onpayApi->isAuthorized()) {
                update_option('woocommerce_onpay_token', null);
                $this->update_option(self::SETTING_ONPAY_GATEWAY_ID, null);
                $this->update_option(self::SETTING_ONPAY_SECRET, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_MOBILEPAY, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_VIABILL, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_CARD, null);
                $this->update_option(self::SETTING_ONPAY_PAYMENTWINDOW_DESIGN, null);
                $this->update_option(self::SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE, null);
                $this->update_option(self::SETTING_ONPAY_TESTMODE, null);

                wp_redirect($this->generateUrl(['page' => 'wc-settings','tab' => 'checkout','section' => 'wc_onpay']));
                exit;
            }
        }

        /**
         * Gets a list of payment window designs available from API
         */
        private function getPaymentWindowDesignOptions() {
            try {
                $onpayApi = $this->getOnpayClient();
            } catch (InvalidArgumentException $exception) {
                return [];
            }
            if(!$onpayApi->isAuthorized()) {
                return [];
            }
            $designs = $onpayApi->gateway()->getPaymentWindowDesigns()->paymentWindowDesigns;
            $options = array_map(function(\OnPay\API\Gateway\SimplePaymentWindowDesign $design) {
                return [
                    'name' => $design->name,
                    'id' => $design->name,
                ];
            }, $designs);
            array_unshift($options, ['name' => __('Default design', 'wc-onpay'), 'id' => 'ONPAY_DEFAULT_WINDOW']);
            $selectOptions = [];
            foreach ($options as $option) {
                $selectOptions[$option['id']] = $option['name'];
            }
            return $selectOptions;
        }

        /**
         * Returns a prepared list of available payment window languages
         *
         * @return array
         */
        private function getPaymentWindowLanguageOptions() {
            return [
                'en' => __('English', 'wc-onpay'),
                'da' => __('Danish', 'wc-onpay'),
                'nl' => __('Dutch', 'wc-onpay'),
                'fo' => __('Faroese', 'wc-onpay'),
                'fr' => __('French', 'wc-onpay'),
                'de' => __('German', 'wc-onpay'),
                'it' => __('Italian', 'wc-onpay'),
                'no' => __('Norwegian', 'wc-onpay'),
                'pl' => __('Polish', 'wc-onpay'),
                'es' => __('Spanish', 'wc-onpay'),
                'sv' => __('Swedish', 'wc-onpay')
            ];
        }

        /**
         * Generates URL for current page with params
         * @param $params
         * @return string
         */
        private function generateUrl($params) {
            if (is_ssl()) {
                $currentPage = 'https://';
            } else {
                $currentPage = 'http://';
            }
            $currentPage .= $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            $baseUrl = explode('?', $currentPage, 2);
            $baseUrl = array_shift($baseUrl);
            $fullUrl = $baseUrl . '?' . http_build_query($params);
            return $fullUrl;
        }

        /**
         * Since Wordpress not really allows getting custom queries, we'll implement this method allowing us to get the values we need.
         * @param string $query
         * @return string|null
         */
        private function getQueryValue($query) {
            if (isset($query, $_GET)) {
                return $_GET[$query];
            }
            return null;
        }

        /**
         * Since Wordpress not really allows getting post values, we have this method for easier access.
         * @param string $key
         * @return string|null
         */
        private function getPostValue($key) {
            if (isset($key, $_POST)) {
                return $_POST[$key];
            }
            return null;
        }

        /**
         * Prints a jsonResponse
         */
        private function jsonResponse($message, $error = false, $responseCode = 200) {
            header('Content-Type: application/json');
            http_response_code($responseCode);
            $response = [];
            if (!$error) {
                $response = ['success' => $message, 'error' => false];
            } else {
                $response = ['error' => $message];
            }
            die(wp_json_encode($response));
        }
    }

    // Add OnPay as payment method to WooCommerce
    add_filter( 'woocommerce_payment_gateways', 'wc_onpay_add_to_woocommerce' );
    function wc_onpay_add_to_woocommerce($methods) {
        $methods[] = 'WC_OnPay';
        return $methods;
    }
    
    // Add action links to OnPay plugin on plugin overview
    add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_onpay_action_links' );
    function wc_onpay_action_links($links) {
        $plugin_links = [
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=wc_onpay') . '">' . __('Settings', 'wc-onpay') . '</a>',
        ];
        return array_merge( $plugin_links, $links );
    }

	// Initialize hooks
    WC_OnPay::get_instance()->init_hooks();
}
?>