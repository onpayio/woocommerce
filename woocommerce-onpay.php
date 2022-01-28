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
* Version: 1.0.20
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
    include_once __DIR__ . '/classes/gateway-vipps.php';

    class WC_OnPay extends WC_Payment_Gateway {
        const PLUGIN_VERSION = '1.0.20';

        const SETTING_ONPAY_GATEWAY_ID = 'gateway_id';
        const SETTING_ONPAY_SECRET = 'secret';
        const SETTING_ONPAY_EXTRA_PAYMENTS_MOBILEPAY = 'extra_payments_mobilepay';
        const SETTING_ONPAY_EXTRA_PAYMENTS_VIABILL = 'extra_payments_viabill';
        const SETTING_ONPAY_EXTRA_PAYMENTS_ANYDAY = 'extra_payments_anyday_split';
        const SETTING_ONPAY_EXTRA_PAYMENTS_VIPPS = 'extra_payments_vipps';
        const SETTING_ONPAY_EXTRA_PAYMENTS_CARD = 'extra_payments_card';
        const SETTING_ONPAY_PAYMENTWINDOW_DESIGN = 'paymentwindow_design';
        const SETTING_ONPAY_PAYMENTWINDOW_LANGUAGE = 'paymentwindow_language';
        const SETTING_ONPAY_TESTMODE = 'testmode_enabled';
        const SETTING_ONPAY_CARDLOGOS = 'card_logos';
        const SETTING_ONPAY_STATUS_AUTOCAPTURE = 'status_autocapture';
        const SETTING_ONPAY_REFUND_INTEGRATION = 'refund_integration';

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
            add_filter('woocommerce_settings_'. $this->id, [$this, 'admin_options']);
            add_action('woocommerce_settings_save_'. $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_api_'. $this->id . '_callback', [$this, 'callback']);
            add_action('woocommerce_before_checkout_form', [$this, 'declinedReturnMessage']);
            add_action('woocommerce_thankyou', [$this, 'completedPage']);
            add_action('woocommerce_order_status_completed', [$this, 'orderStatusCompleteEvent']);
            add_action('woocommerce_scheduled_subscription_payment_onpay_card', [$this, 'subscriptionPayment'], 1, 2);
            add_action('woocommerce_subscription_cancelled_onpay_card', [$this, 'subscriptionCancellation']);
            add_action('woocommerce_order_refunded', [$this, 'refundEvent'], 10, 2);
            add_action('admin_init', [$this, 'gateway_toggle']);
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
            // Validate query parameters from OnPay
            $paymentWindow = new \OnPay\API\PaymentWindow();
            $paymentWindow->setSecret($this->get_option(self::SETTING_ONPAY_SECRET));
            if (!$paymentWindow->validatePayment(wc_onpay_query_helper::get_query())) {
                $this->json_response('Invalid values', true, 400);
            }

            // Find order
            $order = $this->findOrder();
            if (false === $order) {
                $this->json_response('Order not found', true, 400);
            }

            // Is order in pending state, otherwise we don't care.
            if ($order->has_status('pending')) {
                $type = wc_onpay_query_helper::get_query_value('onpay_type');

                // If we're dealing with a subscription
                if ($type === 'subscription') {
                    $orderItems = $order->get_items();
                    // If order is subscription and has more than 1 item, we'll split the remaining items in a new order, and complete the current order with only the subscription.
                    if(count($orderItems) > 1) {
                        $newOrder = $this->cloneOrder($order);
                        $subscriptionFound = false;
                        foreach($orderItems as $itemId => $orderItem) {
                            if (!$subscriptionFound && WC_Subscriptions_Product::is_subscription(wcs_get_canonical_product_id($orderItem))) {
                                $subscriptionFound = true;
                                continue;
                            } else {
                                $order->remove_item($itemId);
                                $newOrder->add_item($orderItem);
                            }
                        }
                        $order->calculate_totals();
                        $order->calculate_shipping();
                        $order->recalculate_coupons();
                        $order->save();
                    }

                    // If a new order is created, add note and relation to original order, and save the new order.
                    if (isset($newOrder)) {
                        $newOrder->add_order_note( __( 'Order split from: ' . $order->get_id(), 'wc-onpay' ));
                        $newOrder->calculate_totals();
                        $newOrder->calculate_shipping();
                        $newOrder->recalculate_coupons();
                        $newOrder->save();

                        $order->add_meta_data($this::WC_ONPAY_ID . '_order_split', $newOrder->get_id());
                        $order->save_meta_data();
                    }
                }

                $onpayNumber = wc_onpay_query_helper::get_query_value('onpay_number');

                // If we're dealing with a subscription
                if ($type === 'subscription') {
                    $order->add_order_note(__( 'Subscription authorized in OnPay.', 'wc-onpay' ));

                    // Write subscription id to subscription order for later reference. 
                    $wcSubscriptions = wcs_get_subscriptions_for_order($order->get_id());
                    foreach ($wcSubscriptions as $id => $subscription) {
                        $subscriptionOrder = new WC_order($id);
                        $subscriptionOrder->set_transaction_id($onpayNumber);
                        $subscriptionOrder->save();
                    }
                    
                    // Create the initial transaction from subscription
                    $currencyHelper = new wc_onpay_currency_helper();
                    $orderCurrency = $currencyHelper->fromAlpha3($order->get_currency());
                    $orderAmount = $currencyHelper->majorToMinor($order->get_total(), $orderCurrency->numeric, '.');
                    $onpaySubscription = $this->get_onpay_client()->subscription()->getSubscription($onpayNumber);
                    $createdTransaction = $this->get_onpay_client()->subscription()->createTransactionFromSubscription($onpaySubscription->uuid, $orderAmount, strval($order->get_id()));

                    // Set onpayNumber to value from newly created transaction
                    $onpayNumber = $createdTransaction->transactionNumber;
                }

                $order->add_order_note(__( 'Transaction authorized in OnPay. Remember to capture amount.', 'wc-onpay' ));
                $order->payment_complete($onpayNumber);
                $order->add_meta_data($this::WC_ONPAY_ID . '_test_mode', wc_onpay_query_helper::get_query_value('onpay_testmode'));
                $order->save_meta_data();
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

        // Clones existing order as a new order.
        private function cloneOrder($order) {
            $newOrder = wc_create_order([
                'customer_id' => $order->get_customer_id(),
                'customer_note' => $order->get_customer_note(),
                'created_via' => $order->get_created_via(),
            ]);
            $newOrder->set_currency($order->get_currency());

            $newOrder->set_billing_first_name($order->get_billing_first_name());
            $newOrder->set_billing_last_name($order->get_billing_last_name());
            $newOrder->set_billing_company($order->get_billing_company());
            $newOrder->set_billing_address_1($order->get_billing_address_1());
            $newOrder->set_billing_address_2($order->get_billing_address_2());
            $newOrder->set_billing_city($order->get_billing_city());
            $newOrder->set_billing_state($order->get_billing_state());
            $newOrder->set_billing_postcode($order->get_billing_postcode());
            $newOrder->set_billing_country($order->get_billing_country());
            $newOrder->set_billing_email($order->get_billing_email());
            $newOrder->set_billing_phone($order->get_billing_phone());

            $newOrder->set_shipping_first_name($order->get_shipping_first_name());
            $newOrder->set_shipping_last_name($order->get_shipping_last_name());
            $newOrder->set_shipping_company($order->get_shipping_company());
            $newOrder->set_shipping_address_1($order->get_shipping_address_1());
            $newOrder->set_shipping_address_2($order->get_shipping_address_2());
            $newOrder->set_shipping_city($order->get_shipping_city());
            $newOrder->set_shipping_state($order->get_shipping_state());
            $newOrder->set_shipping_postcode($order->get_shipping_postcode());
            $newOrder->set_shipping_country($order->get_shipping_country());

            return $newOrder;
        }

        /**
         * Hook for order complete page
         */
        public function completedPage($orderId) {
            $type = wc_onpay_query_helper::get_query_value('onpay_type');
            if ($type === 'subscription') {
                $order = new WC_Order($orderId);
                // More than 1 item, or order is split into a new order
                if (count($order->get_items()) > 1 || $order->meta_exists($this::WC_ONPAY_ID . '_order_split')) {
                    $waitCount = 0;
                    // If no split id is found on order, we'll try and wait for up to 5 seconds for it to appear.
                    while (!$order->meta_exists($this::WC_ONPAY_ID . '_order_split') && $waitCount < 5) {
                        sleep(1);
                        $order->read_meta_data(true);
                        $waitCount++;
                    }

                    $order->read_meta_data(true);
                    $splitOrderId = $order->get_meta($this::WC_ONPAY_ID . '_order_split');
                    if ($splitOrderId !== '') {
                        echo $splitOrderId;
                        $splitOrder = new WC_Order($splitOrderId);
                        $orderActions = wc_get_account_orders_actions($splitOrder);
                        if (array_key_exists('pay', $orderActions)) {
                            wc_add_notice(__( 'Subscription added, please continue with payment for the rest of the order.', 'wc-onpay' ));
                            wp_redirect($orderActions['pay']['url']);
                            return;
                        }
                    }

                    // No split order id found, we'll show the orders overview.
                    wc_add_notice(__( 'Subscription added, please continue with the rest of the order from the list below.', 'wc-onpay' ));
                    wp_redirect(site_url('/my-account/orders/'));
                }
            }
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
                '_payment_methods'=> [
					'type'  => 'title',
					'title' => __('Payment methods', 'wc-onpay'),
				],
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
                self::SETTING_ONPAY_EXTRA_PAYMENTS_VIPPS => [
                    'title' => __('Vipps', 'wc-onpay'),
                    'label' => __('Enable Vipps as payment method', 'wc-onpay'),
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

                '_payment_window'=> [
					'type'  => 'title',
					'title' => __('Payment window', 'wc-onpay'),
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

                '_backoffice'=> [
					'type'  => 'title',
					'title' => __('Backoffice settings', 'wc-onpay'),
				],
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
                $html .= '<h3>' . __('Gateway information', 'wc-onpay') . '</h3>';
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
         * Allows toggling of gateways from payment gateways overview
         */
        public function gateway_toggle() {
            if (isset( $_POST['action'] ) && 'woocommerce_toggle_gateway_enabled' === sanitize_text_field(wp_unslash( $_POST['action']))) {
                $gatewayId = isset( $_POST['gateway_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway_id'] ) ) : false;
                $gatewaySettings = [
                    wc_onpay_gateway_anyday::WC_ONPAY_GATEWAY_ANYDAY_ID => self::SETTING_ONPAY_EXTRA_PAYMENTS_ANYDAY,
                    wc_onpay_gateway_card::WC_ONPAY_GATEWAY_CARD_ID => self::SETTING_ONPAY_EXTRA_PAYMENTS_CARD,
                    wc_onpay_gateway_mobilepay::WC_ONPAY_GATEWAY_MOBILEPAY_ID => self::SETTING_ONPAY_EXTRA_PAYMENTS_MOBILEPAY,
                    wc_onpay_gateway_viabill::WC_ONPAY_GATEWAY_VIABILL_ID => self::SETTING_ONPAY_EXTRA_PAYMENTS_VIABILL,
                    wc_onpay_gateway_vipps::WC_ONPAY_GATEWAY_VIPPS_ID => self::SETTING_ONPAY_EXTRA_PAYMENTS_VIPPS,
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
         * Method that fires when orders change status to completed
         */
        public function orderStatusCompleteEvent($orderId) {
            $order = new WC_Order($orderId);
            // Check if order payment method is OnPay
            if ($this->isOnPayMethod($order->get_payment_method()) && null !== $order->get_transaction_id() && $order->get_transaction_id() !== '') {
                // If autocapture is not enabled, no need to do anything
                if($this->get_option(WC_OnPay::SETTING_ONPAY_STATUS_AUTOCAPTURE) === 'yes') {
                    try {
                        $transaction = $this->get_onpay_client()->transaction()->getTransaction($order->get_transaction_id());
                        $availableAmount = $this->getAvailableAmount($order, $transaction);
                        // If transaction has status active, and charged amount is less than the full amount, we'll capture the remaining amount on transaction
                        if ($transaction->status === 'active' && $availableAmount > 0) {
                            $this->get_onpay_client()->transaction()->captureTransaction($transaction->uuid);
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
         * Function that handles refund event
         */
        public function refundEvent($order_id, $refund_id) {
            // Only perform refund if setting is enabled
            if ($this->get_option(WC_OnPay::SETTING_ONPAY_REFUND_INTEGRATION) === 'yes') {
                $order = wc_get_order($order_id);
                if ($this->isOnPayMethod($order->get_payment_method()) && null !== $order->get_transaction_id() && $order->get_transaction_id() !== '') {
                    // Get the transaction from API
                    $transaction = $this->get_onpay_client()->transaction()->getTransaction($order->get_transaction_id());
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
         * Hook that handles renewal of subscriptions. Creating transactions from subscriptions in OnPay.
         */
        public function subscriptionPayment($amountToCharge, $newOrder) {
            // Get subscription order
            $subscriptionOrder = new WC_Order($newOrder->get_meta('_subscription_renewal'));

            // Create transaction from subscription in OnPay.
            $currencyHelper = new wc_onpay_currency_helper();
            $orderCurrency = $currencyHelper->fromAlpha3($newOrder->get_currency());
            $orderAmount = $currencyHelper->majorToMinor($newOrder->get_total(), $orderCurrency->numeric, '.');
            $onpaySubscription = $this->get_onpay_client()->subscription()->getSubscription($subscriptionOrder->get_transaction_id());

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
            $newOrder->add_meta_data($this::WC_ONPAY_ID . '_test_mode', wc_onpay_query_helper::get_query_value('onpay_testmode'));
            $newOrder->save_meta_data();
        }

        /**
         * Hook that handles cancellation of subscriptions. Cancelling subscriptions in OnPay.
         */
        public function subscriptionCancellation($subscriptionOrder) {
            $onpaySubscription = $this->get_onpay_client()->subscription()->getSubscription($subscriptionOrder->get_transaction_id());
            $cancelledSubscription = $this->get_onpay_client()->subscription()->cancelSubscription($onpaySubscription->uuid);
            if ($cancelledSubscription->status !== 'cancelled') {
                $subscriptionOrder->add_order_note(__('An error occured cancelling subscription in OnPay.', 'wc-onpay'));
            } else{
                $subscriptionOrder->add_order_note(__('Subscription cancelled in OnPay.', 'wc-onpay'));
            }
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
                wc_onpay_gateway_viabill::WC_ONPAY_GATEWAY_VIABILL_ID,
                wc_onpay_gateway_anyday::WC_ONPAY_GATEWAY_ANYDAY_ID,
                wc_onpay_gateway_vipps::WC_ONPAY_GATEWAY_VIPPS_ID,
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
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_VIABILL, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_ANYDAY, null);
                $this->update_option(self::SETTING_ONPAY_EXTRA_PAYMENTS_VIPPS, null);
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
         * Returns available amount based on order and transaction
         */
        private function getAvailableAmount($order, $transaction) {
            $currencyHelper = new wc_onpay_currency_helper();
            
            $orderCurrency = $currencyHelper->fromAlpha3($order->get_currency());
            $orderRefunded = $order->get_total_refunded() * (10 ** $orderCurrency->exp);

            if ($this->get_option(WC_OnPay::SETTING_ONPAY_REFUND_INTEGRATION) === 'yes') {
                $availableAmount = $transaction->amount - $transaction->charged - $orderRefunded;
            } else {
                $availableAmount = $transaction->amount - $transaction->charged;
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
        $methods[] = 'wc_onpay_gateway_vipps';

        return $methods;
    }
    
    // Add action links to OnPay plugin on plugin overview
    add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_onpay_action_links' );
    function wc_onpay_action_links($links) {
        $plugin_links = [
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=wc_onpay') . '">' . __('Settings', 'wc-onpay') . '</a>',
        ];
        return array_merge( $plugin_links, $links );
    }

	// Initialize
    WC_OnPay::get_instance()->init_hooks();
}
?>
