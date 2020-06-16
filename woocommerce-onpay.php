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
* Plugin Name: OnPay.io for WooCommerce
* Description: OnPay.io payment plugin for WooCommerce
* Author: OnPay.io
* Author URI: https://onpay.io/
* Text Domain: wc-onpay
* Domain Path: /languages
* Version: 1.0.5
**/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/classes/currency-helper.php';
require_once __DIR__ . '/classes/token-storage.php';

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
        
        /**
         * Constructor
         */
        public function __construct() {
            $this->id           = 'onpay';
            $this->method_title = 'OnPay';
            $this->has_fields   = false;
            $this->method_description = __('Receive payments with cards and more through OnPay.io', 'wc-onpay');

            $this->init_settings();

            if (is_admin()) {
                $this->title = $this->method_title;
            } else {
                $this->title        = $this->get_active_methods_string('title');
                $this->description  = $this->get_active_methods_string('description');
            }

            load_plugin_textdomain( 'wc-onpay', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        }

        /**
         * Tells WooCommerce whether gateway is available for use.
         * Returns true if gateway is authorized and either card, mpo or viabill is activated.
         */
        public function is_available() {
            $onpayApi = $this->get_onpay_client();
            if (!$this->is_onpay_client_connected($onpayApi) || !$onpayApi->isAuthorized()) {
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

        /**
         * Tells WooCommerce whether plugin needs setup
         * Returns false if gateway is authorized.
         */
        public function needs_setup() {
            $onpayApi = $this->get_onpay_client();
            if (!$onpayApi->isAuthorized()) {
                return true;
            }
            return false;
        }

        /**
         * Initialize hooks
         */
        public function init_hooks() {
            if (is_admin()) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options'] );
            }
            add_action('woocommerce_receipt_' . $this->id, [$this, 'checkout']);
            add_action('woocommerce_api_'. $this->id . '_callback', [$this, 'callback']);
            add_action('post_updated', [$this, 'handle_order_metabox']);
            add_action('add_meta_boxes', [$this, 'meta_boxes']);
        }

        /**
         * Forward cardholder/customer to reciept page, for further forwarding.
         */
        public function process_payment( $order_id ) {
            $order = new WC_Order($order_id);
            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

        /**
         * Method for injecting payment window form into reciept page, and automatically posting form to OnPay.
         */
        public function checkout($order_id) {
            $order = new WC_Order($order_id);
            $paymentWindow = $this->get_payment_window($order);
            $formFields = $paymentWindow->getFormFields();

            echo '<p>' . __( 'Redirecting to payment window', 'wc-onpay' ) . '</p>';
            wc_enqueue_js('document.getElementById("onpay_form").submit();');
        
            echo '<form action="' . $paymentWindow->getActionUrl() . '" method="post" target="_top" id="onpay_form">';
            foreach($paymentWindow->getFormFields() as $key => $formField) {
                echo '<input type="hidden" name="' . $key . '" value="' . $formField . '">';
            }
            echo '</form>';
        }

        /**
         * Method used for callbacks from OnPay. Validates orders using OnPay as method.
         */
        public function callback() {
            $paymentWindow = new \OnPay\API\PaymentWindow();
            $paymentWindow->setSecret($this->get_option(self::SETTING_ONPAY_SECRET));
            if (!$paymentWindow->validatePayment($this->get_query())) {
                $this->json_response('Invalid values', true, 400);
            }
            $order = new WC_Order($this->get_query_value('onpay_reference'));
            if ($order->has_status('pending')) {
                $order->payment_complete($this->get_query_value('onpay_number'));
                $order->add_order_note( __( 'Transaction authorized in OnPay. Remember to capture amount.', 'wc-onpay' ));
            }
            $this->json_response('Order validated');
        }

        /**
         * Initialize form fields for settings page.
         */
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
                    'options' => $this->get_payment_window_design_options(),
                ],
                self::SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE => [
                    'title' => __('Payment window language', 'wc-onpay'),
                    'type' => 'select',
                    'options' => $this->get_payment_window_language_options(),
                ],
                self::SETTING_ONPAY_TESTMODE => [
                    'title' => __('Test Mode', 'wc-onpay'),
                    'label' => ' ',
                    'type' => 'checkbox',
                    'default' => 'no',
                ],
            ];
		}

        /**
         * Method that renders payment gateway settings page in woocommerce
         */
        public function admin_options() {
            $onpayApi = $this->get_onpay_client(true);

            $html = '';
            $html .=  '<h3>OnPay</h3>';
            $html .=  '<p>' . __('Receive payments with cards and more through OnPay.io', 'wc-onpay') . '</p>';
            $html .= '<hr />';
            echo ent2ncr($html);

            $hideForm = false;
            
            try {
                $onpayApi->ping();
            } catch (OnPay\API\Exception\ConnectionException $exception) { // No connection to OnPay API
                echo ent2ncr('<h3>' . __('No connection to OnPay', 'wc-onpay') . '</h3>');
                $GLOBALS['hide_save_button'] = true;
                return;
            } catch (OnPay\API\Exception\TokenException $exception) { // Something's wrong with the token, print link to reauth
                echo ent2ncr('<a href="' . $onpayApi->authorize() . '" class="button-primary">' . __('Log in with OnPay', 'wc-onpay') . '</a>');
                $GLOBALS['hide_save_button'] = true;
                $hideForm = true;
            }

            $this->init_form_fields();
            $this->handle_oauth_callback();
            $this->handle_detach();

            if (!$hideForm) {
                $html = '';
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
            
                echo ent2ncr($html);
            }
        }

        public function process_admin_options() {
            $this->init_form_fields();

            parent::process_admin_options();
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
        public function handle_order_metabox() {
            global $post;

            // Determine that we're on the correct controller
            if ($post->post_type === 'shop_order') {
                $order = new WC_Order($post->ID);
                // Determine that the required data for getting transaction is available.
                if ($order->get_payment_method() === $this->id && null !== $order->get_transaction_id() && $order->get_transaction_id() !== '') {
                    // Get the transaction from API
                    $transaction = $this->get_onpay_client()->transaction()->getTransaction($order->get_transaction_id());
                    $currencyHelper = new wc_onpay_currency_helper();

                    if (null !== $this->get_post_value('onpay_capture') && null !== $this->get_post_value('onpay_capture_amount')) { // If transaction is requested captured.
                        $value = str_replace(',', '.', $this->get_post_value('onpay_capture_amount'));
                        $amount = $currencyHelper->majorToMinor($value, $transaction->currencyCode, '.');
                        $this->get_onpay_client()->transaction()->captureTransaction($transaction->uuid, $amount);
                        $order->add_order_note( __( 'Amount captured on transaction in OnPay.', 'wc-onpay' ));

                    } else if (null !== $this->get_post_value('onpay_refund') && null !== $this->get_post_value('onpay_refund_amount')) { // If transaction is requested refunded.
                        $value = str_replace('.', ',', $this->get_post_value('onpay_refund_amount'));
                        $amount = $currencyHelper->majorToMinor($value, $transaction->currencyCode, ',');
                        $this->get_onpay_client()->transaction()->refundTransaction($transaction->uuid, $amount);
                        $order->add_order_note( __( 'Amount refunded on transaction in OnPay.', 'wc-onpay' ));

                    } else if (null !== $this->get_post_value('onpay_cancel')) { // If transaction is requested cancelled.
                        $this->get_onpay_client()->transaction()->cancelTransaction($transaction->uuid);
                        $order->add_order_note( __( 'Transaction finished/cancelled in OnPay.', 'wc-onpay' ));

                    }
                }
            }
        }

        /**
         * Method that renders the meta box for OnPay transactions on order page.
         */
        public function order_meta_box($post, array $meta) {
            $onpayApi = $this->get_onpay_client();

            try {
                $onpayApi->ping();
            } catch (OnPay\API\Exception\ConnectionException $exception) { // No connection to OnPay API
                echo ent2ncr('<h3>' . __('No connection to OnPay', 'wc-onpay') . '</h3>');
                return;
            } catch (OnPay\API\Exception\TokenException $exception) { // Something's wrong with the token, print link to reauth
                echo ent2ncr('<h3>' . __('Invalid OnPay token, please login on settings page', 'wc-onpay') . '</h3>');
                return;
            }

            $order = $meta['args']['order'];
            $html = '';

            // If order is pending, no need to find the transaction.
            if (null === $order->get_transaction_id() || $order->get_transaction_id() === '' || $order->has_status('pending')) {
                echo __('Pending payment', 'wc-onpay');
            } else {
                try {
                    $transaction = $onpayApi->transaction()->getTransaction($order->get_transaction_id());
                } catch (OnPay\API\Exception\ApiException $exception) {
                    echo __('Error: ', 'wc-onpay') . $exception->getMessage();
                    exit;
                }
                
                $currencyHelper = new wc_onpay_currency_helper();
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

                // Shows info and history of transaction in split view
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

                // Add buttons for handling transaction
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

                // Hidden capture form, revealed by button above
                $html .= '<div id="onpay_action_capture" style="display: none;">';
                $html .= '<p>' . __('Please enter amount to capture', 'wc-onpay') . '</p>';
                $html .= '<input type="text" name="onpay_capture_amount" value="' . $availableAmount . '">';
                $html .= '<hr />';
                $html .= '<input class="button-primary" type="submit" name="onpay_capture" value="' . __('Capture', 'wc-onpay') . '">&nbsp;';
                $html .= '<button class="button-secondary" id="button_onpay_capture_hide">' . __('Cancel', 'wc-onpay') . '</button>';
                $html .= '</div>';
                wc_enqueue_js('$("#button_onpay_capture_hide").on("click", function(event) {event.preventDefault(); $("#onpay_action_capture").slideUp(); $("#onpay_action_buttons").slideDown(); })');
                
                // Hidden refund form, revealed by button above
                $html .= '<div id="onpay_action_refund" style="display: none;">';
                $html .= '<p>' . __('Please enter amount to refund', 'wc-onpay') . '</p>';
                $html .= '<input type="text" name="onpay_refund_amount" value="' . $availableCharged . '">';
                $html .= '<hr />';
                $html .= '<input class="button-primary" type="submit" name="onpay_refund" value="' . __('Refund', 'wc-onpay') . '">&nbsp;';
                $html .= '<button class="button-secondary" id="button_onpay_refund_hide">' . __('Cancel', 'wc-onpay') . '</button>';
                $html .= '</div>';
                wc_enqueue_js('$("#button_onpay_refund_hide").on("click", function(event) {event.preventDefault(); $("#onpay_action_refund").slideUp(); $("#onpay_action_buttons").slideDown(); })');

                // Hidden cancel/finish form, revealed by button above
                $html .= '<div id="onpay_action_cancel" style="display: none;">';
                $html .= '<p>' . __('When finishing or cancelling a transaction, no further capturing of amount will be possible on transaction.', 'wc-onpay') . '</p>';
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
        private function get_active_methods_string($string) {
            $methods = $this->get_active_methods();
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
        private function get_active_methods() {
            $methods = [];
            if ($this->get_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_CARD) === 'yes') {
                $methods[] = __('Card', 'wc-onpay');
            }
            if ($this->get_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_MOBILEPAY) === 'yes') {
                $methods[] = __('MobilePay', 'wc-onpay');
            }
            if ($this->get_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_VIABILL) === 'yes') {
                $methods[] = __('ViaBill', 'wc-onpay');
            }
            return $methods;
        }
        
        /**
         * Returns an instantiated OnPay API client
         *
         * @return \OnPay\OnPayAPI
         */
        private function get_onpay_client($prepareRedirectUri = false) {
            $tokenStorage = new wc_onpay_token_storage();
            $params = [];
            // AdminToken cannot be generated on payment pages
            if($prepareRedirectUri) {
                $params['page'] = 'wc-settings';
                $params['tab'] = 'checkout';
                $params['section'] = 'wc_onpay';
            }
            $url = $this->generate_url($params);
            $onPayAPI = new \OnPay\OnPayAPI($tokenStorage, [
                'client_id' => 'Onpay WooCommerce',
                'redirect_uri' => $url,
            ]);
            return $onPayAPI;
        }

        /**
         * @var \OnPay\OnPayAPI $onpayClient
         * @return boolean
         */
        private function is_onpay_client_connected($onpayClient) {
            if (!$onpayClient instanceof \OnPay\OnPayAPI) {
                return false;
            }
            try {
                $onpayClient->ping();
            } catch (OnPay\API\Exception\ConnectionException $exception) {
                return false;
            }
            return true;
        }

        /**
         * Returns instance of PaymentWindow based on WC_Order
         */
        private function get_payment_window($order) {
            if (!$order instanceof WC_Order) {
                return null;
            }

            $CurrencyHelper = new wc_onpay_currency_helper();

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
        private function handle_oauth_callback() {
            $onpayApi = $this->get_onpay_client(true);
            if(null !== $this->get_query_value('code') && !$onpayApi->isAuthorized()) {
                // We're not authorized with the API, and we have a 'code' value at hand. 
                // Let's authorize, and save the gatewayID and secret accordingly.
                $onpayApi->finishAuthorize($this->get_query_value('code'));
                if ($onpayApi->isAuthorized()) {
                    $this->update_option(self::SETTING_ONPAY_GATEWAY_ID, $onpayApi->gateway()->getInformation()->gatewayId);
                    $this->update_option(self::SETTING_ONPAY_SECRET, $onpayApi->gateway()->getPaymentWindowIntegrationSettings()->secret);
                }
                wp_redirect($this->generate_url(['page' => 'wc-settings','tab' => 'checkout','section' => 'wc_onpay']));
                exit;
            }
        }

        /**
         * Handles detach request on settings page
         * Nulls all plugin settings essentially terminating authorization with OnPay API
         */
        private function handle_detach() {
            $onpayApi = $this->get_onpay_client(true);
            if(null !== $this->get_query_value('detach') && $onpayApi->isAuthorized()) {
                update_option('woocommerce_onpay_token', null);
                $this->update_option(self::SETTING_ONPAY_GATEWAY_ID, null);
                $this->update_option(self::SETTING_ONPAY_SECRET, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_MOBILEPAY, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_VIABILL, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_CARD, null);
                $this->update_option(self::SETTING_ONPAY_PAYMENTWINDOW_DESIGN, null);
                $this->update_option(self::SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE, null);
                $this->update_option(self::SETTING_ONPAY_TESTMODE, null);

                wp_redirect($this->generate_url(['page' => 'wc-settings','tab' => 'checkout','section' => 'wc_onpay']));
                exit;
            }
        }

        /**
         * Gets a list of payment window designs available from API
         */
        private function get_payment_window_design_options() {
            try {
                $onpayApi = $this->get_onpay_client();
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
        private function get_payment_window_language_options() {
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
        private function generate_url($params) {
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
        private function get_query_value($query) {
            if (isset($_GET[$query])) {
                return sanitize_text_field($_GET[$query]);
            }
            return null;
        }

        /**
         * Get all query values sanitized in array.
         * @return array
         */
        private function get_query() {
            $query = [];
            foreach ($_GET as $key => $get) {
                $query[$key] = sanitize_text_field($get);
            }
            return $query;
        }

        /**
         * Since Wordpress not really allows getting post values, we have this method for easier access.
         * @param string $key
         * @return string|null
         */
        private function get_post_value($key) {
            if (isset($_POST[$key])) {
                return sanitize_text_field($_POST[$key]);
            }
            return null;
        }

        /**
         * Prints a json response
         */
        private function json_response($message, $error = false, $responseCode = 200) {
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
