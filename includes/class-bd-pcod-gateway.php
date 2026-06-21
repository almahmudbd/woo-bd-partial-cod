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
		$this->icon        = $this->get_option( 'icon_url', BD_PCOD_Helpers::default_icon( $this->id ) );

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
	 * Read a setting, but fall back to the mode-aware default for empty "text_*"
	 * fields so the admin always sees editable default wording (even if an earlier
	 * save stored an empty value, which would otherwise suppress the field default).
	 *
	 * @param string $key         Setting key.
	 * @param mixed  $empty_value Value to treat as empty.
	 * @return mixed
	 */
	public function get_option( $key, $empty_value = null ) {
		$value = parent::get_option( $key, $empty_value );

		if ( ( '' === $value || null === $value ) && 0 === strpos( $key, 'text_' ) ) {
			$default = BD_PCOD_Helpers::default_text( substr( $key, 5 ), $this->mode );
			if ( '' !== $default ) {
				return $default;
			}
		}

		return $value;
	}

	/**
	 * Default checkout title for this gateway.
	 *
	 * @return string
	 */
	protected function get_default_title() {
		return $this->is_full_mode()
			? __( 'Mobile Payment (bKash/Nagad/Rocket)', 'aam-bd-partial-cod-for-wc' )
			: __( 'Cash on Delivery (advance required)', 'aam-bd-partial-cod-for-wc' );
	}

	/**
	 * Default checkout description for this gateway.
	 *
	 * @return string
	 */
	protected function get_default_description() {
		return $this->is_full_mode()
			? __( 'Pay the full amount via bKash/Nagad/Rocket. Your order is confirmed once we verify the payment.', 'aam-bd-partial-cod-for-wc' )
			: __( 'Confirm your order by paying a small advance via bKash/Nagad/Rocket. Pay the rest as cash on delivery.', 'aam-bd-partial-cod-for-wc' );
	}

	/**
	 * Define the admin settings fields.
	 */
	public function init_form_fields() {
		$fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'aam-bd-partial-cod-for-wc' ),
				'type'    => 'checkbox',
				/* translators: %s: method title */
				'label'   => sprintf( __( 'Enable %s', 'aam-bd-partial-cod-for-wc' ), $this->method_title ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'aam-bd-partial-cod-for-wc' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to the customer at checkout.', 'aam-bd-partial-cod-for-wc' ),
				'default'     => $this->get_default_title(),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'aam-bd-partial-cod-for-wc' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the method at checkout, above the auto-generated payment notice.', 'aam-bd-partial-cod-for-wc' ),
				'default'     => $this->get_default_description(),
			),
			'icon_url'    => array(
				'title'       => __( 'Gateway icon', 'aam-bd-partial-cod-for-wc' ),
				'type'        => 'bd_pcod_image',
				'description' => __( 'Icon shown next to the method name at checkout and in the payment page header. Leave blank to use the bundled default icon.', 'aam-bd-partial-cod-for-wc' ),
				'default'     => BD_PCOD_Helpers::default_icon( $this->id ),
			),
		);

		// The advance calculation / label / fallback only apply when collecting a partial advance.
		if ( ! $this->is_full_mode() ) {
			$fields['advance_type']       = array(
				'title'       => __( 'Advance amount based on', 'aam-bd-partial-cod-for-wc' ),
				'type'        => 'select',
				'description' => __( 'How the required advance is calculated for each order.', 'aam-bd-partial-cod-for-wc' ),
				'default'     => 'delivery_charge',
				'desc_tip'    => true,
				'options'     => array(
					'delivery_charge' => __( 'Delivery charge (order shipping total)', 'aam-bd-partial-cod-for-wc' ),
					'percentage'      => __( 'Percentage of the order total', 'aam-bd-partial-cod-for-wc' ),
					'fixed'           => __( 'Fixed amount', 'aam-bd-partial-cod-for-wc' ),
				),
			);
			$fields['advance_percentage'] = array(
				'title'             => __( 'Advance percentage (%)', 'aam-bd-partial-cod-for-wc' ),
				'type'              => 'number',
				'description'       => __( 'Used when "Percentage of the order total" is selected. E.g. 20 charges 20% of the order total as the advance.', 'aam-bd-partial-cod-for-wc' ),
				'default'           => '20',
				'desc_tip'          => true,
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '100',
					'step' => '0.01',
				),
			);
			$fields['advance_rounding']   = array(
				'title'       => __( 'Round percentage to', 'aam-bd-partial-cod-for-wc' ),
				'type'        => 'select',
				'description' => __( 'Rounds the percentage-based advance to a tidy figure, e.g. nearest 10 turns ৳247 into ৳250.', 'aam-bd-partial-cod-for-wc' ),
				'default'     => '1',
				'desc_tip'    => true,
				'options'     => array(
					'0'   => __( 'No rounding (keep decimals)', 'aam-bd-partial-cod-for-wc' ),
					'1'   => __( 'Nearest 1 (whole taka)', 'aam-bd-partial-cod-for-wc' ),
					'5'   => __( 'Nearest 5', 'aam-bd-partial-cod-for-wc' ),
					'10'  => __( 'Nearest 10', 'aam-bd-partial-cod-for-wc' ),
					'50'  => __( 'Nearest 50', 'aam-bd-partial-cod-for-wc' ),
					'100' => __( 'Nearest 100', 'aam-bd-partial-cod-for-wc' ),
				),
			);
			$fields['advance_fixed']      = array(
				'title'       => __( 'Fixed advance amount', 'aam-bd-partial-cod-for-wc' ),
				'type'        => 'price',
				'description' => __( 'Used when "Fixed amount" is selected.', 'aam-bd-partial-cod-for-wc' ),
				'default'     => '100',
				'desc_tip'    => true,
			);
			$fields['advance_label']      = array(
				'title'       => __( 'Advance label', 'aam-bd-partial-cod-for-wc' ),
				'type'        => 'text',
				'description' => __( 'Word used to describe the advance at checkout, e.g. "delivery charge" or "advance".', 'aam-bd-partial-cod-for-wc' ),
				'default'     => __( 'delivery charge', 'aam-bd-partial-cod-for-wc' ),
				'desc_tip'    => true,
			);
			$fields['fallback_advance']   = array(
				'title'       => __( 'Fallback advance amount', 'aam-bd-partial-cod-for-wc' ),
				'type'        => 'price',
				'description' => __( 'Used whenever the calculated advance would be zero (e.g. free delivery), so the advance is never zero.', 'aam-bd-partial-cod-for-wc' ),
				'default'     => '100',
				'desc_tip'    => true,
			);
		}

		// Verification behaviour.
		$fields['verification_section'] = array(
			'title'       => __( 'Verification', 'aam-bd-partial-cod-for-wc' ),
			'type'        => 'title',
			'description' => __( 'Control what the customer must submit as proof of payment.', 'aam-bd-partial-cod-for-wc' ),
		);
		$fields['collect_trxid']        = array(
			'title'       => __( 'Transaction ID (TrxID)', 'aam-bd-partial-cod-for-wc' ),
			'type'        => 'select',
			'description' => __( 'Ask the customer for the bKash/Nagad transaction ID when they submit payment.', 'aam-bd-partial-cod-for-wc' ),
			'default'     => 'off',
			'desc_tip'    => true,
			'options'     => array(
				'off'      => __( 'Do not ask', 'aam-bd-partial-cod-for-wc' ),
				'optional' => __( 'Ask (optional)', 'aam-bd-partial-cod-for-wc' ),
				'required' => __( 'Ask (required)', 'aam-bd-partial-cod-for-wc' ),
			),
		);
		$fields['sender_number_mode']   = array(
			'title'       => __( 'Sender number', 'aam-bd-partial-cod-for-wc' ),
			'type'        => 'select',
			'description' => __( 'Full requires a valid 11-digit number. Partial lets the customer confirm with just the last few digits.', 'aam-bd-partial-cod-for-wc' ),
			'default'     => 'full',
			'desc_tip'    => true,
			'options'     => array(
				'full'    => __( 'Require full 11-digit number', 'aam-bd-partial-cod-for-wc' ),
				'partial' => __( 'Allow last few digits (3+)', 'aam-bd-partial-cod-for-wc' ),
			),
		);

		// The full gateway can share the partial gateway's numbers/QR/instructions.
		if ( $this->is_full_mode() ) {
			$fields['methods_section']       = array(
				'title'       => __( 'Payment numbers', 'aam-bd-partial-cod-for-wc' ),
				'type'        => 'title',
				'description' => __( 'Configure the numbers customers pay to — or reuse the ones already set on the AAM Partial COD gateway.', 'aam-bd-partial-cod-for-wc' ),
			);
			$fields['reuse_partial_methods'] = array(
				'title'       => __( 'Reuse numbers', 'aam-bd-partial-cod-for-wc' ),
				'type'        => 'select',
				'description' => __( 'Copy once: fills the fields below from the AAM Partial COD gateway on save (you can then edit them). Always mirror: ignores the fields below and reads the partial gateway\'s numbers live, so they stay in sync automatically.', 'aam-bd-partial-cod-for-wc' ),
				'default'     => 'off',
				'options'     => array(
					'off'    => __( 'Use this gateway\'s own numbers', 'aam-bd-partial-cod-for-wc' ),
					'copy'   => __( 'Copy once from AAM Partial COD (on save)', 'aam-bd-partial-cod-for-wc' ),
					'mirror' => __( 'Always mirror AAM Partial COD (live)', 'aam-bd-partial-cod-for-wc' ),
				),
			);
		}

		// Build a settings block for each supported payment method.
		$defaults_enabled = array(
			'bkash_merchant'  => 'no',
			'bkash_personal'  => 'yes',
			'nagad_merchant'  => 'no',
			'nagad_personal'  => 'yes',
			'rocket_merchant' => 'no',
			'rocket_personal' => 'no',
		);

		foreach ( BD_PCOD_Helpers::get_methods_config() as $key => $config ) {
			// Skip methods hidden via the master visibility settings page.
			if ( ! BD_PCOD_Helpers::is_method_visible( $key ) ) {
				continue;
			}

			$label  = $config['label'];
			$action = BD_PCOD_Helpers::action_label( $config['action'] );

			$fields[ $key . '_section' ]      = array(
				'title' => $label,
				'type'  => 'title',
			);
			$fields[ $key . '_enabled' ]      = array(
				/* translators: %s: method label */
				'title'   => sprintf( __( 'Enable %s', 'aam-bd-partial-cod-for-wc' ), $label ),
				'type'    => 'checkbox',
				/* translators: %s: method label */
				'label'   => sprintf( __( 'Accept payments via %s', 'aam-bd-partial-cod-for-wc' ), $label ),
				'default' => isset( $defaults_enabled[ $key ] ) ? $defaults_enabled[ $key ] : 'no',
			);
			$fields[ $key . '_number' ]       = array(
				/* translators: 1: action (Send Money to / Make Payment to), 2: method label */
				'title'       => sprintf( __( '%1$s number (%2$s)', 'aam-bd-partial-cod-for-wc' ), $action, $label ),
				'type'        => 'text',
				'description' => __( 'The number customers send the payment to.', 'aam-bd-partial-cod-for-wc' ),
				'placeholder' => '01XXXXXXXXX',
				'desc_tip'    => true,
			);
			$fields[ $key . '_qr' ]           = array(
				/* translators: %s: method label */
				'title'       => sprintf( __( '%s QR image', 'aam-bd-partial-cod-for-wc' ), $label ),
				'type'        => 'bd_pcod_image',
				'description' => __( 'Upload the QR code image for this method (optional but recommended).', 'aam-bd-partial-cod-for-wc' ),
			);
			$fields[ $key . '_instructions' ] = array(
				/* translators: %s: method label */
				'title'   => sprintf( __( '%s instructions', 'aam-bd-partial-cod-for-wc' ), $label ),
				'type'    => 'textarea',
				/* translators: 1: action, 2: method label */
				'default' => sprintf( __( 'Open %2$s → %1$s → enter the number above → enter the exact amount → confirm. Then submit your sender number below.', 'aam-bd-partial-cod-for-wc' ), $action, $label ),
			);
		}

		// Editable customer-facing texts. Each is blank by default and falls back
		// to the mode-aware default shown as a placeholder.
		$fields['texts_section'] = array(
			'title'       => __( 'Texts / Labels', 'aam-bd-partial-cod-for-wc' ),
			'type'        => 'title',
			'description' => __( 'Customise the wording shown to customers. Each field is pre-filled with the default — edit it directly. Use {amount} and {label} as placeholders in the checkout notice.', 'aam-bd-partial-cod-for-wc' ),
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
					<button type="button" class="button bd-pcod-image-upload"><?php esc_html_e( 'Select image', 'aam-bd-partial-cod-for-wc' ); ?></button>
					<button type="button" class="button bd-pcod-image-remove"><?php esc_html_e( 'Remove', 'aam-bd-partial-cod-for-wc' ); ?></button>
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
			$description = wpautop( wptexturize( $this->description ) );
			echo wp_kses_post( $description );
		}

		$advance = $this->get_cart_advance_amount();
		if ( null === $advance ) {
			return;
		}

		$label  = $this->get_option( 'advance_label', __( 'delivery charge', 'aam-bd-partial-cod-for-wc' ) );
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

		return BD_PCOD_Helpers::calculate_partial_advance( $total, (float) WC()->cart->get_shipping_total(), $this->id );
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
			__( 'Awaiting payment via bKash/Nagad/Rocket.', 'aam-bd-partial-cod-for-wc' )
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
		$this->method_title       = __( 'AAM Partial COD (bKash/Nagad/Rocket)', 'aam-bd-partial-cod-for-wc' );
		$this->method_description = __( 'Customers pay a partial advance (equal to the delivery charge) via bKash, Nagad, or Rocket to confirm a Cash-on-Delivery order. The remaining balance is collected as cash on delivery. You verify each advance manually.', 'aam-bd-partial-cod-for-wc' );

		parent::__construct();
	}
}
