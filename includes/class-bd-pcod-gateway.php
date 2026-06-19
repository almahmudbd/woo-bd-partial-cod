<?php
/**
 * The payment gateways: shared base plus the partial-advance gateway.
 *
 * @package WooBDPartialCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared base for both BD manual mobile-payment gateways.
 *
 * Subclasses set $this->id, $this->mode, $this->method_title and
 * $this->method_description, then call parent::__construct().
 */
abstract class BD_PCOD_Gateway_Base extends WC_Payment_Gateway {

	/**
	 * Payment mode: BD_PCOD_Helpers::MODE_PARTIAL or MODE_FULL.
	 *
	 * @var string
	 */
	public $mode = BD_PCOD_Helpers::MODE_PARTIAL;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->has_fields = true;

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', $this->get_default_title() );
		$this->description = $this->get_option( 'description' );
		$this->icon        = $this->get_option( 'icon_url', '' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_settings_assets' ) );
	}

	/**
	 * Whether this gateway collects the full order total (vs a partial advance).
	 *
	 * @return bool
	 */
	protected function is_full_mode() {
		return BD_PCOD_Helpers::MODE_FULL === $this->mode;
	}

	/**
	 * Default checkout title for this gateway.
	 *
	 * @return string
	 */
	protected function get_default_title() {
		return $this->is_full_mode()
			? __( 'Mobile Payment (bKash/Nagad/Rocket)', 'woo-bd-partial-cod' )
			: __( 'Cash on Delivery (advance required)', 'woo-bd-partial-cod' );
	}

	/**
	 * Default checkout description for this gateway.
	 *
	 * @return string
	 */
	protected function get_default_description() {
		return $this->is_full_mode()
			? __( 'Pay the full amount via bKash/Nagad/Rocket. Your order is confirmed once we verify the payment.', 'woo-bd-partial-cod' )
			: __( 'Confirm your order by paying a small advance via bKash/Nagad/Rocket. Pay the rest as cash on delivery.', 'woo-bd-partial-cod' );
	}

