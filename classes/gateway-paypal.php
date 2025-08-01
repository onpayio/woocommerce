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

include_once 'abstract-gateway.php';

class wc_onpay_gateway_paypal extends wc_onpay_gateway_abstract {
    const WC_ONPAY_GATEWAY_PAYPAL_ID = 'onpay_paypal';

    public function __construct() {
        // Initialize settings
        $this->id = WC_OnPay::WC_ONPAY_SETTINGS_ID;
        $this->init_settings();

        // Define gateway
        $this->id = $this::WC_ONPAY_GATEWAY_PAYPAL_ID;
        $this->method_settings_key = WC_OnPay::SETTING_ONPAY_EXTRA_PAYMENTS_PAYPAL;
        $this->method_title = __('PayPal', 'wc-onpay');
        $this->method_description = __('Payment through PayPal', 'wc-onpay');
        $this->description = $this->getDescriptionString();
        $this->has_fields = false;
        $this->icon = plugin_dir_url(__DIR__) . 'assets/img/paypal.svg';
        $this->title = $this->getMethodTitle();

        if ($this->get_option($this->method_settings_key) !== 'yes') {
            $this->enabled = 'no';
        } else {
            $this->enabled = 'yes';
        }

        parent::__construct();
    }
}