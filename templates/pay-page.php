<?php
/**
 * Standalone gateway payment page (full HTML document).
 *
 * Rendered independently of the theme to mimic a real hosted payment gateway.
 *
 * Exposed variables:
 *
 * @var WC_Order $order
 * @var string   $gateway_id
 * @var string   $icon
 * @var float    $advance
 * @var float    $remaining
 * @var array    $methods
 * @var string   $default_method
 * @var string   $collect_trxid
 * @var string   $sender_mode
 * @var string   $nonce
 * @var string   $return_url
 * @var array    $js_data
 *
 * Override by copying to: yourtheme/woocommerce/woo-bd-partial-cod/pay-page.php
 *
 * @package WooBDPartialCOD
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex,nofollow" />
	<title><?php esc_html_e( 'Complete your payment', 'woo-bd-partial-cod' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( BD_PCOD_URL . 'assets/css/frontend.css?ver=' . BD_PCOD_VERSION ); ?>" />
</head>
<body class="bd-pcod-standalone">
	<div class="bd-pcod-gateway">
		<header class="bd-pcod-gateway__head">
			<span class="bd-pcod-gateway__brand">
				<?php if ( ! empty( $icon ) ) : ?>
					<img class="bd-pcod-gateway__icon" src="<?php echo esc_url( $icon ); ?>" alt="" />
				<?php endif; ?>
				<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
			</span>
			<span class="bd-pcod-gateway__order">
				<?php
				/* translators: %s: order number */
				printf( esc_html__( 'Order #%s', 'woo-bd-partial-cod' ), esc_html( $order->get_order_number() ) );
				?>
			</span>
		</header>

		<main class="bd-pcod-gateway__body">
			<h1 class="bd-pcod-title"><?php echo esc_html( BD_PCOD_Helpers::get_text( $gateway_id, 'pay_title' ) ); ?></h1>

			<div class="bd-pcod-amounts">
				<p class="bd-pcod-amount-due">
					<span><?php echo esc_html( BD_PCOD_Helpers::get_text( $gateway_id, 'pay_now_label' ) ); ?></span>
					<strong><?php echo wp_kses_post( wc_price( $advance ) ); ?></strong>
				</p>
				<?php if ( $remaining > 0 ) : ?>
					<p class="bd-pcod-amount-cod">
						<span><?php echo esc_html( BD_PCOD_Helpers::get_text( $gateway_id, 'remaining_label' ) ); ?></span>
						<strong><?php echo wp_kses_post( wc_price( $remaining ) ); ?></strong>
					</p>
				<?php endif; ?>
			</div>

			<?php if ( empty( $methods ) ) : ?>
				<div class="bd-pcod-alert bd-pcod-alert--info">
					<?php esc_html_e( 'No payment methods are configured. Please contact the store.', 'woo-bd-partial-cod' ); ?>
				</div>
			<?php else : ?>

				<form class="bd-pcod-form" id="bd-pcod-form">
					<p class="form-row form-row-wide">
						<label for="bd-pcod-method"><?php echo esc_html( BD_PCOD_Helpers::get_text( $gateway_id, 'choose_method' ) ); ?> <span class="required">*</span></label>
						<select id="bd-pcod-method" name="method" required>
							<?php foreach ( $methods as $key => $method ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $default_method ); ?>>
									<?php echo esc_html( $method['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</p>

					<div class="bd-pcod-method-panels">
						<?php foreach ( $methods as $key => $method ) : ?>
							<div class="bd-pcod-method-panel" data-method="<?php echo esc_attr( $key ); ?>"<?php echo ( $key === $default_method ) ? '' : ' hidden'; ?>>
								<?php if ( ! empty( $method['qr'] ) ) : ?>
									<div class="bd-pcod-qr">
										<img src="<?php echo esc_url( $method['qr'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: method */ __( '%s QR code', 'woo-bd-partial-cod' ), $method['label'] ) ); ?>" />
									</div>
								<?php endif; ?>

								<div class="bd-pcod-number">
									<span class="bd-pcod-number__label">
										<?php echo esc_html( BD_PCOD_Helpers::action_label( $method['action'] ) ); ?>
									</span>
									<code class="bd-pcod-number__value"><?php echo esc_html( $method['number'] ); ?></code>
									<button type="button" class="button bd-pcod-copy" data-copy="<?php echo esc_attr( $method['number'] ); ?>">
										<?php esc_html_e( 'Copy', 'woo-bd-partial-cod' ); ?>
									</button>
								</div>

								<?php if ( ! empty( $method['instructions'] ) ) : ?>
									<p class="bd-pcod-method__instructions"><?php echo esc_html( $method['instructions'] ); ?></p>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>

					<p class="form-row form-row-wide">
						<label for="bd-pcod-sender"><?php echo esc_html( BD_PCOD_Helpers::get_text( $gateway_id, 'sender_label' ) ); ?> <span class="required">*</span></label>
						<input type="tel" id="bd-pcod-sender" name="sender_number" inputmode="numeric" maxlength="14"
							placeholder="<?php echo esc_attr( 'partial' === $sender_mode ? __( 'Last 3-4 digits', 'woo-bd-partial-cod' ) : '01XXXXXXXXX' ); ?>" required />
						<?php if ( 'partial' === $sender_mode ) : ?>
							<small class="bd-pcod-field-hint"><?php esc_html_e( 'You can enter just the last few digits of the number you paid from.', 'woo-bd-partial-cod' ); ?></small>
						<?php endif; ?>
					</p>

					<?php if ( 'off' !== $collect_trxid ) : ?>
						<p class="form-row form-row-wide">
							<label for="bd-pcod-trxid">
								<?php echo esc_html( BD_PCOD_Helpers::get_text( $gateway_id, 'trxid_label' ) ); ?>
								<?php if ( 'required' === $collect_trxid ) : ?>
									<span class="required">*</span>
								<?php else : ?>
									<span class="bd-pcod-optional">(<?php esc_html_e( 'optional', 'woo-bd-partial-cod' ); ?>)</span>
								<?php endif; ?>
							</label>
							<input type="text" id="bd-pcod-trxid" name="trxid" maxlength="40" placeholder="<?php esc_attr_e( 'e.g. 8N7A6B5C4D', 'woo-bd-partial-cod' ); ?>"<?php echo ( 'required' === $collect_trxid ) ? ' required' : ''; ?> />
						</p>
					<?php endif; ?>

					<input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>" />
					<input type="hidden" name="order_key" value="<?php echo esc_attr( $order->get_order_key() ); ?>" />
					<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>" />

					<p class="form-row">
						<button type="submit" class="button alt bd-pcod-submit"><?php echo esc_html( BD_PCOD_Helpers::get_text( $gateway_id, 'submit_button' ) ); ?></button>
					</p>

					<div class="bd-pcod-form__message" aria-live="polite"></div>
				</form>

			<?php endif; ?>
		</main>

		<footer class="bd-pcod-gateway__foot">
			<?php echo esc_html( BD_PCOD_Helpers::get_text( $gateway_id, 'footer' ) ); ?>
		</footer>
	</div>

	<script src="<?php echo esc_url( includes_url( 'js/jquery/jquery.min.js' ) ); ?>"></script>
	<script>window.bdPcod = <?php echo wp_json_encode( $js_data ); ?>;</script>
	<script src="<?php echo esc_url( BD_PCOD_URL . 'assets/js/frontend.js?ver=' . BD_PCOD_VERSION ); ?>"></script>
</body>
</html>
<?php
// The standalone document is complete; nothing else should render.
exit;
