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
* Version: 1.0.41
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
    include_once __DIR__ . '/classes/country-helper.php';
    include_once __DIR__ . '/classes/order-helper.php';
    include_once __DIR__ . '/classes/query-helper.php';
    include_once __DIR__ . '/classes/token-storage.php';

    include_once __DIR__ . '/classes/gateway-card.php';
    include_once __DIR__ . '/classes/gateway-mobilepay.php';
    include_once __DIR__ . '/classes/gateway-applepay.php';
    include_once __DIR__ . '/classes/gateway-googlepay.php';
    include_once __DIR__ . '/classes/gateway-viabill.php';
    include_once __DIR__ . '/classes/gateway-anyday.php';
    include_once __DIR__ . '/classes/gateway-vipps.php';
    include_once __DIR__ . '/classes/gateway-swish.php';
    include_once __DIR__ . '/classes/gateway-paypal.php';
    include_once __DIR__ . '/classes/gateway-klarna.php';

    class WC_OnPay extends WC_Payment_Gateway {
        const PLUGIN_VERSION = '1.0.41';

        const SETTING_ONPAY_GATEWAY_ID = 'gateway_id';
        const SETTING_ONPAY_SECRET = 'secret';
        const SETTING_ONPAY_EXTRA_PAYMENTS_MOBILEPAY = 'extra_payments_mobilepay';
        const SETTING_ONPAY_EXTRA_PAYMENTS_APPLEPAY = 'extra_payments_applepay';
        const SETTING_ONPAY_EXTRA_PAYMENTS_GOOGLEPAY = 'extra_payments_googlepay';
        const SETTING_ONPAY_EXTRA_PAYMENTS_VIABILL = 'extra_payments_viabill';
        const SETTING_ONPAY_EXTRA_PAYMENTS_ANYDAY = 'extra_payments_anyday_split';
        const SETTING_ONPAY_EXTRA_PAYMENTS_VIPPS = 'extra_payments_vipps';
        const SETTING_ONPAY_EXTRA_PAYMENTS_SWISH = 'extra_payments_swish';
        const SETTING_ONPAY_EXTRA_PAYMENTS_PAYPAL = 'extra_payments_paypal';
        const SETTING_ONPAY_EXTRA_PAYMENTS_KLARNA = 'extra_payments_klarna';
        const SETTING_ONPAY_EXTRA_PAYMENTS_CARD = 'extra_payments_card';
        const SETTING_ONPAY_PAYMENTWINDOW_DESIGN = 'paymentwindow_design';
        const SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE = 'paymentwindow_language';
        const SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE_AUTO = 'paymentwindow_language_auto';
        const SETTING_ONPAY_TESTMODE = 'testmode_enabled';
        const SETTING_ONPAY_CARDLOGOS = 'card_logos';
        const SETTING_ONPAY_STATUS_AUTOCAPTURE = 'status_autocapture';
        const SETTING_ONPAY_REFUND_INTEGRATION = 'refund_integration';

        const WC_ONPAY_ID = 'wc_onpay';
        const WC_ONPAY_SETTINGS_ID = 'onpay';
        const WC_ONPAY_PLATFORM_STRING = 'woocommerce/' . self::PLUGIN_VERSION . '/'. WC_VERSION;

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
            add_filter('woocommerce_settings_'. $this->id, [$this, 'admin_options']);
            add_action('woocommerce_settings_save_'. $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_api_'. $this->id . '_callback', [$this, 'callback']);
            add_action('woocommerce_before_checkout_form', [$this, 'declinedReturnMessage']);
            add_action('woocommerce_thankyou', [$this, 'declinedReturnMessage']);
            add_action('woocommerce_order_status_completed', [$this, 'orderStatusCompleteEvent']);
            add_action('woocommerce_scheduled_subscription_payment_onpay_card', [$this, 'subscriptionPayment'], 1, 2);
            add_action('woocommerce_subscription_cancelled_onpay_card', [$this, 'subscriptionCancellation']);
            add_action('woocommerce_order_refunded', [$this, 'refundEvent'], 10, 2);
            add_action('admin_init', [$this, 'gateway_toggle']);
            add_action('woocommerce_process_shop_order_meta', [$this, 'handle_order_meta_box']);
            add_action('add_meta_boxes', [$this, 'meta_boxes']);
            add_action('wp_enqueue_scripts', [$this, 'register_scripts']);
            add_action('admin_notices', [$this, 'showAdminNotices']);
        }

        public function register_scripts() {
            wp_enqueue_style(WC_OnPay::WC_ONPAY_ID . '_style', plugin_dir_url(__FILE__) . 'assets/css/front.css');
            wp_enqueue_script(WC_OnPay::WC_ONPAY_ID . '_script_jssdk', 'https://onpay.io/sdk/v1.js');

            // If either Apple Pay or Google Pay is enabled, register frontend script for managing these.
            if (
                $this->get_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_APPLEPAY) === 'yes' ||
                $this->get_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_GOOGLEPAY) === 'yes'
            ) {
                wp_enqueue_script(WC_OnPay::WC_ONPAY_ID . '_script', plugin_dir_url(__FILE__) . 'assets/js/apple_google_pay.js');
            }
        }

        /**
         * Method used for callbacks from OnPay. Validates orders using OnPay as method.
         */
        public function callback() {
            $orderHelper = new wc_onpay_order_helper();

            // Validate query parameters from OnPay
            $paymentWindow = new \OnPay\API\PaymentWindow();
            $paymentWindow->setSecret($this->get_option(self::SETTING_ONPAY_SECRET));

            // Get IDs and reference
            $onpayNumber = wc_onpay_query_helper::get_query_value('onpay_number');
            $onpayReference = wc_onpay_query_helper::get_query_value('onpay_reference');
            $createdTransactionNumber = wc_onpay_query_helper::get_query_value('onpay_number_transaction');

            // Validate query parameters and check that onpay_number is present
            if (!$paymentWindow->validatePayment(wc_onpay_query_helper::get_query()) || null === $onpayNumber) {
                $this->json_response('Invalid values', true, 400);
            }

            // Validate that order exists and that order reference and onpay_reference match 
            $order = $this->findOrder();
            $reference = $orderHelper->getOrderReference($order);
            if (false === $order || $reference !== $onpayReference) {
                $this->json_response('Order not found', true, 400);
            }

            $type = wc_onpay_query_helper::get_query_value('onpay_type');
            // Is order in pending state
            if ($order->has_status('pending')) {
                // If we're dealing with a subscription
                if ($type === 'subscription') {
                    // Write subscription id to subscription order and save it. This is the created subscription
                    $wcSubscriptions = wcs_get_subscriptions_for_order($order->get_id());
                    foreach ($wcSubscriptions as $id => $subscription) {
                        $subscription->add_order_note(__( 'Subscription authorized in OnPay.', 'wc-onpay' ));
                        $this->setOnpayId($subscription, $onpayNumber);
                        $subscription->save();
                    }

                    // If we're dealing with an renewal, we need to create a new transaction from the subscription
                    if ($orderHelper->isOrderSubscriptionRenewal($order) || $orderHelper->isOrderSubscriptionEarlyRenewal($order)) {
                        $currencyHelper = new wc_onpay_currency_helper();
                        $orderCurrency = $currencyHelper->fromAlpha3($order->get_currency());
                        $orderAmount = $currencyHelper->majorToMinor($order->get_total(), $orderCurrency->numeric, '.');
                        $onpaySubscription = $this->get_onpay_client()->subscription()->getSubscription($onpayNumber);
                        $createdTransaction = $this->get_onpay_client()->subscription()->createTransactionFromSubscription($onpaySubscription->uuid, $orderAmount, strval($order->get_order_number()));
                        $createdTransactionNumber = $createdTransaction->transactionNumber;
                    }
                }

                // Completion of order
                // Check if we have an ID of a created transaction, and use that for reference if so
                if (null !== $createdTransactionNumber) {
                    $this->setOnpayId($order, $createdTransactionNumber);
                    $order->payment_complete($createdTransactionNumber);
                } else {
                    // Otherwise use the number provided
                    $this->setOnpayId($order, $onpayNumber);
                    $order->payment_complete($onpayNumber);
                }
                // Add remaining data regarding order and save it.
                $order->add_order_note(__( 'Transaction authorized in OnPay. Remember to capture amount.', 'wc-onpay' ));
                $order->add_meta_data($this::WC_ONPAY_ID . '_test_mode', wc_onpay_query_helper::get_query_value('onpay_testmode'));
                $order->save_meta_data();
                $order->save();
            }
            
            if ($type === 'subscription' && null !== wc_onpay_query_helper::get_query_value('update_method')) { // Order is subscription and an update of method is requested.
                // Set OnpayNumber from callback query
                $onpayNumber = wc_onpay_query_helper::get_query_value('onpay_number');
                // Try getting original subscription Id
                $originalSubscription = get_post_meta($order->get_id(), '_subscription_renewal', true);
                if ($originalSubscription !== '') {
                    // If originalSubscription is present, we'll set the subscription order that is to be updated to this.
                    // This is during the flow of an early renewal.
                    $subscriptionOrder = new WC_Subscription($originalSubscription);
                    $this->cancelOnpaySubscription($subscriptionOrder); // Cancel current subscription in OnPay if possible.
                } else {
                    // Otherwise we'll set the subscription to be updated as the current.
                    // This is during the flow of a method update.
                    $subscriptionOrder = new WC_Subscription($order->get_id());
                    // Current subscription will be automatically cancelled.
                }

                // Check that the current subscription order transaction ID is not already the OnPay number submitted.
                if ($this->getOnpayId($subscriptionOrder) !== $onpayNumber) {
                    // We'll simply update the transaction ID on the subscription order
                    $this->setOnpayId($subscriptionOrder, $onpayNumber);
                    $subscriptionOrder->add_order_note(__( 'Subscription updated with new payment info in OnPay.', 'wc-onpay' ));
                    $subscriptionOrder->save();
                }
            }

            $this->json_response('Order validated');
        }

        // Finds order based on query parameters
        private function findOrder() {
            // Get order key
            $key = wc_onpay_query_helper::get_query_value('order_key');

            // If key is not found attempt legacy logic for finding order
            if (null === $key) {
                $reference = wc_onpay_query_helper::get_query_value('onpay_reference');
                if (null === $reference) {
                    return false;
                }

                if (function_exists('wc_seq_order_number_pro')) {
                    // Specifically use 'find_order_by_order_number' function if Sequential Order Number Pro plugin is installed, to find order ID
                    $reference = wc_seq_order_number_pro()->find_order_by_order_number($reference);
                }

                return wc_get_order($reference);
            }

            $orderId = wc_get_order_id_by_order_key($key);
            $order = wc_get_order($orderId);
            return $order;
        }

        public function declinedReturnMessage() {
            $paymentWindow = new \OnPay\API\PaymentWindow();
            $paymentWindow->setSecret($this->get_option($this::SETTING_ONPAY_SECRET));
            
            $key = wc_onpay_query_helper::get_query_value('order_key');
            $orderId = wc_get_order_id_by_order_key($key);
            $order = wc_get_order($orderId);
            
            $isDeclined = wc_onpay_query_helper::get_query_value('declined_from_onpay');
            if ($isDeclined === '1' && $order && !$order->is_paid()) {
                // Order is not paid yet and user is returned through declined url from OnPay.
                // Valid OnPay URL params are also present, which indicates that user did not simply quit payment, but an actual error was encountered.
                echo '<div class="woocommerce-error">' . __('The payment failed. Please try again.', 'wc-onpay') . '</div>';
            }
        }

        /**
         * Method that renders payment gateway settings page in woocommerce
         */
        public function admin_options() {
            $onpayApi = $this->get_onpay_client(true);

            $this->handle_oauth_callback();
            $this->handle_detach();
            $this->handle_refresh();

            $html = '<h3>' . __('OnPay.io', 'wc-onpay') .'</h3>';

            $hideForm = false;
            
            // Print information if OnPay is not pingable.
            try {
                $onpayApi->ping();
            } catch (OnPay\API\Exception\ConnectionException $exception) { // No connection to OnPay API
                $html .= '<h3>' . __('No connection to OnPay', 'wc-onpay') . '</h3>';
                $GLOBALS['hide_save_button'] = true;
                echo ent2ncr($html);
                return;
            } catch (OnPay\API\Exception\TokenException $exception) { // Something's wrong with the token, print link to reauth
                $html .= $this->getOnboardingHtml($onpayApi->authorize());
                $GLOBALS['hide_save_button'] = true;
                $hideForm = true;
            }

            if (!$hideForm) {
                // Get section
                $section = wc_onpay_query_helper::get_query_value('section');

                // Render section menu
                $html .= $this->admin_options_sections($section);
                $html .= '<hr>';

                // Init form for section
                $this->init_section_form($section);

                $html .= '<div class="postbox"><div class="inside">';

                // Print form fields
                $html .= '<table class="form-table">';
                $html .= $this->generate_settings_html([], false);
                $html .= '</table>';

                if (null === $section || '' === $section) {
                    $html .= '<hr>';
                    $html .= $this->init_gateway_info();
                }

                $html .= '</div></div>';
            }

            echo ent2ncr($html);
        }

        function admin_options_sections($currentSection) {
            if (null === $currentSection) {
                $currentSection = '';
            }
        
            // Sections we would like to register
            $sections = [
                ''          => __('General settings', 'wc-onpay'),
                'methods'   => __('Payment methods', 'wc-onpay'),
                'window'    => __('Payment window', 'wc-onpay'),
            ];
        
            // Start printing list of sections
            $html =  '<ul class="subsubsub">';

            // Print individual sections
            $i = 0;
            foreach($sections as $id => $label) {
                $url = add_query_arg(
                    [
                        'page' => 'wc-settings',
                        'tab' => $this->id,
                        'section' => $id,
                    ],
                    admin_url('admin.php')
                );
                $html .= '<li>' . ($i > 0 ? '|' : '') . '<a href="' . $url .'" ' . ($currentSection === $id ? 'class="current"' : '') . '>' . $label . '</a></li>';
                $i++;
            }
        
            // End list
            $html .= '</ul><br>';
            return $html;
        }

        public function process_admin_options() {
            // Get section
            $section = wc_onpay_query_helper::get_query_value('section');
            // Init form for section
            $this->init_section_form($section);
            
            parent::process_admin_options();
        }

        private function init_section_form($section) {
            // Determine what content to print according to section
            if (null === $section || '' === $section) {
                $this->init_general_settings();
            } else if ('methods' === $section) {
                $this->init_method_settings();
            } else if ('window' === $section) {
                $this->init_window_settings();
            }
        }

        private function init_general_settings() {
            $this->form_fields = [
                self::SETTING_ONPAY_STATUS_AUTOCAPTURE => [
                    'title' => __('Automatic capture', 'wc-onpay'),
                    'label' => __('Automatic capture of transactions on status completed', 'wc-onpay'),
                    'description' => __( 'Automatically capture remaining amounts on transactions, when orders are marked with status completed', 'wc-onpay' ),
                    'desc_tip' => true,
                    'type' => 'checkbox',
                    'default' => 'no',
                ],
                self::SETTING_ONPAY_REFUND_INTEGRATION => [
                    'title' => __('Integrate with refund feature', 'wc-onpay'),
                    'label' => __('Integrate with the built in refund feature in WooCommerce', 'wc-onpay'),
                    'desc_tip' => true,
                    'type' => 'checkbox',
                    'default' => 'no',
                ],
            ];
		}

        private function init_method_settings() {
            $this->form_fields = [
                self::SETTING_ONPAY_EXTRA_PAYMENTS_CARD => [
                    'title' => __('Card', 'wc-onpay'),
                    'label' => __('Enable card as payment method', 'wc-onpay'),
                    'type' => 'checkbox',
                    'default' => 'yes',
                ],
                self::SETTING_ONPAY_CARDLOGOS => [
                    'title' => __('Card logos', 'wc-onpay'),
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
                self::SETTING_ONPAY_EXTRA_PAYMENTS_MOBILEPAY => [
                    'title' => __('MobilePay Online', 'wc-onpay'),
                    'label' => __('Enable MobilePay Online as payment method', 'wc-onpay'),
                    'type' => 'checkbox',
                    'default' => 'no',
                ],
                self::SETTING_ONPAY_EXTRA_PAYMENTS_APPLEPAY => [
                    'title' => __('Apple Pay', 'wc-onpay'),
                    'label' => __('Enable Apple Pay as payment method (Only shown if customer browser supports method)', 'wc-onpay'),
                    'type' => 'checkbox',
                    'default' => 'no',
                ],
                self::SETTING_ONPAY_EXTRA_PAYMENTS_GOOGLEPAY => [
                    'title' => __('Google Pay', 'wc-onpay'),
                    'label' => __('Enable Google Pay as payment method (Only shown if customer browser supports method)', 'wc-onpay'),
                    'type' => 'checkbox',
                    'default' => 'no',
                ],
                self::SETTING_ONPAY_EXTRA_PAYMENTS_PAYPAL => [
                    'title' => __('PayPal', 'wc-onpay'),
                    'label' => __('Enable PayPal as payment method', 'wc-onpay'),
                    'type' => 'checkbox',
                    'default' => 'no',
                ],
                self::SETTING_ONPAY_EXTRA_PAYMENTS_KLARNA => [
                    'title' => __('Klarna', 'wc-onpay'),
                    'label' => __('Enable Klarna as payment method', 'wc-onpay'),
                    'type' => 'checkbox',
                    'default' => 'no',
                ],
                self::SETTING_ONPAY_EXTRA_PAYMENTS_VIPPS => [
                    'title' => __('Vipps', 'wc-onpay'),
                    'label' => __('Enable Vipps as payment method', 'wc-onpay'),
                    'type' => 'checkbox',
                    'default' => 'no',
                ],
                self::SETTING_ONPAY_EXTRA_PAYMENTS_SWISH => [
                    'title' => __('Swish', 'wc-onpay'),
                    'label' => __('Enable Swish as payment method', 'wc-onpay'),
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
                    'title' => __('Anyday', 'wc-onpay'),
                    'label' => __('Enable Anyday as payment method', 'wc-onpay'),
                    'type' => 'checkbox',
                    'default' => 'no',
                ],
            ];
		}

        private function init_window_settings() {
            $this->form_fields = [
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
                self::SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE_AUTO => [
                    'title' => __('Automatic payment window language', 'wc-onpay'),
                    'type' => 'checkbox',
                    'default' => 'no',
                    'label' => __('Overrides language chosen above, and instead use frontoffice language', 'wc-onpay'),
                ],
                self::SETTING_ONPAY_TESTMODE => [
                    'title' => __('Test Mode', 'wc-onpay'),
                    'label' => ' ',
                    'type' => 'checkbox',
                    'default' => 'no',
                ],
            ];
		}

        private function init_gateway_info() {
                $html = '<h3>' . __('Gateway information', 'wc-onpay') . '</h3>';
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

                wc_enqueue_js('$("#button_onpay_apilogout").on("click", function(event) {event.preventDefault(); if(confirm(\''. __('Are you sure you want to logout from Onpay?', 'wc-onpay') . '\')) {window.location.href = window.location.href+"&detach=1";}})');
                wc_enqueue_js('$("#button_onpay_refreshsecret").on("click", function(event) {event.preventDefault(); if(confirm(\''. __('Are you sure you want to refresh gateway ID and secret?', 'wc-onpay') . '\')) {window.location.href = window.location.href+"&refresh=1";}})');
            
                return $html;
        }

        /**
         * Allows toggling of gateways from payment gateways overview
         */
        public function gateway_toggle() {
            if (isset( $_POST['action'] ) && 'woocommerce_toggle_gateway_enabled' === sanitize_text_field(wp_unslash( $_POST['action']))) {
                $gatewayId = isset( $_POST['gateway_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway_id'] ) ) : false;
                $gatewaySettings = [
                    wc_onpay_gateway_anyday::WC_ONPAY_GATEWAY_ANYDAY_ID => self::SETTING_ONPAY_EXTRA_PAYMENTS_ANYDAY,
                    wc_onpay_gateway_card::WC_ONPAY_GATEWAY_CARD_ID => self::SETTING_ONPAY_EXTRA_PAYMENTS_CARD,
                    wc_onpay_gateway_mobilepay::WC_ONPAY_GATEWAY_MOBILEPAY_ID => self::SETTING_ONPAY_EXTRA_PAYMENTS_MOBILEPAY,
                    wc_onpay_gateway_applepay::WC_ONPAY_GATEWAY_APPLEPAY_ID => self::SETTING_ONPAY_EXTRA_PAYMENTS_APPLEPAY,
                    wc_onpay_gateway_googlepay::WC_ONPAY_GATEWAY_GOOGLEPAY_ID => self::SETTING_ONPAY_EXTRA_PAYMENTS_GOOGLEPAY,
                    wc_onpay_gateway_viabill::WC_ONPAY_GATEWAY_VIABILL_ID => self::SETTING_ONPAY_EXTRA_PAYMENTS_VIABILL,
                    wc_onpay_gateway_vipps::WC_ONPAY_GATEWAY_VIPPS_ID => self::SETTING_ONPAY_EXTRA_PAYMENTS_VIPPS,
                    wc_onpay_gateway_swish::WC_ONPAY_GATEWAY_SWISH_ID => self::SETTING_ONPAY_EXTRA_PAYMENTS_SWISH,
                    wc_onpay_gateway_paypal::WC_ONPAY_GATEWAY_PAYPAL_ID => self::SETTING_ONPAY_EXTRA_PAYMENTS_PAYPAL,
                    wc_onpay_gateway_klarna::WC_ONPAY_GATEWAY_KLARNA_ID => self::SETTING_ONPAY_EXTRA_PAYMENTS_KLARNA,
                ];
                if (in_array($gatewayId, $this->getGateways()) && array_key_exists($gatewayId, $gatewaySettings)) {
                    $enabled = false;
                    if ($this->get_option($gatewaySettings[$gatewayId]) !== 'yes') {
                        $this->update_option($gatewaySettings[$gatewayId], 'yes');
                        $enabled = true;
                    } else {
                        $this->update_option($gatewaySettings[$gatewayId], 'no');
                    }
                    die(wp_json_encode([
                        'success' => true,
                        'data' => $enabled,
                    ]));
                }
            }
        }


        /**
         * Method that fires when orders change status to completed
         */
        public function orderStatusCompleteEvent($orderId) {
            $order = new WC_Order($orderId);
            $transactionId = $this->getOnpayId($order);
            // Check if order payment method is OnPay
            if ($this->isOnPayMethod($order->get_payment_method()) && null !== $transactionId) {
                // If autocapture is not enabled, no need to do anything
                if($this->get_option(WC_OnPay::SETTING_ONPAY_STATUS_AUTOCAPTURE) === 'yes') {
                    try {
                        $transaction = $this->get_onpay_client()->transaction()->getTransaction($transactionId);
                        $availableAmount = $this->getAvailableAmount($order, $transaction);
                        // If transaction has status active, and charged amount is less than the full amount, we'll capture the remaining amount on transaction
                        if ($transaction->status === 'active' && $availableAmount > 0) {
                            $this->get_onpay_client()->transaction()->captureTransaction($transaction->uuid, $availableAmount);
                            $order->add_order_note( __( 'Status changed to completed. Amount was automatically captured on transaction in OnPay.', 'wc-onpay' ));
                        }
                    } catch (OnPay\API\Exception\ConnectionException $exception) { // No connection to OnPay API
                        $order->add_order_note(__('Automatic capture failed.') . ' ' . __('No connection to OnPay', 'wc-onpay'));
                    } catch (OnPay\API\Exception\TokenException $exception) { // Something's wrong with the token, print link to reauth
                        $order->add_order_note(__( 'Automatic capture failed.') . ' ' . __('Invalid OnPay token, please login on settings page', 'wc-onpay' ));
                    }
                }
            } 
        }

        /**
         * Function that handles refund event
         */
        public function refundEvent($order_id, $refund_id) {
            // Only perform refund if setting is enabled
            if ($this->get_option(WC_OnPay::SETTING_ONPAY_REFUND_INTEGRATION) === 'yes') {
                $order = wc_get_order($order_id);
                $transactionId = $this->getOnpayId($order);
                if ($this->isOnPayMethod($order->get_payment_method()) && null !== $transactionId) {
                    // Get the transaction from API
                    $transaction = $this->get_onpay_client()->transaction()->getTransaction($transactionId);
                    // Get refund data
                    $refund = new WC_Order_Refund($refund_id);
                    // Get amount as minor units
                    $currencyHelper = new wc_onpay_currency_helper();
                    $amount = $currencyHelper->majorToMinor($refund->data['amount'], $transaction->currencyCode, '.');
                    $refundableAmount = $transaction->charged - $transaction->refunded;
                    // Check if amount is lower than amount available for refund.
                    if ($amount <= $refundableAmount) {
                        $this->get_onpay_client()->transaction()->refundTransaction($transaction->uuid, $amount);
                        $order->add_order_note( __( 'Amount automatically refunded on transaction in OnPay.', 'wc-onpay' ));
                        $this->addAdminNotice(__( 'Amount refunded on transaction in OnPay.', 'wc-onpay' ), 'success');
                    } else {
                        $order->add_order_note( __( 'Unable to automatically refund on transaction in OnPay.', 'wc-onpay' ));
                        $this->addAdminNotice(__( 'Unable to refund on transaction in OnPay.', 'wc-onpay' ), 'success');
                    }
                }
            }
        }

        /**
         * Method for setting meta boxes in admin
         */
        public function meta_boxes() {
            // If in admin
            if (is_admin()) {
                // Get order from WooCommerce function
                $order = wc_get_order();
                // Determine that we have an order
                if ($order instanceof WC_Order && !$order instanceof WC_Subscription && $this->isOnPayMethod($order->get_payment_method())) {
                    add_meta_box('mv_other_fields', __('OnPay.io', 'wc-onpay'), [$this,'order_meta_box'], null, 'advanced', 'high', ['order' => $order]);
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
            $transactionId = $this->getOnpayId($order);
            $html = '';

            // If order is pending, no need to find the transaction.
            if (null === $transactionId || $order->has_status('pending')) {
                echo __('Pending payment', 'wc-onpay');
            } else {
                try {
                    $transaction = $onpayApi->transaction()->getTransaction($transactionId);
                } catch (OnPay\API\Exception\ApiException $exception) {
                    echo __('Error: ', 'wc-onpay') . $exception->getMessage();
                    exit;
                }

                $currencyHelper = new wc_onpay_currency_helper();
                $currency = $currencyHelper->fromNumeric($transaction->currencyCode);

                $amount = $currencyHelper->minorToMajor($transaction->amount, $currency->numeric);
                $availableAmount = $currencyHelper->minorToMajor($this->getAvailableAmount($order, $transaction), $currency->numeric);
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

                $html .= '<tr><td colspan="2">';
                $html .= '<a href="' . $this->getOnPayManageLink($transaction->uuid, 'transaction') . '" target="_blank" class="button button-small button-secondary" id="button_onpay_manage_link">' . __('View transaction in OnPay', 'wc-onpay') . '</a>';
                $html .= '</td></tr>';

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
                   $history->dateTime->setTimeZone(wp_timezone());
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

                $html .= '<hr />';

                // Add buttons for handling transaction
                $html .= '<div id="onpay_action_buttons">';
                $buttonsShown = false;
                if ($transaction->charged < $transaction->amount && $transaction->status === 'active') {
                    $html .= '<button class="button-primary" id="button_onpay_capture_reveal">' . __('Capture', 'wc-onpay') . '</button>&nbsp;';
                    wc_enqueue_js('$("#button_onpay_capture_reveal").on("click", function(event) {event.preventDefault(); $("#onpay_action_capture").slideDown(); $("#onpay_action_buttons").slideUp(); })');
                    $buttonsShown = true;
                }

                // Show refund button if transaction is refundable, and refund integration setting is disabled.
                if (0 < $transaction->charged && $transaction->refunded < $transaction->charged) {
                    $html .= '<button class="button-secondary" id="button_onpay_refund_reveal">' . __('Refund in OnPay', 'wc-onpay') . '</button>&nbsp;';
                    wc_enqueue_js('$("#button_onpay_refund_reveal").on("click", function(event) {event.preventDefault(); $("#onpay_action_refund").slideDown(); $("#onpay_action_buttons").slideUp(); })');
                    $buttonsShown = true;
                }

                if ($transaction->status === 'active') {
                    $html .= '<button class="button-secondary" id="button_onpay_cancel_reveal">' . ($transaction->charged === 0 ? __('Cancel transaction', 'wc-onpay') : __('Finish transaction', 'wc-onpay')) . '</button>&nbsp;';
                    wc_enqueue_js('$("#button_onpay_cancel_reveal").on("click", function(event) {event.preventDefault(); $("#onpay_action_cancel").slideDown(); $("#onpay_action_buttons").slideUp(); })');
                    $buttonsShown = true;
                }
                if ($buttonsShown) {
                    $html .= '<p style="color: #888; font-size: .92em; margin-bottom: 0;">' . __('The buttons above only affect the transaction in OnPay, and do not update the order here in WooCommerce.', 'wc-onpay') . '</p>';
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
         * Method that handles postback of OnPay order meta box
         */
        public function handle_order_meta_box($postId) {
            // Get Order from post id provided
            $order = wc_get_order($postId);
            $transactionId = $this->getOnpayId($order);

            // Determine that we have an order and the required data for getting transaction is available.
            // We're not interested in handling on subscriptions here
            if ($order instanceof WC_Order && !$order instanceof WC_Subscription && $this->isOnPayMethod($order->get_payment_method()) && null !== $transactionId) {
                // Get the transaction from API
                $transaction = $this->get_onpay_client()->transaction()->getTransaction($transactionId);
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

        /**
         * Hook that handles renewal of subscriptions. Creating transactions from subscriptions in OnPay.
         */
        public function subscriptionPayment($amountToCharge, $newOrder) {
            // Get subscription order
            $subscriptionOrder = new WC_Subscription($newOrder->get_meta('_subscription_renewal'));
            $subscriptionId = $this->getOnpayId($subscriptionOrder);

            // Create transaction from subscription in OnPay.
            $currencyHelper = new wc_onpay_currency_helper();
            $orderCurrency = $currencyHelper->fromAlpha3($newOrder->get_currency());
            $orderAmount = $currencyHelper->majorToMinor($newOrder->get_total(), $orderCurrency->numeric, '.');
            $onpaySubscription = $this->get_onpay_client()->subscription()->getSubscription($subscriptionId);

            // Subscription no longer active.
            if ($onpaySubscription->status !== 'active') {
                $subscriptionOrder->update_status(__('expired', 'Subscription no longer active in OnPay.', 'wc-onpay'));
                $newOrder->update_status('failed', __('Subscription no longer active in OnPay.', 'wc-onpay'));
                return;
            }

            try {
                $createdOnpayTransaction = $this->get_onpay_client()->subscription()->createTransactionFromSubscription($onpaySubscription->uuid, $orderAmount, strval($newOrder->get_order_number()));
            } catch (WoocommerceOnpay\OnPay\API\Exception\ApiException $exception) {
                $subscriptionOrder->add_order_note(__('Authorizing new transaction failed.', 'wc-onpay'));
                $newOrder->update_status('failed', __('Authorizing new transaction failed.', 'wc-onpay'));
                return;
            }

            $onpayNumber = $createdOnpayTransaction->transactionNumber;

            // Finalize order
            $newOrder->add_order_note(__('Transaction authorized in OnPay. Remember to capture amount.', 'wc-onpay'));
            $newOrder->payment_complete($onpayNumber);
            $this->setOnpayId($newOrder, $onpayNumber);
            $newOrder->add_meta_data($this::WC_ONPAY_ID . '_test_mode', wc_onpay_query_helper::get_query_value('onpay_testmode'));
            $newOrder->save_meta_data();
        }

        /**
         * Hook that handles cancellation of subscriptions. Cancelling subscriptions in OnPay.
         */
        public function subscriptionCancellation($subscriptionOrder) {
            $cancelledSubscription = $this->cancelOnpaySubscription($subscriptionOrder);
            if ($cancelledSubscription->status !== 'cancelled') {
                $subscriptionOrder->add_order_note(__('An error occured cancelling subscription in OnPay.', 'wc-onpay'));
            } else{
                $subscriptionOrder->add_order_note(__('Subscription cancelled in OnPay.', 'wc-onpay'));
            }
        }

        private function cancelOnpaySubscription($subscriptionOrder) {
            $subscriptionId = $this->getOnpayId($subscriptionOrder);
            // No need to do anything is no transaction ID is currently assigned.
            if (null === $subscriptionId) {
                return;
            }

            // No need to do anything if no subscription is found by current Transaction ID in OnPay.
            $onpaySubscription = $this->get_onpay_client()->subscription()->getSubscription($subscriptionId);

            if ($onpaySubscription->status === 'cancelled') {
                return;
            }
            return $this->get_onpay_client()->subscription()->cancelSubscription($onpaySubscription->uuid);
        }

        /**
         * Returns whether High Performance Order Storage is enabled in WooCommerce
         */
        function isHposEnabled() {
            if (function_exists('wc_get_container')) {
                $cotc = wc_get_container()->get(Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class);
                return $cotc->custom_orders_table_usage_is_enabled();
            }
            return false;
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
            if (in_array($paymentMethod, $this->getGateways())) {
                return true;
            }
            return false;
        }

        private function getGateways() {
            return [
                wc_onpay_gateway_card::WC_ONPAY_GATEWAY_CARD_ID,
                wc_onpay_gateway_mobilepay::WC_ONPAY_GATEWAY_MOBILEPAY_ID,
                wc_onpay_gateway_applepay::WC_ONPAY_GATEWAY_APPLEPAY_ID,
                wc_onpay_gateway_googlepay::WC_ONPAY_GATEWAY_GOOGLEPAY_ID,
                wc_onpay_gateway_viabill::WC_ONPAY_GATEWAY_VIABILL_ID,
                wc_onpay_gateway_anyday::WC_ONPAY_GATEWAY_ANYDAY_ID,
                wc_onpay_gateway_vipps::WC_ONPAY_GATEWAY_VIPPS_ID,
                wc_onpay_gateway_swish::WC_ONPAY_GATEWAY_SWISH_ID,
                wc_onpay_gateway_paypal::WC_ONPAY_GATEWAY_PAYPAL_ID,
                wc_onpay_gateway_klarna::WC_ONPAY_GATEWAY_KLARNA_ID,
            ];
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
                $params['tab'] = self::WC_ONPAY_ID;
            }
            $url = wc_onpay_query_helper::generate_url($params);
            $onPayAPI = new \OnPay\OnPayAPI($tokenStorage, [
                'client_id' => 'Onpay WooCommerce',
                'redirect_uri' => $url,
                'platform' => self::WC_ONPAY_PLATFORM_STRING,
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
                    $this->update_option(self::SETTING_ONPAY_REFUND_INTEGRATION, 'yes');
                }
                wp_redirect(wc_onpay_query_helper::generate_url(['page' => 'wc-settings','tab' => self::WC_ONPAY_ID]));
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
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_APPLEPAY, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_GOOGLEPAY, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_VIABILL, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_ANYDAY, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_VIPPS, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_SWISH, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_PAYPAL, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_KLARNA, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_CARD, null);
                $this->update_option(self::SETTING_ONPAY_PAYMENTWINDOW_DESIGN, null);
                $this->update_option(self::SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE, null);
                $this->update_option(self::SETTING_ONPAY_TESTMODE, null);
                $this->update_option(self::SETTING_ONPAY_CARDLOGOS, null);
                $this->update_option(self::SETTING_ONPAY_STATUS_AUTOCAPTURE, null);
                $this->update_option(self::SETTING_ONPAY_REFUND_INTEGRATION, null);

                wp_redirect(wc_onpay_query_helper::generate_url(['page' => 'wc-settings','tab' => self::WC_ONPAY_ID]));
                exit;
            }
        }

        private function handle_refresh() {
            $onpayApi = $this->get_onpay_client(true);
            if(null !== wc_onpay_query_helper::get_query_value('refresh') && $onpayApi->isAuthorized()) {
                $this->update_option(self::SETTING_ONPAY_GATEWAY_ID, $onpayApi->gateway()->getInformation()->gatewayId);
                $this->update_option(self::SETTING_ONPAY_SECRET, $onpayApi->gateway()->getPaymentWindowIntegrationSettings()->secret);

                $this->addAdminNotice(__('Gateway ID and secret was refreshed', 'wc-onpay'), 'info');

                wp_redirect(wc_onpay_query_helper::generate_url(['page' => 'wc-settings','tab' => self::WC_ONPAY_ID]));
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
                'is' => __('Icelandic', 'wc-onpay'),
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

        private function setOnpayId(WC_Order $wcOrder, string$transactionId) {
            $key = '_onpay_id';
            if ($wcOrder->meta_exists($key)) {
                $wcOrder->update_meta_data($key, $transactionId);
            } else {
                $wcOrder->add_meta_data($key, $transactionId);
            }
            $wcOrder->set_transaction_id($transactionId);
            $wcOrder->save_meta_data();
        }

        private function getOnpayId(WC_Order $wcOrder) {
            // Attempt getting transaction id using our own meta value
            $transaction_id = $wcOrder->get_meta('_onpay_id');
		    if (empty($transaction_id)) {
                // Failing getting by our value, check for generic transaction_id
                $transaction_id = $wcOrder->get_transaction_id();
            }
            if ($transaction_id === '') {
                return null;
            }
            return $transaction_id;
        }

        /**
         * Returns available amount based on order and transaction
         */
        private function getAvailableAmount($order, $transaction) {
            $currencyHelper = new wc_onpay_currency_helper();
            
            $orderCurrency = $currencyHelper->fromAlpha3($order->get_currency());
            $orderRefunded = $currencyHelper->majorToMinor($order->get_total_refunded(), $orderCurrency->numeric, '.');

            // If order amount is lower than transaction amount, we'll roll with that instead
            $orderTotal = $currencyHelper->majorToMinor($order->get_total(), $orderCurrency->numeric, '.');
            $total = $transaction->amount;
            if($orderTotal < $total) {
                $total = $orderTotal;
            }

            if ($this->get_option(WC_OnPay::SETTING_ONPAY_REFUND_INTEGRATION) === 'yes') {
                $availableAmount = $total - $transaction->charged - $orderRefunded;
            } else {
                $availableAmount = $total - $transaction->charged;
            }

            if ($availableAmount < 0) {
                $availableAmount = 0;
            }

            return $availableAmount;
        }

        /**
         * Prints a json response
         */
        private function json_response($message, $error = false, $responseCode = 200) {
            header('Content-Type: application/json', true, $responseCode);
            $response = [];
            if (!$error) {
                $response = ['success' => $message, 'error' => false];
            } else {
                $response = ['error' => $message];
            }
            die(wp_json_encode($response));
        }

	private function getOnboardingHtml($authUrl) {
            $html = '<div style="border-radius: .25rem; text-align: center; background-color: #ffffff; box-shadow: 0 15px 35px rgba(50,50,93,.1), 0 5px 15px rgba(0,0,0,.07); -webkit-box-shadow: 0 15px 35px rgba(50,50,93,.1), 0 5px 15px rgba(0,0,0,.07); padding: 1.25rem; max-width: 500px; min-height: 200px; display: flex; flex-direction: column; justify-content: space-between;">';
            $html .= '<a href="' . $authUrl . '" style="background-color: #fb617f; color: #fff; border-color: #fb617f; font-weight: bold; padding: .75rem; font-size: 1rem; line-height: 1.5; border-radius: .25rem; text-decoration: none;">' . __('Log in with OnPay account', 'wc-onpay') . '</a>';
            $html .= '<hr style="border-top: 1px solid rgba(0,0,0,.1); width: 100%; margin: 20px 0 20px 0;">';
            $html .= '<h3 style="margin: 0 0 15px 0;">' . __('Don\'t have an OnPay account yet?', 'wc-onpay') . '</h3>';
            $html .= '<span style="margin-bottom: auto;">' . __('Order one through DanDomain from DKK 0,- per month.', 'wc-onpay') . '</span>';
            $html .= '<div style="display: flex;flex-wrap: wrap;align-content: space-between;">';
            $html .= '<a href="https://dandomain.dk/betalingssystem/priser" style="margin: auto; width: 43%; background-color: #fb617f; color: #fff; border-color: #fb617f; font-weight: bold; padding: .375rem .75rem; line-height: 1.5; border-radius: .25rem; text-decoration: none;" target="_blank">' . __('Get OnPay now', 'wc-onpay') . '</a>';
            $html .= '<a href="https://onpay.io/#brands" style="margin: auto; width: 43%; background-color: #fff; color: #fb617f; border: 1px solid #fb617f; font-weight: bold; padding: .375rem .75rem; line-height: 1.5; border-radius: .25rem; text-decoration: none;" target="_blank">' . __('OnPay sellers', 'wc-onpay') . '</a>';
            $html .= '</div></div>';

    	    return $html;
	    }

        private function getOnPayManageLink($uuid, $type) {
            $url = 'https://manage.onpay.io/';
            $url .= $this->get_option(self::SETTING_ONPAY_GATEWAY_ID) . '/';
            if ('transaction' === $type) {
                $url .= 'transactions/';
            } else if ('subscription' === $type) {
                $url .= 'subscriptions/';
            }
            $url .= 'view/' . $uuid;
            return $url;
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

        /**
         * Plugin url.
         *
         * @return string
         */
        public static function plugin_url() {
            return untrailingslashit( plugins_url( '/', __FILE__ ) );
        }

        /**
         * Plugin url.
         *
         * @return string
         */
        public static function plugin_abspath() {
            return trailingslashit( plugin_dir_path( __FILE__ ) );
        }
    }

    // Add OnPay as payment method to WooCommerce
    add_filter( 'woocommerce_payment_gateways', 'wc_onpay_add_to_woocommerce' );
    function wc_onpay_add_to_woocommerce($methods) {
        $methods[] = 'wc_onpay_gateway_card';
        $methods[] = 'wc_onpay_gateway_mobilepay';
        $methods[] = 'wc_onpay_gateway_applepay';
        $methods[] = 'wc_onpay_gateway_googlepay';
        $methods[] = 'wc_onpay_gateway_viabill';
        $methods[] = 'wc_onpay_gateway_anyday';
        $methods[] = 'wc_onpay_gateway_vipps';
        $methods[] = 'wc_onpay_gateway_swish';
        $methods[] = 'wc_onpay_gateway_paypal';
        $methods[] = 'wc_onpay_gateway_klarna';

        return $methods;
    }

    // Register methods as block layout available
    add_action( 'woocommerce_blocks_loaded', 'wc_onpay_blocks_methods' );
    function wc_onpay_blocks_methods() {
        if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType') ) {
			require_once 'classes/blocks/gateway-card-block.php';
			require_once 'classes/blocks/gateway-anyday-block.php';
            require_once 'classes/blocks/gateway-applepay-block.php';
            require_once 'classes/blocks/gateway-googlepay-block.php';
			require_once 'classes/blocks/gateway-mobilepay-block.php';
			require_once 'classes/blocks/gateway-paypal-block.php';
			require_once 'classes/blocks/gateway-swish-block.php';
			require_once 'classes/blocks/gateway-viabill-block.php';
			require_once 'classes/blocks/gateway-vipps-block.php';
			require_once 'classes/blocks/gateway-klarna-block.php';
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function(Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
					$payment_method_registry->register(new wc_onpay_gateway_card_block());
					$payment_method_registry->register(new wc_onpay_gateway_anyday_block());
					$payment_method_registry->register(new wc_onpay_gateway_applepay_block());
					$payment_method_registry->register(new wc_onpay_gateway_googlepay_block());
					$payment_method_registry->register(new wc_onpay_gateway_mobilepay_block());
					$payment_method_registry->register(new wc_onpay_gateway_paypal_block());
					$payment_method_registry->register(new wc_onpay_gateway_swish_block());
					$payment_method_registry->register(new wc_onpay_gateway_viabill_block());
					$payment_method_registry->register(new wc_onpay_gateway_vipps_block());
					$payment_method_registry->register(new wc_onpay_gateway_klarna_block());
				}
			);
		}
    }
    
    // Add action links to OnPay plugin on plugin overview
    add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_onpay_action_links' );
    function wc_onpay_action_links($links) {
        $plugin_links = [
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=wc_onpay') . '">' . __('Settings', 'wc-onpay') . '</a>',
        ];
        return array_merge( $plugin_links, $links );
    }

    // Add tab in woocommerce settings for OnPay
    add_filter('woocommerce_settings_tabs_array', 'add_settings_tab', 50);
    function add_settings_tab( $settings_tabs ) {
        $settings_tabs['wc_onpay'] = __( 'OnPay.io', 'wc_onpay' );
        return $settings_tabs;
    }

	// Initialize
    WC_OnPay::get_instance()->init_hooks();
}
?>
