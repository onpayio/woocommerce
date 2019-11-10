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
            $this->has_fields   = false;
            $this->method_description = __('Recieve payments with cards and more through OnPay.io', 'wc-onpay');

            $this->supports = [
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
            ];

            $this->init_form_fields();
            $this->init_settings();

            $this->title        = $this->getActiveMethodsString('title');
            $this->description  = $this->getActiveMethodsString('description');
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
            add_action( 'woocommerce_receipt_' . $this->id, [$this, 'checkout']);
        }

        public function process_payment( $order_id ) {
            $order = new WC_Order( $order_id );
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
                $html .= '<td><button class="button-secondary" onclick="logoutClick()">' . __('Log out from OnPay', 'wc-onpay') . '</button></td>';
                $html .= '</tr>';

                $html .= '</tbody></table>';
                $html .= '<hr />';

                $html .= '<script>function logoutClick(){event.preventDefault(); if(confirm(\''. __('Are you sure you want to logout from Onpay?', 'wc-onpay') . '\')) {window.location.href = window.location.href+"&detach=1";}}</script>';
            }
            
            echo ent2ncr($html);
        }

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
                return __('Pay with', 'wc-onpay') . ' ' . $methodsString;
            } else if ($string === 'description') {
                return __('Pay through OnPay using', 'wc-onpay') . ' ' . $methodsString;
            }
            return null;
        }

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
            $paymentWindow->setReference($order->get_data()['order_key']);
            $paymentWindow->setType("payment");
            $paymentWindow->setAcceptUrl($this->get_return_url($order));
            // $paymentWindow->setAcceptUrl($this->context->link->getModuleLink('onpay', 'payment', ['accept' => 1], Configuration::get('PS_SSL_ENABLED')));
            // $paymentWindow->setDeclineUrl($this->context->link->getModuleLink('onpay', 'payment', [], Configuration::get('PS_SSL_ENABLED')));
            // $paymentWindow->setCallbackUrl($this->context->link->getModuleLink('onpay', 'callback', [], Configuration::get('PS_SSL_ENABLED'), null));
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
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=wc_onpay') . '">' . __('Settings', 'woo_onpay') . '</a>',
        ];
        return array_merge( $plugin_links, $links );
    }

	// Initialize hooks
    WC_OnPay::get_instance()->init_hooks();
}
?>