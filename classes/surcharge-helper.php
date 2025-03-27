<?php
/**
 * MIT License
 *
 * Copyright (c) 2025 OnPay.io
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
class wc_onpay_surcharge_helper {

    const VAT_CLASS_META_KEY = '_onpay_surcharge_tax_class';

    /**
     * Returns VAT rate according to customer billing, and the selected tax rate in settings
     * 
     * @param string|null $vatRateOption
     * @param WC_Customer $customer
     * @param WC_Order $order
     * @return float
     */
    public static function getSurchargeVatRate($vatRateOption, $customer, $order) {
        if ($vatRateOption !== null) {
            if ('none' === $vatRateOption) {
                $rate = 0.0;
            } else {
                // Get tax class name from order and option
                $taxClass = self::getWCTaxClassName($vatRateOption, $order);
                // Find rates that apply
                $rates = array_values(WC_Tax::find_rates(
                    [
                        'country' => $customer->get_billing_country(),
                        'state' => $customer->get_billing_state(),
                        'city' => $customer->get_billing_postcode(),
                        'postcode' => $customer->get_billing_city,
                        'tax_class' => $taxClass
                    ]
                ));
                // Pick first one, since they are sorted by priority, most important first
                $firstRate = array_shift($rates);

                $rate = (float)$firstRate['rate'];
            }
        }
        return $rate;
    }

    /**
     * Returns a slug name as WooCommerce names them, sorting out our custom values.
     * 
     * @param string $vatRateOption
     * @param WC_Customer $customer
     * @return string
     */
    public static function getWCTaxClassName($vatRateOption, $order) {
        // Set tax class to selected VAT rate
        $taxClass = $vatRateOption;
        if ('auto' === $taxClass) { // If auto is selected set tax class as the item in order with highest total value
            $highestItem = null;
            foreach($order->get_items() as $item) { // Loop order items
                if (null === $highestItem) {
                    $highestItem = $item;
                } else if($highestItem->get_total() < $item->get_total()) {
                    $highestItem = $item;
                }
            }
            $taxClass = $highestItem->get_tax_class(); // Grab tax class of highest item
        } else if ('standard' === $taxClass) { // If standard is selected set tax class to empty string
            $taxClass = ''; 
        }
        return $taxClass;
    }

    /**
     * Returns Item fee for surcharge fee with or with applied taxes, that can be added to an order.
     * 
     * @param int $fee
     * @param WC_Order $order
     * @param WC_Customer $customer
     * @return WC_Order_Item_Fee
     */
    public static function getSurchargeItemFee($fee, $order, $customer) {
        $currencyHelper = new wc_onpay_currency_helper();
        $orderCurrency = $currencyHelper->fromAlpha3($order->get_currency());
        // Grab fee amount and calculate tax amount using stored tax rate on order
        $feeAmount = $currencyHelper->minorToMajor($fee, $orderCurrency->numeric);
        $orderTaxClass = self::getOrderTaxClass($order);

        $itemFee = new WC_Order_Item_Fee();
        $itemFee->set_name(__( 'Card surcharge fee', 'wc-onpay'));

        if ("" !== $orderTaxClass && "none" !== $orderTaxClass) { // If the stored tax class is not empty, and not "none"
            // Grab the taxrate in the format that was sent to OnPay, so the calculation is precise.
            $taxClassRate = self::formatSurchargeRate(self::getSurchargeVatRate($orderTaxClass, $customer, $order));  
            // Calculate original fee without taxes using the tax rate
            $feeNoTaxAmount = $feeAmount / (1 + ($taxClassRate / 10000));
            // Calculate the tax amount of the fee
            $feeTaxAmount = $feeAmount - $feeNoTaxAmount;
            // Add data to fee item
            $itemFee->set_total_tax($feeTaxAmount); // Amount of the fee that is tax
            $itemFee->set_total($feeNoTaxAmount); // Amount of the fee without taxes
            $itemFee->set_tax_status('taxable'); // Instructs to calculate taxes
            $itemFee->set_tax_class( self::getWCTaxClassName($orderTaxClass, $order)); // Tax class to utilize for calculation
        } else { // If tax rate is not available or none, we'll just add the surcharge fee without the tax calculation.
            $itemFee->set_tax_status('none');
            $itemFee->set_total($feeAmount);
        }

        return $itemFee;
    }

    /**
     * Saves a tax class string value for surcharge fee, to the order meta.
     * 
     * @param WC_Order $order
     * @param string $class
     * @return void
     */
    public static function saveOrderTaxClass($order, $class) {
        if ($order->meta_exists(self::VAT_CLASS_META_KEY)) {
            $order->update_meta_data(self::VAT_CLASS_META_KEY, $class);
        } else {
            $order->add_meta_data(self::VAT_CLASS_META_KEY, $class);
        }
        $order->save_meta_data();
    }

    /**
     * Retrieves a tax class string value for surcharge fee, from the order meta.
     * 
     * @param WC_Order $order
     * @return string
     */
    public static function getOrderTaxClass($order) {
        return $order->get_meta(self::VAT_CLASS_META_KEY);
    }

    /**
     * Formats a tax rate float from WooCommerce, into the format that OnPay expects and supports.
     * Truncates to the 2 most significant decimals, and removes the decimal separator.
     * 
     * @param float $rate
     * @return int
     */
    public static function formatSurchargeRate($rate) {
        return (int)number_format($rate, 2, '', '');
    }
}
