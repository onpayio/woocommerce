<?php
/**
 * MIT License
 *
 * Copyright (c) 2022 OnPay.io
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
class wc_onpay_logger_helper {

	public static function logTokenError($message, $context = []) {
		if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
			$contextStr = !empty($context) ? ' Context: ' . wp_json_encode($context) : '';
			error_log('[OnPay Token Error] ' . $message . $contextStr);
		}
		if (function_exists('wc_get_logger')) {
			$logger = wc_get_logger();
			$context['source'] = 'onpay-token';
			$logger->error($message, $context);
		}
	}

	/**
	 * Unified token logging method used for connection drops and payment failures.
	 * Accepts a custom message and context. If no message is provided, a sensible
	 * default is generated based on whether a token is present in storage.
	 *
	 * @param string $message
	 * @param array $context
	 */
	public static function logTokenProblem($message = '', $context = []) {
		$tokenStorage = new wc_onpay_token_storage();
		$hasStoredToken = $tokenStorage->hasStoredToken();

		if (empty($message)) {
			if ($hasStoredToken) {
				$message = 'OnPay connection dropped: Token exists in storage but is invalid or could not be refreshed.';
			} else {
				$message = 'OnPay connection failed: No valid token available.';
			}
		}

		$context = array_merge([
			'token_stored' => $hasStoredToken,
			'timestamp' => current_time('mysql'),
		], $context);

		self::logTokenError($message, $context);
	}

}
