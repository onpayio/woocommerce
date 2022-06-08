=== OnPay.io for WooCommerce ===
Contributors: onpayio
Tags: onpay, gateway, payment, payment gateway, woocommerce, psp
Requires at least: 5.3
Tested up to: 6.0.0
Requires PHP: 5.6
Stable tag: 1.0.25
License: MIT
License URI: https://mit-license.org/

Plugin for WooCommerce, based on the official OnPay PHP SDK.

== Description ==
Plugin for WooCommerce, based on the official OnPay PHP SDK. The plugin adds the following functionality to WooCommerce:
- Usage of OnPay as a payment method.
- Validation of orders with callbacks directly from OnPay, outside the context of the cardholders browser.
- Management of transaction on order pages in backoffice.

Don't  have an OnPay account yet? Order one through <a href="https://dandomain.dk/betalingssystem/priser" target="_blank">DanDomain</a> from DKK 0,- per month.

<a href="https://onpay.io/#brands" target="_blank">OnPay sellers</a>

== Installation ==
1. Install plugin as any other Wordpress plugin.
2. Log in with OnPay on plugin settings page in woocommerce settings.
3. Plugin configures itself automatically after successful login.
4. Setup up the plugin with the desired configuration.
5. You're ready to go.

== Dependencies ==
1. PHP: >= 5.6
2. Wordpress >= 5.3
2. WooCommerce >= 3.8.1

== Changelog ==

= [1.0.25]
Added support for customers updating payment method

= [1.0.24] =
Confirmed compatibility with version 6.0 of Wordpress.

= [1.0.23] =
Improved validation of parameters on callback and decline endpoints.

= [1.0.22] =
Added swish as available payment option.

= [1.0.21] =
Based identification of orders in callback, on order_key instead of unpredictable order_number, but keep using order_number for reference

= [1.0.20] =
Fixed invalid Redirect urls when logging in through OnPay.
Added support for Sequential Order Number Pro, when validating orders

= [1.0.19] =
Added feature for including WooCommerce refunded values in calculated amounts for capture.
Fixed bug where latest order is selected instead of the order in question
Fixed names of gateways shown in lists and removed base wc_onpay gateway that wasnt a real gateway
Added support for activated toggle switches on payment gateways page
Added function to allow automatic refund when using built in refund function of woocommerce

= [1.0.18] =
Added support for WooCommerce Subscriptions
Added support for order numbers, instead of using ids.

= [1.0.17] =
Exclude paragonIE random_compat from scoper, since this repo is registered in the global space, and results in errors if prefixed with a namespace.
Added feature for enabling autocapture of transaction, when order is marked as completed.

= [1.0.16] =
Fixed version tag mismatches

= [1.0.15] =
Updated Anyday branding

= [1.0.14] =
Added Vipps as payment option
Properly set HTTP header and code in json responses

= [1.0.13] =
Added styling for Anyday Split logo
Show Maestro logo if Mastercard is shown
Fix datetimes shown in log for transactions to follow timezone set in Wordpress.

= [1.0.12] =
Added method for showing notices in admin
Added better handling of errors on order page in admin
Get data from Order object the correct way in abstract_gateway

= [1.0.11] =
Updated version of onpayio/php-sdk
Added website field to payment window
Added Anyday Split as an payment option
Implemented platform field in payment window

= [1.0.10] =
Split methods form one single into individual payment methods shown when choosing method in frontoffice.
Added feature for choosing card logos shown on OnPay credit card method.
Updated MobilePay logo.

= [1.0.9] =
Fix bug with invalid token crashing whole site
Updated dependencies, PHP SDK and onpayio oauth2 dependency
Implemented paymentinfo for paymentwindow, setting available values
Confirmed working on Wordpress 5.5.1 and WooCommerce 4.6.0

= [1.0.8] =
When users are sent to the payment window, the value for declineUrl has been set to the url for the checkout page. If user returns to declineUrl because of an error encountered in OnPay, an error message will be shown.

= [1.0.7] =
Confirmed working on Wordpress 5.5 and WooCommerce 4.4.1
Added prefix to dependency namespaces during build, to prevent any overlap with dependency versions from other plugins that might be installed

= [1.0.6] =
Confirmed working on Wordpress 5.4.2 and WooCommerce 4.2.0
Fix incompatibility with PHP 5.6
Update composer dependencies to latest versions

= [1.0.5] =
Fixed issue with transaction_id null value being used to fetch transaction, resulting in an error.
Confirmed working on Wordpress 5.4.1 and WooCommerce 4.1.0

= [1.0.4] =
Added missing translatable strings
Added danish translation of plugin

= [1.0.3] =
Tested compatibility for Wordpress 5.4 and WooCommerce 4.0.1
Properly handle no connection to API, and show error message in such case

= [1.0.2] =
Only initialize the settings fields on the settings page
Updated the OnPay SDK, with the latest version that resolves a issue with invalid oauth tokens


= 1.0.1 =
Added README.txt for use by Wordpress.org
Sanitize and escape values when getting query and post values directly from PHP.
Updated class names to a format unique for plugin and similar to the rest of Wordpress' naming scheme.

= 1.0.0 =
Initial release
