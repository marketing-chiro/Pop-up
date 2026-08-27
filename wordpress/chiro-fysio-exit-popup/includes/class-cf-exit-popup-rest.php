<?php
/**
 * Het meetpunt waar de pop-up zijn gebeurtenissen naartoe stuurt.
 *
 * Dit adres is bewust openbaar: bezoekers zijn niet ingelogd, en een
 * beveiligingssleutel zou verlopen zodra er een cacheplugin voor de site
 * staat. In plaats daarvan accepteren we alleen een korte, vaste lijst van
 * waarden en begrenzen we hoeveel een enkele bezoeker kan versturen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF_Exit_Popup_Rest {

	const NAMESPACE_V1 = 'cf-exit-popup/v1';

	/** Maximaal aantal meldingen per bezoeker per uur. */
	const RATE_LIMIT = 30;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/event',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_event' ),
				'permission_callback' => '__return_true', // openbaar, zie toelichting bovenaan
			)
		);
	}

	/**
	 * Neemt één gebeurtenis aan.
	 */
	public static function handle_event( WP_REST_Request $request ) {
		if ( self::rate_limited() ) {
			// 429 met een lege body: de pop-up doet hier niets mee, en we
			// verklappen niets over de limiet.
			return new WP_REST_Response( null, 429 );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( null, 400 );
		}

		$sid  = isset( $body['sid'] ) ? (string) $body['sid'] : '';
		$type = isset( $body['type'] ) ? (string) $body['type'] : '';

		// De code van een vertoning heeft een vast formaat; alles daarbuiten
		// is geen verzoek dat wij gemaakt hebben.
		if ( ! preg_match( '/^[a-z0-9]{16}$/', $sid ) ) {
			return new WP_REST_Response( null, 400 );
		}

		$allowed_types = array( 'open', 'answer', 'call', 'whatsapp', 'appointment', 'help', 'close' );
		if ( ! in_array( $type, $allowed_types, true ) ) {
			return new WP_REST_Response( null, 400 );
		}

		$data = array(
			'sid'     => $sid,
			'type'    => $type,
			'page'    => self::clean_path( isset( $body['page'] ) ? (string) $body['page'] : '' ),
			'device'  => self::pick( isset( $body['device'] ) ? (string) $body['device'] : '', array( 'desktop', 'mobiel' ) ),
			'trigger' => self::pick( isset( $body['trigger'] ) ? (string) $body['trigger'] : '', array( 'mouseleave', 'idle', 'scroll-up', 'test', 'handmatig' ) ),
			'answer'  => self::pick( isset( $body['answer'] ) ? (string) $body['answer'] : '', array( 'ja', 'nee' ) ),
			'visitor' => self::pick( isset( $body['visitor'] ) ? (string) $body['visitor'] : '', array( 'nieuw', 'terugkerend' ) ),
			// Bij 'close': op welk scherm de bezoeker stond toen hij wegklikte.
			'step'    => self::pick( isset( $body['step'] ) ? (string) $body['step'] : '', array( 'ask', 'yes', 'no' ) ),
		);

		CF_Exit_Popup_Storage::record( $data );

		// 204: aangenomen, verder niets te melden. De pop-up wacht hier niet op.
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Houdt alleen het pad over, zonder domein, zoekopdracht of anker.
	 *
	 * Een zoekopdracht in de URL kan zomaar iets persoonlijks bevatten, dus
	 * die knippen we er hier bewust af.
	 */
	private static function clean_path( $raw ) {
		$path = wp_parse_url( $raw, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '/';
		}

		$path = '/' . ltrim( sanitize_text_field( $path ), '/' );

		return substr( $path, 0, 190 );
	}

	/**
	 * Laat alleen waarden door die op de lijst staan.
	 */
	private static function pick( $value, $allowed ) {
		return in_array( $value, $allowed, true ) ? $value : '';
	}

	/**
	 * Eenvoudige begrenzing per bezoeker.
	 *
	 * Het IP-adres wordt alleen gehasht gebruikt als sleutel van een tijdelijke
	 * teller en nergens opgeslagen; na een uur verdwijnt die teller vanzelf.
	 */
	private static function rate_limited() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return false;
		}

		$key   = 'cf_exit_rl_' . substr( wp_hash( $ip ), 0, 20 );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return true;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return false;
	}
}
