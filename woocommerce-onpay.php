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
* Version: 1.0.11
**/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

add_action('plugins_loaded', 'init_onpay', 0);

function init_onpay() {

    // Make sure that Woocommerce is enabled and that Payment gateway class is available
    if (!defined('WC_VERSION') || !class_exists('WC_Payment_Gateway')) {
		return;
    }
    include_once __DIR__ . '/require.php';

    include_once __DIR__ . '/classes/currency-helper.php';
    include_once __DIR__ . '/classes/query-helper.php';
    include_once __DIR__ . '/classes/token-storage.php';

    include_once __DIR__ . '/classes/gateway-card.php';
    include_once __DIR__ . '/classes/gateway-mobilepay.php';
    include_once __DIR__ . '/classes/gateway-viabill.php';
    include_once __DIR__ . '/classes/gateway-anyday.php';

    class WC_OnPay extends WC_Payment_Gateway {
        const PLUGIN_VERSION = '1.0.11';

        const SETTING_ONPAY_GATEWAY_ID = 'gateway_id';
        const SETTING_ONPAY_SECRET = 'secret';
        const SETTING_ONPAY_EXTRA_PAYMENTS_MOBILEPAY = 'extra_payments_mobilepay';
        const SETTING_ONPAY_EXTRA_PAYMENTS_VIABILL = 'extra_payments_viabill';
        const SETTING_ONPAY_EXTRA_PAYMENTS_ANYDAY = 'extra_payments_anyday_split';
        const SETTING_ONPAY_EXTRA_PAYMENTS_CARD = 'extra_payments_card';
        const SETTING_ONPAY_PAYMENTWINDOW_DESIGN = 'paymentwindow_design';
        const SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE = 'paymentwindow_language';
        const SETTING_ONPAY_TESTMODE = 'testmode_enabled';
        const SETTING_ONPAY_CARDLOGOS = 'card_logos';

        const WC_ONPAY_ID = 'wc_onpay';
        const WC_ONPAY_SETTINGS_ID = 'onpay';

        const WC_ONPAY_SESSION_ADMIN_NOTICES = 'onpay_admin_notices';

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
            $this->id = $this::WC_ONPAY_ID;
            $this->method_title = __('OnPay.io', 'wc-onpay');
            $this->has_fields   = false;
            $this->method_description = __('Receive payments with cards and more through OnPay.io', 'wc-onpay');

            $this->init_settings();

            load_plugin_textdomain( 'wc-onpay', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        }

        /**
         * Tells WooCommerce whether gateway is available for use.
         */
        public function is_available() {
            return false;
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
            add_action('woocommerce_update_options_payment_gateways_'. $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_api_'. $this->id . '_callback', [$this, 'callback']);
            add_action('woocommerce_before_checkout_form', [$this, 'declinedReturnMessage']);
            add_action('save_post', [$this, 'handle_order_metabox']);
            add_action('add_meta_boxes', [$this, 'meta_boxes']);
            add_action('wp_enqueue_scripts', [$this, 'register_styles']);
            add_action('admin_notices', [$this, 'showAdminNotices']);
        }

        public function register_styles() {
            wp_register_style(WC_OnPay::WC_ONPAY_ID . '_style', plugin_dir_url(__FILE__) . 'assets/css/front.css');
            wp_enqueue_style(WC_OnPay::WC_ONPAY_ID . '_style');
        }

        /**
         * Method used for callbacks from OnPay. Validates orders using OnPay as method.
         */
        public function callback() {
            $paymentWindow = new \OnPay\API\PaymentWindow();
            $paymentWindow->setSecret($this->get_option(self::SETTING_ONPAY_SECRET));
            if (!$paymentWindow->validatePayment(wc_onpay_query_helper::get_query())) {
                $this->json_response('Invalid values', true, 400);
            }
            $order = new WC_Order(wc_onpay_query_helper::get_query_value('onpay_reference'));
            if ($order->has_status('pending')) {
                $order->payment_complete(wc_onpay_query_helper::get_query_value('onpay_number'));
                $order->add_order_note( __( 'Transaction authorized in OnPay. Remember to capture amount.', 'wc-onpay' ));
                $order->add_meta_data($this::WC_ONPAY_ID . '_test_mode', wc_onpay_query_helper::get_query_value('onpay_testmode'));
                $order->save_meta_data();
            }
            $this->json_response('Order validated');
        }

        public function declinedReturnMessage() {
            $paymentWindow = new \OnPay\API\PaymentWindow();
            $paymentWindow->setSecret($this->get_option($this::SETTING_ONPAY_SECRET));
            $order = new WC_Order(wc_onpay_query_helper::get_query_value('onpay_reference'));
            $isDeclined = wc_onpay_query_helper::get_query_value('declined_from_onpay');
            if (!$order->is_paid() && $isDeclined === '1' && $paymentWindow->validatePayment($_GET)) {
                // Order is not paid yet and user is returned through declined url from OnPay.
                // Valid OnPay URL params are also present, which indicates that user did not simply quit payment, but an actual error was encountered.
                echo '<div class="woocommerce-error">' . __('The payment failed. Please try again.', 'wc-onpay') . '</div>';
            }
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
                self::SETTING_ONPAY_EXTRA_PAYMENTS_ANYDAY => [
                    'title' => __('Anyday Split', 'wc-onpay'),
                    'label' => __('Enable Anyday Split as payment method', 'wc-onpay'),
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
                self::SETTING_ONPAY_CARDLOGOS => [
                    'title' => __( 'Card logos', 'wc-onpay' ),
                    'type' => 'multiselect',
                    'description' => __( 'Card logos shown for the Card payment method.', 'wc-onpay' ),
                    'desc_tip' => true,
                    'class'             => 'wc-enhanced-select',
                    'custom_attributes' => [
                        'data-placeholder' => __( 'Select logos', 'wc-onpay' )
                    ],
                    'default' => '',
                    'options' => $this->get_card_logo_options(),
                ],
            ];
		}

        /**
         * Method that renders payment gateway settings page in woocommerce
         */
        public function admin_options() {
            $onpayApi = $this->get_onpay_client(true);

            $html = '';
            $html .=  '<h3>' . __('OnPay.io', 'wc-onpay') .'</h3>';
            $html .=  '<p>' . __('Receive payments with cards and more through OnPay.io', 'wc-onpay') . '</p>';
            echo ent2ncr($html);

            $hideForm = false;
            
            try {
                $onpayApi->ping();
            } catch (OnPay\API\Exception\ConnectionException $exception) { // No connection to OnPay API
                echo ent2ncr('<h3>' . __('No connection to OnPay', 'wc-onpay') . '</h3>');
                $GLOBALS['hide_save_button'] = true;
                return;
            } catch (OnPay\API\Exception\TokenException $exception) { // Something's wrong with the token, print link to reauth
                echo ent2ncr($this->getOnboardingHtml());
                echo ent2ncr('<hr />');
                echo ent2ncr('<a href="' . $onpayApi->authorize() . '" class="button-primary">' . __('Log in with OnPay account', 'wc-onpay') . '</a>');
                $GLOBALS['hide_save_button'] = true;
                $hideForm = true;
            }

            $this->init_form_fields();
            $this->handle_oauth_callback();
            $this->handle_detach();
            $this->handle_refresh();

            if (!$hideForm) {
                $html = '<hr />';
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
                $html .= '<td><button class="button-secondary" id="button_onpay_refreshsecret">' . __('Refresh', 'wc-onpay') . '</button>&nbsp;<button class="button-secondary" id="button_onpay_apilogout">' . __('Log out from OnPay', 'wc-onpay') . '</button></td>';
                $html .= '</tr>';

                $html .= '</tbody></table>';
                $html .= '<hr />';

                wc_enqueue_js('$("#button_onpay_apilogout").on("click", function(event) {event.preventDefault(); if(confirm(\''. __('Are you sure you want to logout from Onpay?', 'wc-onpay') . '\')) {window.location.href = window.location.href+"&detach=1";}})');
                wc_enqueue_js('$("#button_onpay_refreshsecret").on("click", function(event) {event.preventDefault(); if(confirm(\''. __('Are you sure you want to refresh gateway ID and secret?', 'wc-onpay') . '\')) {window.location.href = window.location.href+"&refresh=1";}})');
            
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
                if ($this->isOnPayMethod($order->get_payment_method())) {
                    add_meta_box('mv_other_fields', __('OnPay.io', 'wc-onpay'), [$this,'order_meta_box'], 'shop_order', 'advanced', 'high', ['order' => $order]);
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
                if ($this->isOnPayMethod($order->get_payment_method()) && null !== $order->get_transaction_id() && $order->get_transaction_id() !== '') {
                    // Get the transaction from API
                    $transaction = $this->get_onpay_client()->transaction()->getTransaction($order->get_transaction_id());
                    $currencyHelper = new wc_onpay_currency_helper();

                    try {
                        if (null !== wc_onpay_query_helper::get_post_value('onpay_capture') && null !== wc_onpay_query_helper::get_post_value('onpay_capture_amount')) { // If transaction is requested captured.                            
                            $value = str_replace(',', '.', wc_onpay_query_helper::get_post_value('onpay_capture_amount'));
                            $amount = $currencyHelper->majorToMinor($value, $transaction->currencyCode, '.');
                            $this->get_onpay_client()->transaction()->captureTransaction($transaction->uuid, $amount);
                            $order->add_order_note( __( 'Amount captured on transaction in OnPay.', 'wc-onpay' ));
                            $this->addAdminNotice(__( 'Amount captured on transaction in OnPay.', 'wc-onpay' ), 'success');

                        } else if (null !== wc_onpay_query_helper::get_post_value('onpay_refund') && null !== wc_onpay_query_helper::get_post_value('onpay_refund_amount')) { // If transaction is requested refunded.
                            $value = str_replace('.', ',', wc_onpay_query_helper::get_post_value('onpay_refund_amount'));
                            $amount = $currencyHelper->majorToMinor($value, $transaction->currencyCode, ',');
                            $this->get_onpay_client()->transaction()->refundTransaction($transaction->uuid, $amount);
                            $order->add_order_note( __( 'Amount refunded on transaction in OnPay.', 'wc-onpay' ));
                            $this->addAdminNotice(__( 'Amount refunded on transaction in OnPay.', 'wc-onpay' ), 'success');

                        } else if (null !== wc_onpay_query_helper::get_post_value('onpay_cancel')) { // If transaction is requested cancelled.
                            $this->get_onpay_client()->transaction()->cancelTransaction($transaction->uuid);
                            $order->add_order_note( __( 'Transaction finished/cancelled in OnPay.', 'wc-onpay' ));
                            $this->addAdminNotice(__( 'Transaction finished/cancelled in OnPay.', 'wc-onpay' ), 'info');
                        }
                    } catch (OnPay\API\Exception\ApiException $exception) {
                        $this->addAdminNotice(__('OnPay error: ', 'wc-onpay') . $exception->getMessage(), 'error');
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

                if ($transaction->acquirer === 'test' || $order->get_meta($this::WC_ONPAY_ID . '_test_mode')) {
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

                $cardType = $transaction->cardType;
                if ($cardType === null) {
                    $cardType = $transaction->acquirer;
                }

                $html .= '<tr><td><strong>' . __('Card type', 'wc-onpay') . '</strong></td><td>' . $cardType . '</td></tr>';

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

        private function addAdminNotice($text, $type = 'success', $dismissable = true) {
            $classes = [
                'notice',
            ];

            if ($type === 'success') {
                $classes[] = 'notice-success';
            } else if ($type === 'warning') {
                $classes[] = 'notice-warning';
            } else if ($type === 'error') {
                $classes[] = 'notice-error';
            } else if ($type === 'info') {
                $classes[] = 'notice-info';
            }

            if ($dismissable === true) {
                $classes[] = 'is-dismissible';
            }

            $transientNotices = get_transient(self::WC_ONPAY_SESSION_ADMIN_NOTICES . '_' . get_current_user_id());
            if ($transientNotices === false) {
                $transientNotices = [];
            }
            $transientNotices[] = '<div class="' . implode(' ', $classes) . '"><p>' . $text . '</p></div>';
            set_transient(self::WC_ONPAY_SESSION_ADMIN_NOTICES . '_' . get_current_user_id(), $transientNotices);
        }
        
        public function showAdminNotices() {
            $transientNotices = get_transient(self::WC_ONPAY_SESSION_ADMIN_NOTICES . '_' . get_current_user_id());
            if ($transientNotices !== false) {
                foreach($transientNotices as $notice) {
                    echo $notice;
                }
                delete_transient(self::WC_ONPAY_SESSION_ADMIN_NOTICES . '_' . get_current_user_id());
            }
        }

        /**
         * @param $paymentMethod
         * @return bool
         */
        private function isOnPayMethod($paymentMethod) {
            if (in_array($paymentMethod, [
                wc_onpay_gateway_card::WC_ONPAY_GATEWAY_CARD_ID,
                wc_onpay_gateway_mobilepay::WC_ONPAY_GATEWAY_MOBILEPAY_ID,
                wc_onpay_gateway_viabill::WC_ONPAY_GATEWAY_VIABILL_ID,
                wc_onpay_gateway_anyday::WC_ONPAY_GATEWAY_ANYDAY_SPLIT_ID,
            ])) {
                return true;
            }
            return false;
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
                $params['section'] = $this::WC_ONPAY_ID;
            }
            $url = wc_onpay_query_helper::generate_url($params);
            $onPayAPI = new \OnPay\OnPayAPI($tokenStorage, [
                'client_id' => 'Onpay WooCommerce',
                'redirect_uri' => $url,
            ]);
            return $onPayAPI;
        }

        /**
         * Handle callback in oauth flow
         */
        private function handle_oauth_callback() {
            $onpayApi = $this->get_onpay_client(true);
            if(null !== wc_onpay_query_helper::get_query_value('code') && !$onpayApi->isAuthorized()) {
                // We're not authorized with the API, and we have a 'code' value at hand. 
                // Let's authorize, and save the gatewayID and secret accordingly.
                $onpayApi->finishAuthorize(wc_onpay_query_helper::get_query_value('code'));
                if ($onpayApi->isAuthorized()) {
                    $this->update_option(self::SETTING_ONPAY_GATEWAY_ID, $onpayApi->gateway()->getInformation()->gatewayId);
                    $this->update_option(self::SETTING_ONPAY_SECRET, $onpayApi->gateway()->getPaymentWindowIntegrationSettings()->secret);
                    $this->update_option(self::SETTING_ONPAY_CARDLOGOS, ['mastercard', 'visa']);
                }
                wp_redirect(wc_onpay_query_helper::generate_url(['page' => 'wc-settings','tab' => 'checkout','section' => $this::WC_ONPAY_ID]));
                exit;
            }
        }

        /**
         * Handles detach request on settings page
         * Nulls all plugin settings essentially terminating authorization with OnPay API
         */
        private function handle_detach() {
            $onpayApi = $this->get_onpay_client(true);
            if(null !== wc_onpay_query_helper::get_query_value('detach') && $onpayApi->isAuthorized()) {
                update_option('woocommerce_onpay_token', null);
                $this->update_option(self::SETTING_ONPAY_GATEWAY_ID, null);
                $this->update_option(self::SETTING_ONPAY_SECRET, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_MOBILEPAY, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_VIABILL, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_ANYDAY, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_CARD, null);
                $this->update_option(self::SETTING_ONPAY_PAYMENTWINDOW_DESIGN, null);
                $this->update_option(self::SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE, null);
                $this->update_option(self::SETTING_ONPAY_TESTMODE, null);
                $this->update_option(self::SETTING_ONPAY_CARDLOGOS, null);

                wp_redirect(wc_onpay_query_helper::generate_url(['page' => 'wc-settings','tab' => 'checkout','section' => $this::WC_ONPAY_ID]));
                exit;
            }
        }

        private function handle_refresh() {
            $onpayApi = $this->get_onpay_client(true);
            if(null !== wc_onpay_query_helper::get_query_value('refresh') && $onpayApi->isAuthorized()) {
                $this->update_option(self::SETTING_ONPAY_GATEWAY_ID, $onpayApi->gateway()->getInformation()->gatewayId);
                $this->update_option(self::SETTING_ONPAY_SECRET, $onpayApi->gateway()->getPaymentWindowIntegrationSettings()->secret);

                $this->addAdminNotice(__('Gateway ID and secret was refreshed', 'wc-onpay'), 'info');

                wp_redirect(wc_onpay_query_helper::generate_url(['page' => 'wc-settings','tab' => 'checkout','section' => $this::WC_ONPAY_ID]));
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
         * Returns a prepared list of card logos
         *
         * @return array
         */
        private function get_card_logo_options() {
            return [
                'american-express'      => __('American Express/AMEX', 'wc-onpay'),
                'dankort'               => __('Dankort', 'wc-onpay'),
                'diners'                => __('Diners', 'wc-onpay'),
                'discover'              => __('Discover', 'wc-onpay'),
                'forbrugsforeningen'    => __('Forbrugsforeningen', 'wc-onpay'),
                'jcb'                   => __('JCB', 'wc-onpay'),
                'mastercard'            => __('Mastercard/Maestro', 'wc-onpay'),
                'unionpay'              => __('UnionPay', 'wc-onpay'),
                'visa'                  => __('Visa/VPay/Visa Electron ', 'wc-onpay')
            ];
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

        private function getOnboardingHtml() {
            $html = '<span>' . __('Don\'t  have an OnPay account yet? Order one through DanDomain from DKK 0,- per month.', 'wc-onpay') . '</span>';
            $html .= '&nbsp;';
            $html .= '<a href="https://dandomain.dk/betalingssystem/priser" class="button-primary" target="_blank">' . __('Get OnPay now', 'wc-onpay') . '</a>';
            $html .= '&nbsp;';
            $html .= '<a href="https://onpay.io/#brands" class="button" target="_blank">' . __('OnPay sellers', 'wc-onpay') . '</a>';

            return $html;
        }

        /**
         * Return the name of the option in the WP DB.
         * Hardcode to use the defined settings_id as id for settings, for legacy reasons.
         *
         * @return string
         */
        public function get_option_key() {
            return $this->plugin_id . self::WC_ONPAY_SETTINGS_ID . '_settings';
        }
    }

    // Add OnPay as payment method to WooCommerce
    add_filter( 'woocommerce_payment_gateways', 'wc_onpay_add_to_woocommerce' );
    function wc_onpay_add_to_woocommerce($methods) {
        $methods[] = 'wc_onpay_gateway_card';
        $methods[] = 'wc_onpay_gateway_mobilepay';
        $methods[] = 'wc_onpay_gateway_viabill';
        $methods[] = 'wc_onpay_gateway_anyday';
        $methods[] = 'wc_onpay';

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

	// Initialize
    WC_OnPay::get_instance()->init_hooks();
}
?>
