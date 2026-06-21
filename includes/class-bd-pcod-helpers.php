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
			BD_PCOD_BANK_GATEWAY_ID => self::MODE_FULL,
		);
	}

	// Option holding the master visibility toggles (which gateways/methods are exposed).
	const OPTION_VISIBILITY = 'bd_pcod_visibility';

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
	 * Human-readable label for a gateway id (used on the visibility settings page).
	 *
	 * @param string $gateway_id Gateway id.
	 * @return string
	 */
	public static function gateway_label( $gateway_id ) {
		$labels = array(
			BD_PCOD_GATEWAY_ID      => __( 'AAM Partial COD (bKash/Nagad/Rocket)', 'aam-bd-partial-cod-for-wc' ),
			BD_PCOD_FULL_GATEWAY_ID => __( 'Full Mobile Payment (bKash/Nagad/Rocket)', 'aam-bd-partial-cod-for-wc' ),
			BD_PCOD_BANK_GATEWAY_ID => __( 'Manual Bank Transfer', 'aam-bd-partial-cod-for-wc' ),
		);
		return isset( $labels[ $gateway_id ] ) ? $labels[ $gateway_id ] : $gateway_id;
	}

	/**
	 * The master visibility toggles, as a structured array.
	 *
	 * @return array{gateways:array<string,int>,methods:array<string,int>}
	 */
	public static function get_visibility() {
		$opt = get_option( self::OPTION_VISIBILITY, array() );
		return is_array( $opt ) ? $opt : array();
	}

	/**
	 * Whether a gateway is exposed (registered with WooCommerce).
	 *
	 * Defaults to visible when never configured, so existing installs are unaffected.
	 *
	 * @param string $gateway_id Gateway id.
	 * @return bool
	 */
	public static function is_gateway_visible( $gateway_id ) {
		$visibility = self::get_visibility();
		if ( ! isset( $visibility['gateways'] ) || ! array_key_exists( $gateway_id, (array) $visibility['gateways'] ) ) {
			return true;
		}
		return ! empty( $visibility['gateways'][ $gateway_id ] );
	}

	/**
	 * Whether a payment method is exposed (shown in settings and offered to customers).
	 *
	 * Defaults to visible when never configured.
	 *
	 * @param string $method Method key.
	 * @return bool
	 */
	public static function is_method_visible( $method ) {
		$visibility = self::get_visibility();
		if ( ! isset( $visibility['methods'] ) || ! array_key_exists( $method, (array) $visibility['methods'] ) ) {
			return true;
		}
		return ! empty( $visibility['methods'][ $method ] );
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
	 * Default checkout icon URL for a gateway, bundled with the plugin.
	 *
	 * @param string $gateway_id Gateway id.
	 * @return string
	 */
	public static function default_icon( $gateway_id ) {
		if ( BD_PCOD_BANK_GATEWAY_ID === $gateway_id ) {
			$file = 'assets/bank-icon.png';
		} elseif ( self::MODE_FULL === self::gateway_mode( $gateway_id ) ) {
			$file = 'assets/desi-gateways.jpg';
		} else {
			$file = 'assets/cod-icon.png';
		}

		return BD_PCOD_URL . $file;
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
	 * Calculate the partial advance for a given total/shipping pair.
	 *
	 * Supports three strategies (the gateway's "advance_type" setting):
	 *  - delivery_charge: the order's shipping total (the original behaviour);
	 *  - percentage:      a percentage of the order total, optionally rounded;
	 *  - fixed:           a flat amount.
	 *
	 * Whatever the strategy, the fallback amount is used when the result would be
	 * zero (e.g. free delivery, or an unconfigured amount) so the advance is never
	 * ৳0, and the advance never exceeds the order total.
	 *
	 * @param float  $total      Order/cart total.
	 * @param float  $shipping   Order/cart shipping total.
	 * @param string $gateway_id Gateway id whose settings to read.
	 * @return float
	 */
	public static function calculate_partial_advance( $total, $shipping, $gateway_id ) {
		$total    = (float) $total;
		$shipping = (float) $shipping;
		$type     = self::get_setting( $gateway_id, 'advance_type', 'delivery_charge' );

		switch ( $type ) {
			case 'percentage':
				$percent = (float) self::get_setting( $gateway_id, 'advance_percentage', 0 );
				$amount  = $total * ( $percent / 100 );
				$amount  = self::round_to_step( $amount, (float) self::get_setting( $gateway_id, 'advance_rounding', 0 ) );
				break;

			case 'fixed':
				$amount = (float) self::get_setting( $gateway_id, 'advance_fixed', 0 );
				break;

			case 'delivery_charge':
			default:
				$amount = $shipping;
				break;
		}

		// Never leave the advance at zero — fall back to the configured amount.
		if ( $amount <= 0 ) {
			$amount = (float) self::get_setting( $gateway_id, 'fallback_advance', 0 );
		}

		// Never exceed the order total.
		if ( $total > 0 && $amount > $total ) {
			$amount = $total;
		}

		return round( $amount, wc_get_price_decimals() );
	}

	/**
	 * Round an amount to the nearest step (e.g. nearest 10). A step of 0 (or less)
	 * leaves the amount untouched.
	 *
	 * @param float $amount Amount to round.
	 * @param float $step   Rounding step.
	 * @return float
	 */
	public static function round_to_step( $amount, $step ) {
		$step = (float) $step;
		if ( $step <= 0 ) {
			return (float) $amount;
		}
		return round( $amount / $step ) * $step;
	}

	/**
	 * Calculate the advance amount due for an order.
	 *
	 * In full mode this is the entire order total. In partial mode it is resolved
	 * by {@see self::calculate_partial_advance()} from the gateway's configured
	 * advance strategy.
	 *
	 * @param WC_Order $order The order.
	 * @return float
	 */
	public static function get_advance_amount( $order ) {
		$total = (float) $order->get_total();

		if ( self::MODE_FULL === self::order_mode( $order ) ) {
			$amount = round( $total, wc_get_price_decimals() );
		} else {
			$amount = self::calculate_partial_advance( $total, (float) $order->get_shipping_total(), $order->get_payment_method() );
		}

		/**
		 * Filter the calculated advance amount.
		 *
		 * @param float    $amount Advance amount.
		 * @param WC_Order $order  Order object.
		 */
		return (float) apply_filters( 'bd_pcod_advance_amount', $amount, $order );
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
			'bkash_merchant'  => array(
				'label'  => __( 'bKash (Merchant)', 'aam-bd-partial-cod-for-wc' ),
				'action' => 'payment',
			),
			'bkash_personal'  => array(
				'label'  => __( 'bKash (Personal)', 'aam-bd-partial-cod-for-wc' ),
				'action' => 'send',
			),
			'nagad_merchant'  => array(
				'label'  => __( 'Nagad (Merchant)', 'aam-bd-partial-cod-for-wc' ),
				'action' => 'payment',
			),
			'nagad_personal'  => array(
				'label'  => __( 'Nagad (Personal)', 'aam-bd-partial-cod-for-wc' ),
				'action' => 'send',
			),
			'rocket_merchant' => array(
				'label'  => __( 'Rocket (Merchant)', 'aam-bd-partial-cod-for-wc' ),
				'action' => 'payment',
			),
			'rocket_personal' => array(
				'label'  => __( 'Rocket (Personal)', 'aam-bd-partial-cod-for-wc' ),
				'action' => 'send',
			),
		);
	}

	/**
	 * Map of legacy single-account method keys to their new "personal" equivalents.
	 *
	 * Older versions stored Nagad/Rocket as a single "Send Money" method; those are
	 * the personal-account variants now, so existing numbers/QR/instructions migrate
	 * onto the *_personal keys. (bKash already had personal/merchant variants.)
	 *
	 * @return array<string,string> Old method key => new method key.
	 */
	public static function legacy_method_map() {
		return array(
			'nagad'  => 'nagad_personal',
			'rocket' => 'rocket_personal',
		);
	}

	/**
	 * One-time migration: rename legacy Nagad/Rocket method settings (and their
	 * visibility toggles) to the new *_personal keys, preserving the store's
	 * configured numbers, QR images and instructions.
	 *
	 * Runs once per upgrade (guarded by a stored DB version) and is a no-op once
	 * the legacy keys are gone.
	 */
	public static function migrate_legacy_method_keys() {
		$map      = self::legacy_method_map();
		$suffixes = array( '_enabled', '_number', '_qr', '_instructions' );

		// Per-gateway method settings (both the partial and full gateways).
		foreach ( array_keys( self::gateways() ) as $gateway_id ) {
			$option   = 'woocommerce_' . $gateway_id . '_settings';
			$settings = get_option( $option, array() );
			if ( ! is_array( $settings ) || empty( $settings ) ) {
				continue;
			}

			$changed = false;
			foreach ( $map as $old => $new ) {
				foreach ( $suffixes as $suffix ) {
					$old_key = $old . $suffix;
					if ( ! array_key_exists( $old_key, $settings ) ) {
						continue;
					}
					$new_key = $new . $suffix;
					if ( ! array_key_exists( $new_key, $settings ) ) {
						$settings[ $new_key ] = $settings[ $old_key ];
					}
					unset( $settings[ $old_key ] );
					$changed = true;
				}
			}

			if ( $changed ) {
				update_option( $option, $settings );
			}
		}

		// Master visibility toggles.
		$visibility = get_option( self::OPTION_VISIBILITY, array() );
		if ( is_array( $visibility ) && isset( $visibility['methods'] ) && is_array( $visibility['methods'] ) ) {
			$changed = false;
			foreach ( $map as $old => $new ) {
				if ( ! array_key_exists( $old, $visibility['methods'] ) ) {
					continue;
				}
				if ( ! array_key_exists( $new, $visibility['methods'] ) ) {
					$visibility['methods'][ $new ] = $visibility['methods'][ $old ];
				}
				unset( $visibility['methods'][ $old ] );
				$changed = true;
			}
			if ( $changed ) {
				update_option( self::OPTION_VISIBILITY, $visibility );
			}
		}
	}

	/**
	 * Customer-facing instruction label for a method's action.
	 *
	 * @param string $action send|payment.
	 * @return string
	 */
	public static function action_label( $action ) {
		return 'payment' === $action
			? __( 'Make Payment to', 'aam-bd-partial-cod-for-wc' )
			: __( 'Send Money to', 'aam-bd-partial-cod-for-wc' );
	}

	/**
	 * Human-readable label for a payment method key.
	 *
	 * @param string $method Method key.
	 * @return string
	 */
	public static function method_label( $method ) {
		$config = self::get_methods_config();
		if ( isset( $config[ $method ] ) ) {
			return $config[ $method ]['label'];
		}
		// Bank account slots: resolve from enabled banks for a live label.
		if ( 0 === strpos( $method, 'bank_' ) ) {
			$banks = self::get_enabled_banks();
			if ( isset( $banks[ $method ] ) && '' !== $banks[ $method ]['name'] ) {
				return $banks[ $method ]['name'];
			}
		}
		return ucwords( str_replace( '_', ' ', (string) $method ) );
	}

	/**
	 * Return all enabled bank accounts configured on the bank transfer gateway.
	 *
	 * @return array[] Keyed by slot key (bank_1 … bank_5).
	 */
	public static function get_enabled_banks() {
		$banks    = array();
		$settings = self::get_settings( BD_PCOD_BANK_GATEWAY_ID );

		for ( $i = 1; $i <= 5; $i++ ) {
			$k = 'bank_' . $i;
			if ( 'yes' !== ( isset( $settings[ $k . '_enabled' ] ) ? $settings[ $k . '_enabled' ] : 'no' ) ) {
				continue;
			}
			$acct = trim( isset( $settings[ $k . '_account_number' ] ) ? $settings[ $k . '_account_number' ] : '' );
			if ( '' === $acct ) {
				continue;
			}
			$banks[ $k ] = array(
				'name'           => trim( isset( $settings[ $k . '_name' ] ) ? $settings[ $k . '_name' ] : '' ),
				'account_name'   => trim( isset( $settings[ $k . '_account_name' ] ) ? $settings[ $k . '_account_name' ] : '' ),
				'account_number' => $acct,
				'branch'         => trim( isset( $settings[ $k . '_branch' ] ) ? $settings[ $k . '_branch' ] : '' ),
				'routing'        => trim( isset( $settings[ $k . '_routing' ] ) ? $settings[ $k . '_routing' ] : '' ),
				'phone'          => trim( isset( $settings[ $k . '_phone' ] ) ? $settings[ $k . '_phone' ] : '' ),
			);
		}

		return $banks;
	}

	/**
	 * Sanitize a bank account confirmation entry.
	 *
	 * Accepts 4–30 digits (last few digits, or full account number).
	 *
	 * @param string $raw Raw input.
	 * @return string|false Digits only, or false if invalid.
	 */
	public static function sanitize_bank_account( $raw ) {
		$digits = preg_replace( '/\D+/', '', (string) $raw );
		$len    = strlen( $digits );
		return ( $len >= 4 && $len <= 30 ) ? $digits : false;
	}

	/**
	 * Human-readable label for a workflow status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			self::STATUS_AWAITING  => __( 'Awaiting payment', 'aam-bd-partial-cod-for-wc' ),
			self::STATUS_SUBMITTED => __( 'Submitted — needs review', 'aam-bd-partial-cod-for-wc' ),
			self::STATUS_VERIFIED  => __( 'Verified', 'aam-bd-partial-cod-for-wc' ),
			self::STATUS_REJECTED  => __( 'Rejected', 'aam-bd-partial-cod-for-wc' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Unknown', 'aam-bd-partial-cod-for-wc' );
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
			// Hidden by the master visibility settings — never offer it.
			if ( ! self::is_method_visible( $key ) ) {
				continue;
			}

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
				'label' => __( 'Checkout notice', 'aam-bd-partial-cod-for-wc' ),
			),
			'pay_title'        => array(
				'type'  => 'text',
				'label' => __( 'Payment page title', 'aam-bd-partial-cod-for-wc' ),
			),
			'pay_now_label'    => array(
				'type'  => 'text',
				'label' => __( '"Pay now" amount label', 'aam-bd-partial-cod-for-wc' ),
			),
			'remaining_label'  => array(
				'type'  => 'text',
				'label' => __( '"Remaining" amount label', 'aam-bd-partial-cod-for-wc' ),
			),
			'choose_method'    => array(
				'type'  => 'text',
				'label' => __( '"Choose payment method" label', 'aam-bd-partial-cod-for-wc' ),
			),
			'sender_label'     => array(
				'type'  => 'text',
				'label' => __( 'Sender number field label', 'aam-bd-partial-cod-for-wc' ),
			),
			'trxid_label'      => array(
				'type'  => 'text',
				'label' => __( 'Transaction ID field label', 'aam-bd-partial-cod-for-wc' ),
			),
			'submit_button'    => array(
				'type'  => 'text',
				'label' => __( 'Submit button text', 'aam-bd-partial-cod-for-wc' ),
			),
			'footer'           => array(
				'type'  => 'text',
				'label' => __( 'Payment page footer note', 'aam-bd-partial-cod-for-wc' ),
			),
			'status_verified'  => array(
				'type'  => 'textarea',
				'label' => __( 'Status message — verified', 'aam-bd-partial-cod-for-wc' ),
			),
			'status_submitted' => array(
				'type'  => 'textarea',
				'label' => __( 'Status message — submitted', 'aam-bd-partial-cod-for-wc' ),
			),
			'status_pending'   => array(
				'type'  => 'textarea',
				'label' => __( 'Status message — not paid yet', 'aam-bd-partial-cod-for-wc' ),
			),
			'pay_button'       => array(
				'type'  => 'text',
				'label' => __( '"Pay now" button text (thank-you page)', 'aam-bd-partial-cod-for-wc' ),
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
					? __( 'You must pay the full amount of {amount} now via bKash/Nagad/Rocket to confirm this order.', 'aam-bd-partial-cod-for-wc' )
					: __( 'You must pay {amount} ({label}) now to confirm this order. The remaining balance is collected as cash on delivery.', 'aam-bd-partial-cod-for-wc' );
			case 'pay_title':
				return $is_full
					? __( 'Complete your payment', 'aam-bd-partial-cod-for-wc' )
					: __( 'Confirm your order — pay the advance', 'aam-bd-partial-cod-for-wc' );
			case 'pay_now_label':
				return $is_full
					? __( 'Pay now to confirm', 'aam-bd-partial-cod-for-wc' )
					: __( 'Pay now to confirm', 'aam-bd-partial-cod-for-wc' );
			case 'remaining_label':
				return __( 'Remaining (cash on delivery)', 'aam-bd-partial-cod-for-wc' );
			case 'choose_method':
				return __( 'Choose payment method', 'aam-bd-partial-cod-for-wc' );
			case 'sender_label':
				return __( 'Your sender mobile number', 'aam-bd-partial-cod-for-wc' );
			case 'trxid_label':
				return __( 'Transaction ID (TrxID)', 'aam-bd-partial-cod-for-wc' );
			case 'submit_button':
				return __( 'Submit & confirm order', 'aam-bd-partial-cod-for-wc' );
			case 'footer':
				return $is_full
					? __( 'Your order stays unconfirmed until your payment is received and verified.', 'aam-bd-partial-cod-for-wc' )
					: __( 'Your order stays unconfirmed until the advance is paid and verified.', 'aam-bd-partial-cod-for-wc' );
			case 'status_verified':
				return $is_full
					? __( 'Your payment has been verified. Your order is confirmed!', 'aam-bd-partial-cod-for-wc' )
					: __( 'Your advance payment has been verified. Your order is confirmed!', 'aam-bd-partial-cod-for-wc' );
			case 'status_submitted':
				return __( 'Your payment details have been submitted and are awaiting verification. We will confirm your order shortly.', 'aam-bd-partial-cod-for-wc' );
			case 'status_pending':
				return $is_full
					? __( 'Your order is NOT confirmed yet — your payment is still pending.', 'aam-bd-partial-cod-for-wc' )
					: __( 'Your order is NOT confirmed yet — the advance payment is still pending.', 'aam-bd-partial-cod-for-wc' );
			case 'pay_button':
				return $is_full
					? __( 'Pay now', 'aam-bd-partial-cod-for-wc' )
					: __( 'Pay the advance now', 'aam-bd-partial-cod-for-wc' );
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
