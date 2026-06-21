=== AAM Partial COD & Mobile Payment for WooCommerce ===
Contributors: almahmudbd
Tags: woocommerce, payment gateway, bkash, nagad, partial payment
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.5.6
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Collect a partial advance (delivery charge) or the full order total via bKash/Nagad/Rocket to confirm orders. No API keys required.

== Description ==

Two manual mobile-payment gateways built for the Bangladeshi market, verified by you from the order screen:

* **BD Partial COD** — the customer pays a small advance equal to the delivery charge via bKash/Nagad/Rocket to confirm a Cash-on-Delivery order. The rest is collected as cash on delivery.
* **BD Manual Mobile Payment (full)** — the customer pays the full order total via bKash/Nagad/Rocket up front.

Both share the same payment page, verification workflow, and settings — enable one or both at checkout.

**Customer flow**

1. At checkout the customer sees a notice (e.g. "You must pay ৳X (delivery charge) now to confirm this order.").
2. After placing the order they land on a standalone payment page showing a QR code, a copyable number, and a form.
3. They pay via bKash/Nagad/Rocket, then submit their sender number (and optionally the Transaction ID).
4. The order is held until you verify the payment.

**Admin flow**

* Open the order — the metabox shows the method, sender number, Transaction ID, amount due, and remaining COD.
* Click **Verify payment** to confirm the order (status → Processing), or **Reject** to send it back.

**Features**

* Two modes: partial advance (delivery charge) and full payment.
* bKash, Nagad, and Rocket — each with Merchant and Personal accounts, each with its own number, QR image, and instructions, independently switched on/off.
* **Editable texts** — customise every customer-facing string (checkout notice, payment page title/labels/button/footer, status messages) per gateway, in any language. Every field is pre-filled with the default, ready to edit.
* **Transaction ID (TrxID)** — ask for it Off / Optional / Required.
* **Flexible sender number** — require a full 11-digit number, or let the customer confirm with just the last few digits.
* **Gateway icon** — choose the icon shown beside the method at checkout and in the payment page header.
* **Reuse numbers** — the full gateway can use its own numbers, copy the partial gateway's numbers once on save, or always mirror them live.
* Copy-to-clipboard number and QR display.
* Manual admin verification — no API keys required.
* High-Performance Order Storage (HPOS) compatible.
* Theme-overridable payment template and i18n-ready strings.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` and activate it.
2. Go to WooCommerce → Settings → Payments and open **BD Partial COD** and/or **BD Manual Mobile Payment (full)**.
3. Enable the gateway, set your bKash/Nagad/Rocket numbers, upload QR images, and choose the icon.
4. Optionally edit the **Texts / Labels** section, set the Transaction ID and Sender number behaviour, and save.

== Frequently Asked Questions ==

= Does this verify payments automatically? =
No. Verification is manual by design — you confirm each payment from the order screen. No bKash/Nagad API credentials are needed.

= Can I take the full amount instead of just the delivery charge? =
Yes. Enable the **BD Manual Mobile Payment (full)** gateway, which collects the entire order total.

= Can I customise the wording shown to customers? =
Yes. Each gateway has a **Texts / Labels** section where every customer-facing string can be edited. Each field is pre-filled with the default text, so you can change the wording directly.

= What if shipping is free? =
On the partial gateway, the configured "Fallback advance amount" is used so the advance is never ৳0.

= Do I have to enter my numbers twice for both gateways? =
No. Set **Reuse numbers** on the full gateway to either *Copy once* or *Always mirror*.

= Does it support the block-based checkout? =
The plugin targets the classic (shortcode) checkout. Block (Store API) support is planned.

== Screenshots ==

1. Checkout page showing the partial COD gateway with the advance notice.
2. Standalone payment page with QR code, copyable number, and submission form.
3. Admin order metabox showing payment details and Verify/Reject buttons.
4. Gateway settings — payment methods, editable texts, and icon option.

== Changelog ==

= 1.5.6 =
* Standalone payment pages now register their CSS/JS through wp_enqueue_style()/wp_enqueue_script() instead of hard-coded tags.
* Fixed the Plugin URI and trimmed the tag list for WordPress.org compliance.

= 1.5.4 =
* WordPress.org submission: renamed slug to aam-bd-partial-cod-for-wc, updated plugin name, added Requires Plugins header.

= 1.5.3 =
* Default gateway icons are now bundled with the plugin (cod-icon.png for partial COD, desi-gateways.jpg for full payment).

= 1.5.2 =
* Fix: the Texts / Labels fields now always show the (editable) default wording, even if an earlier save had stored them empty.

= 1.5.1 =
* Build: produce a spec-compliant ZIP (forward-slash paths) so WordPress can install/overwrite it correctly. Added native build.ps1/build.cmd for Windows.

= 1.5.0 =
* New BD Manual Mobile Payment (full) gateway that collects the full order total.
* Editable texts — customise every customer-facing string per gateway; each field is pre-filled with the default.
* Transaction ID (TrxID) field with Off / Optional / Required setting.
* Flexible sender number — require the full 11-digit number, or accept just the last few digits.
* Gateway icon option now also shows in the payment page header.
* Reuse numbers — off / copy once / always mirror.
* Refreshed standalone payment page styling.

= 1.4.0 =
* Standalone page for the payment process.

= 1.0.0 =
* Initial release.
