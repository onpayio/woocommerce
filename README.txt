=== OnPay.io for WooCommerce ===
Contributors: onpayio
Tags: onpay, gateway, payment, payment gateway, woocommerce, psp
Requires at least: 5.3
Tested up to: 5.3
Requires PHP: 5.6
Stable tag: 1.0.2
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
2. WooCommerce >= 3.9.2

== Changelog ==

= [1.0.2] =
Only initialize the settings fields on the settings page
Updated the OnPay SDK, with the latest version that resolves a issue with invalid oauth tokens


= 1.0.1 =
Added README.txt for use by Wordpress.org
Sanitize and escape values when getting query and post values directly from PHP.
Updated class names to a format unique for plugin and similar to the rest of Wordpress' naming scheme.

= 1.0.0 =
Initial release