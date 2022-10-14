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

include_once 'abstract-gateway.php';

class wc_onpay_gateway_mobilepay extends wc_onpay_gateway_abstract {
    const WC_ONPAY_GATEWAY_MOBILEPAY_ID = 'onpay_mobilepay';

    public function __construct() {
        // Initialize settings
        $this->id = WC_OnPay::WC_ONPAY_SETTINGS_ID;
        $this->init_settings();

        // Define gateway
        $this->id = $this::WC_ONPAY_GATEWAY_MOBILEPAY_ID;
        $this->method_title = __('MobilePay', 'wc-onpay');
        $this->description = __('Payment using MobilePay', 'wc-onpay');
        $this->method_description = $this->description;
        $this->has_fields = false;
        $this->icon = plugin_dir_url(__DIR__) . 'assets/img/mobilepay.svg';
        $this->title = $this->getMethodTitle();

        if ($this->get_option(WC_OnPay::SETTING_ONPAY_EXTRA_PAYMENTS_MOBILEPAY) !== 'yes') {
            $this->enabled = 'no';
        } else {
            $this->enabled = 'yes';
        }

        parent::__construct();
    }
}