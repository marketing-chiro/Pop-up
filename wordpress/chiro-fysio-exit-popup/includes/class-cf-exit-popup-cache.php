<?php
/**
 * Leegt de cache van de site nadat er iets is gewijzigd.
 *
 * De instellingen van de pop-up staan in de HTML van elke pagina. Zolang een
 * cacheplugin de oude versie blijft uitserveren, ziet een bezoeker dus nog de
 * oude teksten en kleuren - ook al staat het in het dashboard allang goed.
 *
 * Daarom kloppen we na het opslaan bij elke bekende cacheplugin aan. Zit er
 * geen enkele op de site, dan gebeurt er simpelweg niets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF_Exit_Popup_Cache {

	/**
	 * Leegt alles wat er te legen valt.
	 *
	 * @return string[] Namen van de plugins die daadwerkelijk gereageerd hebben.
	 */
	public static function flush() {
		$geleegd = array();

		// WP Rocket
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$geleegd[] = 'WP Rocket';
		}
		if ( function_exists( 'rocket_clean_minify' ) ) {
			rocket_clean_minify();
		}

		// LiteSpeed Cache
		if ( class_exists( 'LiteSpeed\Purge' ) && method_exists( 'LiteSpeed\Purge', 'purge_all' ) ) {
			LiteSpeed\Purge::purge_all();
			$geleegd[] = 'LiteSpeed Cache';
		} elseif ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
			$geleegd[] = 'LiteSpeed Cache';
		}

		// W3 Total Cache
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$geleegd[] = 'W3 Total Cache';
		}

		// WP Super Cache
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$geleegd[] = 'WP Super Cache';
		}

		// WP Fastest Cache
		if ( isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
			$GLOBALS['wp_fastest_cache']->deleteCache( true );
			$geleegd[] = 'WP Fastest Cache';
		}

		// Autoptimize
		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			autoptimizeCache::clearall();
			$geleegd[] = 'Autoptimize';
		}

		// SiteGround Optimizer
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
			$geleegd[] = 'SiteGround Optimizer';
		}

		// Cache Enabler
		if ( has_action( 'cache_enabler_clear_complete_cache' ) ) {
			do_action( 'cache_enabler_clear_complete_cache' );
			$geleegd[] = 'Cache Enabler';
		}

		// Breeze
		if ( class_exists( 'Breeze_PurgeCache' ) && method_exists( 'Breeze_PurgeCache', 'breeze_cache_flush' ) ) {
			Breeze_PurgeCache::breeze_cache_flush();
			$geleegd[] = 'Breeze';
		}

		// Hummingbird
		if ( class_exists( '\Hummingbird\Core\Utils' ) && has_action( 'wphb_clear_page_cache' ) ) {
			do_action( 'wphb_clear_page_cache' );
			$geleegd[] = 'Hummingbird';
		}

		// Swift Performance
		if ( class_exists( 'Swift_Performance_Cache' ) && method_exists( 'Swift_Performance_Cache', 'clear_all_cache' ) ) {
			Swift_Performance_Cache::clear_all_cache();
			$geleegd[] = 'Swift Performance';
		}

		// Cloudflare, via de officiële plugin
		if ( class_exists( '\CF\WordPress\Hooks' ) ) {
			do_action( 'cloudflare_purge_everything' );
			$geleegd[] = 'Cloudflare';
		}

		// De ingebouwde objectcache van WordPress zelf.
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		/**
		 * Voor cacheplugins die hier niet bij staan.
		 *
		 * Zelf iets toevoegen kan met:
		 *   add_action( 'cf_exit_popup_flush_cache', 'mijn_eigen_cache_legen' );
		 */
		do_action( 'cf_exit_popup_flush_cache' );

		return array_unique( $geleegd );
	}

	/**
	 * Zijn er cacheplugins actief die de wijziging kunnen tegenhouden?
	 *
	 * Wordt gebruikt om op de instelpagina een waarschuwing te tonen als we
	 * niets kunnen legen maar er wel gecachet wordt.
	 */
	public static function detected() {
		$namen = array();

		$controles = array(
			'WP Rocket'            => function_exists( 'rocket_clean_domain' ),
			'LiteSpeed Cache'      => class_exists( 'LiteSpeed\Purge' ) || has_action( 'litespeed_purge_all' ),
			'W3 Total Cache'       => function_exists( 'w3tc_flush_all' ),
			'WP Super Cache'       => function_exists( 'wp_cache_clear_cache' ),
			'WP Fastest Cache'     => isset( $GLOBALS['wp_fastest_cache'] ),
			'Autoptimize'          => class_exists( 'autoptimizeCache' ),
			'SiteGround Optimizer' => function_exists( 'sg_cachepress_purge_cache' ),
			'Cache Enabler'        => has_action( 'cache_enabler_clear_complete_cache' ),
			'Breeze'               => class_exists( 'Breeze_PurgeCache' ),
			'Hummingbird'          => class_exists( '\Hummingbird\Core\Utils' ),
			'Swift Performance'    => class_exists( 'Swift_Performance_Cache' ),
			'Cloudflare'           => class_exists( '\CF\WordPress\Hooks' ),
		);

		foreach ( $controles as $naam => $aanwezig ) {
			if ( $aanwezig ) {
				$namen[] = $naam;
			}
		}

		return $namen;
	}
}
