<?php
/**
 * Test waar terugbelverzoeken heen gaan, en of een mislukte verzending
 * zichtbaar blijft.
 *
 * Aanleiding: een terugbelverzoek dat wel opgeslagen wordt maar niet gemaild,
 * is in de praktijk een gemist telefoontje. Het viel voorheen nergens op:
 * wp_mail() geeft alleen true of false terug en die kwam nergens terecht.
 *
 * Draait zonder WordPress; de handvol functies die de klasse gebruikt staan
 * hieronder nagebouwd, zodat we de echte code testen en geen kopie ervan.
 *
 *   php test/terugbelmail.php
 */

$goed = 0;
$fout = 0;

function check( $naam, $ok, $extra = '' ) {
	global $goed, $fout;
	if ( $ok ) {
		$goed++;
		echo "  PASS  {$naam}\n";
	} else {
		$fout++;
		echo "  FAIL  {$naam}" . ( $extra ? "  -> {$extra}" : '' ) . "\n";
	}
}

define( 'ABSPATH', __DIR__ );

/* --- WordPress, net genoeg om de klasse te laten draaien ------------------- */

$GLOBALS['opties']   = array();
$GLOBALS['post']     = array();   // wat wp_mail meekreeg
$GLOBALS['mag_mail'] = true;      // laat wp_mail slagen of falen
$GLOBALS['reden']    = '';        // wat de mailserver zou melden
$GLOBALS['filters']  = array();

function get_option( $naam, $standaard = false ) {
	return array_key_exists( $naam, $GLOBALS['opties'] ) ? $GLOBALS['opties'][ $naam ] : $standaard;
}

function update_option( $naam, $waarde, $autoload = null ) {
	$GLOBALS['opties'][ $naam ] = $waarde;
	return true;
}

function delete_option( $naam ) {
	unset( $GLOBALS['opties'][ $naam ] );
	return true;
}

function is_email( $adres ) {
	return (bool) filter_var( (string) $adres, FILTER_VALIDATE_EMAIL );
}

function add_filter( $haak, $fn, $prio = 10, $args = 1 ) {
	$GLOBALS['filters'][ $haak ][] = $fn;
}

function remove_filter( $haak, $fn, $prio = 10 ) {
	if ( empty( $GLOBALS['filters'][ $haak ] ) ) {
		return;
	}
	$GLOBALS['filters'][ $haak ] = array_values( array_filter(
		$GLOBALS['filters'][ $haak ],
		function ( $bestaand ) use ( $fn ) {
			return $bestaand !== $fn;
		}
	) );
}

function add_action( $haak, $fn, $prio = 10, $args = 1 ) {
	add_filter( $haak, $fn, $prio, $args );
}

function remove_action( $haak, $fn, $prio = 10 ) {
	remove_filter( $haak, $fn, $prio );
}

function get_bloginfo( $wat ) {
	return 'Chiro-Fysio';
}

function home_url() {
	return 'https://www.chiro-fysio.nl';
}

function admin_url( $pad = '' ) {
	return 'https://www.chiro-fysio.nl/wp-admin/' . $pad;
}

function current_time( $formaat ) {
	return 'timestamp' === $formaat ? time() : gmdate( 'Y-m-d H:i:s' );
}

function mysql2date( $formaat, $datum ) {
	return gmdate( $formaat, strtotime( $datum ) );
}

class WP_Error {
	public $code;
	public $bericht;

	public function __construct( $code, $bericht = '' ) {
		$this->code    = $code;
		$this->bericht = $bericht;
	}

	public function get_error_message() {
		return $this->bericht;
	}
}

function is_wp_error( $ding ) {
	return $ding instanceof WP_Error;
}

/**
 * De mailserver. Slaagt of faalt op commando, en meldt bij falen zijn reden
 * via dezelfde hook als WordPress dat doet.
 */
function wp_mail( $naar, $onderwerp, $tekst, $headers = array() ) {
	$GLOBALS['post'][] = array(
		'naar'      => $naar,
		'onderwerp' => $onderwerp,
		'tekst'     => $tekst,
		'afzender'  => isset( $GLOBALS['filters']['wp_mail_from_name'][0] )
			? call_user_func( $GLOBALS['filters']['wp_mail_from_name'][0], 'WordPress' )
			: 'WordPress',
	);

	if ( $GLOBALS['mag_mail'] ) {
		return true;
	}

	foreach ( isset( $GLOBALS['filters']['wp_mail_failed'] ) ? $GLOBALS['filters']['wp_mail_failed'] : array() as $fn ) {
		call_user_func( $fn, new WP_Error( 'wp_mail_failed', $GLOBALS['reden'] ) );
	}

	return false;
}

require_once __DIR__ . '/../wordpress/chiro-fysio-exit-popup/includes/class-cf-exit-popup-callbacks.php';

/* --- De tests -------------------------------------------------------------- */

echo "\n[1] Waar het verzoek heen gaat\n";

