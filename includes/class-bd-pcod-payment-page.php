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
		// Render the standalone gateway page when our pay URL is requested.
		add_action( 'template_redirect', array( $this, 'maybe_render_standalone_page' ) );
		// The order-received (thank-you) page shows only a confirmation/status.
		add_action( 'woocommerce_thankyou_' . BD_PCOD_GATEWAY_ID, array( $this, 'render_thankyou_status' ), 5 );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'render_email_instructions' ), 10, 4 );

		add_action( 'wp_ajax_bd_pcod_submit', array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_bd_pcod_submit', array( $this, 'handle_submit' ) );
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
				esc_html__( 'This payment link is invalid or has expired.', 'woo-bd-partial-cod' ),
				esc_html__( 'Payment error', 'woo-bd-partial-cod' ),
				array( 'response' => 403 )
			);
		}

		// Once submitted or verified, there is nothing to pay — show the order.
		$status = $order->get_meta( BD_PCOD_Helpers::META_STATUS );
		if ( in_array( $status, array( BD_PCOD_Helpers::STATUS_SUBMITTED, BD_PCOD_Helpers::STATUS_VERIFIED ), true ) ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		$methods        = BD_PCOD_Helpers::get_enabled_methods();
		$default_method = isset( $methods['bkash_merchant'] ) ? 'bkash_merchant' : ( $methods ? array_key_first( $methods ) : '' );
		$nonce          = wp_create_nonce( 'bd_pcod_submit' );

		$context = array(
			'order'          => $order,
			'advance'        => (float) $order->get_meta( BD_PCOD_Helpers::META_ADVANCE ),
			'remaining'      => (float) $order->get_meta( BD_PCOD_Helpers::META_REMAINING ),
			'methods'        => $methods,
			'default_method' => $default_method,
			'nonce'          => $nonce,
			'return_url'     => $order->get_checkout_order_received_url(),
			'js_data'        => array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => $nonce,
				'copied'       => __( 'Copied!', 'woo-bd-partial-cod' ),
				'copy'         => __( 'Copy', 'woo-bd-partial-cod' ),
				'invalidPhone' => __( 'Please enter a valid 11-digit mobile number (e.g. 01XXXXXXXXX).', 'woo-bd-partial-cod' ),
				'required'     => __( 'Please fill in all required fields.', 'woo-bd-partial-cod' ),
				'leaveWarning' => __( 'You have not completed your payment yet. If you leave now, your order will stay unconfirmed.', 'woo-bd-partial-cod' ),
			),
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

		$status  = $order->get_meta( BD_PCOD_Helpers::META_STATUS );
		$pay_url = BD_PCOD_Helpers::get_pay_url( $order );

		echo '<section class="bd-pcod-payment" id="bd-pcod-payment">';

		if ( BD_PCOD_Helpers::STATUS_VERIFIED === $status ) {
			echo '<div class="bd-pcod-alert bd-pcod-alert--success">';
			esc_html_e( 'Your advance payment has been verified. Your order is confirmed!', 'woo-bd-partial-cod' );
			echo '</div>';
		} elseif ( in_array( $status, array( BD_PCOD_Helpers::STATUS_SUBMITTED, BD_PCOD_Helpers::STATUS_VERIFIED ), true ) ) {
			echo '<div class="bd-pcod-alert bd-pcod-alert--info">';
			esc_html_e( 'Your payment details have been submitted and are awaiting verification. We will confirm your order shortly.', 'woo-bd-partial-cod' );
			echo '</div>';
		} else {
			echo '<div class="bd-pcod-alert bd-pcod-alert--warning">';
			esc_html_e( 'Your order is NOT confirmed yet — the advance payment is still pending.', 'woo-bd-partial-cod' );
			echo '</div>';
			printf(
				'<p><a href="%1$s" class="button alt">%2$s</a></p>',
				esc_url( $pay_url ),
				esc_html__( 'Pay the advance now', 'woo-bd-partial-cod' )
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
			echo "\n" . wp_strip_all_tags(
				sprintf(
					/* translators: 1: amount, 2: url */
					__( 'Please pay the advance of %1$s to confirm your order, then submit your payment details here: %2$s', 'woo-bd-partial-cod' ),
					$advance,
					$url
				)
			) . "\n\n";
			return;
		}

		echo '<div style="margin:0 0 24px;padding:12px 16px;border:1px solid #e0a800;background:#fff8e5;border-radius:6px;">';
		printf(
			/* translators: 1: amount, 2: url */
			wp_kses_post( __( 'Please pay the advance of <strong>%1$s</strong> to confirm your order, then <a href="%2$s">submit your payment details here</a>.', 'woo-bd-partial-cod' ) ),
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
		$sender    = isset( $_POST['sender_number'] ) ? wp_unslash( $_POST['sender_number'] ) : '';

		$order = $order_id ? wc_get_order( $order_id ) : false;

		// Validate ownership via the order key (the customer may be a guest).
		if ( ! $order || ! BD_PCOD_Helpers::is_our_order( $order ) || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Order not found or access denied.', 'woo-bd-partial-cod' ) ), 403 );
		}

		// Idempotency: if already submitted/verified, don't overwrite.
		$current = $order->get_meta( BD_PCOD_Helpers::META_STATUS );
		if ( in_array( $current, array( BD_PCOD_Helpers::STATUS_SUBMITTED, BD_PCOD_Helpers::STATUS_VERIFIED ), true ) ) {
			wp_send_json_success(
				array(
					'message'   => __( 'Your payment details have already been submitted and are awaiting verification.', 'woo-bd-partial-cod' ),
					'submitted' => true,
					'redirect'  => $order->get_checkout_order_received_url(),
				)
			);
		}

		$methods = BD_PCOD_Helpers::get_enabled_methods();
		if ( ! isset( $methods[ $method ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please choose a valid payment method.', 'woo-bd-partial-cod' ) ), 400 );
		}

		$sender = BD_PCOD_Helpers::sanitize_bd_phone( $sender );
		if ( false === $sender ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid 11-digit mobile number (e.g. 01XXXXXXXXX).', 'woo-bd-partial-cod' ) ), 400 );
		}

		// Persist the submission.
		$order->update_meta_data( BD_PCOD_Helpers::META_METHOD, $method );
		$order->update_meta_data( BD_PCOD_Helpers::META_SENDER, $sender );
		$order->update_meta_data( BD_PCOD_Helpers::META_STATUS, BD_PCOD_Helpers::STATUS_SUBMITTED );

		$order->add_order_note(
			sprintf(
				/* translators: 1: method, 2: sender number, 3: amount */
				__( 'Customer submitted advance payment: %1$s, sender %2$s, amount %3$s. Awaiting verification.', 'woo-bd-partial-cod' ),
				BD_PCOD_Helpers::method_label( $method ),
				$sender,
				wc_price( (float) $order->get_meta( BD_PCOD_Helpers::META_ADVANCE ) )
			)
		);

		// Move the order to on-hold while we verify.
		$order->update_status( 'on-hold' );
		$order->save();

		wp_send_json_success(
			array(
				'message'   => __( 'Thank you! Your payment details have been submitted and are awaiting verification. We will confirm your order shortly.', 'woo-bd-partial-cod' ),
				'submitted' => true,
				'redirect'  => $order->get_checkout_order_received_url(),
			)
		);
	}

	/**
	 * Load a template file, allowing theme overrides via woocommerce/woo-bd-partial-cod/.
	 *
	 * @param string $template Template filename.
	 * @param array  $context  Variables to expose to the template.
	 */
	protected function load_template( $template, $context = array() ) {
		$override = locate_template( array( 'woocommerce/woo-bd-partial-cod/' . $template ) );
		$path     = $override ? $override : BD_PCOD_PATH . 'templates/' . $template;

		if ( ! file_exists( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $context );
		include $path;
	}
}
