<?php
/**
 * Standalone bank transfer payment page.
 *
 * @var WC_Order $order
 * @var string   $gateway_id
 * @var string   $icon
 * @var float    $total
 * @var array    $banks
 * @var string   $default_bank
 * @var string   $nonce
 * @var string   $return_url
 * @var array    $js_data
 *
 * @package BDPartialCOD
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex,nofollow" />
	<title><?php esc_html_e( 'Complete your bank transfer', 'aam-partial-cod' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( BD_PCOD_URL . 'assets/css/frontend.css?ver=' . BD_PCOD_VERSION ); ?>" /> <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Standalone full-document template outside WP theme context.
?>
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
			printf( esc_html__( 'Order #%s', 'aam-partial-cod' ), esc_html( $order->get_order_number() ) );
			?>
		</span>
	</header>

	<main class="bd-pcod-gateway__body">
		<h1 class="bd-pcod-title"><?php esc_html_e( 'Complete your bank transfer', 'aam-partial-cod' ); ?></h1>

		<div class="bd-pcod-amounts">
			<p class="bd-pcod-amount-due">
				<span><?php esc_html_e( 'Transfer amount', 'aam-partial-cod' ); ?></span>
				<strong><?php echo wp_kses_post( wc_price( $total ) ); ?></strong>
			</p>
		</div>

		<?php if ( empty( $banks ) ) : ?>
			<div class="bd-pcod-alert bd-pcod-alert--info">
				<?php esc_html_e( 'No bank accounts are configured. Please contact the store.', 'aam-partial-cod' ); ?>
			</div>
		<?php else : ?>

		<form class="bd-pcod-form" id="bd-pcod-bank-form">

			<p class="form-row form-row-wide">
				<label for="bd-pcod-bank"><?php esc_html_e( 'Select bank', 'aam-partial-cod' ); ?> <span class="required">*</span></label>
				<select id="bd-pcod-bank" name="bank" required>
					<?php foreach ( $banks as $bd_pcod_key => $bd_pcod_bank ) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template loop variable.
					?>
						<option value="<?php echo esc_attr( $bd_pcod_key ); ?>" <?php selected( $bd_pcod_key, $default_bank ); ?>>
							<?php echo esc_html( $bd_pcod_bank['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<div class="bd-pcod-method-panels">
				<?php foreach ( $banks as $bd_pcod_key => $bd_pcod_bank ) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template loop variable.
				?>
				<div class="bd-pcod-method-panel bd-pcod-bank-panel" data-method="<?php echo esc_attr( $bd_pcod_key ); ?>"<?php echo ( $bd_pcod_key === $default_bank ) ? '' : ' hidden'; ?>>

					<?php
					$bd_pcod_rows = array( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variable.
						__( 'Bank', 'aam-partial-cod' )           => $bd_pcod_bank['name'],
						__( 'Account name', 'aam-partial-cod' )   => $bd_pcod_bank['account_name'],
						__( 'Account number', 'aam-partial-cod' ) => $bd_pcod_bank['account_number'],
						__( 'Branch', 'aam-partial-cod' )         => $bd_pcod_bank['branch'],
						__( 'Routing number', 'aam-partial-cod' ) => $bd_pcod_bank['routing'],
						__( 'Phone', 'aam-partial-cod' )          => $bd_pcod_bank['phone'],
					);
					foreach ( $bd_pcod_rows as $bd_pcod_label => $bd_pcod_value ) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template loop variable.
						if ( '' === $bd_pcod_value ) continue;
						?>
						<div class="bd-pcod-bank-row<?php echo ( __( 'Account number', 'aam-partial-cod' ) === $bd_pcod_label ) ? ' bd-pcod-bank-row--acct' : ''; ?>">
							<span class="bd-pcod-bank-row__label"><?php echo esc_html( $bd_pcod_label ); ?></span>
							<code class="bd-pcod-bank-row__value"><?php echo esc_html( $bd_pcod_value ); ?></code>
							<button type="button" class="button bd-pcod-copy" data-copy="<?php echo esc_attr( $bd_pcod_value ); ?>">
								<?php esc_html_e( 'Copy', 'aam-partial-cod' ); ?>
							</button>
						</div>
					<?php endforeach; ?>

				</div>
				<?php endforeach; ?>
			</div>

			<p class="form-row form-row-wide">
				<label for="bd-pcod-acct-confirm">
					<?php esc_html_e( 'Your account number (or last 4 digits)', 'aam-partial-cod' ); ?>
					<span class="required">*</span>
				</label>
				<input type="text" id="bd-pcod-acct-confirm" name="account_confirm"
					inputmode="numeric" maxlength="30"
					placeholder="<?php esc_attr_e( 'e.g. last 4 digits: 5678', 'aam-partial-cod' ); ?>" required />
				<small class="bd-pcod-field-hint">
					<?php esc_html_e( 'Enter the account number you are transferring FROM, or just the last 4 digits.', 'aam-partial-cod' ); ?>
				</small>
			</p>

			<input type="hidden" name="order_id"  value="<?php echo esc_attr( $order->get_id() ); ?>" />
			<input type="hidden" name="order_key" value="<?php echo esc_attr( $order->get_order_key() ); ?>" />
			<input type="hidden" name="nonce"     value="<?php echo esc_attr( $nonce ); ?>" />

			<p class="form-row">
				<button type="submit" class="button alt bd-pcod-submit">
					<?php esc_html_e( 'Confirm transfer & submit', 'aam-partial-cod' ); ?>
				</button>
			</p>

			<div class="bd-pcod-form__message" aria-live="polite"></div>
		</form>

		<?php endif; ?>
	</main>

	<footer class="bd-pcod-gateway__foot">
		<?php esc_html_e( 'Your order stays unconfirmed until your bank transfer is verified.', 'aam-partial-cod' ); ?>
	</footer>

</div>
<script src="<?php echo esc_url( includes_url( 'js/jquery/jquery.min.js' ) ); ?>" ><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Standalone full-document template outside WP theme context.
?></script>
<script>window.bdPcod = <?php echo wp_json_encode( $js_data ); ?>;</script>
<script src="<?php echo esc_url( BD_PCOD_URL . 'assets/js/frontend.js?ver=' . BD_PCOD_VERSION ); ?>" ><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Standalone full-document template outside WP theme context.
?></script>
</body>
</html>
<?php exit; ?>
