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

	public static function init() {
		add_action( 'cf_exit_popup_cleanup', array( __CLASS__, 'opruimen' ) );
		add_action( 'admin_post_cf_exit_popup_callback_status', array( __CLASS__, 'status_wijzigen' ) );
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
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY handled (handled)
		) {$collate};";

		dbDelta( $sql );
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

		self::melden( $rij );

		return true;
	}

	/**
	 * Mailt de praktijk dat er iemand teruggebeld wil worden.
	 *
	 * Het verzoek staat ook in het dashboard, dus als de mail niet aankomt is
	 * het niet weg. Daarom laten we een mislukte verzending het opslaan niet
	 * tegenhouden.
	 */
	private static function melden( $rij ) {
		$naar = get_option( 'cf_exit_popup_mail_to' );
		if ( ! $naar || ! is_email( $naar ) ) {
			$naar = get_option( 'admin_email' );
		}

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

		wp_mail(
			$naar,
			'Terugbelverzoek van ' . $rij['name'],
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
				"SELECT id, created_at, name, phone, reason, page
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
