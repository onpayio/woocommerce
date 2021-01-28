=== OnPay.io for WooCommerce ===
Contributors: onpayio
Tags: onpay, gateway, payment, payment gateway, woocommerce, psp
Requires at least: 5.3
Tested up to: 5.6
Requires PHP: 5.6
Stable tag: 1.0.11
License: MIT
License URI: https://mit-license.org/

Plugin for WooCommerce, based on the official OnPay PHP SDK.

== Description ==
Plugin for WooCommerce, based on the official OnPay PHP SDK. The plugin adds the following functionality to WooCommerce:
- Usage of OnPay as a payment method.
- Validation of orders with callbacks directly from OnPay, outside the context of the cardholders browser.
- Management of transaction on order pages in backoffice.

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