$GLOBALS['opties'] = array( 'admin_email' => 'beheer@voorbeeld.nl' );
check(
	'zonder instelling naar de beheerder van de site',
	'beheer@voorbeeld.nl' === CF_Exit_Popup_Callbacks::bestemming()
);

$GLOBALS['opties']['cf_exit_popup_mail_to'] = 'cijfers@chiro-fysio.nl';
check(
	'anders naar het adres voor de meldingen',
	'cijfers@chiro-fysio.nl' === CF_Exit_Popup_Callbacks::bestemming()
);

$GLOBALS['opties']['cf_exit_popup_callback_mail'] = 'marketing@chiro-fysio.nl';
check(
	'maar een eigen adres voor terugbelverzoeken wint',
	'marketing@chiro-fysio.nl' === CF_Exit_Popup_Callbacks::bestemming()
);

$GLOBALS['opties']['cf_exit_popup_callback_mail'] = 'geen adres';
check(
	'onzin in het veld valt terug in plaats van te verdwijnen',
	'cijfers@chiro-fysio.nl' === CF_Exit_Popup_Callbacks::bestemming()
);

check(
	'het standaardadres is dat van marketing',
	'marketing@chiro-fysio.nl' === CF_Exit_Popup_Callbacks::STANDAARD_ADRES
);

echo "\n[2] Een geslaagde verzending\n";

$GLOBALS['opties'] = array(
	'admin_email'                 => 'beheer@voorbeeld.nl',
	'cf_exit_popup_callback_mail' => 'marketing@chiro-fysio.nl',
	'cf_exit_popup_mail_error'    => array( 'reden' => 'oude fout' ),
);
$GLOBALS['post']     = array();
$GLOBALS['mag_mail'] = true;

$uit = CF_Exit_Popup_Callbacks::versturen( 'marketing@chiro-fysio.nl', 'Test', 'Hallo' );
check( 'meldt dat het gelukt is', true === $uit );
check( 'en ruimt de vorige foutmelding op', ! isset( $GLOBALS['opties']['cf_exit_popup_mail_error'] ) );
check(
	'de afzender draagt de naam van de praktijk, niet "WordPress"',
	isset( $GLOBALS['post'][0] ) && 'Chiro-Fysio (website)' === $GLOBALS['post'][0]['afzender'],
	isset( $GLOBALS['post'][0] ) ? $GLOBALS['post'][0]['afzender'] : 'niets verstuurd'
);
check( 'de filters blijven niet hangen na afloop', empty( $GLOBALS['filters']['wp_mail_from_name'] ) );

echo "\n[3] Een mislukte verzending\n";

$GLOBALS['post']     = array();
$GLOBALS['mag_mail'] = false;
$GLOBALS['reden']    = 'SMTP Error: Could not authenticate.';

$uit = CF_Exit_Popup_Callbacks::versturen( 'marketing@chiro-fysio.nl', 'Test', 'Hallo' );
check( 'meldt dat het misging', false === $uit );

$bewaard = get_option( 'cf_exit_popup_mail_error' );
check( 'de fout wordt bewaard', is_array( $bewaard ) );
check(
	'met de reden van de mailserver erbij',
	is_array( $bewaard ) && 'SMTP Error: Could not authenticate.' === $bewaard['reden'],
	is_array( $bewaard ) ? $bewaard['reden'] : 'niets bewaard'
);
check(
	'en met het adres waar het heen had gemoeten',
	is_array( $bewaard ) && 'marketing@chiro-fysio.nl' === $bewaard['naar']
);
check( 'ook nu blijven er geen filters hangen', empty( $GLOBALS['filters']['wp_mail_failed'] ) );

echo "\n[4] Het proefbericht\n";

$GLOBALS['post']     = array();
$GLOBALS['mag_mail'] = true;

$uit = CF_Exit_Popup_Callbacks::proefbericht();
check( 'gaat naar het ingestelde adres', true === $uit && 'marketing@chiro-fysio.nl' === $GLOBALS['post'][0]['naar'] );

$uit = CF_Exit_Popup_Callbacks::proefbericht( 'iemand@anders.nl' );
check( 'of naar een adres dat je meegeeft', true === $uit && 'iemand@anders.nl' === $GLOBALS['post'][1]['naar'] );

$uit = CF_Exit_Popup_Callbacks::proefbericht( 'geen adres' );
check( 'een ongeldig adres levert een nette fout op', is_wp_error( $uit ) );

$GLOBALS['mag_mail'] = false;
$GLOBALS['reden']    = 'Relay access denied';
$uit                 = CF_Exit_Popup_Callbacks::proefbericht();
check( 'en een mislukte proef ook', is_wp_error( $uit ) );
check(
	'met de reden erin, zodat je weet wat je moet oplossen',
	is_wp_error( $uit ) && false !== strpos( $uit->get_error_message(), 'Relay access denied' ),
	is_wp_error( $uit ) ? $uit->get_error_message() : 'geen fout'
);

echo "\n" . ( $fout ? "{$fout} controle(s) mislukt.\n" : "Terugbelmail in orde ({$goed} controles).\n" );
exit( $fout ? 1 : 0 );
