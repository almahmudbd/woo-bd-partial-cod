<?php
/**
 * Admin verification UI: order metabox, verify/reject actions, orders list column.
 *
 * @package WooBDPartialCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin-side verification of submitted advance payments.
 */
class BD_PCOD_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var BD_PCOD_Admin|null
	 */
	protected static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return BD_PCOD_Admin
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
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'admin_post_bd_pcod_verify', array( $this, 'handle_action' ) );
		add_action( 'admin_post_bd_pcod_reject', array( $this, 'handle_action' ) );

		// Orders list column (supports both legacy CPT and HPOS screens).
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_column' ), 20 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_order_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_order_column' ), 20, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_order_column' ), 20, 2 );
	}

	/**
	 * Register the metabox on the order edit screen (HPOS + legacy).
	 */
	public function register_metabox() {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'bd_pcod_metabox',
			__( 'Advance Payment (AAM Partial COD)', 'aam-bd-partial-cod-for-wc' ),
			array( $this, 'render_metabox' ),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Render the metabox contents.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order object (depends on HPOS).
	 */
	public function render_metabox( $post_or_order ) {
		$order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );

		if ( ! BD_PCOD_Helpers::is_our_order( $order ) ) {
			echo '<p>' . esc_html__( 'This order did not use the AAM Partial COD gateway.', 'aam-bd-partial-cod-for-wc' ) . '</p>';
			return;
		}

		$status    = $order->get_meta( BD_PCOD_Helpers::META_STATUS );
		$method    = $order->get_meta( BD_PCOD_Helpers::META_METHOD );
		$sender    = $order->get_meta( BD_PCOD_Helpers::META_SENDER );
		$trxid     = $order->get_meta( BD_PCOD_Helpers::META_TRXID );
		$advance   = (float) $order->get_meta( BD_PCOD_Helpers::META_ADVANCE );
		$remaining = (float) $order->get_meta( BD_PCOD_Helpers::META_REMAINING );
		$is_full   = ( BD_PCOD_Helpers::MODE_FULL === BD_PCOD_Helpers::order_mode( $order ) );

		echo '<div class="bd-pcod-metabox">';

		printf(
			'<p><strong>%s:</strong> %s</p>',
			esc_html__( 'Status', 'aam-bd-partial-cod-for-wc' ),
			esc_html( BD_PCOD_Helpers::status_label( $status ) )
		);
		printf(
			'<p><strong>%s:</strong> %s</p>',
			$is_full ? esc_html__( 'Amount due', 'aam-bd-partial-cod-for-wc' ) : esc_html__( 'Advance due', 'aam-bd-partial-cod-for-wc' ),
			wp_kses_post( wc_price( $advance ) )
		);
		if ( ! $is_full ) {
			printf(
				'<p><strong>%s:</strong> %s</p>',
				esc_html__( 'Remaining (COD)', 'aam-bd-partial-cod-for-wc' ),
				wp_kses_post( wc_price( $remaining ) )
			);
		}

		if ( $method ) {
			printf(
				'<p><strong>%s:</strong> %s</p>',
				esc_html__( 'Method', 'aam-bd-partial-cod-for-wc' ),
				esc_html( BD_PCOD_Helpers::method_label( $method ) )
			);
		}
		if ( $sender ) {
			printf(
				'<p><strong>%s:</strong> <code>%s</code></p>',
				esc_html__( 'Sender number', 'aam-bd-partial-cod-for-wc' ),
				esc_html( $sender )
			);
		}
		if ( $trxid ) {
			printf(
				'<p><strong>%s:</strong> <code>%s</code></p>',
				esc_html__( 'Transaction ID', 'aam-bd-partial-cod-for-wc' ),
				esc_html( $trxid )
			);
		}

		if ( BD_PCOD_Helpers::STATUS_SUBMITTED === $status ) {
			$verify_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=bd_pcod_verify&order_id=' . $order->get_id() ),
				'bd_pcod_action_' . $order->get_id()
			);
			$reject_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=bd_pcod_reject&order_id=' . $order->get_id() ),
				'bd_pcod_action_' . $order->get_id()
			);

			echo '<p class="bd-pcod-metabox__actions">';
			printf(
				'<a href="%s" class="button button-primary">%s</a> ',
				esc_url( $verify_url ),
				esc_html__( 'Verify payment', 'aam-bd-partial-cod-for-wc' )
			);
			printf(
				'<a href="%s" class="button">%s</a>',
				esc_url( $reject_url ),
				esc_html__( 'Reject', 'aam-bd-partial-cod-for-wc' )
			);
			echo '</p>';
		} elseif ( BD_PCOD_Helpers::STATUS_AWAITING === $status ) {
			echo '<p class="description">' . esc_html__( 'The customer has not submitted payment details yet.', 'aam-bd-partial-cod-for-wc' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Handle the verify/reject admin actions.
	 */
	public function handle_action() {
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$action   = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'aam-bd-partial-cod-for-wc' ) );
		}

		check_admin_referer( 'bd_pcod_action_' . $order_id );

		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! BD_PCOD_Helpers::is_our_order( $order ) ) {
			wp_die( esc_html__( 'Order not found.', 'aam-bd-partial-cod-for-wc' ) );
		}

		$advance   = wc_price( (float) $order->get_meta( BD_PCOD_Helpers::META_ADVANCE ) );
		$remaining = wc_price( (float) $order->get_meta( BD_PCOD_Helpers::META_REMAINING ) );
		$method    = BD_PCOD_Helpers::method_label( $order->get_meta( BD_PCOD_Helpers::META_METHOD ) );
		$sender    = $order->get_meta( BD_PCOD_Helpers::META_SENDER );

		if ( 'bd_pcod_verify' === $action ) {
			$is_full = ( BD_PCOD_Helpers::MODE_FULL === BD_PCOD_Helpers::order_mode( $order ) );
			$order->update_meta_data( BD_PCOD_Helpers::META_STATUS, BD_PCOD_Helpers::STATUS_VERIFIED );
			$note = $is_full
				? sprintf(
					/* translators: 1: amount, 2: method, 3: sender */
					__( 'Payment %1$s verified (%2$s, sender %3$s). Order confirmed.', 'aam-bd-partial-cod-for-wc' ),
					$advance,
					$method,
					$sender
				)
				: sprintf(
					/* translators: 1: amount, 2: method, 3: sender, 4: remaining */
					__( 'Advance %1$s verified (%2$s, sender %3$s). %4$s due as cash on delivery.', 'aam-bd-partial-cod-for-wc' ),
					$advance,
					$method,
					$sender,
					$remaining
				);
			$order->add_order_note( $note, true ); // Note to customer.
			$order->update_status( 'processing' );
		} else {
			$order->update_meta_data( BD_PCOD_Helpers::META_STATUS, BD_PCOD_Helpers::STATUS_REJECTED );
			$order->add_order_note(
				__( 'Advance payment could not be verified. Please contact the customer.', 'aam-bd-partial-cod-for-wc' )
			);
			$order->update_status( 'pending' );
		}

		$order->save();

		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * Add an "Advance" column to the orders list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_order_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new['bd_pcod_advance'] = __( 'Mobile payment', 'aam-bd-partial-cod-for-wc' );
			}
		}
		// Fallback if order_status column was not present.
		if ( ! isset( $new['bd_pcod_advance'] ) ) {
			$new['bd_pcod_advance'] = __( 'Mobile payment', 'aam-bd-partial-cod-for-wc' );
		}
		return $new;
	}

	/**
	 * Render the "Advance" column value.
	 *
	 * @param string           $column        Column key.
	 * @param int|WC_Order|null $post_or_order Order id/object depending on screen.
	 */
	public function render_order_column( $column, $post_or_order = null ) {
		if ( 'bd_pcod_advance' !== $column ) {
			return;
		}

		$order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order );
		if ( ! $order || ! BD_PCOD_Helpers::is_our_order( $order ) ) {
			echo '<span aria-hidden="true">&ndash;</span>';
			return;
		}

		$status = $order->get_meta( BD_PCOD_Helpers::META_STATUS );
		$colors = array(
			BD_PCOD_Helpers::STATUS_AWAITING  => '#999',
			BD_PCOD_Helpers::STATUS_SUBMITTED => '#e0a800',
			BD_PCOD_Helpers::STATUS_VERIFIED  => '#46b450',
			BD_PCOD_Helpers::STATUS_REJECTED  => '#dc3232',
		);
		$color = isset( $colors[ $status ] ) ? $colors[ $status ] : '#999';

		printf(
			'<mark style="background:%1$s;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;white-space:nowrap;">%2$s</mark>',
			esc_attr( $color ),
			esc_html( BD_PCOD_Helpers::status_label( $status ) )
		);
	}
}
