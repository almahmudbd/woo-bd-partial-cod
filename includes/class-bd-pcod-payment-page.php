<?php
/**
 * Front-end payment page (order-received) and AJAX submission handler.
 *
 * @package WooBDPartialCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the QR/number/sender-form payment UI and handles its AJAX submission.
 */
class BD_PCOD_Payment_Page {

	/**
	 * Singleton instance.
	 *
	 * @var BD_PCOD_Payment_Page|null
	 */
	protected static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return BD_PCOD_Payment_Page
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: register hooks.
	 */
	protected function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_render_standalone_page' ) );

		// Thank-you status for all three gateways.
		foreach ( array( BD_PCOD_GATEWAY_ID, BD_PCOD_FULL_GATEWAY_ID, BD_PCOD_BANK_GATEWAY_ID ) as $gw ) {
			add_action( 'woocommerce_thankyou_' . $gw, array( $this, 'render_thankyou_status' ), 5 );
		}

		add_action( 'woocommerce_email_before_order_table', array( $this, 'render_email_instructions' ), 10, 4 );

		add_action( 'wp_ajax_bd_pcod_submit', array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_bd_pcod_submit', array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_bd_pcod_bank_submit', array( $this, 'handle_bank_submit' ) );
		add_action( 'wp_ajax_nopriv_bd_pcod_bank_submit', array( $this, 'handle_bank_submit' ) );
	}

	/**
	 * Render the standalone gateway payment page when the pay URL is requested.
	 *
	 * The URL is home_url( '/?bd_pcod_pay={id}&order_key={key}' ); we output a
	 * self-contained page (independent of the theme) and exit, mimicking a real
	 * hosted payment gateway.
	 */
	public function maybe_render_standalone_page() {
		if ( ! isset( $_GET['bd_pcod_pay'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$order_id  = absint( wp_unslash( $_GET['bd_pcod_pay'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order     = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! BD_PCOD_Helpers::is_our_order( $order ) || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			wp_die(
				esc_html__( 'This payment link is invalid or has expired.', 'aam-bd-partial-cod-for-wc' ),
				esc_html__( 'Payment error', 'aam-bd-partial-cod-for-wc' ),
				array( 'response' => 403 )
			);
		}

		// Once submitted or verified, there is nothing to pay — show the order.
		$status = $order->get_meta( BD_PCOD_Helpers::META_STATUS );
		if ( in_array( $status, array( BD_PCOD_Helpers::STATUS_SUBMITTED, BD_PCOD_Helpers::STATUS_VERIFIED ), true ) ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		$gateway_id = $order->get_payment_method();

		// Bank transfer gateway gets its own template.
		if ( BD_PCOD_BANK_GATEWAY_ID === $gateway_id ) {
			$banks        = BD_PCOD_Helpers::get_enabled_banks();
			$default_bank = $banks ? array_key_first( $banks ) : '';
			$nonce        = wp_create_nonce( 'bd_pcod_bank_submit' );
			$icon         = trim( (string) BD_PCOD_Helpers::get_setting( $gateway_id, 'icon_url', '' ) );
			if ( '' === $icon ) {
				$icon = BD_PCOD_Helpers::default_icon( $gateway_id );
			}

			$this->enqueue_standalone_assets(
				array(
					'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
					'nonce'          => $nonce,
					'action'         => 'bd_pcod_bank_submit',
					'copied'         => __( 'Copied!', 'aam-bd-partial-cod-for-wc' ),
					'copy'           => __( 'Copy', 'aam-bd-partial-cod-for-wc' ),
					'required'       => __( 'Please select a bank and enter your account number.', 'aam-bd-partial-cod-for-wc' ),
					'invalidAccount' => __( 'Please enter at least the last 4 digits of your account number.', 'aam-bd-partial-cod-for-wc' ),
					'leaveWarning'   => __( 'You have not completed your payment yet. If you leave now, your order will stay unconfirmed.', 'aam-bd-partial-cod-for-wc' ),
				)
			);

			$this->load_template(
				'bank-pay-page.php',
				array(
					'order'        => $order,
					'gateway_id'   => $gateway_id,
					'icon'         => esc_url_raw( $icon ),
					'total'        => (float) $order->get_meta( BD_PCOD_Helpers::META_ADVANCE ),
					'banks'        => $banks,
					'default_bank' => $default_bank,
					'nonce'        => $nonce,
					'return_url'   => $order->get_checkout_order_received_url(),
				)
			);
			exit;
		}

		$methods        = BD_PCOD_Helpers::get_enabled_methods( $gateway_id );
		$default_method = isset( $methods['bkash_merchant'] ) ? 'bkash_merchant' : ( $methods ? array_key_first( $methods ) : '' );
		$nonce          = wp_create_nonce( 'bd_pcod_submit' );
		$collect_trxid  = BD_PCOD_Helpers::get_setting( $gateway_id, 'collect_trxid', 'off' );
		$sender_mode    = BD_PCOD_Helpers::get_setting( $gateway_id, 'sender_number_mode', 'full' );

		$icon = trim( (string) BD_PCOD_Helpers::get_setting( $gateway_id, 'icon_url', '' ) );
		if ( '' === $icon ) {
			$icon = BD_PCOD_Helpers::default_icon( $gateway_id );
		}

		$this->enqueue_standalone_assets(
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => $nonce,
				'copied'        => __( 'Copied!', 'aam-bd-partial-cod-for-wc' ),
				'copy'          => __( 'Copy', 'aam-bd-partial-cod-for-wc' ),
				'senderMode'    => $sender_mode,
				'trxidMode'     => $collect_trxid,
				'invalidPhone'  => __( 'Please enter a valid 11-digit mobile number (e.g. 01XXXXXXXXX).', 'aam-bd-partial-cod-for-wc' ),
				'invalidDigits' => __( 'Please enter at least the last 3 digits of your sender number.', 'aam-bd-partial-cod-for-wc' ),
				'requiredTrxid' => __( 'Please enter the transaction ID (TrxID).', 'aam-bd-partial-cod-for-wc' ),
				'required'      => __( 'Please fill in all required fields.', 'aam-bd-partial-cod-for-wc' ),
				'leaveWarning'  => __( 'You have not completed your payment yet. If you leave now, your order will stay unconfirmed.', 'aam-bd-partial-cod-for-wc' ),
			)
		);

		$context = array(
			'order'          => $order,
			'gateway_id'     => $gateway_id,
			'icon'           => esc_url_raw( $icon ),
			'advance'        => (float) $order->get_meta( BD_PCOD_Helpers::META_ADVANCE ),
			'remaining'      => (float) $order->get_meta( BD_PCOD_Helpers::META_REMAINING ),
			'methods'        => $methods,
			'default_method' => $default_method,
			'collect_trxid'  => $collect_trxid,
			'sender_mode'    => $sender_mode,
			'nonce'          => $nonce,
			'return_url'     => $order->get_checkout_order_received_url(),
		);

		$this->load_template( 'pay-page.php', $context );
		exit;
	}

	/**
	 * Render a short status/confirmation block on the order-received page.
	 *
	 * The actual payment happens on the gateway (order-pay) page; here we only
	 * confirm the order's current state and, if the advance is still unpaid,
	 * point the customer back to the gateway page.
	 *
	 * @param int $order_id Order ID.
	 */
	public function render_thankyou_status( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! BD_PCOD_Helpers::is_our_order( $order ) ) {
			return;
		}

		$status     = $order->get_meta( BD_PCOD_Helpers::META_STATUS );
		$pay_url    = BD_PCOD_Helpers::get_pay_url( $order );
		$gateway_id = $order->get_payment_method();

		echo '<section class="bd-pcod-payment" id="bd-pcod-payment">';

		if ( BD_PCOD_Helpers::STATUS_VERIFIED === $status ) {
			echo '<div class="bd-pcod-alert bd-pcod-alert--success">';
			echo esc_html( BD_PCOD_Helpers::get_text( $gateway_id, 'status_verified' ) );
			echo '</div>';
		} elseif ( in_array( $status, array( BD_PCOD_Helpers::STATUS_SUBMITTED, BD_PCOD_Helpers::STATUS_VERIFIED ), true ) ) {
			echo '<div class="bd-pcod-alert bd-pcod-alert--info">';
			echo esc_html( BD_PCOD_Helpers::get_text( $gateway_id, 'status_submitted' ) );
			echo '</div>';
		} else {
			echo '<div class="bd-pcod-alert bd-pcod-alert--warning">';
			echo esc_html( BD_PCOD_Helpers::get_text( $gateway_id, 'status_pending' ) );
			echo '</div>';
			printf(
				'<p><a href="%1$s" class="button alt">%2$s</a></p>',
				esc_url( $pay_url ),
				esc_html( BD_PCOD_Helpers::get_text( $gateway_id, 'pay_button' ) )
			);
		}

		echo '</section>';
	}

	/**
	 * Append a short payment reminder to customer emails for awaiting/submitted orders.
	 *
	 * @param WC_Order $order         Order object.
	 * @param bool     $sent_to_admin Whether the email is for the admin.
	 * @param bool     $plain_text    Whether the email is plain text.
	 * @param WC_Email $email         Email object.
	 */
	public function render_email_instructions( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( $sent_to_admin || ! BD_PCOD_Helpers::is_our_order( $order ) ) {
			return;
		}

		$status = $order->get_meta( BD_PCOD_Helpers::META_STATUS );
		if ( ! in_array( $status, array( BD_PCOD_Helpers::STATUS_AWAITING, BD_PCOD_Helpers::STATUS_SUBMITTED ), true ) ) {
			return;
		}

		$advance = wc_price( (float) $order->get_meta( BD_PCOD_Helpers::META_ADVANCE ) );

		// Awaiting customers go to the standalone gateway page to pay; once
		// submitted, the order-received page shows their status.
		$url = ( BD_PCOD_Helpers::STATUS_AWAITING === $status )
			? BD_PCOD_Helpers::get_pay_url( $order )
			: $order->get_checkout_order_received_url();

		if ( $plain_text ) {
			$line = sprintf(
				/* translators: 1: amount, 2: url */
				__( 'Please pay %1$s to confirm your order, then submit your payment details here: %2$s', 'aam-bd-partial-cod-for-wc' ),
				$advance,
				$url
			);
			echo "\n" . esc_html( wp_strip_all_tags( $line ) ) . "\n\n";
			return;
		}

		echo '<div style="margin:0 0 24px;padding:12px 16px;border:1px solid #e0a800;background:#fff8e5;border-radius:6px;">';
		printf(
			/* translators: 1: amount, 2: url */
			wp_kses_post( __( 'Please pay <strong>%1$s</strong> to confirm your order, then <a href="%2$s">submit your payment details here</a>.', 'aam-bd-partial-cod-for-wc' ) ),
			wp_kses_post( $advance ),
			esc_url( $url )
		);
		echo '</div>';
	}

	/**
	 * Handle the AJAX submission of the customer's sender number + transaction ID.
	 */
	public function handle_submit() {
		check_ajax_referer( 'bd_pcod_submit', 'nonce' );

		$order_id  = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$method    = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';
		$sender    = isset( $_POST['sender_number'] ) ? sanitize_text_field( wp_unslash( $_POST['sender_number'] ) ) : '';
		$trxid_raw = isset( $_POST['trxid'] ) ? sanitize_text_field( wp_unslash( $_POST['trxid'] ) ) : '';

		$order = $order_id ? wc_get_order( $order_id ) : false;

		// Validate ownership via the order key (the customer may be a guest).
		if ( ! $order || ! BD_PCOD_Helpers::is_our_order( $order ) || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Order not found or access denied.', 'aam-bd-partial-cod-for-wc' ) ), 403 );
		}

		// Idempotency: if already submitted/verified, don't overwrite.
		$current = $order->get_meta( BD_PCOD_Helpers::META_STATUS );
		if ( in_array( $current, array( BD_PCOD_Helpers::STATUS_SUBMITTED, BD_PCOD_Helpers::STATUS_VERIFIED ), true ) ) {
			wp_send_json_success(
				array(
					'message'   => __( 'Your payment details have already been submitted and are awaiting verification.', 'aam-bd-partial-cod-for-wc' ),
					'submitted' => true,
					'redirect'  => $order->get_checkout_order_received_url(),
				)
			);
		}

		$gateway_id = $order->get_payment_method();

		$methods = BD_PCOD_Helpers::get_enabled_methods( $gateway_id );
		if ( ! isset( $methods[ $method ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please choose a valid payment method.', 'aam-bd-partial-cod-for-wc' ) ), 400 );
		}

		$sender_mode = BD_PCOD_Helpers::get_setting( $gateway_id, 'sender_number_mode', 'full' );
		$sender      = BD_PCOD_Helpers::sanitize_sender_number( $sender, $sender_mode );
		if ( false === $sender ) {
			$message = ( 'partial' === $sender_mode )
				? __( 'Please enter at least the last 3 digits of your sender number.', 'aam-bd-partial-cod-for-wc' )
				: __( 'Please enter a valid 11-digit mobile number (e.g. 01XXXXXXXXX).', 'aam-bd-partial-cod-for-wc' );
			wp_send_json_error( array( 'message' => $message ), 400 );
		}

		// Transaction ID: required, optional, or not collected.
		$collect_trxid = BD_PCOD_Helpers::get_setting( $gateway_id, 'collect_trxid', 'off' );
		$trxid         = ( 'off' === $collect_trxid ) ? '' : $trxid_raw;
		if ( 'required' === $collect_trxid && '' === $trxid ) {
			wp_send_json_error( array( 'message' => __( 'Please enter the transaction ID (TrxID).', 'aam-bd-partial-cod-for-wc' ) ), 400 );
		}

		// Persist the submission.
		$order->update_meta_data( BD_PCOD_Helpers::META_METHOD, $method );
		$order->update_meta_data( BD_PCOD_Helpers::META_SENDER, $sender );
		if ( '' !== $trxid ) {
			$order->update_meta_data( BD_PCOD_Helpers::META_TRXID, $trxid );
		}
		$order->update_meta_data( BD_PCOD_Helpers::META_STATUS, BD_PCOD_Helpers::STATUS_SUBMITTED );

		$order->add_order_note(
			sprintf(
				/* translators: 1: method, 2: sender number, 3: amount, 4: trxid suffix */
				__( 'Customer submitted payment: %1$s, sender %2$s, amount %3$s%4$s. Awaiting verification.', 'aam-bd-partial-cod-for-wc' ),
				BD_PCOD_Helpers::method_label( $method ),
				$sender,
				wc_price( (float) $order->get_meta( BD_PCOD_Helpers::META_ADVANCE ) ),
				'' !== $trxid ? sprintf( /* translators: %s: transaction id */ __( ', TrxID %s', 'aam-bd-partial-cod-for-wc' ), $trxid ) : ''
			)
		);

		// Move the order to on-hold while we verify.
		$order->update_status( 'on-hold' );
		$order->save();

		wp_send_json_success(
			array(
				'message'   => __( 'Thank you! Your payment details have been submitted and are awaiting verification. We will confirm your order shortly.', 'aam-bd-partial-cod-for-wc' ),
				'submitted' => true,
				'redirect'  => $order->get_checkout_order_received_url(),
			)
		);
	}

	/**
	 * AJAX: handle the bank transfer confirmation submission.
	 */
	public function handle_bank_submit() {
		check_ajax_referer( 'bd_pcod_bank_submit', 'nonce' );

		$order_id   = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order_key  = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$bank       = isset( $_POST['bank'] ) ? sanitize_key( wp_unslash( $_POST['bank'] ) ) : '';
		$acct_raw   = isset( $_POST['account_confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['account_confirm'] ) ) : '';

		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || $order->get_payment_method() !== BD_PCOD_BANK_GATEWAY_ID || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Order not found or access denied.', 'aam-bd-partial-cod-for-wc' ) ), 403 );
		}

		$current = $order->get_meta( BD_PCOD_Helpers::META_STATUS );
		if ( in_array( $current, array( BD_PCOD_Helpers::STATUS_SUBMITTED, BD_PCOD_Helpers::STATUS_VERIFIED ), true ) ) {
			wp_send_json_success( array( 'submitted' => true, 'redirect' => $order->get_checkout_order_received_url() ) );
		}

		$banks = BD_PCOD_Helpers::get_enabled_banks();
		if ( ! isset( $banks[ $bank ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please select a valid bank.', 'aam-bd-partial-cod-for-wc' ) ), 400 );
		}

		$acct = BD_PCOD_Helpers::sanitize_bank_account( $acct_raw );
		if ( false === $acct ) {
			wp_send_json_error( array( 'message' => __( 'Please enter at least the last 4 digits of your account number.', 'aam-bd-partial-cod-for-wc' ) ), 400 );
		}

		$order->update_meta_data( BD_PCOD_Helpers::META_METHOD, $bank );
		$order->update_meta_data( BD_PCOD_Helpers::META_SENDER, $acct );
		$order->update_meta_data( BD_PCOD_Helpers::META_STATUS, BD_PCOD_Helpers::STATUS_SUBMITTED );
		$order->add_order_note(
			sprintf(
				/* translators: 1: bank name, 2: account digits, 3: amount */
				__( 'Customer submitted bank transfer: %1$s, account ending %2$s, amount %3$s. Awaiting verification.', 'aam-bd-partial-cod-for-wc' ),
				$banks[ $bank ]['name'],
				$acct,
				wc_price( (float) $order->get_meta( BD_PCOD_Helpers::META_ADVANCE ) )
			)
		);
		$order->update_status( 'on-hold' );
		$order->save();

		wp_send_json_success( array(
			'message'   => __( 'Thank you! Your transfer details have been submitted and are awaiting verification.', 'aam-bd-partial-cod-for-wc' ),
			'submitted' => true,
			'redirect'  => $order->get_checkout_order_received_url(),
		) );
	}

	/**
	 * Register and enqueue the standalone page's CSS/JS so the templates can emit
	 * them via wp_print_styles()/wp_print_scripts() instead of hard-coded tags.
	 *
	 * The script's runtime config (formerly a raw window.bdPcod <script>) is passed
	 * through wp_localize_script(), which creates the same global.
	 *
	 * @param array $js_data Data to expose to frontend.js as window.bdPcod.
	 */
	protected function enqueue_standalone_assets( $js_data ) {
		wp_enqueue_style(
			'bd-pcod-frontend',
			BD_PCOD_URL . 'assets/css/frontend.css',
			array(),
			BD_PCOD_VERSION
		);

		wp_enqueue_script(
			'bd-pcod-frontend',
			BD_PCOD_URL . 'assets/js/frontend.js',
			array( 'jquery' ),
			BD_PCOD_VERSION,
			true
		);

		wp_localize_script( 'bd-pcod-frontend', 'bdPcod', $js_data );
	}

	/**
	 * Load a template file, allowing theme overrides via woocommerce/aam-bd-partial-cod-for-wc/.
	 *
	 * @param string $template Template filename.
	 * @param array  $context  Variables to expose to the template.
	 */
	protected function load_template( $template, $context = array() ) {
		$override = locate_template( array( 'woocommerce/aam-bd-partial-cod-for-wc/' . $template ) );
		$path     = $override ? $override : BD_PCOD_PATH . 'templates/' . $template;

		if ( ! file_exists( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $context );
		include $path;
	}
}
