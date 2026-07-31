<?php
/**
 * Houdt in de gaten of de pop-up nog daadwerkelijk meet.
 *
 * De aanleiding hiervoor was een echte storing: een query liep stuk op een
 * gereserveerd woord, waardoor het dashboard nullen toonde alsof het een
 * rustige week was. Zoiets kun je maanden over het hoofd zien. Daarom kijkt
 * de plugin nu zelf of alles nog draait, en meldt hij het als dat niet zo is.
 *
 * Er gaat alleen een bericht uit als er echt iets aan de hand is. Een plugin
 * die elke dag een mail stuurt, wordt binnen een week weggefilterd.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF_Exit_Popup_Health {

	/** Zoveel dagen zonder enige meting vinden we verdacht. */
	const STIL_NA_DAGEN = 3;

	/** Pas waarschuwen als de plugin zich eerder al bewezen heeft. */
	const MINIMAAL_EERDER_GEMETEN = 15;

	/** Niet vaker dan eens per zoveel dagen dezelfde waarschuwing sturen. */
	const HERHAAL_NA_DAGEN = 7;

	public static function init() {
		add_action( 'cf_exit_popup_healthcheck', array( __CLASS__, 'run_check' ) );
		add_action( 'cf_exit_popup_weekly_digest', array( __CLASS__, 'send_digest' ) );
	}

	/**
	 * Alles wat de statuskaart op het dashboard laat zien.
	 *
	 * @param bool $force Meetpunt opnieuw benaderen in plaats van het
	 *                    opgeslagen antwoord van het afgelopen uur gebruiken.
	 */
	public static function report( $force = false ) {
		global $wpdb;

		$table = CF_Exit_Popup_Storage::table();

		$bestaat = (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		// Sommige opslaglagen (zoals SQLite) kennen SHOW TABLES niet; dan
		// proberen we het gewoon met een telling.
		$regels = null;
		if ( ! $bestaat ) {
			$regels  = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
			$bestaat = ( null !== $regels );
		} else {
			$regels = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
		}

		$laatste = $bestaat
			? $wpdb->get_var( "SELECT MAX(created_at) FROM {$table}" ) // phpcs:ignore
			: null;

		$verslag = array(
			'tabel_bestaat' => $bestaat,
			'regels'        => (int) $regels,
			'laatste_event' => $laatste,
			'stil_sinds'    => self::dagen_geleden( $laatste ),
			'events_24u'    => self::tel_sinds( 1 ),
			'events_7d'     => self::tel_sinds( 7 ),
			'db_fout'       => get_option( 'cf_exit_popup_last_db_error' ),
			'cron_opruimen' => wp_next_scheduled( 'cf_exit_popup_cleanup' ),
			'cron_controle' => wp_next_scheduled( 'cf_exit_popup_healthcheck' ),
			'cron_weekmail' => wp_next_scheduled( 'cf_exit_popup_weekly_digest' ),
			'bestanden'     => self::assets_aanwezig(),
			'meetpunt'      => self::test_meetpunt( $force ),
		);

		$verslag['status'] = self::beoordeel( $verslag );

		return $verslag;
	}

	/**
	 * Eén woord dat samenvat hoe het ervoor staat.
	 */
	private static function beoordeel( $v ) {
		if ( ! $v['tabel_bestaat'] || ! $v['bestanden'] || ! $v['meetpunt']['ok'] || $v['db_fout'] ) {
			return 'probleem';
		}

		// Nog nooit iets gemeten is geen storing, dat is een verse installatie.
		if ( 0 === $v['regels'] ) {
			return 'wachten';
		}

		if ( null !== $v['stil_sinds'] && $v['stil_sinds'] >= self::STIL_NA_DAGEN ) {
			return 'let op';
		}

		if ( ! $v['cron_opruimen'] || ! $v['cron_controle'] ) {
			return 'let op';
		}

		return 'goed';
	}

	private static function dagen_geleden( $datum ) {
		if ( ! $datum ) {
			return null;
		}

		return (int) floor( ( strtotime( current_time( 'mysql' ) ) - strtotime( $datum ) ) / DAY_IN_SECONDS );
	}

	private static function tel_sinds( $dagen ) {
		global $wpdb;

		$table = CF_Exit_Popup_Storage::table();
		$sinds = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( $dagen * DAY_IN_SECONDS ) );

		$n = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $sinds ) // phpcs:ignore
		);

		return (int) $n;
	}

	/**
	 * Staan de bestanden er nog die de pop-up nodig heeft?
	 */
	private static function assets_aanwezig() {
		$dir = plugin_dir_path( CF_EXIT_POPUP_FILE ) . 'assets/';

		return file_exists( $dir . 'exit-intent-popup.js' ) && file_exists( $dir . 'exit-intent-popup.css' );
	}

	/**
	 * Klopt er aan het eigen meetpunt en kijkt of er iemand opendoet.
	 *
	 * We sturen bewust een onbruikbare code mee: het meetpunt hoort dan met
	 * "400, dat klopt niet" te antwoorden. Zo weten we dat het bereikbaar is
	 * zonder dat er een verzonnen meting in de cijfers belandt.
	 */
	private static function test_meetpunt( $force = false ) {
		$cache = get_transient( 'cf_exit_popup_endpoint_check' );
		if ( ! $force && is_array( $cache ) ) {
			return $cache;
		}

		$antwoord = wp_remote_post(
			rest_url( 'cf-exit-popup/v1/event' ),
			array(
				'timeout'  => 8,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'sid' => 'zelfcontrole', 'type' => 'open' ) ),
				'blocking' => true,
			)
		);

		if ( is_wp_error( $antwoord ) ) {
			$uitkomst = array(
				'ok'      => false,
				'code'    => 0,
				'melding' => $antwoord->get_error_message(),
			);
		} else {
			$code = (int) wp_remote_retrieve_response_code( $antwoord );
			// 400 is hier het goede antwoord: bereikbaar en het weigert onzin.
			$uitkomst = array(
				'ok'      => in_array( $code, array( 400, 204, 429 ), true ),
				'code'    => $code,
				'melding' => '',
			);
		}

		set_transient( 'cf_exit_popup_endpoint_check', $uitkomst, HOUR_IN_SECONDS );

		return $uitkomst;
	}

	/* ======================================================================
	   Dagelijkse controle
	   ====================================================================== */

	public static function run_check() {
		$v = self::report( true );

		if ( in_array( $v['status'], array( 'goed', 'wachten' ), true ) ) {
			// Alles in orde: onthouden dat we niets te melden hebben, zodat een
			// volgende storing weer als nieuw geldt.
			delete_option( 'cf_exit_popup_alert_sent' );
			return;
		}

		// Nog te weinig geschiedenis om iets zinnigs over te zeggen.
		if ( $v['regels'] < self::MINIMAAL_EERDER_GEMETEN ) {
			return;
		}

		$vorige = (int) get_option( 'cf_exit_popup_alert_sent' );
		if ( $vorige && ( time() - $vorige ) < ( self::HERHAAL_NA_DAGEN * DAY_IN_SECONDS ) ) {
			return; // niet blijven herhalen
		}

		self::send_alert( $v );
		update_option( 'cf_exit_popup_alert_sent', time(), false );
	}

	private static function send_alert( $v ) {
		$naar  = self::ontvanger();
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$link  = admin_url( 'admin.php?page=cf-exit-popup' );

		$reden = array();
		if ( ! $v['tabel_bestaat'] ) {
			$reden[] = 'De tabel met metingen is niet meer te vinden.';
		}
		if ( ! $v['bestanden'] ) {
			$reden[] = 'De bestanden van de pop-up staan niet meer op hun plek.';
		}
		if ( ! $v['meetpunt']['ok'] ) {
			$reden[] = 'Het meetpunt is niet bereikbaar (antwoord: ' . $v['meetpunt']['code'] . ').';
		}
		if ( $v['db_fout'] ) {
			$reden[] = 'De database gaf een fout: ' . $v['db_fout']['message'];
		}
		if ( null !== $v['stil_sinds'] && $v['stil_sinds'] >= self::STIL_NA_DAGEN ) {
			$reden[] = 'Er zijn al ' . $v['stil_sinds'] . ' dagen geen metingen meer binnengekomen.';
		}
		if ( ! $v['cron_opruimen'] || ! $v['cron_controle'] ) {
			$reden[] = 'De automatische taken staan niet meer ingepland.';
		}

		$tekst  = "De exit-pop-up op {$site} lijkt niet meer goed te werken.\n\n";
		$tekst .= "Wat er opvalt:\n";
		foreach ( $reden as $r ) {
			$tekst .= " - {$r}\n";
		}
		$tekst .= "\nLaatste meting: " . ( $v['laatste_event'] ? $v['laatste_event'] : 'nog nooit' ) . "\n";
		$tekst .= "Metingen afgelopen week: {$v['events_7d']}\n\n";
		$tekst .= "Bekijk het dashboard: {$link}\n\n";
		$tekst .= "Vaak is de oorzaak een cache- of optimalisatieplugin die het script tegenhoudt. ";
		$tekst .= "Leegmaken van de cache lost dat meestal op.\n\n";
		$tekst .= "Dit bericht komt maar eens per week terug zolang het probleem blijft.\n";

		wp_mail( $naar, "[{$site}] De exit-pop-up meet niets meer", $tekst );
	}

	/* ======================================================================
	   Wekelijkse samenvatting
	   ====================================================================== */

	public static function send_digest() {
		$t = CF_Exit_Popup_Storage::totals( 7 );

		// Een lege week is geen bericht waard.
		if ( 0 === (int) $t['shown'] ) {
			return;
		}

		$naar = self::ontvanger();
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$link = admin_url( 'admin.php?page=cf-exit-popup&periode=7' );

		$beantwoord = (int) $t['found'] + (int) $t['not_found'];
		$pct        = $beantwoord > 0 ? round( $t['not_found'] / $beantwoord * 100 ) : 0;

		$tekst  = "De cijfers van de exit-pop-up op {$site}, afgelopen zeven dagen.\n\n";
		$tekst .= "  Pop-up getoond            {$t['shown']}\n";
		$tekst .= "  Vond niet wat die zocht   {$t['not_found']}  ({$pct}% van wie antwoord gaf)\n";
		$tekst .= "  Nam alsnog contact op     {$t['rescued']}\n";
		$tekst .= "  Toch weg, zonder contact  {$t['lost']}\n";

		$paginas = CF_Exit_Popup_Storage::top_pages( 7, 3 );
		if ( $paginas ) {
			$tekst .= "\nWaar bezoekers vastliepen:\n";
			foreach ( $paginas as $p ) {
				$tekst .= "  {$p['not_found']}x  {$p['page']}\n";
			}
		}

		if ( $t['lost'] > 0 ) {
			$tekst .= "\nDie {$t['lost']} bezoekers zeiden dat ze niet vonden wat ze zochten ";
			$tekst .= "en vertrokken zonder contact op te nemen. Daar lag werk.\n";
		}

		$tekst .= "\nHele dashboard: {$link}\n";

		wp_mail( $naar, "[{$site}] Exit-pop-up: de week in vier cijfers", $tekst );
	}

	/**
	 * Waar de berichten heen gaan. Standaard het beheerdersadres van de site.
	 */
	private static function ontvanger() {
		$adres = get_option( 'cf_exit_popup_mail_to' );

		if ( ! $adres || ! is_email( $adres ) ) {
			$adres = get_option( 'admin_email' );
		}

		return $adres;
	}
}
