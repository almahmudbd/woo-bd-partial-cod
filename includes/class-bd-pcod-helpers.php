<?php
/**
 * Shared helpers: advance-amount calculation, sanitizers, status constants,
 * gateway registry, and customer-facing text resolution.
 *
 * @package WooBDPartialCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static helper methods used across the gateways, payment page, and admin UI.
 */
class BD_PCOD_Helpers {

	// Meta keys stored on the order.
	const META_ADVANCE   = '_bd_pcod_advance_amount';
	const META_REMAINING = '_bd_pcod_remaining_cod';
	const META_STATUS    = '_bd_pcod_status';
	const META_METHOD    = '_bd_pcod_method';
	const META_SENDER    = '_bd_pcod_sender_number';
	const META_TRXID     = '_bd_pcod_trxid';

	// Payment workflow statuses (stored in META_STATUS).
	const STATUS_AWAITING  = 'awaiting_payment'; // Order placed, customer has not submitted proof yet.
	const STATUS_SUBMITTED = 'submitted';        // Customer submitted sender number/TrxID, awaiting admin.
	const STATUS_VERIFIED  = 'verified';         // Admin confirmed the payment was received.
	const STATUS_REJECTED  = 'rejected';         // Admin rejected the submission.

	// Payment modes.
	const MODE_PARTIAL = 'partial'; // Collect an advance (delivery charge); rest is COD.
	const MODE_FULL    = 'full';    // Collect the full order total up front.

	/**
	 * Registry of the gateways this plugin provides, keyed by gateway id.
	 *
	 * @return array<string,string> Map of gateway id => mode.
	 */
	public static function gateways() {
		return array(
			BD_PCOD_GATEWAY_ID      => self::MODE_PARTIAL,
			BD_PCOD_FULL_GATEWAY_ID => self::MODE_FULL,
		);
	}

	/**
	 * The payment mode for a given gateway id.
	 *
	 * @param string $gateway_id Gateway id.
	 * @return string partial|full.
	 */
	public static function gateway_mode( $gateway_id ) {
		$gateways = self::gateways();
		return isset( $gateways[ $gateway_id ] ) ? $gateways[ $gateway_id ] : self::MODE_PARTIAL;
	}

	/**
	 * The payment mode for a given order (resolved from its payment method).
	 *
	 * @param WC_Order $order Order object.
	 * @return string partial|full.
	 */
	public static function order_mode( $order ) {
		return self::gateway_mode( $order instanceof WC_Order ? $order->get_payment_method() : '' );
	}

	/**
	 * Get a gateway's settings array.
	 *
	 * @param string $gateway_id Gateway id.
	 * @return array
	 */
	public static function get_settings( $gateway_id = BD_PCOD_GATEWAY_ID ) {
		return get_option( 'woocommerce_' . $gateway_id . '_settings', array() );
	}

