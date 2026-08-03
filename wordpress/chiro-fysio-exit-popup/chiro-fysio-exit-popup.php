<?php
/**
 * Plugin Name:       Chiro-Fysio exit-intent pop-up
 * Description:       Vraagt bezoekers die de site dreigen te verlaten of ze gevonden hebben wat ze zochten, toont anders het telefoonnummer van de praktijk, en houdt in een eigen dashboard bij hoe vaak dat gebeurt.
 * Version:           1.6.0
 * Requires at least: 5.5
 * Requires PHP:      7.0
 * Author:            Chiro-Fysio
 * License:           GPL-2.0-or-later
 * Text Domain:       chiro-fysio-exit-popup
 */

// Directe toegang tot dit bestand blokkeren.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CF_EXIT_POPUP_VERSION', '1.6.0' );
define( 'CF_EXIT_POPUP_DB_VERSION', '2' );
define( 'CF_EXIT_POPUP_FILE', __FILE__ );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-cf-exit-popup-options.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cf-exit-popup-cache.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cf-exit-popup-storage.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cf-exit-popup-rest.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cf-exit-popup-admin.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cf-exit-popup-health.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cf-exit-popup-view.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cf-exit-popup-app.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cf-exit-popup-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cf-exit-popup-editor.php';

CF_Exit_Popup_Rest::init();
CF_Exit_Popup_Admin::init();
CF_Exit_Popup_Health::init();
CF_Exit_Popup_App::init();
CF_Exit_Popup_Settings::init();
CF_Exit_Popup_Editor::init();

/**
 * Bij activatie: tabel klaarzetten en de opruimtaak inplannen.
 */
function cf_exit_popup_activate() {
	CF_Exit_Popup_Storage::install();
	CF_Exit_Popup_App::setup_roles();

	// De adressen van de app moeten bekend zijn voordat ze werken.
	CF_Exit_Popup_App::add_rewrite();
	flush_rewrite_rules();

	cf_exit_popup_schedule_tasks();
}

/**
 * Zet de terugkerende taken klaar. Draait bij activatie en wordt ook bij elk
 * beheerbezoek nagelopen, zodat een taak die om wat voor reden ook verdwijnt
 * vanzelf weer terugkomt.
 */
function cf_exit_popup_schedule_tasks() {
	if ( ! wp_next_scheduled( 'cf_exit_popup_cleanup' ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'cf_exit_popup_cleanup' );
	}

	if ( ! wp_next_scheduled( 'cf_exit_popup_healthcheck' ) ) {
		// 's Ochtends vroeg, zodat een melding er ligt voordat de praktijk opengaat.
		wp_schedule_event( strtotime( 'tomorrow 6:30' ), 'daily', 'cf_exit_popup_healthcheck' );
	}

	if ( ! wp_next_scheduled( 'cf_exit_popup_weekly_digest' ) ) {
		wp_schedule_event( strtotime( 'next monday 7:30' ), 'weekly', 'cf_exit_popup_weekly_digest' );
	}
}
register_activation_hook( __FILE__, 'cf_exit_popup_activate' );

/**
 * Bij deactivatie de geplande taak weer opruimen. De metingen blijven staan,
 * zodat je ze niet kwijt bent als de plugin even uit gaat.
 */
function cf_exit_popup_deactivate() {
	flush_rewrite_rules();
	wp_clear_scheduled_hook( 'cf_exit_popup_cleanup' );
	wp_clear_scheduled_hook( 'cf_exit_popup_healthcheck' );
	wp_clear_scheduled_hook( 'cf_exit_popup_weekly_digest' );
}
register_deactivation_hook( __FILE__, 'cf_exit_popup_deactivate' );

add_action( 'cf_exit_popup_cleanup', array( 'CF_Exit_Popup_Storage', 'cleanup' ) );

/**
 * Vangt het geval op dat de plugin via een bestandsupload is bijgewerkt:
 * dan draait de activatiehook niet, maar moet de tabel er wel zijn.
 */
function cf_exit_popup_maybe_upgrade() {
	if ( get_option( 'cf_exit_popup_db_version' ) !== CF_EXIT_POPUP_DB_VERSION ) {
		CF_Exit_Popup_Storage::install();
		CF_Exit_Popup_App::setup_roles();
		flush_rewrite_rules();
	}

	// Verdwenen taken stilletjes herstellen.
	cf_exit_popup_schedule_tasks();
}
add_action( 'admin_init', 'cf_exit_popup_maybe_upgrade' );

/**
 * Laadt de stylesheet en het script in de front-end.
 */
