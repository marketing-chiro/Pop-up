<?php
/**
 * Plugin Name:       Chiro-Fysio exit-intent pop-up
 * Description:       Vraagt bezoekers die de site dreigen te verlaten of ze gevonden hebben wat ze zochten, toont anders het telefoonnummer van de praktijk, en houdt in een eigen dashboard bij hoe vaak dat gebeurt.
 * Version:           1.1.0
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

define( 'CF_EXIT_POPUP_VERSION', '1.1.0' );
define( 'CF_EXIT_POPUP_DB_VERSION', '1' );
define( 'CF_EXIT_POPUP_FILE', __FILE__ );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-cf-exit-popup-storage.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cf-exit-popup-rest.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cf-exit-popup-admin.php';

CF_Exit_Popup_Rest::init();
CF_Exit_Popup_Admin::init();

/**
 * Bij activatie: tabel klaarzetten en de opruimtaak inplannen.
 */
function cf_exit_popup_activate() {
	CF_Exit_Popup_Storage::install();

	if ( ! wp_next_scheduled( 'cf_exit_popup_cleanup' ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'cf_exit_popup_cleanup' );
	}
}
register_activation_hook( __FILE__, 'cf_exit_popup_activate' );

/**
 * Bij deactivatie de geplande taak weer opruimen. De metingen blijven staan,
 * zodat je ze niet kwijt bent als de plugin even uit gaat.
 */
function cf_exit_popup_deactivate() {
	wp_clear_scheduled_hook( 'cf_exit_popup_cleanup' );
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
	}
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

	// Waar het script zijn metingen naartoe mag sturen. Zonder dit adres houdt
	// de pop-up gewoon op met meten - hij blijft dan verder werken.
	wp_add_inline_script(
		'cf-exit-popup',
		'window.CF_EXIT_POPUP = ' . wp_json_encode(
			array( 'endpoint' => esc_url_raw( rest_url( 'cf-exit-popup/v1/event' ) ) )
		) . ';',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'cf_exit_popup_enqueue_assets' );

/**
 * Snelkoppeling naar het dashboard op de pluginpagina.
 */
function cf_exit_popup_action_links( $links ) {
	$url = admin_url( 'admin.php?page=cf-exit-popup' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">Dashboard</a>' );

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'cf_exit_popup_action_links' );
