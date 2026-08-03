<?php
/**
 * Het beheerscherm van de e-mailcampagnes.
 *
 * Voorlopig één pagina: de koppeling met Laposta. Het campagne-overzicht en
 * het starten van een verzending komen hier straks bij.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF_Campagne_Admin {

	const SLUG = 'cf-campagnes';

	/** Hoe lang we de uitkomst van de verbindingscontrole bewaren. */
	const STATUS_CACHE = 'cf_campagne_status';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_cf_campagne_opslaan', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function add_menu() {
		add_menu_page(
			'E-mailcampagnes',
			'E-mailcampagnes',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-email-alt',
			31
		);
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'cf-campagne-admin',
			plugin_dir_url( CF_CAMPAGNE_FILE ) . 'assets/admin.css',
			array(),
			CF_CAMPAGNE_VERSION
		);
	}

	/* --- Opslaan -------------------------------------------------------------- */

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'chiro-fysio-campagnes' ) );
		}

		check_admin_referer( 'cf_campagne_opslaan' );

		$melding = 'opgeslagen';

		if ( isset( $_POST['loskoppelen'] ) ) {
			CF_Campagne_Laposta::sleutel_opslaan( '' );
			CF_Campagne_Laposta::lijst_opslaan( '' );
			$melding = 'losgekoppeld';
		} else {
			// Een leeg veld betekent hier "niet wijzigen": een bestaande sleutel
			// tonen we nooit terug, dus je kunt hem ook niet per ongeluk wissen
			// door het formulier opnieuw op te slaan.
			$nieuw = isset( $_POST['sleutel'] )
				? trim( sanitize_text_field( wp_unslash( $_POST['sleutel'] ) ) )
				: '';

			if ( '' !== $nieuw ) {
				CF_Campagne_Laposta::sleutel_opslaan( $nieuw );
			}

			if ( isset( $_POST['lijst'] ) ) {
				CF_Campagne_Laposta::lijst_opslaan( wp_unslash( $_POST['lijst'] ) );
			}
		}

		delete_transient( self::STATUS_CACHE );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::SLUG, 'cf_melding' => $melding ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* --- Scherm --------------------------------------------------------------- */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'chiro-fysio-campagnes' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$melding = isset( $_GET['cf_melding'] ) ? sanitize_text_field( wp_unslash( $_GET['cf_melding'] ) ) : '';
		?>
		<div class="wrap cf-camp">
			<h1 class="cf-camp__title">E-mailcampagnes</h1>
			<p class="cf-camp__sub">
				De verbinding met Laposta, en straks het overzicht van wat je campagnes opleveren.
			</p>

			<?php if ( 'losgekoppeld' === $melding ) : ?>
				<div class="notice notice-success"><p>De koppeling met Laposta is verbroken.</p></div>
			<?php elseif ( 'opgeslagen' === $melding ) : ?>
				<div class="notice notice-success"><p>Opgeslagen.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cf_campagne_opslaan">
				<?php wp_nonce_field( 'cf_campagne_opslaan' ); ?>

				<?php self::render_koppeling(); ?>

				<p><button type="submit" class="button button-primary">Opslaan</button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * De koppeling met Laposta.
	 *
	 * De sleutel wordt bewust nooit teruggetoond. Wie hem heeft, kan mail
	 * versturen namens de praktijk - dat hoort niet in de HTML van een pagina
	 * te staan, ook niet achter een inlog.
	 */
	private static function render_koppeling() {
		$ingesteld = CF_Campagne_Laposta::ingesteld();
		$status    = false;

		if ( $ingesteld ) {
			// Even bewaren: dit is een echte oproep naar Laposta, en de pagina
			// wordt bij elke opslag opnieuw getekend.
			$status = get_transient( self::STATUS_CACHE );

			if ( false === $status ) {
				$status = CF_Campagne_Laposta::controleer();
				set_transient( self::STATUS_CACHE, $status, 5 * MINUTE_IN_SECONDS );
			}
		}
		?>
		<section class="cf-camp__card">
			<h2 class="cf-camp__cardtitle">Koppeling met Laposta</h2>
			<p class="cf-camp__cardsub">
				De sleutel maak je aan in Laposta onder <strong>Profiel &rarr; API</strong>.
				Hij blijft op de server staan en komt nooit in de app of in een pagina terecht.
			</p>

			<?php if ( ! $ingesteld ) : ?>

				<p>
					<label for="cf-camp-sleutel"><strong>API-sleutel</strong></label><br>
					<input type="password" id="cf-camp-sleutel" name="sleutel"
						class="regular-text" autocomplete="off" spellcheck="false"
						placeholder="Plak hier de sleutel uit Laposta">
				</p>
				<p class="cf-camp__note">Nog geen koppeling.</p>

			<?php else : ?>

				<?php if ( $status && $status['goed'] ) : ?>
					<p class="cf-camp__note cf-camp__note--ok">
						<strong>&check; <?php echo esc_html( $status['melding'] ); ?></strong>
					</p>
				<?php else : ?>
					<p class="cf-camp__note cf-camp__note--warn">
						<strong>Er staat een sleutel, maar de verbinding lukt niet.</strong><br>
						<?php echo esc_html( $status ? $status['melding'] : CF_Campagne_Laposta::laatste_fout() ); ?>
					</p>
				<?php endif; ?>

				<?php if ( $status && ! empty( $status['lijsten'] ) ) : ?>
					<p>
						<label for="cf-camp-lijst"><strong>Welke lijst gebruiken we?</strong></label><br>
						<select id="cf-camp-lijst" name="lijst">
							<?php foreach ( $status['lijsten'] as $lijst ) : ?>
								<option value="<?php echo esc_attr( $lijst['id'] ); ?>"
									<?php selected( CF_Campagne_Laposta::lijst_id(), $lijst['id'] ); ?>>
									<?php
									printf(
										'%s (%s aanmeldingen)',
										esc_html( $lijst['naam'] ),
										esc_html( number_format_i18n( $lijst['leden'] ) )
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</p>
				<?php endif; ?>

				<p>
					<label for="cf-camp-sleutel">Sleutel vervangen</label><br>
					<input type="password" id="cf-camp-sleutel" name="sleutel"
						class="regular-text" autocomplete="off" spellcheck="false"
						placeholder="Laat leeg om de huidige te houden">
					<button type="submit" name="loskoppelen" value="1" class="button"
						onclick="return confirm('De koppeling wordt verbroken. Doorgaan?');">
						Loskoppelen
					</button>
				</p>

			<?php endif; ?>
		</section>
		<?php
	}
}
