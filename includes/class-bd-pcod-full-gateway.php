<?php
/**
 * Full-payment gateway: collects the entire order total via manual mobile payment.
 *
 * @package WooBDPartialCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manual full-payment gateway (bKash/Nagad/Rocket), verified by the admin.
 *
 * Shares all behaviour with the partial gateway via BD_PCOD_Gateway_Base; the
 * only difference is the amount collected (full order total) and the wording.
 */
class BD_PCOD_Full_Gateway extends BD_PCOD_Gateway_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = BD_PCOD_FULL_GATEWAY_ID;
		$this->mode               = BD_PCOD_Helpers::MODE_FULL;
		$this->method_title       = __( 'BD Manual Mobile Payment (full)', 'woo-bd-partial-cod' );
		$this->method_description = __( 'Customers pay the full order total via bKash, Nagad, or Rocket and submit their payment details. You verify each payment manually — no API keys required.', 'woo-bd-partial-cod' );

		parent::__construct();
	}

	/**
	 * Save settings, then — for the "copy once" choice — pull the partial
	 * gateway's method details into this gateway's own fields so they are
	 * visible and editable. The "mirror" choice is resolved live at runtime
	 * instead (see BD_PCOD_Helpers::method_source_gateway()).
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		if ( 'copy' === $this->get_option( 'reuse_partial_methods', 'off' ) ) {
			$this->copy_partial_methods();
		}

		return $saved;
	}

	/**
	 * Copy the per-method settings (enabled/number/QR/instructions) from the
	 * partial gateway into this gateway's saved settings.
	 */
	protected function copy_partial_methods() {
		$partial = BD_PCOD_Helpers::get_settings( BD_PCOD_GATEWAY_ID );

		foreach ( BD_PCOD_Helpers::method_setting_keys() as $key ) {
			if ( array_key_exists( $key, $partial ) ) {
				$this->settings[ $key ] = $partial[ $key ];
			}
		}

		// "Copy once" is a one-shot action: reset to own numbers so the copied
		// values can be edited freely and a later save won't overwrite them.
		$this->settings['reuse_partial_methods'] = 'off';

		update_option(
			$this->get_option_key(),
			apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ),
			'yes'
		);

		// Refresh the in-memory copy so the freshly saved values render immediately.
		$this->init_settings();
	}
}
