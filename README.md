=== BD Partial COD Gateway (bKash/Nagad) ===
Contributors: almahmud
Tags: woocommerce, payment gateway, cash on delivery, bkash, nagad, bangladesh, partial payment
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Collect a partial advance (equal to the delivery charge) via bKash/Nagad to confirm Cash-on-Delivery orders. Reduce fake COD orders.

== Description ==

A WooCommerce payment gateway built for the Bangladeshi market. Instead of taking the full amount, the customer pays a small **advance equal to the order's delivery charge** through bKash or Nagad to confirm a Cash-on-Delivery order. The remaining balance is collected as cash on delivery.

**Customer flow**

1. At checkout, the customer sees a notice: "You must pay ৳X (delivery charge) now to confirm this order."
2. After placing the order, they land on a payment page showing a QR code, a copyable merchant number, and a form.
3. They send the advance via bKash/Nagad, then submit their sender number and Transaction ID.
4. The order is held until you verify the payment.

**Admin flow**

* Open the order — the "Advance Payment" metabox shows the method, sender number, Transaction ID, advance due, and remaining COD.
* Click **Verify payment** to confirm the order (status → Processing), or **Reject** to send it back.

**Features**

* Advance amount equals the order's shipping/delivery fee (with a fallback amount for free-shipping orders).
* bKash and Nagad, each with its own number, account type, QR image, and instructions.
* Copy-to-clipboard merchant number and QR display.
* Manual admin verification — no API keys required.
* High-Performance Order Storage (HPOS) compatible.
* Theme-overridable payment template and i18n-ready strings.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` and activate it.
2. Go to WooCommerce → Settings → Payments → "BD Partial COD (bKash/Nagad)".
3. Enable the gateway, set your bKash/Nagad numbers, upload QR images, and set a fallback advance amount.
4. Save.

== Frequently Asked Questions ==

= Does this verify payments automatically? =
No. Verification is manual by design — you confirm each advance from the order screen. No bKash/Nagad API credentials are needed.

= What if shipping is free? =
The configured "Fallback advance amount" is used so the advance is never ৳0.

= Does it support the block-based checkout? =
v1 targets the classic (shortcode) checkout. Block (Store API) support is planned.

== Changelog ==

= 1.0.0 =
* Initial release.
