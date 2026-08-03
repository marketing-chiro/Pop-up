<?php
/**
 * Plugin Name:       Chiro-Fysio e-mailcampagnes
 * Description:       Koppelt de site aan Laposta: campagnes bekijken en starten, en meten wat een mail oplevert tot en met de klik naar de agenda.
 * Version:           0.1.0
 * Requires at least: 5.5
 * Requires PHP:      7.0
 * Author:            Chiro-Fysio
 * License:           GPL-2.0-or-later
 * Text Domain:       chiro-fysio-campagnes
 */

/*
 * Waarom dit een eigen plugin is, en niet een onderdeel van de exit-pop-up:
 *
 * De pop-up en de nieuwsbrief zijn twee losse dingen die niets van elkaar
 * nodig hebben. Zet je de pop-up ooit uit, dan hoort je e-mail gewoon door te
 * draaien - en andersom. Ze delen straks alleen het dashboard, en dat is een
 * kijkvenster, geen koppeling.
 *
 * Deze plugin werkt dus zelfstandig. Staat de pop-upplugin er niet, dan
 * ontbreekt alleen het gezamenlijke overzicht; alles hier blijft werken.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CF_CAMPAGNE_VERSION', '0.1.0' );
define( 'CF_CAMPAGNE_FILE', __FILE__ );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-cf-campagne-laposta.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cf-campagne-admin.php';

CF_Campagne_Admin::init();

/**
 * Snelkoppeling naar de instellingen op de pluginpagina.
 */
function cf_campagne_action_links( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'admin.php?page=' . CF_Campagne_Admin::SLUG ) ) . '">Instellingen</a>'
	);

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'cf_campagne_action_links' );

/**
 * Bij verwijderen blijft de sleutel staan.
 *
 * Bewust: haal je de plugin er even af om iets te proberen, dan wil je niet
 * opnieuw een sleutel hoeven aanmaken. Loskoppelen doe je met de knop op de
 * instelpagina - dat is een bewuste handeling, verwijderen niet altijd.
 */
