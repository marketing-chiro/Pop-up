<?php
/**
 * Terugbelverzoeken.
 *
 * Waarom dit er is: in de eerste 30 dagen kregen 37 bezoekers die iets niet
 * konden vinden een groot telefoonnummer te zien, en belde er niemand. Niet
 * omdat het nummer verstopt zat, maar omdat bellen veel vraagt - een vreemde
 * spreken, tijdens kantooruren, en je vraag ter plekke uitleggen. Een nummer
 * achterlaten kost drie seconden en kan om tien uur 's avonds.
 *
 * Over de gegevens die hier binnenkomen:
 *
 * De metingen van de pop-up staan bewust in een tabel zonder persoonsgegevens.
 * Een naam en telefoonnummer horen daar dus niet thuis, en krijgen een eigen
 * tabel met een eigen bewaartermijn.
 *
 * We vragen met opzet NIET waar de vraag over gaat. Zou iemand daar "al weken
 * rugpijn" invullen, dan staat er een gezondheidsgegeven in de database van een
 * marketingtool - en dat is precies wat je bij een zorgpraktijk niet wilt. Het
 * onderwerp weten we al grofweg uit de redenvraag (kosten, klacht, afspraak,
 * anders), en dat is genoeg om voorbereid terug te bellen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF_Exit_Popup_Callbacks {

	/** Na hoeveel dagen een afgehandeld verzoek vanzelf verdwijnt. */
	const BEWAARTERMIJN = 90;

	/**
	 * Waar de verzoeken standaard heen gaan.
	 *
	 * Dit is het adres waar vandaan teruggebeld wordt, niet het beheeradres van
	 * de site: een terugbelverzoek is werk voor een mens, en dat moet in de
	 * postbus liggen van degene die de telefoon pakt.
	 */
	const STANDAARD_ADRES = 'marketing@chiro-fysio.nl';

	/** Na hoeveel uur een openstaand verzoek een herinnering oplevert. */
	const HERINNER_NA_UUR = 20;

	public static function init() {
		add_action( 'cf_exit_popup_cleanup', array( __CLASS__, 'opruimen' ) );
		add_action( 'cf_exit_popup_healthcheck', array( __CLASS__, 'herinneren' ) );
		add_action( 'admin_post_cf_exit_popup_callback_status', array( __CLASS__, 'status_wijzigen' ) );
	}

	/**
	 * Het adres waar terugbelverzoeken heen gaan.
	 *
	 * Eerst het eigen adres voor terugbelverzoeken, dan het adres voor de
	 * weekcijfers, en pas als laatste de beheerder van de site.
	 */
	public static function bestemming() {
		$eigen = get_option( 'cf_exit_popup_callback_mail' );
		if ( $eigen && is_email( $eigen ) ) {
			return $eigen;
		}

		$meldingen = get_option( 'cf_exit_popup_mail_to' );
		if ( $meldingen && is_email( $meldingen ) ) {
			return $meldingen;
		}

		return get_option( 'admin_email' );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'cf_exit_popup_callbacks';
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			name varchar(100) NOT NULL DEFAULT '',
			phone varchar(32) NOT NULL DEFAULT '',
			reason varchar(20) NOT NULL DEFAULT '',
			page varchar(190) NOT NULL DEFAULT '',
			handled tinyint(1) NOT NULL DEFAULT 0,
			handled_at datetime DEFAULT NULL,
			mailed tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY handled (handled)
		) {$collate};";

		dbDelta( $sql );

		// Het adres waar vandaan teruggebeld wordt, maar alleen als er nog niets
		// staat: een keuze die iemand zelf gemaakt heeft mag een update niet
		// overschrijven.
		if ( ! get_option( 'cf_exit_popup_callback_mail' ) ) {
			update_option( 'cf_exit_popup_callback_mail', self::STANDAARD_ADRES, false );
		}
	}

	/* --- Binnenkomen ---------------------------------------------------------- */

	/**
	 * Maakt van een telefoonnummer iets waar je mee kunt bellen.
	 *
	 * Bewust ruim: mensen schrijven een nummer op tien manieren op, en een
	 * formulier dat een geldig nummer weigert kost je precies het gesprek waar
	 * het om begonnen was. We halen er alleen uit wat er niet in hoort.
	 *
	 * @return string Het opgeschoonde nummer, of '' als het niets kan zijn.
	 */
	public static function nummer_opschonen( $ruw ) {
		$nummer = preg_replace( '/[^0-9+]/', '', (string) $ruw );

		// Een plus mag alleen vooraan staan.
		$nummer = preg_replace( '/(?<!^)\+/', '', $nummer );

		$cijfers = strlen( preg_replace( '/[^0-9]/', '', $nummer ) );

		// Nederlandse nummers hebben er 10, met landcode 11 of 12. Onder de 9
		// kan het geen telefoonnummer zijn; boven de 15 ook niet (dat is de
		// internationale bovengrens).
		if ( $cijfers < 9 || $cijfers > 15 ) {
			return '';
		}

		return $nummer;
	}

	/**
	 * Slaat een verzoek op en stuurt de praktijk een bericht.
	 *
	 * @return true|WP_Error
	 */
	public static function aannemen( $data ) {
		global $wpdb;

		$naam   = trim( sanitize_text_field( isset( $data['name'] ) ? $data['name'] : '' ) );
		$nummer = self::nummer_opschonen( isset( $data['phone'] ) ? $data['phone'] : '' );

		if ( '' === $nummer ) {
			return new WP_Error( 'cf_geen_nummer', 'Dat lijkt geen telefoonnummer.' );
		}

		if ( '' === $naam ) {
			return new WP_Error( 'cf_geen_naam', 'Vul even uw naam in.' );
		}

		$rij = array(
			'created_at' => current_time( 'mysql' ),
			'name'       => mb_substr( $naam, 0, 100 ),
			'phone'      => mb_substr( $nummer, 0, 32 ),
			'reason'     => isset( $data['reason'] ) ? (string) $data['reason'] : '',
			'page'       => isset( $data['page'] ) ? (string) $data['page'] : '',
			'handled'    => 0,
		);

		$ok = $wpdb->insert( self::table(), $rij ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( ! $ok ) {
			return new WP_Error( 'cf_opslaan_mislukt', 'Kon het verzoek niet opslaan.' );
		}

		$id = (int) $wpdb->insert_id;

		// Of de mail aankwam weten we niet - dat weet niemand - maar of hij de
		// deur uit ging wel. Dat verschil is het hele punt: gaat het versturen
		// mis, dan hoort dat in het dashboard te staan en niet in stilte te
		// verdwijnen. Het verzoek zelf staat dan nog steeds in de tabel.
		$verstuurd = self::melden( $rij );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array( 'mailed' => $verstuurd ? 1 : 0 ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Mailt de praktijk dat er iemand teruggebeld wil worden.
	 *
	 * Het verzoek staat ook in het dashboard, dus als de mail niet aankomt is
	 * het niet weg. Daarom laten we een mislukte verzending het opslaan niet
	 * tegenhouden.
	 *
	 * @return bool Of de mail de deur uit ging.
	 */
	private static function melden( $rij ) {
		$naar = self::bestemming();

		$onderwerpen = array(
			'kosten'   => 'kosten of vergoeding',
			'klacht'   => 'een klacht of behandeling',
			'afspraak' => 'een afspraak maken',
			'anders'   => 'iets anders',
		);

		$onderwerp = isset( $onderwerpen[ $rij['reason'] ] ) ? $onderwerpen[ $rij['reason'] ] : '';

		$regels = array(
			'Er is een terugbelverzoek binnengekomen via de website.',
			'',
			'Naam:     ' . $rij['name'],
			'Nummer:   ' . $rij['phone'],
		);

		if ( $onderwerp ) {
			$regels[] = 'Vraag over: ' . $onderwerp;
		}

		if ( $rij['page'] ) {
			$regels[] = 'Pagina:   ' . $rij['page'];
		}

		$regels[] = '';
		$regels[] = 'Afhandelen kan in het dashboard:';
		$regels[] = admin_url( 'admin.php?page=cf-exit-popup' );

		return self::versturen(
			$naar,
			'Terugbelverzoek van ' . $rij['name'],
			implode( "\n", $regels )
		);
	}

	/**
	 * Verstuurt een bericht en onthoudt wat er misging.
	 *
	 * wp_mail() geeft alleen true of false terug; de reden staat in een aparte
	 * hook. Zonder die reden staat er straks "verzenden mislukt" in het
	 * dashboard en kun je er niets mee. Nu staat de melding van de mailserver
	 * er letterlijk bij.
	 *
	 * De afzendernaam zetten we ook: mail van "WordPress" komt bij een
	 * Microsoft-postbus eerder in ongewenst terecht dan mail met de naam van de
	 * praktijk erop. Het adres laten we ongemoeid, want dat is al het adres van
	 * het eigen domein en dat is precies wat SPF en DMARC verwachten.
	 *
	 * @return bool
	 */
	public static function versturen( $naar, $onderwerp, $tekst ) {
		$naam = function () {
			return get_bloginfo( 'name' ) . ' (website)';
		};

		$fout = null;
		$vang = function ( $wp_error ) use ( &$fout ) {
			$fout = $wp_error->get_error_message();
		};

		add_filter( 'wp_mail_from_name', $naam, 20 );
		add_action( 'wp_mail_failed', $vang );

		$ok = wp_mail( $naar, $onderwerp, $tekst );

		remove_filter( 'wp_mail_from_name', $naam, 20 );
		remove_action( 'wp_mail_failed', $vang );

		if ( $ok ) {
			delete_option( 'cf_exit_popup_mail_error' );
		} else {
			update_option(
				'cf_exit_popup_mail_error',
				array(
					'tijd'   => current_time( 'mysql' ),
					'naar'   => $naar,
					'reden'  => $fout ? $fout : 'De mailserver gaf geen reden op.',
				),
				false
			);
		}

		return (bool) $ok;
	}

	/**
	 * Stuurt een proefbericht, zodat je niet op een echt verzoek hoeft te
	 * wachten om te weten of het werkt.
	 *
	 * @return true|WP_Error
	 */
	public static function proefbericht( $naar = '' ) {
		$naar = $naar ? $naar : self::bestemming();

		if ( ! is_email( $naar ) ) {
			return new WP_Error( 'cf_geen_adres', 'Dat is geen geldig e-mailadres.' );
		}

		$regels = array(
			'Dit is een proefbericht van de pop-up op ' . home_url() . '.',
			'',
			'Ligt dit in de postbus, dan komen terugbelverzoeken ook aan.',
			'Zo niet, kijk dan eerst in Ongewenste e-mail en in de quarantaine',
			'van Microsoft 365.',
			'',
			'Verstuurd op ' . current_time( 'd-m-Y H:i' ) . '.',
		);

		if ( ! self::versturen( $naar, 'Proefbericht van de website', implode( "\n", $regels ) ) ) {
			$laatste = get_option( 'cf_exit_popup_mail_error' );
			$reden   = is_array( $laatste ) && ! empty( $laatste['reden'] ) ? $laatste['reden'] : 'onbekend';

			return new WP_Error( 'cf_mail_mislukt', $reden );
		}

		return true;
	}

	/**
	 * Herinnert aan verzoeken die blijven liggen.
	 *
	 * Dit is het vangnet onder de mail. Gaat een melding verloren - in de
	 * ongewenste map, of omdat de mailserver even niet wilde - dan zou een
	 * verzoek anders alleen in het dashboard staan, en daar kijkt niemand als
	 * hij geen reden heeft om te kijken. Eén herinnering per dag, en alleen als
	 * er echt iets openstaat.
	 */
	public static function herinneren() {
		global $wpdb;

		$table = self::table();
		$grens = gmdate(
			'Y-m-d H:i:s',
			strtotime( '-' . self::HERINNER_NA_UUR . ' hours', (int) current_time( 'timestamp' ) )
		);

		$oud = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT created_at, name, phone
				 FROM {$table}
				 WHERE handled = 0 AND created_at < %s
				 ORDER BY created_at ASC
				 LIMIT 25", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$grens
			),
			ARRAY_A
		);

		if ( ! $oud ) {
			return;
		}

		$aantal = count( $oud );
		$regels = array(
			1 === $aantal
				? 'Er staat nog een terugbelverzoek open dat niemand heeft afgevinkt.'
				: 'Er staan nog ' . $aantal . ' terugbelverzoeken open die niemand heeft afgevinkt.',
			'',
		);

		foreach ( $oud as $rij ) {
			$regels[] = mysql2date( 'd-m H:i', $rij['created_at'] ) . '  ' .
				$rij['name'] . '  ' . $rij['phone'];
		}

		$regels[] = '';
		$regels[] = 'Afvinken kan in het dashboard:';
		$regels[] = admin_url( 'admin.php?page=cf-exit-popup' );

		self::versturen(
			self::bestemming(),
			1 === $aantal
				? 'Nog een terugbelverzoek open'
				: 'Nog ' . $aantal . ' terugbelverzoeken open',
			implode( "\n", $regels )
		);
	}

	/* --- In het dashboard ------------------------------------------------------ */

	/**
	 * De openstaande verzoeken, nieuwste eerst.
	 */
	public static function openstaand( $limit = 25 ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, created_at, name, phone, reason, page, mailed
				 FROM {$table}
				 WHERE handled = 0
				 ORDER BY created_at DESC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);
	}

	public static function aantal_open() {
		global $wpdb;
		$table = self::table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(*) FROM {$table} WHERE handled = 0" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Hoeveel er in een periode binnenkwamen. Voor het dashboard.
	 */
	public static function aantal_periode( $dagen ) {
		global $wpdb;
		$table = self::table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', strtotime( '-' . (int) $dagen . ' days', (int) current_time( 'timestamp' ) ) )
			)
		);
	}

	public static function status_wijzigen() {
		if ( ! current_user_can( CF_Exit_Popup_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'chiro-fysio-exit-popup' ) );
		}

		check_admin_referer( 'cf_exit_popup_callback_status' );

		global $wpdb;

		$id = isset( $_POST['verzoek'] ) ? absint( $_POST['verzoek'] ) : 0;

		if ( $id ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table(),
				array( 'handled' => 1, 'handled_at' => current_time( 'mysql' ) ),
				array( 'id' => $id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=cf-exit-popup' ) );
		exit;
	}

	/**
	 * Ruimt afgehandelde verzoeken op.
	 *
	 * Openstaande verzoeken blijven staan: iemand die nog teruggebeld moet
	 * worden mag niet vanzelf uit de lijst verdwijnen.
	 */
	public static function opruimen() {
		global $wpdb;

		$table = self::table();
		$grens = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::BEWAARTERMIJN . ' days' ) );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE handled = 1 AND handled_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$grens
			)
		);
	}
}
