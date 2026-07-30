<?php
/**
 * Plugin Name:       Chiro-Fysio exit-intent pop-up
 * Description:       Vraagt bezoekers die de site dreigen te verlaten of ze gevonden hebben wat ze zochten, en toont anders het telefoonnummer van de praktijk.
 * Version:           1.0.0
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

define( 'CF_EXIT_POPUP_VERSION', '1.0.0' );

/**
 * Laadt de stylesheet en het script in de front-end.
 *
 * De bestanden staan in assets/ zodat je ze los kunt bijwerken zonder aan de
 * plugin zelf te komen.
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
}
add_action( 'wp_enqueue_scripts', 'cf_exit_popup_enqueue_assets' );
