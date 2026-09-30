<?php
/**
 * MIT License
 *
 * Copyright (c) 2026 OnPay.io
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
 * Persists the short-lived OAuth CSRF `state` and PKCE `code_verifier` across
 * the redirect from OnPay's authorize URL back to the plugin's callback.
 *
 * Uses WordPress transients with a 15-minute TTL.
 */
class wc_onpay_auth_state_storage implements \OnPay\AuthStateStorageInterface {
    const TTL_SECONDS = 900; // 15 minutes; the OAuth code exchange has to happen in this window.
    const STATE_KEY = 'onpay_oauth_state';
    const VERIFIER_KEY = 'onpay_oauth_verifier';

    public function saveState(string $state): void {
        set_transient(self::STATE_KEY, $state, self::TTL_SECONDS);
    }

    public function getState(): ?string {
        $value = get_transient(self::STATE_KEY);
        return is_string($value) && '' !== $value ? $value : null;
    }

    public function saveCodeVerifier(string $codeVerifier): void {
        set_transient(self::VERIFIER_KEY, $codeVerifier, self::TTL_SECONDS);
    }

    public function getCodeVerifier(): ?string {
        $value = get_transient(self::VERIFIER_KEY);
        return is_string($value) && '' !== $value ? $value : null;
    }

    public function clear(): void {
        delete_transient(self::STATE_KEY);
        delete_transient(self::VERIFIER_KEY);
    }
}
