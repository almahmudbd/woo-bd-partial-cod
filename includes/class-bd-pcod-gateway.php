<?php
/**
 * The payment gateway: settings, checkout notice, and order creation.
 *
 * @package WooBDPartialCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * BD Partial COD payment gateway.
 */
class BD_PCOD_Gateway extends WC_Payment_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = BD_PCOD_GATEWAY_ID;
		$this->method_title       = __( 'BD Partial COD (bKash/Nagad/Rocket)', 'woo-bd-partial-cod' );
		$this->method_description = __( 'Customers pay a partial advance (equal to the delivery charge) via bKash, Nagad, or Rocket to confirm a Cash-on-Delivery order. The remaining balance is collected as cash on delivery. You verify each advance manually.', 'woo-bd-partial-cod' );
		$this->has_fields         = true;

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Cash on Delivery (advance required)', 'woo-bd-partial-cod' ) );
		$this->description = $this->get_option( 'description' );
		$this->icon        = $this->get_option( 'icon_url', '' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_settings_assets' ) );
	}

	/**
	 * Define the admin settings fields.
	 */
	public function init_form_fields() {
		$fields = array(
			'enabled'          => array(
				'title'   => __( 'Enable/Disable', 'woo-bd-partial-cod' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable BD Partial COD Gateway', 'woo-bd-partial-cod' ),
				'default' => 'no',
			),
			'title'            => array(
				'title'       => __( 'Title', 'woo-bd-partial-cod' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to the customer at checkout.', 'woo-bd-partial-cod' ),
				'default'     => __( 'Cash on Delivery (advance required)', 'woo-bd-partial-cod' ),
				'desc_tip'    => true,
			),
			'description'      => array(
				'title'       => __( 'Description', 'woo-bd-partial-cod' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the method at checkout, above the auto-generated advance notice.', 'woo-bd-partial-cod' ),
				'default'     => __( 'Confirm your order by paying a small advance via bKash/Nagad/Rocket. Pay the rest as cash on delivery.', 'woo-bd-partial-cod' ),
			),
			'icon_url'         => array(
				'title'       => __( 'Checkout icon', 'woo-bd-partial-cod' ),
				'type'        => 'bd_pcod_image',
				'description' => __( 'Icon shown next to the method name at checkout.', 'woo-bd-partial-cod' ),
				'default'     => 'https://sukkarshop.com/wp-content/uploads/2026/03/cod-icon.png',
			),
			'advance_label'    => array(
				'title'       => __( 'Advance label', 'woo-bd-partial-cod' ),
				'type'        => 'text',
				'description' => __( 'Word used to describe the advance, e.g. "delivery charge".', 'woo-bd-partial-cod' ),
				'default'     => __( 'delivery charge', 'woo-bd-partial-cod' ),
				'desc_tip'    => true,
			),
			'fallback_advance' => array(
				'title'       => __( 'Fallback advance amount', 'woo-bd-partial-cod' ),
				'type'        => 'price',
				'description' => __( 'Used when an order has no shipping fee (e.g. free delivery), so the advance is never zero.', 'woo-bd-partial-cod' ),
				'default'     => '100',
				'desc_tip'    => true,
			),
		);

		// Build a settings block for each supported payment method.
		$defaults_enabled = array(
			'bkash_personal' => 'yes',
			'bkash_merchant' => 'no',
			'nagad'          => 'yes',
			'rocket'         => 'no',
		);

		foreach ( BD_PCOD_Helpers::get_methods_config() as $key => $config ) {
			$label  = $config['label'];
			$action = BD_PCOD_Helpers::action_label( $config['action'] );

			$fields[ $key . '_section' ]      = array(
				'title' => $label,
				'type'  => 'title',
			);
			$fields[ $key . '_enabled' ]      = array(
				/* translators: %s: method label */
				'title'   => sprintf( __( 'Enable %s', 'woo-bd-partial-cod' ), $label ),
				'type'    => 'checkbox',
				/* translators: %s: method label */
				'label'   => sprintf( __( 'Accept advance payments via %s', 'woo-bd-partial-cod' ), $label ),
				'default' => isset( $defaults_enabled[ $key ] ) ? $defaults_enabled[ $key ] : 'no',
			);
			$fields[ $key . '_number' ]       = array(
				/* translators: 1: action (Send Money to / Make Payment to), 2: method label */
				'title'       => sprintf( __( '%1$s number (%2$s)', 'woo-bd-partial-cod' ), $action, $label ),
				'type'        => 'text',
				'description' => __( 'The number customers send the advance to.', 'woo-bd-partial-cod' ),
				'placeholder' => '01XXXXXXXXX',
				'desc_tip'    => true,
			);
			$fields[ $key . '_qr' ]           = array(
				/* translators: %s: method label */
				'title'       => sprintf( __( '%s QR image', 'woo-bd-partial-cod' ), $label ),
				'type'        => 'bd_pcod_image',
				'description' => __( 'Upload the QR code image for this method (optional but recommended).', 'woo-bd-partial-cod' ),
			);
			$fields[ $key . '_instructions' ] = array(
				/* translators: %s: method label */
				'title'   => sprintf( __( '%s instructions', 'woo-bd-partial-cod' ), $label ),
				'type'    => 'textarea',
				/* translators: 1: action, 2: method label */
				'default' => sprintf( __( 'Open %2$s → %1$s → enter the number above → enter the exact amount → confirm. Then submit your sender number below.', 'woo-bd-partial-cod' ), $action, $label ),
			);
		}

		$this->form_fields = $fields;
	}

	/**
	 * Render a custom image-picker settings field (uses the WP media uploader).
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_bd_pcod_image_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'       => '',
			'description' => '',
		);
		$data      = wp_parse_args( $data, $defaults );
		$value     = $this->get_option( $key );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<fieldset class="bd-pcod-image-field">
					<input type="url" class="input-text regular-input bd-pcod-image-url" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="https://…" />
					<button type="button" class="button bd-pcod-image-upload"><?php esc_html_e( 'Select image', 'woo-bd-partial-cod' ); ?></button>
					<button type="button" class="button bd-pcod-image-remove"><?php esc_html_e( 'Remove', 'woo-bd-partial-cod' ); ?></button>
					<p class="bd-pcod-image-preview">
						<?php if ( $value ) : ?>
							<img src="<?php echo esc_url( $value ); ?>" alt="" style="max-width:160px;height:auto;display:block;margin-top:8px;" />
						<?php endif; ?>
					</p>
					<?php if ( $data['description'] ) : ?>
						<p class="description"><?php echo wp_kses_post( $data['description'] ); ?></p>
					<?php endif; ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Sanitize the custom image field on save.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_bd_pcod_image_field( $key, $value ) {
		return esc_url_raw( trim( (string) $value ) );
	}

	/**
	 * Enqueue the media uploader on this gateway's settings screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_settings_assets( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		if ( ! isset( $_GET['section'] ) || BD_PCOD_GATEWAY_ID !== sanitize_text_field( wp_unslash( $_GET['section'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script(
			'bd-pcod-admin',
			BD_PCOD_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			BD_PCOD_VERSION,
			true
		);
	}

	/**
	 * Whether this gateway is available for use at checkout.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		// Require at least one mobile method configured.
		return ! empty( BD_PCOD_Helpers::get_enabled_methods() );
	}

	/**
	 * Render the checkout fields / notice for this method.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wpautop( wp_kses_post( wptexturize( $this->description ) ) );
		}

		$advance = $this->get_cart_advance_amount();
		if ( null === $advance ) {
			return;
		}

		$label = $this->get_option( 'advance_label', __( 'delivery charge', 'woo-bd-partial-cod' ) );

		echo '<p class="bd-pcod-checkout-notice">';
		printf(
			/* translators: 1: advance amount, 2: advance label (e.g. delivery charge). */
			wp_kses_post( __( 'You must pay <strong>%1$s</strong> (%2$s) now to confirm this order. The remaining balance is collected as cash on delivery.', 'woo-bd-partial-cod' ) ),
			wp_kses_post( wc_price( $advance ) ),
			esc_html( $label )
		);
		echo '</p>';
	}

	/**
	 * Estimate the advance amount from the current cart for the checkout notice.
	 *
	 * @return float|null
	 */
	protected function get_cart_advance_amount() {
		if ( ! WC()->cart ) {
			return null;
		}

		$shipping = (float) WC()->cart->get_shipping_total();
		if ( $shipping <= 0 ) {
			$shipping = (float) $this->get_option( 'fallback_advance', 0 );
		}

		$total = (float) WC()->cart->get_total( 'edit' );
		if ( $total > 0 && $shipping > $total ) {
			$shipping = $total;
		}

		return round( $shipping, wc_get_price_decimals() );
	}

	/**
	 * Process the payment: create the order, store advance details, redirect to the payment page.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$advance   = BD_PCOD_Helpers::get_advance_amount( $order );
		$remaining = BD_PCOD_Helpers::get_remaining_cod( $order );

		$order->update_meta_data( BD_PCOD_Helpers::META_ADVANCE, $advance );
		$order->update_meta_data( BD_PCOD_Helpers::META_REMAINING, $remaining );
		$order->update_meta_data( BD_PCOD_Helpers::META_STATUS, BD_PCOD_Helpers::STATUS_AWAITING );

		// Keep the order pending until the customer submits proof of the advance payment.
		$order->update_status(
			'pending',
			__( 'Awaiting advance payment via bKash/Nagad.', 'woo-bd-partial-cod' )
		);
		$order->save();

		// Reduce stock now so the order holds inventory while awaiting payment.
		wc_reduce_stock_levels( $order_id );

		// Empty the cart and send the customer to the standalone gateway
		// page where they complete the advance payment.
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => BD_PCOD_Helpers::get_pay_url( $order ),
		);
	}
}
