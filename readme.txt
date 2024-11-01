=== Verofy Payment Gateway ===
Contributors: v9grouptechaccount
Tags: payment,payments,online payment,verofy
Requires at least: 4.6
Tested up to: 5.9.2
Stable tag: 1.6.3
Requires PHP: 5.2.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verofy's Payment Gateway is a hosted payment solution for WooCommerce. Offering excellent rates and with Faster Payments you will receive your money quickly.

== Description ==

This plugin extends payment options in a WooCommerce shopping system.

All required keys (YOUR_SELLER_ID, YOUR_SELLER_KEY, HASH_KEY) will be located in your activation email.

For your test payment prior to go live please enable SANDBOX mode.

For more information and for your test card credentials please review the setup and testing documentation: https://help.posinabox.eu/docs/ecomm_verofy_guide

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/plugin-name` directory, or install the plugin through the WordPress plugins screen directly.
1. Activate the plugin through the 'Plugins' screen in WordPress
1. Use the Settings->Plugin Name screen to configure the plugin

== Changelog ==

= 1.6.3 =
* Support for extension: Sequential Order Numbers

= 1.6.2 =
* Amending property callbacks (currency, IDs and total)

= 1.6.1 =
* Payment debug mode

= 1.6 =
* Compatibility tests

= 1.5.9 =
* ZIP and Address value change

= 1.5.8 =
* Payment - Hash Key integration

= 1.5.7 =
* Payment metadata - Phone number type

= 1.5.6 =
* Payment metadata update

= 1.5.5 =
* Replacement of deprecated WC function and removal of notice

= 1.5.4 =
* Payment handler callback fix

= 1.5.3 =
* Logo fix

= 1.5.2 =
* This fix is removing warning when WP debug mode is enabled

= 1.5.1 =
* Update wording

= 1.5 =
* Success URL is default set to an order received page instead of my orders

= 1.4 =
* Remove update order status to on-hold which fire an email to customer before payment

= 1.3 =
* Fix logo on a payment screen

= 1.2 =
* Reduce the stock only when payment is successfully taken

= 1.2 =
* Allow EUR currency (Terminal has to be boarded with EURO currency as well)

= 1.1 =
* HTTP callback fix

= 1.0 =
* First release

== Upgrade Notice ==

= 1.0 =
First release
