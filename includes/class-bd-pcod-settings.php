<?php
/**
 * Master visibility settings page: toggle which gateways and payment methods
 * are exposed. Turning something off here removes it from WooCommerce entirely
 * (gateways are no longer registered; methods no longer appear in settings or
 * at checkout).
 *
 * @package WooBDPartialCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-level settings page (linked from the Plugins list) for showing/hiding
 * the gateways and payment methods.
 */
class BD_PCOD_Settings {

	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'bd-pcod-settings';

	/**
	 * Settings group / option-page id used by the Settings API.
	 */
	const SETTINGS_GROUP = 'bd_pcod_settings_group';

	/**
	 * Singleton instance.
	 *
	 * @var BD_PCOD_Settings|null
	 */
	protected static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return BD_PCOD_Settings
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
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BD_PCOD_FILE ), array( $this, 'add_action_link' ) );
	}

	/**
	 * Add the "Settings" link under the plugin name on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_action_link( $links ) {
		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'aam-partial-cod' )
		);
		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * Register the settings page as a WooCommerce submenu.
	 */
	public function register_page() {
		add_submenu_page(
			'woocommerce',
			__( 'BD COD Visibility', 'aam-partial-cod' ),
			__( 'BD COD Visibility', 'aam-partial-cod' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the visibility option with the Settings API.
	 */
	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			BD_PCOD_Helpers::OPTION_VISIBILITY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize posted toggles into a structured array, filling absent (unchecked)
	 * boxes with 0 so every known gateway/method has an explicit value.
	 *
	 * @param mixed $input Raw posted value.
	 * @return array{gateways:array<string,int>,methods:array<string,int>}
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$in_gates = isset( $input['gateways'] ) ? (array) $input['gateways'] : array();
		$in_meths = isset( $input['methods'] ) ? (array) $input['methods'] : array();

		$clean = array(
			'gateways' => array(),
			'methods'  => array(),
		);

		foreach ( array_keys( BD_PCOD_Helpers::gateways() ) as $gateway_id ) {
			$clean['gateways'][ $gateway_id ] = empty( $in_gates[ $gateway_id ] ) ? 0 : 1;
		}
		foreach ( array_keys( BD_PCOD_Helpers::get_methods_config() ) as $method ) {
			$clean['methods'][ $method ] = empty( $in_meths[ $method ] ) ? 0 : 1;
		}

		return $clean;
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AAM Partial COD — Visibility', 'aam-partial-cod' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Choose which payment gateways and mobile-money methods this plugin exposes. Anything switched off here is removed from WooCommerce entirely — it will not appear in WooCommerce → Settings → Payments, nor at checkout.', 'aam-partial-cod' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>

				<h2><?php esc_html_e( 'Payment gateways', 'aam-partial-cod' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
					<?php foreach ( array_keys( BD_PCOD_Helpers::gateways() ) as $gateway_id ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( BD_PCOD_Helpers::gateway_label( $gateway_id ) ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( BD_PCOD_Helpers::OPTION_VISIBILITY . '[gateways][' . $gateway_id . ']' ); ?>" value="1" <?php checked( BD_PCOD_Helpers::is_gateway_visible( $gateway_id ) ); ?> />
									<?php esc_html_e( 'Show this gateway', 'aam-partial-cod' ); ?>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Payment methods', 'aam-partial-cod' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
					<?php foreach ( BD_PCOD_Helpers::get_methods_config() as $method => $config ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $config['label'] ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( BD_PCOD_Helpers::OPTION_VISIBILITY . '[methods][' . $method . ']' ); ?>" value="1" <?php checked( BD_PCOD_Helpers::is_method_visible( $method ) ); ?> />
									<?php esc_html_e( 'Show this method', 'aam-partial-cod' ); ?>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
