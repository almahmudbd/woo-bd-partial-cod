<?php
/**
 * Plugin Name:       BD Partial COD Gateway
 * Plugin URI:        https://github.com/almahmud/custom-gateway
 * Description:        Collect a partial advance payment (equal to the delivery charge) via bKash/Nagad to confirm a Cash-on-Delivery order. The rest is paid as cash on delivery. Manual admin verification, no API keys required.
 * Version:           1.4.0
 * Author:            almahmud
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-bd-partial-cod
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.0
 *
 * @package WooBDPartialCOD
 */

defined( 'ABSPATH' ) || exit;

define( 'BD_PCOD_VERSION', '1.3.0' );
define( 'BD_PCOD_FILE', __FILE__ );
define( 'BD_PCOD_PATH', plugin_dir_path( __FILE__ ) );
define( 'BD_PCOD_URL', plugin_dir_url( __FILE__ ) );
define( 'BD_PCOD_GATEWAY_ID', 'bd_partial_cod' );

/**
 * Declare High-Performance Order Storage (HPOS) compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BD_PCOD_FILE, true );
		}
	}
);

/**
 * Bootstrap the plugin once all plugins are loaded, so we can verify WooCommerce is active.
 */
add_action( 'plugins_loaded', 'bd_pcod_init' );

/**
 * Initialise the plugin.
 */
function bd_pcod_init() {
	load_plugin_textdomain( 'woo-bd-partial-cod', false, dirname( plugin_basename( BD_PCOD_FILE ) ) . '/languages' );

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'bd_pcod_missing_wc_notice' );
		return;
	}

	require_once BD_PCOD_PATH . 'includes/class-bd-pcod-helpers.php';
	require_once BD_PCOD_PATH . 'includes/class-bd-pcod-gateway.php';
	require_once BD_PCOD_PATH . 'includes/class-bd-pcod-payment-page.php';

	// Register the gateway with WooCommerce.
	add_filter( 'woocommerce_payment_gateways', 'bd_pcod_register_gateway' );

	// Front-end payment page + AJAX submission handler.
	BD_PCOD_Payment_Page::instance();

	// Admin verification UI (only in wp-admin).
	if ( is_admin() ) {
		require_once BD_PCOD_PATH . 'includes/class-bd-pcod-admin.php';
		BD_PCOD_Admin::instance();
	}

	// Front-end assets.
	add_action( 'wp_enqueue_scripts', 'bd_pcod_enqueue_frontend_assets' );
}

/**
 * Register the gateway class with WooCommerce.
 *
 * @param array $gateways Registered gateways.
 * @return array
 */
function bd_pcod_register_gateway( $gateways ) {
	$gateways[] = 'BD_PCOD_Gateway';
	return $gateways;
}

/**
 * Enqueue the front-end CSS on the order-received page (for the status block).
 *
 * The standalone gateway page loads its own CSS/JS directly in its template.
 */
function bd_pcod_enqueue_frontend_assets() {
	if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
		return;
	}

	wp_enqueue_style(
		'bd-pcod-frontend',
		BD_PCOD_URL . 'assets/css/frontend.css',
		array(),
		BD_PCOD_VERSION
	);
}

/**
 * Admin notice when WooCommerce is not active.
 */
function bd_pcod_missing_wc_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'BD Partial COD Gateway requires WooCommerce to be installed and active.', 'woo-bd-partial-cod' );
	echo '</p></div>';
}
