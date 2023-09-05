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

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

abstract class wc_onpay_abstract_gateway_block extends AbstractPaymentMethodType {
	protected $gateway;

	protected $name;

	protected $hasRegisteredJs = false;

	public function is_active() {
		return $this->gateway->enabled;
	}

	public function get_payment_method_script_handles() {
		$method = [
			'id' => $this->name,
			'title' => $this->gateway->method_title,
			'description' => $this->gateway->description,
			'icon' => $this->gateway->icon,
			'logos' => $this->gateway->getMethodLogos(),
			'supports' => $this->gateway->supports
		];

		if (false === $this->hasRegisteredJs) {
			wp_register_script(
				WC_OnPay::WC_ONPAY_ID . '_blocks',
				WC_OnPay::plugin_url() . '/assets/js/blocks.js',
				[],
				false,
				true
			);
			// Register object of methods if not already registered.
			wp_add_inline_script(WC_OnPay::WC_ONPAY_ID . '_blocks', 'if (typeof wc_onpay_methods === \'undefined\') {var wc_onpay_methods={}}', 'before');
			// Add method to list of objects with info
			wp_add_inline_script(WC_OnPay::WC_ONPAY_ID . '_blocks', 'wc_onpay_methods.' . $this->name . '=' . json_encode($method), 'before');

			$this->hasRegisteredJs = true;
		}

		return [WC_OnPay::WC_ONPAY_ID . '_blocks'];
	}

	public function get_payment_method_data() {
		return [
            'id' => $this->name,
			'title' => $this->gateway->method_title,
			'description' => $this->gateway->description,
			'supports' => array_filter($this->gateway->supports, [
                $this->gateway,
                'supports'
            ]),
		];
	}
}
