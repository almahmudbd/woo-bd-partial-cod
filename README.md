 BD Partial COD Gateway (bKash/Nagad/Rocket)
--------
Contributors: almahmud
Tags: woocommerce, payment gateway, cash on delivery, bkash, nagad, rocket, bangladesh, partial payment
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Collect a partial advance (delivery charge) **or** the full order total via bKash/Nagad/Rocket to confirm orders. Reduce fake COD orders — no API keys required.

## Description

Two manual mobile-payment gateways built for the Bangladeshi market, verified by you from the order screen:

* **BD Partial COD** — the customer pays a small **advance equal to the delivery charge** via bKash/Nagad/Rocket to confirm a Cash-on-Delivery order. The rest is collected as cash on delivery.
* **BD Manual Mobile Payment (full)** — the customer pays the **full order total** via bKash/Nagad/Rocket up front.

Both share the same payment page, verification workflow, and settings — enable one or both at checkout.

**Customer flow**

1. At checkout, the customer sees a notice (e.g. "You must pay ৳X (delivery charge) now to confirm this order.").
2. After placing the order, they land on a standalone payment page showing a QR code, a copyable number, and a form.
3. They pay via bKash/Nagad/Rocket, then submit their sender number (and, optionally, the Transaction ID).
4. The order is held until you verify the payment.

**Admin flow**

* Open the order — the metabox shows the method, sender number, Transaction ID, amount due, and remaining COD.
* Click **Verify payment** to confirm the order (status → Processing), or **Reject** to send it back.

**Features**

* Two modes: partial advance (delivery charge) and full payment.
* bKash (Personal/Merchant), Nagad, and Rocket — each with its own number, QR image, and instructions.
* **Editable texts** — customise every customer-facing string (checkout notice, payment page title/labels/button/footer, status messages) per gateway, in any language. Every field is pre-filled with the default, ready to edit.
* **Transaction ID (TrxID)** — ask for it Off / Optional / Required.
* **Flexible sender number** — require a full 11-digit number, or let the customer confirm with just the last few digits.
* **Gateway icon** — choose the icon shown beside the method at checkout and in the payment page header.
* **Reuse numbers** — the full gateway can use its own numbers, copy the partial gateway's numbers/QR/instructions once (then fine-tune), or always mirror them live so the two stay in sync.
* Copy-to-clipboard number and QR display.
* Manual admin verification — no API keys required.
* High-Performance Order Storage (HPOS) compatible.
* Theme-overridable payment template and i18n-ready strings.

##  Installation

1. Upload the plugin folder to `/wp-content/plugins/` and activate it.
2. Go to WooCommerce → Settings → Payments and open **BD Partial COD** and/or **BD Manual Mobile Payment (full)**.
3. Enable the gateway, set your bKash/Nagad/Rocket numbers, upload QR images, choose the icon, and (for partial) set a fallback advance amount.
4. Optionally edit the **Texts / Labels** section, set the **Transaction ID** and **Sender number** behaviour, and save.

## Frequently Asked Questions

= Does this verify payments automatically? =
No. Verification is manual by design — you confirm each payment from the order screen. No bKash/Nagad API credentials are needed.

= Can I take the full amount instead of just the delivery charge? =
Yes. Enable the **BD Manual Mobile Payment (full)** gateway, which collects the entire order total.

= Can I customise the wording shown to customers? =
Yes. Each gateway has a **Texts / Labels** section where every customer-facing string can be edited. Each field is pre-filled with the default text, so you can change the wording directly.

= What if shipping is free? =
On the partial gateway, the configured "Fallback advance amount" is used so the advance is never ৳0.

= Do I have to enter my numbers twice for both gateways? =
No. Set **Reuse numbers** on the full gateway to either *Copy once* (fills its fields from the partial gateway on save, then edit freely) or *Always mirror* (reads the partial gateway's numbers live, so any change there applies to both automatically).

= Does it support the block-based checkout? =
The plugin targets the classic (shortcode) checkout. Block (Store API) support is planned.

== Changelog ==

= 1.5.0 =
* New **BD Manual Mobile Payment (full)** gateway that collects the full order total (shares the partial gateway's workflow via a common base class).
* **Editable texts** — customise every customer-facing string per gateway via a new "Texts / Labels" section; each field is pre-filled with the default.
* **Transaction ID (TrxID)** field with Off / Optional / Required setting.
* **Flexible sender number** — require the full 11-digit number, or accept just the last few digits.
* **Gateway icon** option now also shows in the payment page header.
* **Reuse numbers** — the full gateway can use its own numbers, copy the partial gateway's numbers/QR/instructions once on save, or always mirror them live.
* Refreshed standalone payment page styling (inputs, buttons, copy button, QR, header).

= 1.4.0 =
* Standalone page for the payment process.

= 1.0.0 =
* Initial release.
