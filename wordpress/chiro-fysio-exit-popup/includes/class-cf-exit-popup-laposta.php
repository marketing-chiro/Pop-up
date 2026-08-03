<?php
/**
 * De koppeling met Laposta.
 *
 * Alles wat met de mailtool praat, gaat hier langs. Twee redenen om dat op één
 * plek te houden:
 *
 * 1. De API-sleutel mag nooit in de browser komen. Het dashboard is namelijk
 *    ook via een geheime link te bekijken, zonder inloggen. Zou de sleutel
 *    daarin zitten, dan kon iedereen met die link mail versturen namens de
 *    praktijk. Dus: de sleutel blijft hier, server-side, en de app vraagt het
 *    aan de plugin in plaats van rechtstreeks aan Laposta.
 *
 * 2. Laposta staat 120 verzoeken per minuut toe (30 op een gratis account).
 *    Door alles door één deur te laten gaan, kunnen we tellen en afremmen in
 *    plaats van er per ongeluk doorheen te knallen.
 *
 * De cijfers per campagne halen we niet elke keer op: die komen via webhooks
 * binnen en staan in onze eigen tabel. Dit bestand is er voor het versturen,
 * de lijsten en de eenmalige controles.
 *
 * Documentatie: https://api.laposta.nl/doc/index.nl.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF_Exit_Popup_Laposta {

	/** Waar de sleutel staat. Bewust zonder autoload: hij hoort niet op elke pagina mee te komen. */
	const OPTIE_SLEUTEL = 'cf_exit_popup_laposta_key';

	/** Het gekozen lijst-ID binnen Laposta. */
	const OPTIE_LIJST = 'cf_exit_popup_laposta_list';

	/** Onthoudt de laatste foutmelding, zodat een stille storing zichtbaar wordt. */
	const OPTIE_FOUT = 'cf_exit_popup_laposta_last_error';

	const BASIS = 'https://api.laposta.nl/v2/';

	/**
	 * Hoeveel verzoeken we onszelf per minuut toestaan.
	 *
	 * Bewust onder de 120 van een betaald account: er kan ook nog een webhook
	 * of een handmatige actie tussendoor komen.
	 */
	const LIMIET_PER_MINUUT = 60;

	/* --- Instellingen -------------------------------------------------------- */

	public static function sleutel() {
		return (string) get_option( self::OPTIE_SLEUTEL, '' );
	}

	/**
	 * Bewaart de sleutel. Leeg opslaan verwijdert hem, zodat je de koppeling
	 * ook weer los kunt trekken.
	 */
	public static function sleutel_opslaan( $sleutel ) {
		$sleutel = trim( (string) $sleutel );

		if ( '' === $sleutel ) {
			delete_option( self::OPTIE_SLEUTEL );
			delete_option( self::OPTIE_FOUT );
			return;
		}

		update_option( self::OPTIE_SLEUTEL, $sleutel, false );
		delete_option( self::OPTIE_FOUT );
	}

	public static function lijst_id() {
		return (string) get_option( self::OPTIE_LIJST, '' );
	}

	public static function lijst_opslaan( $id ) {
		update_option( self::OPTIE_LIJST, sanitize_text_field( (string) $id ), false );
	}

	/** Is de koppeling ingesteld? Zegt niets over of hij ook werkt. */
	public static function ingesteld() {
		return '' !== self::sleutel();
	}

	/** De laatste fout, of een lege string als alles goed ging. */
	public static function laatste_fout() {
		return (string) get_option( self::OPTIE_FOUT, '' );
	}

	/* --- Het verzoek zelf ---------------------------------------------------- */

	/**
	 * Doet één verzoek aan Laposta.
	 *
	 * @param string $methode GET, POST of DELETE.
	 * @param string $pad     Bijvoorbeeld 'campaign' of 'campaign/123/action/send'.
	 * @param array  $data    Body bij POST, querystring bij GET.
	 * @return array|WP_Error Het uitgepakte antwoord, of een WP_Error met uitleg.
	 */
	public static function verzoek( $methode, $pad, $data = array() ) {
		$sleutel = self::sleutel();

		if ( '' === $sleutel ) {
			return new WP_Error(
				'cf_laposta_geen_sleutel',
				'Er is nog geen Laposta-sleutel ingesteld.'
			);
		}

		if ( ! self::mag_nog() ) {
			return new WP_Error(
				'cf_laposta_te_druk',
				'Even wachten: er zijn te veel verzoeken achter elkaar gedaan.'
			);
		}

		$url = self::BASIS . ltrim( $pad, '/' );
		$args = array(
			'method'  => $methode,
			'timeout' => 20,
			'headers' => array(
				// Laposta gebruikt Basic auth met de sleutel als gebruikersnaam
				// en een leeg wachtwoord.
				'Authorization' => 'Basic ' . base64_encode( $sleutel . ':' ),
				'Accept'        => 'application/json',
			),
		);

		if ( 'GET' === $methode ) {
			if ( ! empty( $data ) ) {
				$url = add_query_arg( $data, $url );
			}
		} elseif ( ! empty( $data ) ) {
			$args['body'] = $data;
		}

		$antwoord = wp_remote_request( $url, $args );

		if ( is_wp_error( $antwoord ) ) {
			self::fout_onthouden( $antwoord->get_error_message() );
			return $antwoord;
		}

		$code = (int) wp_remote_retrieve_response_code( $antwoord );
		$body = json_decode( wp_remote_retrieve_body( $antwoord ), true );

		// Over de limiet heen: Laposta geeft aan hoe lang we moeten wachten.
		if ( 429 === $code ) {
			$wacht = (int) wp_remote_retrieve_header( $antwoord, 'retry-after' );
			self::fout_onthouden( 'Te veel verzoeken. Probeer het over ' . max( 1, $wacht ) . ' seconden opnieuw.' );

			return new WP_Error(
				'cf_laposta_limiet',
				'Laposta laat even geen verzoeken meer toe.',
				array( 'retry_after' => $wacht )
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			$melding = self::melding_uit( $body, $code );
			self::fout_onthouden( $melding );

			return new WP_Error( 'cf_laposta_fout', $melding, array( 'status' => $code ) );
		}

		if ( ! is_array( $body ) ) {
			self::fout_onthouden( 'Onbegrijpelijk antwoord van Laposta.' );
			return new WP_Error( 'cf_laposta_leeg', 'Onbegrijpelijk antwoord van Laposta.' );
		}

		delete_option( self::OPTIE_FOUT );

		return $body;
	}

	/**
	 * Haalt een leesbare melding uit het antwoord.
	 *
	 * Laposta zet de uitleg in error.message; staat daar niets, dan is de
	 * HTTP-code het enige dat we hebben.
	 */
	private static function melding_uit( $body, $code ) {
		if ( isset( $body['error']['message'] ) ) {
			return (string) $body['error']['message'];
		}

		if ( 401 === $code ) {
			return 'De sleutel wordt niet geaccepteerd. Klopt hij nog?';
		}

		if ( 404 === $code ) {
			return 'Niet gevonden bij Laposta.';
		}

		return 'Laposta gaf foutcode ' . $code . ' terug.';
	}

	private static function fout_onthouden( $melding ) {
		update_option( self::OPTIE_FOUT, (string) $melding, false );
	}

	/**
	 * Simpele rem: telt de verzoeken van de afgelopen minuut.
	 *
	 * Niet waterdicht bij twee gelijktijdige processen, maar het vangt het
	 * geval af waar het echt om gaat: een lus die per ongeluk doordraait.
	 */
	private static function mag_nog() {
		$sleutel = 'cf_laposta_tempo_' . gmdate( 'YmdHi' );
		$aantal  = (int) get_transient( $sleutel );

		if ( $aantal >= self::LIMIET_PER_MINUUT ) {
			return false;
		}

		set_transient( $sleutel, $aantal + 1, 120 );

		return true;
	}

	/* --- Lijsten ------------------------------------------------------------- */

	/**
	 * Alle lijsten in het account, teruggebracht tot wat we nodig hebben.
	 *
	 * @return array|WP_Error
	 */
	public static function lijsten() {
		$antwoord = self::verzoek( 'GET', 'list' );

		if ( is_wp_error( $antwoord ) ) {
			return $antwoord;
		}

		$uit = array();

		foreach ( (array) $antwoord['data'] as $rij ) {
			if ( empty( $rij['list'] ) ) {
				continue;
			}

			$l = $rij['list'];

			$uit[] = array(
				'id'          => (string) $l['list_id'],
				'naam'        => (string) $l['name'],
				'leden'       => (int) ( isset( $l['members']['active'] ) ? $l['members']['active'] : 0 ),
				'afgemeld'    => (int) ( isset( $l['members']['unsubscribed'] ) ? $l['members']['unsubscribed'] : 0 ),
			);
		}

		return $uit;
	}

	/* --- Campagnes ----------------------------------------------------------- */

	/**
	 * De campagnes in het account.
	 *
	 * @return array|WP_Error
	 */
	public static function campagnes() {
		$antwoord = self::verzoek( 'GET', 'campaign' );

		if ( is_wp_error( $antwoord ) ) {
			return $antwoord;
		}

		$uit = array();

		foreach ( (array) $antwoord['data'] as $rij ) {
			if ( empty( $rij['campaign'] ) ) {
				continue;
			}

			$uit[] = self::campagne_opschonen( $rij['campaign'] );
		}

		// Nieuwste eerst: dat is bijna altijd waar je naar zoekt.
		usort(
			$uit,
			function ( $a, $b ) {
				return strcmp( $b['aangemaakt'], $a['aangemaakt'] );
			}
		);

		return $uit;
	}

	/**
	 * Eén campagne.
	 *
	 * @return array|WP_Error
	 */
	public static function campagne( $id ) {
		$antwoord = self::verzoek( 'GET', 'campaign/' . rawurlencode( $id ) );

		if ( is_wp_error( $antwoord ) ) {
			return $antwoord;
		}

		if ( empty( $antwoord['campaign'] ) ) {
			return new WP_Error( 'cf_laposta_leeg', 'Deze campagne bestaat niet meer.' );
		}

		return self::campagne_opschonen( $antwoord['campaign'] );
	}

	/**
	 * Vertaalt het antwoord van Laposta naar de velden die de app gebruikt.
	 *
	 * Dit staat expres apart: verandert Laposta ooit een veldnaam, dan hoeft
	 * er maar op één plek iets aangepast te worden.
	 */
	private static function campagne_opschonen( $c ) {
		$stat = isset( $c['statistics'] ) ? $c['statistics'] : array();

		return array(
			'id'          => (string) ( isset( $c['campaign_id'] ) ? $c['campaign_id'] : '' ),
			'naam'        => (string) ( isset( $c['name'] ) ? $c['name'] : '' ),
			'onderwerp'   => (string) ( isset( $c['subject'] ) ? $c['subject'] : '' ),
			'status'      => (string) ( isset( $c['state'] ) ? $c['state'] : '' ),
			'lijst'       => (string) ( isset( $c['list_id'] ) ? $c['list_id'] : '' ),
			'aangemaakt'  => (string) ( isset( $c['created'] ) ? $c['created'] : '' ),
			'verstuurd'   => (string) ( isset( $c['delivery_requested'] ) ? $c['delivery_requested'] : '' ),
			'ontvangers'  => (int) ( isset( $stat['recipients'] ) ? $stat['recipients'] : 0 ),
			'geopend'     => (int) ( isset( $stat['opens_unique'] ) ? $stat['opens_unique'] : 0 ),
			'geklikt'     => (int) ( isset( $stat['clicks_unique'] ) ? $stat['clicks_unique'] : 0 ),
			'bounces'     => (int) ( isset( $stat['bounces'] ) ? $stat['bounces'] : 0 ),
			'afgemeld'    => (int) ( isset( $stat['unsubscribes'] ) ? $stat['unsubscribes'] : 0 ),
		);
	}

	/* --- Controle ------------------------------------------------------------ */

	/**
	 * Kijkt of de sleutel werkt.
	 *
	 * Gebruikt de lijsten-oproep, want die is licht en zegt meteen of er ook
	 * daadwerkelijk iets te versturen valt.
	 *
	 * @return array {
	 *     @type bool   $goed    Werkt de koppeling?
	 *     @type string $melding Uitleg voor op het scherm.
	 *     @type array  $lijsten De gevonden lijsten.
	 * }
	 */
	public static function controleer() {
		if ( ! self::ingesteld() ) {
			return array(
				'goed'    => false,
				'melding' => 'Er is nog geen sleutel ingevuld.',
				'lijsten' => array(),
			);
		}

		$lijsten = self::lijsten();

		if ( is_wp_error( $lijsten ) ) {
			return array(
				'goed'    => false,
				'melding' => $lijsten->get_error_message(),
				'lijsten' => array(),
			);
		}

		if ( empty( $lijsten ) ) {
			return array(
				'goed'    => true,
				'melding' => 'De koppeling werkt, maar er staat nog geen lijst in Laposta.',
				'lijsten' => array(),
			);
		}

		$totaal = 0;
		foreach ( $lijsten as $l ) {
			$totaal += $l['leden'];
		}

		return array(
			'goed'    => true,
			'melding' => sprintf(
				'Verbonden. %d %s gevonden, samen %s %s.',
				count( $lijsten ),
				1 === count( $lijsten ) ? 'lijst' : 'lijsten',
				number_format_i18n( $totaal ),
				1 === $totaal ? 'aanmelding' : 'aanmeldingen'
			),
			'lijsten' => $lijsten,
		);
	}
}
