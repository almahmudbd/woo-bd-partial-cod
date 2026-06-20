<?php
/**
 * Manual bank transfer gateway.
 *
 * Customers transfer the full order total to one of the configured bank
 * accounts and confirm with their own account number (or last 4 digits).
 * The store owner verifies the transfer manually before confirming the order.
 *
 * @package BDPartialCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce gateway for manual bank transfers.
 */
class BD_PCOD_Bank_Gateway extends WC_Payment_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = BD_PCOD_BANK_GATEWAY_ID;
		$this->method_title       = __( 'BD Manual Bank Transfer', 'aam-partial-cod' );
		$this->method_description = __( 'Customers transfer the full order amount to one of your bank accounts and submit their account details for verification. No API keys required.', 'aam-partial-cod' );
		$this->has_fields         = true;

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Bank Transfer (manual verification)', 'aam-partial-cod' ) );
		$this->description = $this->get_option( 'description' );
		$this->icon        = $this->get_option( 'icon_url', '' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Admin settings form fields.
	 */
	public function init_form_fields() {
		$fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'aam-partial-cod' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Manual Bank Transfer', 'aam-partial-cod' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'aam-partial-cod' ),
				'type'        => 'text',
				'default'     => __( 'Bank Transfer (manual verification)', 'aam-partial-cod' ),
				'desc_tip'    => true,
				'description' => __( 'Payment method title shown to the customer at checkout.', 'aam-partial-cod' ),
			),
			'description' => array(
				'title'   => __( 'Description', 'aam-partial-cod' ),
				'type'    => 'textarea',
				'default' => __( 'Transfer the full order amount to our bank account and submit your details. We will confirm once the transfer is verified.', 'aam-partial-cod' ),
			),
			'icon_url'    => array(
				'title'       => __( 'Gateway icon URL', 'aam-partial-cod' ),
				'type'        => 'text',
				'description' => __( 'Icon shown next to the method name at checkout. Leave blank for no icon.', 'aam-partial-cod' ),
				'desc_tip'    => true,
				'default'     => '',
				'placeholder' => 'https://…',
			),
		);

		// Five configurable bank account slots.
		for ( $i = 1; $i <= 5; $i++ ) {
			$k                     = 'bank_' . $i;
			$fields[ $k . '_section' ] = array(
				/* translators: %d: slot number */
				'title' => sprintf( __( 'Bank Account %d', 'aam-partial-cod' ), $i ),
				'type'  => 'title',
			);
			$fields[ $k . '_enabled' ] = array(
				'title'   => __( 'Enable', 'aam-partial-cod' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show this bank account', 'aam-partial-cod' ),
				'default' => 'no',
			);
			$fields[ $k . '_name' ]    = array(
				'title'       => __( 'Bank name', 'aam-partial-cod' ),
				'type'        => 'text',
				'placeholder' => __( 'e.g. Dutch-Bangla Bank', 'aam-partial-cod' ),
				'default'     => '',
			);
			$fields[ $k . '_account_name' ]   = array(
				'title'       => __( 'Account name', 'aam-partial-cod' ),
				'type'        => 'text',
				'placeholder' => __( 'Account holder name', 'aam-partial-cod' ),
				'default'     => '',
			);
			$fields[ $k . '_account_number' ] = array(
				'title'       => __( 'Account number', 'aam-partial-cod' ),
				'type'        => 'text',
				'placeholder' => '0000000000000',
				'default'     => '',
			);
			$fields[ $k . '_branch' ]  = array(
				'title'       => __( 'Branch name', 'aam-partial-cod' ),
				'type'        => 'text',
				'placeholder' => __( 'e.g. Motijheel Branch (optional)', 'aam-partial-cod' ),
				'default'     => '',
			);
			$fields[ $k . '_routing' ] = array(
				'title'       => __( 'Routing number', 'aam-partial-cod' ),
				'type'        => 'text',
				'placeholder' => __( 'optional', 'aam-partial-cod' ),
				'default'     => '',
			);
			$fields[ $k . '_phone' ]   = array(
				'title'       => __( 'Phone / contact', 'aam-partial-cod' ),
				'type'        => 'text',
				'placeholder' => __( 'optional', 'aam-partial-cod' ),
				'default'     => '',
			);
		}

		$this->form_fields = $fields;
	}

	/**
	 * Show the gateway at checkout only when at least one bank account is configured.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		return ! empty( BD_PCOD_Helpers::get_enabled_banks() );
	}

	/**
	 * Render the checkout description / amount notice.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wpautop( wp_kses_post( wptexturize( $this->description ) ) );
		}
		if ( WC()->cart ) {
			$total = (float) WC()->cart->get_total( 'edit' );
			printf(
				'<p class="bd-pcod-checkout-notice">%s</p>',
				wp_kses(
					sprintf(
						/* translators: %s: formatted price */
						__( 'Transfer <strong>%s</strong> via bank transfer to confirm your order.', 'aam-partial-cod' ),
						wc_price( $total )
					),
					array( 'strong' => array(), 'span' => array( 'class' => array() ) )
				)
			);
		}
	}

	/**
	 * Create the order, store the amount, and redirect to the bank payment page.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order   = wc_get_order( $order_id );
		$advance = round( (float) $order->get_total(), wc_get_price_decimals() );

		$order->update_meta_data( BD_PCOD_Helpers::META_ADVANCE, $advance );
		$order->update_meta_data( BD_PCOD_Helpers::META_REMAINING, 0 );
		$order->update_meta_data( BD_PCOD_Helpers::META_STATUS, BD_PCOD_Helpers::STATUS_AWAITING );
		$order->update_status( 'pending', __( 'Awaiting bank transfer confirmation.', 'aam-partial-cod' ) );
		$order->save();

		wc_reduce_stock_levels( $order_id );

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => BD_PCOD_Helpers::get_pay_url( $order ),
		);
	}
}
