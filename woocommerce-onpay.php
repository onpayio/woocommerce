<?php
/**
* Plugin Name: WooCommerce OnPay.io
* Plugin URI: https://onpay.io/
* Description: WooCommerce payment plugin for OnPay.io.
* Author: OnPay.io
* Author URI: https://onpay.io/
* Text Domain: wc-onpay
* Version: 1.0
**/

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/classes/CurrencyHelper.php';
require_once __DIR__ . '/classes/TokenStorage.php';

add_action('plugins_loaded', 'init_onpay', 0);

function init_onpay() {

    if (!class_exists('WC_Payment_Gateway')) {
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
            $this->title        = $this->method_title;
            $this->icon         = 'logo.png';
            $this->has_fields   = false;
            $this->method_description = 'Allow payments with cards and more through OnPay.io';

            $this->supports = array(
                'subscriptions',
				'products',
				'subscription_cancellation',
				'subscription_reactivation',
				'subscription_suspension',
				'subscription_amount_changes',
				'subscription_date_changes',
				'subscription_payment_method_change_admin',
				'subscription_payment_method_change_customer',
				'refunds',
				'multiple_subscriptions',
				'pre-orders',
            );

            $this->init_form_fields();
            $this->init_settings();
        }

        public function init_hooks() {
            if (is_admin()) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options') );
            }
        }

        public function init_form_fields() {
            $this->form_fields = array(
				self::SETTING_ONPAY_GATEWAY_ID => array(
                    'title' => __('Gateway ID', 'wc-onpay'),
                    'type' => 'text',
                    'disabled' => true,
                ),
            );
		}

        public function admin_options() {
            $onpayApi = $this->getOnpayClient(true);

            // Handle callback from OAUTH flow
            if($this->getQueryValue('code') && !$onpayApi->isAuthorized()) {
                // We're not authorized with the API, and we have a 'code' value at hand. 
                // Let's authorize, and save the gatewayID and secret accordingly.
                $onpayApi->finishAuthorize($this->getQueryValue('code'));
                if ($onpayApi->isAuthorized()) {
                    $this->update_option(self::SETTING_ONPAY_GATEWAY_ID, $onpayApi->gateway()->getInformation()->gatewayId);
                    $this->update_option(self::SETTING_ONPAY_SECRET, $onpayApi->gateway()->getPaymentWindowIntegrationSettings()->secret);
                }
                wp_redirect($this->generateUrl(array(
                    'page' => 'wc-settings',
                    'tab' => 'checkout',
                    'section' => 'wc_onpay',
                )));
                exit;
            }

            $html = '';
			$html .=  '<h3>OnPay</h3>';
            $html .=  '<p>WooCommerce payment plugin for OnPay.io</p>';

            if (!$onpayApi->isAuthorized()) {
                $html .=  '<a href="' . $onpayApi->authorize() . '" class="button-primary">Login with OnPay</a>';
                $GLOBALS['hide_save_button'] = true; // We won't be needing the global save settings button right now
            } else {
                $html .= '<table class="form-table">';
                $html .= $this->generate_settings_html(array(), false);
                $html .= '</table>';
            }
            
            echo ent2ncr($html);
        }
        
        /**
         * Returns an instantiated OnPay API client
         *
         * @return \OnPay\OnPayAPI
         */
        private function getOnpayClient($prepareRedirectUri = false) {
            $tokenStorage = new TokenStorage();
            $params = array();
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
        $plugin_links = array(
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=wc_onpay') . '">' . __('Settings', 'woo_onpay') . '</a>',
        );
        return array_merge( $plugin_links, $links );
    }

	// Initialize hooks
    WC_OnPay::get_instance()->init_hooks();
}
?>