	/**
	 * Read a single gateway setting with a default.
	 *
	 * @param string $gateway_id Gateway id.
	 * @param string $key        Setting key.
	 * @param mixed  $default    Default value.
	 * @return mixed
	 */
	public static function get_setting( $gateway_id, $key, $default = '' ) {
		$settings = self::get_settings( $gateway_id );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Calculate the advance amount due for an order.
	 *
	 * In full mode this is the entire order total. In partial mode it equals the
	 * order's shipping/delivery total, falling back to a fixed amount when shipping
	 * is zero so the advance is never ৳0.
	 *
	 * @param WC_Order $order The order.
	 * @return float
	 */
	public static function get_advance_amount( $order ) {
		$total = (float) $order->get_total();

		if ( self::MODE_FULL === self::order_mode( $order ) ) {
			$amount = $total;
		} else {
			$amount = (float) $order->get_shipping_total();

			if ( $amount <= 0 ) {
				$amount = (float) self::get_setting( $order->get_payment_method(), 'fallback_advance', 0 );
			}

			// Never exceed the order total.
			if ( $amount > $total ) {
				$amount = $total;
			}
		}

		/**
		 * Filter the calculated advance amount.
		 *
		 * @param float    $amount Advance amount.
		 * @param WC_Order $order  Order object.
		 */
		return (float) apply_filters( 'bd_pcod_advance_amount', round( $amount, wc_get_price_decimals() ), $order );
	}

	/**
	 * Calculate the remaining amount to collect as cash on delivery.
	 *
	 * @param WC_Order $order The order.
	 * @return float
	 */
	public static function get_remaining_cod( $order ) {
		$remaining = (float) $order->get_total() - self::get_advance_amount( $order );
		return (float) max( 0, round( $remaining, wc_get_price_decimals() ) );
	}

	/**
	 * Sanitize and validate a Bangladeshi mobile number.
	 *
	 * @param string $raw Raw input.
	 * @return string|false Normalised 11-digit number (01XXXXXXXXX) or false if invalid.
	 */
	public static function sanitize_bd_phone( $raw ) {
		$digits = preg_replace( '/\D+/', '', (string) $raw );

		// Accept a leading country code (880) and normalise to local 11-digit form.
		if ( 13 === strlen( $digits ) && 0 === strpos( $digits, '880' ) ) {
			$digits = '0' . substr( $digits, 3 );
		} elseif ( 12 === strlen( $digits ) && 0 === strpos( $digits, '88' ) ) {
			$digits = substr( $digits, 2 );
		}

		if ( preg_match( '/^01[3-9]\d{8}$/', $digits ) ) {
			return $digits;
		}

		return false;
	}

	/**
	 * Sanitize the customer's sender number according to the configured strictness.
	 *
	 * In "full" mode a complete, valid 11-digit number is required. In "partial"
	 * mode the customer may confirm with just the last few digits (3–14 digits),
	 * which the store owner matches against their statement.
	 *
	 * @param string $raw  Raw input.
	 * @param string $mode full|partial number requirement.
	 * @return string|false Cleaned digits, or false if invalid.
	 */
	public static function sanitize_sender_number( $raw, $mode = 'full' ) {
		if ( 'partial' === $mode ) {
			$digits = preg_replace( '/\D+/', '', (string) $raw );
			$len    = strlen( $digits );
			return ( $len >= 3 && $len <= 14 ) ? $digits : false;
		}

		return self::sanitize_bd_phone( $raw );
	}

	/**
	 * Whether a given order used one of this plugin's gateways.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	public static function is_our_order( $order ) {
		return $order instanceof WC_Order && array_key_exists( $order->get_payment_method(), self::gateways() );
	}

	/**
	 * Build the standalone gateway (pay) page URL for an order.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	public static function get_pay_url( $order ) {
		return add_query_arg(
			array(
				'bd_pcod_pay' => $order->get_id(),
				'order_key'   => $order->get_order_key(),
			),
			home_url( '/' )
		);
	}

	/**
	 * Central registry of supported payment methods.
	 *
	 * Each method has a display label and an "action" that determines the
	 * wording shown to the customer ("Send Money to" vs "Make Payment to").
	 *
	 * @return array[] Keyed by method slug: array{ label, action }.
	 */
	public static function get_methods_config() {
		return array(
			'bkash_personal' => array(
				'label'  => __( 'bKash (Personal)', 'woo-bd-partial-cod' ),
				'action' => 'send',
			),
			'bkash_merchant' => array(
				'label'  => __( 'bKash (Merchant)', 'woo-bd-partial-cod' ),
				'action' => 'payment',
			),
			'nagad'          => array(
				'label'  => __( 'Nagad', 'woo-bd-partial-cod' ),
				'action' => 'send',
			),
			'rocket'         => array(
				'label'  => __( 'Rocket', 'woo-bd-partial-cod' ),
				'action' => 'send',
			),
		);
	}

	/**
	 * Customer-facing instruction label for a method's action.
	 *
	 * @param string $action send|payment.
	 * @return string
	 */
	public static function action_label( $action ) {
		return 'payment' === $action
			? __( 'Make Payment to', 'woo-bd-partial-cod' )
			: __( 'Send Money to', 'woo-bd-partial-cod' );
	}

	/**
	 * Human-readable label for a payment method key.
	 *
	 * @param string $method Method key.
	 * @return string
	 */
	public static function method_label( $method ) {
		$config = self::get_methods_config();
		return isset( $config[ $method ] )
			? $config[ $method ]['label']
			: ucwords( str_replace( '_', ' ', (string) $method ) );
	}

	/**
	 * Human-readable label for a workflow status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			self::STATUS_AWAITING  => __( 'Awaiting payment', 'woo-bd-partial-cod' ),
			self::STATUS_SUBMITTED => __( 'Submitted — needs review', 'woo-bd-partial-cod' ),
			self::STATUS_VERIFIED  => __( 'Verified', 'woo-bd-partial-cod' ),
			self::STATUS_REJECTED  => __( 'Rejected', 'woo-bd-partial-cod' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Unknown', 'woo-bd-partial-cod' );
	}

	/**
	 * Return the enabled payment methods (with details) for a given gateway.
	 *
	 * @param string $gateway_id Gateway id.
	 * @return array[] Keyed by method slug: array{ label, action, number, qr, instructions }.
	 */
	public static function get_enabled_methods( $gateway_id = BD_PCOD_GATEWAY_ID ) {
		$methods = array();
		$source  = self::method_source_gateway( $gateway_id );

		foreach ( self::get_methods_config() as $key => $config ) {
			if ( 'yes' !== self::get_setting( $source, $key . '_enabled', 'no' ) ) {
				continue;
			}

			$number = trim( (string) self::get_setting( $source, $key . '_number', '' ) );
			if ( '' === $number ) {
				continue;
			}

			$methods[ $key ] = array(
				'label'        => $config['label'],
				'action'       => $config['action'],
				'number'       => $number,
				'qr'           => esc_url_raw( (string) self::get_setting( $source, $key . '_qr', '' ) ),
				'instructions' => (string) self::get_setting( $source, $key . '_instructions', '' ),
			);
		}

		return $methods;
	}

	/**
	 * Which gateway's per-method settings (numbers, QR, instructions) to read.
	 *
	 * The full gateway can be set to "mirror" the partial gateway, in which case
	 * its numbers are read live from the partial gateway at runtime.
	 *
	 * @param string $gateway_id Gateway id being rendered.
	 * @return string Gateway id to read method settings from.
	 */
	public static function method_source_gateway( $gateway_id ) {
		if ( BD_PCOD_FULL_GATEWAY_ID === $gateway_id
			&& 'mirror' === self::get_setting( $gateway_id, 'reuse_partial_methods', 'off' ) ) {
			return BD_PCOD_GATEWAY_ID;
		}

		return $gateway_id;
	}

	/**
	 * The per-method setting keys (enabled/number/qr/instructions) for every method.
	 *
	 * @return string[]
	 */
	public static function method_setting_keys() {
		$keys = array();
		foreach ( array_keys( self::get_methods_config() ) as $method ) {
			foreach ( array( '_enabled', '_number', '_qr', '_instructions' ) as $suffix ) {
				$keys[] = $method . $suffix;
			}
		}
		return $keys;
	}

	/**
	 * The list of editable customer-facing text keys, with field type and label.
	 *
	 * Used to build the admin "Texts" section and to resolve runtime strings.
	 *
	 * @return array<string,array{type:string,label:string}>
	 */
	public static function text_fields() {
		return array(
			'checkout_notice'  => array(
				'type'  => 'textarea',
				'label' => __( 'Checkout notice', 'woo-bd-partial-cod' ),
			),
			'pay_title'        => array(
				'type'  => 'text',
				'label' => __( 'Payment page title', 'woo-bd-partial-cod' ),
			),
			'pay_now_label'    => array(
				'type'  => 'text',
				'label' => __( '"Pay now" amount label', 'woo-bd-partial-cod' ),
			),
			'remaining_label'  => array(
				'type'  => 'text',
				'label' => __( '"Remaining" amount label', 'woo-bd-partial-cod' ),
			),
			'choose_method'    => array(
				'type'  => 'text',
				'label' => __( '"Choose payment method" label', 'woo-bd-partial-cod' ),
			),
			'sender_label'     => array(
				'type'  => 'text',
				'label' => __( 'Sender number field label', 'woo-bd-partial-cod' ),
			),
			'trxid_label'      => array(
				'type'  => 'text',
				'label' => __( 'Transaction ID field label', 'woo-bd-partial-cod' ),
			),
			'submit_button'    => array(
				'type'  => 'text',
				'label' => __( 'Submit button text', 'woo-bd-partial-cod' ),
			),
			'footer'           => array(
				'type'  => 'text',
				'label' => __( 'Payment page footer note', 'woo-bd-partial-cod' ),
			),
			'status_verified'  => array(
				'type'  => 'textarea',
				'label' => __( 'Status message — verified', 'woo-bd-partial-cod' ),
			),
			'status_submitted' => array(
				'type'  => 'textarea',
				'label' => __( 'Status message — submitted', 'woo-bd-partial-cod' ),
			),
			'status_pending'   => array(
				'type'  => 'textarea',
				'label' => __( 'Status message — not paid yet', 'woo-bd-partial-cod' ),
			),
			'pay_button'       => array(
				'type'  => 'text',
				'label' => __( '"Pay now" button text (thank-you page)', 'woo-bd-partial-cod' ),
			),
		);
	}

	/**
	 * Default text for a key, in the given mode.
	 *
	 * Placeholders: {amount} (formatted price) and {label} (advance label).
	 *
	 * @param string $key  Text key.
	 * @param string $mode partial|full.
	 * @return string
	 */
	public static function default_text( $key, $mode = self::MODE_PARTIAL ) {
		$is_full = ( self::MODE_FULL === $mode );

		switch ( $key ) {
			case 'checkout_notice':
				return $is_full
					? __( 'You must pay the full amount of {amount} now via bKash/Nagad/Rocket to confirm this order.', 'woo-bd-partial-cod' )
					: __( 'You must pay {amount} ({label}) now to confirm this order. The remaining balance is collected as cash on delivery.', 'woo-bd-partial-cod' );
			case 'pay_title':
				return $is_full
					? __( 'Complete your payment', 'woo-bd-partial-cod' )
					: __( 'Confirm your order — pay the advance', 'woo-bd-partial-cod' );
			case 'pay_now_label':
				return $is_full
					? __( 'Pay now to confirm', 'woo-bd-partial-cod' )
					: __( 'Pay now to confirm', 'woo-bd-partial-cod' );
			case 'remaining_label':
				return __( 'Remaining (cash on delivery)', 'woo-bd-partial-cod' );
			case 'choose_method':
				return __( 'Choose payment method', 'woo-bd-partial-cod' );
			case 'sender_label':
				return __( 'Your sender mobile number', 'woo-bd-partial-cod' );
			case 'trxid_label':
				return __( 'Transaction ID (TrxID)', 'woo-bd-partial-cod' );
			case 'submit_button':
				return __( 'Submit & confirm order', 'woo-bd-partial-cod' );
			case 'footer':
				return $is_full
					? __( 'Your order stays unconfirmed until your payment is received and verified.', 'woo-bd-partial-cod' )
					: __( 'Your order stays unconfirmed until the advance is paid and verified.', 'woo-bd-partial-cod' );
			case 'status_verified':
				return $is_full
					? __( 'Your payment has been verified. Your order is confirmed!', 'woo-bd-partial-cod' )
					: __( 'Your advance payment has been verified. Your order is confirmed!', 'woo-bd-partial-cod' );
			case 'status_submitted':
				return __( 'Your payment details have been submitted and are awaiting verification. We will confirm your order shortly.', 'woo-bd-partial-cod' );
			case 'status_pending':
				return $is_full
					? __( 'Your order is NOT confirmed yet — your payment is still pending.', 'woo-bd-partial-cod' )
					: __( 'Your order is NOT confirmed yet — the advance payment is still pending.', 'woo-bd-partial-cod' );
			case 'pay_button':
				return $is_full
					? __( 'Pay now', 'woo-bd-partial-cod' )
					: __( 'Pay the advance now', 'woo-bd-partial-cod' );
		}

		return '';
	}

	/**
	 * Resolve a customer-facing string for a gateway: the admin override if set,
	 * otherwise the mode-aware default, with placeholders substituted.
	 *
	 * @param string $gateway_id   Gateway id.
	 * @param string $key          Text key.
	 * @param array  $replacements Map of placeholder name => replacement (no braces).
	 * @return string
	 */
	public static function get_text( $gateway_id, $key, $replacements = array() ) {
		$override = trim( (string) self::get_setting( $gateway_id, 'text_' . $key, '' ) );
		$text     = ( '' !== $override )
			? $override
			: self::default_text( $key, self::gateway_mode( $gateway_id ) );

		foreach ( $replacements as $name => $value ) {
			$text = str_replace( '{' . $name . '}', $value, $text );
		}

		return $text;
	}
}
