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
class wc_onpay_order_helper {
    /**
     * @param WC_Order $order
     * @return bool
     */
    public function isOrderSubscription($order) {
        // Check if order is or contains a subscription or is renwal
        if (
            (function_exists('wcs_is_subscription') && wcs_is_subscription($order->id)) || // Order is a subscription
            (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order->id)) || // Order contains a subscription
            (function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($order->id)) || // Is a subscription renewal
            (function_exists('wcs_order_contains_early_renewal') && wcs_order_contains_early_renewal($order->id)) // Is an early subscription renewal
        ) {
            return true;
        }
        return false;
    }

    /**
     * @param WC_Order $order
     * @return bool
     */
    public function isOrderSubscriptionRenewal($order) {
        // Check if order is subscription renwal
        if (
            (function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($order->id)) || // Is a subscription renewal
            (function_exists('wcs_order_contains_early_renewal') && wcs_order_contains_early_renewal($order->id)) // Is an early subscription renewal
        ) {
            return true;
        }
        return false;
    }

    /**
     * @param WC_Order $order
     * @return string
     */
    public function getOrderReference($order) {
        $reference = $order->get_order_number();

        // Check if order is subscription
        if ($this->isOrderSubscription($order)) {
            // Attempt extracting initial subscription order number if subscription
            foreach (wcs_get_subscriptions_for_order($order, ['order_type' => 'any']) as $subscription) {
                $reference = $subscription->get_order_number();
                continue;
            }
        }
        
        return $reference;
    }
}
