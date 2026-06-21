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
		$this->method_title       = __( 'BD Manual Bank Transfer', 'aam-bd-partial-cod-for-wc' );
		$this->method_description = __( 'Customers transfer the full order amount to one of your bank accounts and submit their account details for verification. No API keys required.', 'aam-bd-partial-cod-for-wc' );
		$this->has_fields         = true;

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Bank Transfer (manual verification)', 'aam-bd-partial-cod-for-wc' ) );
		$this->description = $this->get_option( 'description' );
		$icon_url          = trim( (string) $this->get_option( 'icon_url', '' ) );
		$this->icon        = ( '' !== $icon_url ) ? $icon_url : BD_PCOD_Helpers::default_icon( $this->id );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Admin settings form fields.
	 */
	public function init_form_fields() {
		$fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'aam-bd-partial-cod-for-wc' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Manual Bank Transfer', 'aam-bd-partial-cod-for-wc' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'aam-bd-partial-cod-for-wc' ),
				'type'        => 'text',
				'default'     => __( 'Bank Transfer (manual verification)', 'aam-bd-partial-cod-for-wc' ),
				'desc_tip'    => true,
				'description' => __( 'Payment method title shown to the customer at checkout.', 'aam-bd-partial-cod-for-wc' ),
			),
			'description' => array(
				'title'   => __( 'Description', 'aam-bd-partial-cod-for-wc' ),
				'type'    => 'textarea',
				'default' => __( 'Transfer the full order amount to our bank account and submit your details. We will confirm once the transfer is verified.', 'aam-bd-partial-cod-for-wc' ),
			),
			'icon_url'    => array(
				'title'       => __( 'Gateway icon URL', 'aam-bd-partial-cod-for-wc' ),
				'type'        => 'text',
				'description' => __( 'Icon shown next to the method name at checkout. Leave blank for no icon.', 'aam-bd-partial-cod-for-wc' ),
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
				'title' => sprintf( __( 'Bank Account %d', 'aam-bd-partial-cod-for-wc' ), $i ),
				'type'  => 'title',
			);
			$fields[ $k . '_enabled' ] = array(
				'title'   => __( 'Enable', 'aam-bd-partial-cod-for-wc' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show this bank account', 'aam-bd-partial-cod-for-wc' ),
				'default' => 'no',
			);
			$fields[ $k . '_name' ]    = array(
				'title'       => __( 'Bank name', 'aam-bd-partial-cod-for-wc' ),
				'type'        => 'text',
				'placeholder' => __( 'e.g. Dutch-Bangla Bank', 'aam-bd-partial-cod-for-wc' ),
				'default'     => '',
			);
			$fields[ $k . '_account_name' ]   = array(
				'title'       => __( 'Account name', 'aam-bd-partial-cod-for-wc' ),
				'type'        => 'text',
				'placeholder' => __( 'Account holder name', 'aam-bd-partial-cod-for-wc' ),
				'default'     => '',
			);
			$fields[ $k . '_account_number' ] = array(
				'title'       => __( 'Account number', 'aam-bd-partial-cod-for-wc' ),
				'type'        => 'text',
				'placeholder' => '0000000000000',
				'default'     => '',
			);
			$fields[ $k . '_branch' ]  = array(
				'title'       => __( 'Branch name', 'aam-bd-partial-cod-for-wc' ),
				'type'        => 'text',
				'placeholder' => __( 'e.g. Motijheel Branch (optional)', 'aam-bd-partial-cod-for-wc' ),
				'default'     => '',
			);
			$fields[ $k . '_routing' ] = array(
				'title'       => __( 'Routing number', 'aam-bd-partial-cod-for-wc' ),
				'type'        => 'text',
				'placeholder' => __( 'optional', 'aam-bd-partial-cod-for-wc' ),
				'default'     => '',
			);
			$fields[ $k . '_phone' ]   = array(
				'title'       => __( 'Phone / contact', 'aam-bd-partial-cod-for-wc' ),
				'type'        => 'text',
				'placeholder' => __( 'optional', 'aam-bd-partial-cod-for-wc' ),
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
			$description = wpautop( wptexturize( $this->description ) );
			echo wp_kses_post( $description );
		}
		if ( WC()->cart ) {
			$total = (float) WC()->cart->get_total( 'edit' );
			printf(
				'<p class="bd-pcod-checkout-notice">%s</p>',
				wp_kses(
					sprintf(
						/* translators: %s: formatted price */
						__( 'Transfer <strong>%s</strong> via bank transfer to confirm your order.', 'aam-bd-partial-cod-for-wc' ),
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
		$order->update_status( 'pending', __( 'Awaiting bank transfer confirmation.', 'aam-bd-partial-cod-for-wc' ) );
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
