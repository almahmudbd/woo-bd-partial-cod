<?php
/**
 * Shared helpers: advance-amount calculation, sanitizers, status constants.
 *
 * @package WooBDPartialCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static helper methods used across the gateway, payment page, and admin UI.
 */
class BD_PCOD_Helpers {

	// Meta keys stored on the order.
	const META_ADVANCE   = '_bd_pcod_advance_amount';
	const META_REMAINING = '_bd_pcod_remaining_cod';
	const META_STATUS    = '_bd_pcod_status';
	const META_METHOD    = '_bd_pcod_method';
	const META_SENDER    = '_bd_pcod_sender_number';

	// Payment workflow statuses (stored in META_STATUS).
	const STATUS_AWAITING  = 'awaiting_payment'; // Order placed, customer has not submitted proof yet.
	const STATUS_SUBMITTED = 'submitted';        // Customer submitted sender number/TrxID, awaiting admin.
	const STATUS_VERIFIED  = 'verified';         // Admin confirmed the advance was received.
	const STATUS_REJECTED  = 'rejected';         // Admin rejected the submission.

	/**
	 * Get the gateway settings array.
	 *
	 * @return array
	 */
	public static function get_settings() {
		return get_option( 'woocommerce_' . BD_PCOD_GATEWAY_ID . '_settings', array() );
	}

	/**
	 * Read a single gateway setting with a default.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_setting( $key, $default = '' ) {
		$settings = self::get_settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Calculate the advance amount due for an order.
	 *
	 * The advance equals the order's shipping/delivery total. When shipping is
	 * zero (e.g. free delivery) we fall back to the configured fixed amount so
	 * the advance is never ৳0.
	 *
	 * @param WC_Order $order The order.
	 * @return float
	 */
	public static function get_advance_amount( $order ) {
		$shipping = (float) $order->get_shipping_total();

		if ( $shipping <= 0 ) {
			$shipping = (float) self::get_setting( 'fallback_advance', 0 );
		}

		// Never exceed the order total.
		$total = (float) $order->get_total();
		if ( $shipping > $total ) {
			$shipping = $total;
		}

		/**
		 * Filter the calculated advance amount.
		 *
		 * @param float    $shipping Advance amount.
		 * @param WC_Order $order    Order object.
		 */
		return (float) apply_filters( 'bd_pcod_advance_amount', round( $shipping, wc_get_price_decimals() ), $order );
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
	 * Whether a given order used this gateway.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	public static function is_our_order( $order ) {
		return $order instanceof WC_Order && BD_PCOD_GATEWAY_ID === $order->get_payment_method();
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
	 * Return the enabled payment methods with their configured details.
	 *
	 * @return array[] Keyed by method slug: array{ label, action, number, qr, instructions }.
	 */
	public static function get_enabled_methods() {
		$methods = array();

		foreach ( self::get_methods_config() as $key => $config ) {
			if ( 'yes' !== self::get_setting( $key . '_enabled', 'no' ) ) {
				continue;
			}

			$number = trim( (string) self::get_setting( $key . '_number', '' ) );
			if ( '' === $number ) {
				continue;
			}

			$methods[ $key ] = array(
				'label'        => $config['label'],
				'action'       => $config['action'],
				'number'       => $number,
				'qr'           => esc_url_raw( (string) self::get_setting( $key . '_qr', '' ) ),
				'instructions' => (string) self::get_setting( $key . '_instructions', '' ),
			);
		}

		return $methods;
	}
}
