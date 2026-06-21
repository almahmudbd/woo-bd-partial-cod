<?php
/**
 * Plugin Name:       AAM Partial COD & Mobile Payment for WooCommerce
 * Plugin URI:        https://github.com/almahmudbd/woo-bd-partial-cod
 * Description:       Collect a partial advance (delivery charge) or the full order total via bKash/Nagad/Rocket. Manual admin verification, no API keys required. Bangladeshi Easy Payment Solution for WooCommerce.
 * Version:           1.5.6
 * Author:            almahmudbd
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aam-bd-partial-cod-for-wc
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   9.0
 *
 * @package BDPartialCOD
 */

defined( 'ABSPATH' ) || exit;

define( 'BD_PCOD_VERSION', '1.5.6' );
define( 'BD_PCOD_FILE', __FILE__ );
define( 'BD_PCOD_PATH', plugin_dir_path( __FILE__ ) );
define( 'BD_PCOD_URL', plugin_dir_url( __FILE__ ) );
define( 'BD_PCOD_GATEWAY_ID', 'bd_partial_cod' );
define( 'BD_PCOD_FULL_GATEWAY_ID', 'bd_full_mobile' );
define( 'BD_PCOD_BANK_GATEWAY_ID', 'bd_bank_transfer' );

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
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'bd_pcod_missing_wc_notice' );
		return;
	}

	require_once BD_PCOD_PATH . 'includes/class-bd-pcod-helpers.php';
	require_once BD_PCOD_PATH . 'includes/class-bd-pcod-gateway.php';
	require_once BD_PCOD_PATH . 'includes/class-bd-pcod-full-gateway.php';
	require_once BD_PCOD_PATH . 'includes/class-bd-pcod-bank-gateway.php';
	require_once BD_PCOD_PATH . 'includes/class-bd-pcod-payment-page.php';

	// Run one-time data migrations after a plugin update.
	bd_pcod_maybe_upgrade();

	// Register the gateway with WooCommerce.
	add_filter( 'woocommerce_payment_gateways', 'bd_pcod_register_gateway' );

	// Front-end payment page + AJAX submission handler.
	BD_PCOD_Payment_Page::instance();

	// Admin verification UI + settings page (only in wp-admin).
	if ( is_admin() ) {
		require_once BD_PCOD_PATH . 'includes/class-bd-pcod-admin.php';
		BD_PCOD_Admin::instance();

		require_once BD_PCOD_PATH . 'includes/class-bd-pcod-settings.php';
		BD_PCOD_Settings::instance();
	}

	// Front-end assets.
	add_action( 'wp_enqueue_scripts', 'bd_pcod_enqueue_frontend_assets' );
}

/**
 * Run one-time upgrade routines when the stored DB version is behind the code.
 *
 * Keeps existing stores' configured numbers/QR/instructions intact when method
 * keys change between releases (see BD_PCOD_Helpers::migrate_legacy_method_keys()).
 */
function bd_pcod_maybe_upgrade() {
	$installed = (string) get_option( 'bd_pcod_db_version', '' );
	if ( version_compare( $installed, BD_PCOD_VERSION, '>=' ) ) {
		return;
	}

	BD_PCOD_Helpers::migrate_legacy_method_keys();

	update_option( 'bd_pcod_db_version', BD_PCOD_VERSION );
}

/**
 * Register the gateway class with WooCommerce.
 *
 * @param array $gateways Registered gateways.
 * @return array
 */
function bd_pcod_register_gateway( $gateways ) {
	if ( BD_PCOD_Helpers::is_gateway_visible( BD_PCOD_GATEWAY_ID ) ) {
		$gateways[] = 'BD_PCOD_Gateway';
	}
	if ( BD_PCOD_Helpers::is_gateway_visible( BD_PCOD_FULL_GATEWAY_ID ) ) {
		$gateways[] = 'BD_PCOD_Full_Gateway';
	}
	if ( BD_PCOD_Helpers::is_gateway_visible( BD_PCOD_BANK_GATEWAY_ID ) ) {
		$gateways[] = 'BD_PCOD_Bank_Gateway';
	}
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
	echo esc_html__( 'AAM Partial COD Gateway requires WooCommerce to be installed and active.', 'aam-bd-partial-cod-for-wc' );
	echo '</p></div>';
}
