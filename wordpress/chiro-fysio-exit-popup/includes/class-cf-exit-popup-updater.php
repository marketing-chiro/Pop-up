<?php
/**
 * Automatisch bijwerken, zonder telkens een zip te uploaden.
 *
 * WordPress kan plugins zelf bijwerken, maar kijkt daarvoor standaard alleen in
 * de officiele pluginmap. Deze plugin staat daar niet in, dus moesten nieuwe
 * versies met de hand geuploard worden. Dat werkt niet in de praktijk: een
 * kleine correctie kost dan een handeling van iemand die er net niet aan denkt.
 *
 * Daarom wijzen we WordPress hier naar een eigen bestandje met de laatste
 * versie erin. Vanaf dat moment verschijnt er gewoon "Update beschikbaar" bij
 * Plugins, met een knop ernaast - en kun je bij Plugins ook
 * "Automatische updates inschakelen" aanzetten, zodat je er helemaal niets
 * meer aan hoeft te doen.
 *
 * Let op wat je hiermee afspreekt: wie het bestand achter MANIFEST beheert,
 * kan code op deze site installeren. Dat is jullie eigen repository, dus dat
 * is in orde - maar het is wel een echte vertrouwensrelatie. Wil je hem ergens
 * anders neerzetten, gebruik dan het filter 'cf_exit_popup_update_manifest'.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF_Exit_Popup_Updater {

	/** Waar de laatste versie staat. */
	const MANIFEST = 'https://raw.githubusercontent.com/marketing-chiro/pop-up/claude/chiro-fysio-exit-popup-eahq6b/dist/update-exit-popup.json';

	/** Hoe lang we het antwoord bewaren, zodat we niet bij elk beheerbezoek kijken. */
	const CACHE_KEY = 'cf_exit_popup_update_check';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'aanbieden' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 20, 3 );

		// Na een update het antwoord weggooien, anders blijft "update
		// beschikbaar" staan terwijl hij net gedaan is.
		add_action( 'upgrader_process_complete', array( __CLASS__, 'vergeet' ), 10, 0 );

		// Handmatig verversen via Dashboard -> Updates.
		add_action( 'load-update-core.php', array( __CLASS__, 'vergeet' ) );
	}

	public static function vergeet() {
		delete_transient( self::CACHE_KEY );
	}

	private static function bestandsnaam() {
		return plugin_basename( CF_EXIT_POPUP_FILE );
	}

	private static function slug() {
		return dirname( self::bestandsnaam() );
	}

	/**
	 * Haalt het versiebestand op.
	 *
	 * @return array|false
	 */
	private static function op_afstand() {
		$bewaard = get_transient( self::CACHE_KEY );

		if ( is_array( $bewaard ) ) {
			return $bewaard;
		}

		/**
		 * Waar de plugin naar updates kijkt.
		 *
		 * Gebruik dit als je de bestanden ergens anders neerzet.
		 */
		$url = apply_filters( 'cf_exit_popup_update_manifest', self::MANIFEST );

		$antwoord = wp_remote_get( $url, array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/json' ),
		) );

		if ( is_wp_error( $antwoord ) || 200 !== (int) wp_remote_retrieve_response_code( $antwoord ) ) {
			// Even niet bereikbaar? Dan een uur met rust laten. Bijwerken mag
			// nooit het beheerscherm ophouden of vollopen met meldingen.
			set_transient( self::CACHE_KEY, array(), HOUR_IN_SECONDS );
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $antwoord ), true );

		if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['download_url'] ) ) {
			set_transient( self::CACHE_KEY, array(), HOUR_IN_SECONDS );
			return false;
		}

		// Alleen van ons eigen adres downloaden, en alleen over https. Zonder
		// deze controle zou een aangepast versiebestand willekeurige code op de
		// site kunnen laten installeren.
		if ( ! self::adres_vertrouwd( $data['download_url'] ) ) {
			set_transient( self::CACHE_KEY, array(), HOUR_IN_SECONDS );
			return false;
		}

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Komt het downloadadres van dezelfde plek als het versiebestand?
	 */
	private static function adres_vertrouwd( $download_url ) {
		$bron = apply_filters( 'cf_exit_popup_update_manifest', self::MANIFEST );

		$a = wp_parse_url( $download_url );
		$b = wp_parse_url( $bron );

		if ( empty( $a['host'] ) || empty( $b['host'] ) ) {
			return false;
		}

		return 'https' === strtolower( isset( $a['scheme'] ) ? $a['scheme'] : '' )
			&& strtolower( $a['host'] ) === strtolower( $b['host'] );
	}

	/**
	 * Vertelt WordPress of er een nieuwere versie klaarstaat.
	 */
	public static function aanbieden( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$data = self::op_afstand();

		if ( ! $data ) {
			return $transient;
		}

		$bestand = self::bestandsnaam();

		$info = (object) array(
			'slug'        => self::slug(),
			'plugin'      => $bestand,
			'new_version' => (string) $data['version'],
			'url'         => isset( $data['homepage'] ) ? $data['homepage'] : '',
			'package'     => (string) $data['download_url'],
			'tested'      => isset( $data['tested'] ) ? $data['tested'] : '',
			'requires'    => isset( $data['requires'] ) ? $data['requires'] : '',
			'requires_php'=> isset( $data['requires_php'] ) ? $data['requires_php'] : '',
		);

		if ( version_compare( $data['version'], CF_EXIT_POPUP_VERSION, '>' ) ) {
			$transient->response[ $bestand ] = $info;
			unset( $transient->no_update[ $bestand ] );

			return $transient;
		}

		// Bij zijn telt ook: zonder deze regel toont WordPress geen schakelaar
		// voor automatische updates, en dan blijft het handwerk.
		$transient->no_update[ $bestand ] = $info;
		unset( $transient->response[ $bestand ] );

		return $transient;
	}

	/**
	 * Vult het venster achter "Details bekijken".
	 */
	public static function details( $resultaat, $actie, $args ) {
		if ( 'plugin_information' !== $actie ) {
			return $resultaat;
		}

		if ( ! isset( $args->slug ) || self::slug() !== $args->slug ) {
			return $resultaat;
		}

		$data = self::op_afstand();

		if ( ! $data ) {
			return $resultaat;
		}

		return (object) array(
			'name'          => isset( $data['name'] ) ? $data['name'] : 'Chiro-Fysio exit-intent pop-up',
			'slug'          => self::slug(),
			'version'       => (string) $data['version'],
			'author'        => isset( $data['author'] ) ? $data['author'] : 'Chiro-Fysio',
			'homepage'      => isset( $data['homepage'] ) ? $data['homepage'] : '',
			'requires'      => isset( $data['requires'] ) ? $data['requires'] : '',
			'tested'        => isset( $data['tested'] ) ? $data['tested'] : '',
			'requires_php'  => isset( $data['requires_php'] ) ? $data['requires_php'] : '',
			'last_updated'  => isset( $data['last_updated'] ) ? $data['last_updated'] : '',
			'download_link' => (string) $data['download_url'],
			'sections'      => isset( $data['sections'] ) && is_array( $data['sections'] )
				? array_map( 'wp_kses_post', $data['sections'] )
				: array(),
		);
	}
}