function cf_exit_popup_enqueue_assets() {
	// Nooit laden in de beheeromgeving, feeds of tijdens previews van de
	// blokeditor: daar heeft een exit-pop-up niets te zoeken.
	if ( is_admin() || is_feed() || is_preview() ) {
		return;
	}

	// In het dashboard uitgezet? Dan laden we niets. De cijfers blijven staan.
	if ( ! CF_Exit_Popup_Options::get( 'enabled' ) ) {
		return;
	}

	// Ingelogde beheerders zien de pop-up standaard niet, zodat je rustig kunt
	// werken. Wil je hem juist wel zien om te testen? Gebruik dan ?cf-popup=test
	// in de URL, of verwijder deze drie regels.
	if ( current_user_can( 'edit_posts' ) && ! isset( $_GET['cf-popup'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$dir = plugin_dir_path( __FILE__ ) . 'assets/';
	$url = plugin_dir_url( __FILE__ ) . 'assets/';

	// filemtime als versie: na een wijziging haalt de browser het bestand
	// meteen opnieuw op in plaats van een oude versie uit de cache te gebruiken.
	$css_version = file_exists( $dir . 'exit-intent-popup.css' )
		? (string) filemtime( $dir . 'exit-intent-popup.css' )
		: CF_EXIT_POPUP_VERSION;

	$js_version = file_exists( $dir . 'exit-intent-popup.js' )
		? (string) filemtime( $dir . 'exit-intent-popup.js' )
		: CF_EXIT_POPUP_VERSION;

	wp_enqueue_style(
		'cf-exit-popup',
		$url . 'exit-intent-popup.css',
		array(),
		$css_version
	);

	wp_enqueue_script(
		'cf-exit-popup',
		$url . 'exit-intent-popup.js',
		array(),
		$js_version,
		true // in de footer, zodat de pagina niet vertraagt
	);

	// Het meetpunt en alle instellingen uit het dashboard. Zonder dit adres
	// houdt de pop-up op met meten, maar blijft hij verder gewoon werken; zonder
	// de instellingen valt hij terug op de standaardwaarden in het script zelf.
	wp_add_inline_script(
		'cf-exit-popup',
		'window.CF_EXIT_POPUP = ' . wp_json_encode(
			array(
				'endpoint' => esc_url_raw( rest_url( 'cf-exit-popup/v1/event' ) ),
				'config'   => CF_Exit_Popup_Options::for_script(),
			)
		) . ';',
		'before'
	);

	// De huisstijl als CSS-variabelen. De hele opmaak is daarop gebouwd, dus dit
	// kleurt in één keer de knoppen, randen en accenten.
	wp_add_inline_style( 'cf-exit-popup', CF_Exit_Popup_Options::brand_css() );
}
add_action( 'wp_enqueue_scripts', 'cf_exit_popup_enqueue_assets' );

/**
 * Snelkoppeling naar het dashboard op de pluginpagina.
 */
function cf_exit_popup_action_links( $links ) {
	$url = admin_url( 'admin.php?page=cf-exit-popup' );
	array_unshift(
		$links,
		'<a href="' . esc_url( $url ) . '">Dashboard</a>',
		'<a href="' . esc_url( CF_Exit_Popup_App::url() ) . '" target="_blank" rel="noopener">App</a>'
	);

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'cf_exit_popup_action_links' );

/**
 * Houdt cache- en optimalisatieplugins van het script af.
 *
 * Dit is verreweg de meest voorkomende manier waarop zoiets stilletjes stopt
 * met werken: een plugin voegt alle JavaScript samen of stelt het uit, en het
 * script draait daarna net te laat of helemaal niet meer. Elk van deze
 * markeringen betekent bij een van de bekende plugins "deze met rust laten":
 *
 *   data-no-optimize   Autoptimize
 *   data-no-defer      Autoptimize, Swift Performance
 *   data-cfasync       Cloudflare Rocket Loader
 *   data-nowprocket    WP Rocket
 *   data-no-minify     LiteSpeed Cache, SG Optimizer
 */
function cf_exit_popup_keep_script_untouched( $tag, $handle ) {
	if ( 'cf-exit-popup' !== $handle ) {
		return $tag;
	}

	return str_replace(
		'<script ',
		'<script data-no-optimize="1" data-no-defer="1" data-cfasync="false" data-nowprocket data-no-minify="1" ',
		$tag
	);
}
add_filter( 'script_loader_tag', 'cf_exit_popup_keep_script_untouched', 10, 2 );