	/**
	 * Define the admin settings fields.
	 */
	public function init_form_fields() {
		$fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'woo-bd-partial-cod' ),
				'type'    => 'checkbox',
				/* translators: %s: method title */
				'label'   => sprintf( __( 'Enable %s', 'woo-bd-partial-cod' ), $this->method_title ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'woo-bd-partial-cod' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to the customer at checkout.', 'woo-bd-partial-cod' ),
				'default'     => $this->get_default_title(),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'woo-bd-partial-cod' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the method at checkout, above the auto-generated payment notice.', 'woo-bd-partial-cod' ),
				'default'     => $this->get_default_description(),
			),
			'icon_url'    => array(
				'title'       => __( 'Gateway icon', 'woo-bd-partial-cod' ),
				'type'        => 'bd_pcod_image',
				'description' => __( 'Icon shown next to the method name at checkout and in the payment page header. Leave blank for no icon.', 'woo-bd-partial-cod' ),
				'default'     => 'https://sukkarshop.com/wp-content/uploads/2026/03/cod-icon.png',
			),
		);

		// The advance label / fallback only apply when collecting a partial advance.
		if ( ! $this->is_full_mode() ) {
			$fields['advance_label']    = array(
				'title'       => __( 'Advance label', 'woo-bd-partial-cod' ),
				'type'        => 'text',
				'description' => __( 'Word used to describe the advance, e.g. "delivery charge".', 'woo-bd-partial-cod' ),
				'default'     => __( 'delivery charge', 'woo-bd-partial-cod' ),
				'desc_tip'    => true,
			);
			$fields['fallback_advance'] = array(
				'title'       => __( 'Fallback advance amount', 'woo-bd-partial-cod' ),
				'type'        => 'price',
				'description' => __( 'Used when an order has no shipping fee (e.g. free delivery), so the advance is never zero.', 'woo-bd-partial-cod' ),
				'default'     => '100',
				'desc_tip'    => true,
			);
		}

		// Verification behaviour.
		$fields['verification_section'] = array(
			'title'       => __( 'Verification', 'woo-bd-partial-cod' ),
			'type'        => 'title',
			'description' => __( 'Control what the customer must submit as proof of payment.', 'woo-bd-partial-cod' ),
		);
		$fields['collect_trxid']        = array(
			'title'       => __( 'Transaction ID (TrxID)', 'woo-bd-partial-cod' ),
			'type'        => 'select',
			'description' => __( 'Ask the customer for the bKash/Nagad transaction ID when they submit payment.', 'woo-bd-partial-cod' ),
			'default'     => 'off',
			'desc_tip'    => true,
			'options'     => array(
				'off'      => __( 'Do not ask', 'woo-bd-partial-cod' ),
				'optional' => __( 'Ask (optional)', 'woo-bd-partial-cod' ),
				'required' => __( 'Ask (required)', 'woo-bd-partial-cod' ),
			),
		);
		$fields['sender_number_mode']   = array(
			'title'       => __( 'Sender number', 'woo-bd-partial-cod' ),
			'type'        => 'select',
			'description' => __( 'Full requires a valid 11-digit number. Partial lets the customer confirm with just the last few digits.', 'woo-bd-partial-cod' ),
			'default'     => 'full',
			'desc_tip'    => true,
			'options'     => array(
				'full'    => __( 'Require full 11-digit number', 'woo-bd-partial-cod' ),
				'partial' => __( 'Allow last few digits (3+)', 'woo-bd-partial-cod' ),
			),
		);

		// The full gateway can share the partial gateway's numbers/QR/instructions.
		if ( $this->is_full_mode() ) {
			$fields['methods_section']       = array(
				'title'       => __( 'Payment numbers', 'woo-bd-partial-cod' ),
				'type'        => 'title',
				'description' => __( 'Configure the numbers customers pay to — or reuse the ones already set on the BD Partial COD gateway.', 'woo-bd-partial-cod' ),
			);
			$fields['reuse_partial_methods'] = array(
				'title'       => __( 'Reuse numbers', 'woo-bd-partial-cod' ),
				'type'        => 'select',
				'description' => __( 'Copy once: fills the fields below from the BD Partial COD gateway on save (you can then edit them). Always mirror: ignores the fields below and reads the partial gateway\'s numbers live, so they stay in sync automatically.', 'woo-bd-partial-cod' ),
				'default'     => 'off',
				'options'     => array(
					'off'    => __( 'Use this gateway\'s own numbers', 'woo-bd-partial-cod' ),
					'copy'   => __( 'Copy once from BD Partial COD (on save)', 'woo-bd-partial-cod' ),
					'mirror' => __( 'Always mirror BD Partial COD (live)', 'woo-bd-partial-cod' ),
				),
			);
		}

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
				'label'   => sprintf( __( 'Accept payments via %s', 'woo-bd-partial-cod' ), $label ),
				'default' => isset( $defaults_enabled[ $key ] ) ? $defaults_enabled[ $key ] : 'no',
			);
			$fields[ $key . '_number' ]       = array(
				/* translators: 1: action (Send Money to / Make Payment to), 2: method label */
				'title'       => sprintf( __( '%1$s number (%2$s)', 'woo-bd-partial-cod' ), $action, $label ),
				'type'        => 'text',
				'description' => __( 'The number customers send the payment to.', 'woo-bd-partial-cod' ),
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

		// Editable customer-facing texts. Each is blank by default and falls back
		// to the mode-aware default shown as a placeholder.
		$fields['texts_section'] = array(
			'title'       => __( 'Texts / Labels', 'woo-bd-partial-cod' ),
			'type'        => 'title',
			'description' => __( 'Customise the wording shown to customers. Each field is pre-filled with the default — edit it directly. Use {amount} and {label} as placeholders in the checkout notice.', 'woo-bd-partial-cod' ),
		);

		foreach ( BD_PCOD_Helpers::text_fields() as $key => $field ) {
			$fields[ 'text_' . $key ] = array(
				'title'   => $field['label'],
				'type'    => $field['type'],
				'default' => BD_PCOD_Helpers::default_text( $key, $this->mode ),
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
		if ( ! isset( $_GET['section'] ) || $this->id !== sanitize_text_field( wp_unslash( $_GET['section'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
		return ! empty( BD_PCOD_Helpers::get_enabled_methods( $this->id ) );
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

		$label  = $this->get_option( 'advance_label', __( 'delivery charge', 'woo-bd-partial-cod' ) );
		$notice = BD_PCOD_Helpers::get_text(
			$this->id,
			'checkout_notice',
			array(
				'amount' => '<strong>' . wc_price( $advance ) . '</strong>',
				'label'  => esc_html( $label ),
			)
		);

		echo '<p class="bd-pcod-checkout-notice">' . wp_kses_post( $notice ) . '</p>';
	}

	/**
	 * Estimate the amount due from the current cart for the checkout notice.
	 *
	 * @return float|null
	 */
	protected function get_cart_advance_amount() {
		if ( ! WC()->cart ) {
			return null;
		}

		$total = (float) WC()->cart->get_total( 'edit' );

		if ( $this->is_full_mode() ) {
			return round( $total, wc_get_price_decimals() );
		}

		$shipping = (float) WC()->cart->get_shipping_total();
		if ( $shipping <= 0 ) {
			$shipping = (float) $this->get_option( 'fallback_advance', 0 );
		}

		if ( $total > 0 && $shipping > $total ) {
			$shipping = $total;
		}

		return round( $shipping, wc_get_price_decimals() );
	}

	/**
	 * Process the payment: create the order, store payment details, redirect to the payment page.
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

		// Keep the order pending until the customer submits proof of payment.
		$order->update_status(
			'pending',
			__( 'Awaiting payment via bKash/Nagad/Rocket.', 'woo-bd-partial-cod' )
		);
		$order->save();

		// Reduce stock now so the order holds inventory while awaiting payment.
		wc_reduce_stock_levels( $order_id );

		// Empty the cart and send the customer to the standalone gateway
		// page where they complete the payment.
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => BD_PCOD_Helpers::get_pay_url( $order ),
		);
	}
}

/**
 * Partial-advance gateway: collects the delivery charge up front, rest is COD.
 */
class BD_PCOD_Gateway extends BD_PCOD_Gateway_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = BD_PCOD_GATEWAY_ID;
		$this->mode               = BD_PCOD_Helpers::MODE_PARTIAL;
		$this->method_title       = __( 'BD Partial COD (bKash/Nagad/Rocket)', 'woo-bd-partial-cod' );
		$this->method_description = __( 'Customers pay a partial advance (equal to the delivery charge) via bKash, Nagad, or Rocket to confirm a Cash-on-Delivery order. The remaining balance is collected as cash on delivery. You verify each advance manually.', 'woo-bd-partial-cod' );

		parent::__construct();
	}
}
