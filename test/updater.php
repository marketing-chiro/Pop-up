<?php
/**
 * Test de updater zonder een hele WordPress.
 *
 * De belangrijkste regel hier is de controle op het downloadadres. Zonder die
 * controle zou een aangepast versiebestand de site willekeurige code kunnen
 * laten installeren - dat is precies het soort fout dat je niet met het blote
 * oog uit de code haalt. Vandaar deze test.
 *
 * Draaien:  php test/updater.php
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

/* --- Zoveel van WordPress als de klasse nodig heeft ---------------------- */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'CF_EXIT_POPUP_FILE', '/plugins/chiro-fysio-exit-popup/chiro-fysio-exit-popup.php' );
define( 'CF_EXIT_POPUP_VERSION', '1.9.0' );

$GLOBALS['opslag']   = array();
$GLOBALS['antwoord'] = null;

function get_transient( $k ) {
	return isset( $GLOBALS['opslag'][ $k ] ) ? $GLOBALS['opslag'][ $k ] : false;
}
function set_transient( $k, $v, $t = 0 ) {
	$GLOBALS['opslag'][ $k ] = $v;
	return true;
}
function delete_transient( $k ) {
	unset( $GLOBALS['opslag'][ $k ] );
	return true;
}
function add_filter() {}
function add_action() {}
function apply_filters( $naam, $waarde ) {
	return $waarde;
}
function plugin_basename( $bestand ) {
	return 'chiro-fysio-exit-popup/chiro-fysio-exit-popup.php';
}
function wp_parse_url( $url ) {
	return parse_url( $url );
}
function wp_kses_post( $s ) {
	return $s;
}
function is_wp_error( $d ) {
	return $d instanceof WP_Error;
}
class WP_Error {}
function wp_remote_get( $url, $args = array() ) {
	return $GLOBALS['antwoord'];
}
function wp_remote_retrieve_response_code( $a ) {
	return isset( $a['code'] ) ? $a['code'] : 0;
}
function wp_remote_retrieve_body( $a ) {
	return isset( $a['body'] ) ? $a['body'] : '';
}

require_once __DIR__ . '/../wordpress/chiro-fysio-exit-popup/includes/class-cf-exit-popup-updater.php';

/* --- Hulpjes -------------------------------------------------------------- */

$HOST = 'https://raw.githubusercontent.com/marketing-chiro/pop-up/claude/chiro-fysio-exit-popup-eahq6b/dist';

function stel_manifest_in( $versie, $download ) {
	$GLOBALS['opslag'] = array();
	$GLOBALS['antwoord'] = array(
		'code' => 200,
		'body' => json_encode( array(
			'version'      => $versie,
			'download_url' => $download,
			'name'         => 'Chiro-Fysio exit-intent pop-up',
		) ),
	);
}

function vraag_transient() {
	$t = new stdClass();
	$t->response  = array();
	$t->no_update = array();
	return CF_Exit_Popup_Updater::aanbieden( $t );
}

$BESTAND = 'chiro-fysio-exit-popup/chiro-fysio-exit-popup.php';

/* --- 1. Nieuwere versie ---------------------------------------------------- */

echo "\n[1] Er staat een nieuwere versie klaar\n";
stel_manifest_in( '2.0.0', $HOST . '/chiro-fysio-exit-popup.zip' );
$t = vraag_transient();
check( 'update wordt aangeboden', isset( $t->response[ $BESTAND ] ) );
check( 'met het juiste versienummer',
	isset( $t->response[ $BESTAND ] ) && '2.0.0' === $t->response[ $BESTAND ]->new_version );
check( 'staat niet tegelijk bij "bij"', ! isset( $t->no_update[ $BESTAND ] ) );

/* --- 2. Zelfde versie ------------------------------------------------------ */

echo "\n[2] De site is al bij\n";
stel_manifest_in( '1.9.0', $HOST . '/chiro-fysio-exit-popup.zip' );
$t = vraag_transient();
check( 'geen update aangeboden', ! isset( $t->response[ $BESTAND ] ) );
// Zonder deze regel toont WordPress geen schakelaar voor automatische updates,
// en dan blijft het handwerk - precies wat we wilden oplossen.
check( 'wel gemeld als "bij", zodat automatisch bijwerken aan kan',
	isset( $t->no_update[ $BESTAND ] ) );

/* --- 3. Oudere versie op afstand ------------------------------------------- */

echo "\n[3] Op afstand staat een oudere versie\n";
stel_manifest_in( '1.0.0', $HOST . '/chiro-fysio-exit-popup.zip' );
$t = vraag_transient();
check( 'geen terugval naar een oudere versie', ! isset( $t->response[ $BESTAND ] ) );

/* --- 4. Het downloadadres --------------------------------------------------- */

echo "\n[4] Downloadadres wordt gecontroleerd\n";

stel_manifest_in( '2.0.0', 'https://kwaadaardig.example.com/nep.zip' );
$t = vraag_transient();
check( 'ander domein wordt geweigerd', ! isset( $t->response[ $BESTAND ] ) );

stel_manifest_in( '2.0.0', 'http://raw.githubusercontent.com/x/y/z.zip' );
$t = vraag_transient();
check( 'zonder https wordt geweigerd', ! isset( $t->response[ $BESTAND ] ) );

stel_manifest_in( '2.0.0', $HOST . '/chiro-fysio-exit-popup.zip' );
$t = vraag_transient();
check( 'eigen adres over https wordt geaccepteerd', isset( $t->response[ $BESTAND ] ) );

/* --- 5. Als er iets misgaat ------------------------------------------------- */

echo "\n[5] Storingen mogen het beheer niet ophouden\n";

$GLOBALS['opslag'] = array();
$GLOBALS['antwoord'] = array( 'code' => 404, 'body' => '' );
$t = vraag_transient();
check( 'onbereikbaar versiebestand geeft geen update', ! isset( $t->response[ $BESTAND ] ) );

$GLOBALS['opslag'] = array();
$GLOBALS['antwoord'] = array( 'code' => 200, 'body' => 'geen json' );
$t = vraag_transient();
check( 'onleesbaar antwoord geeft geen update', ! isset( $t->response[ $BESTAND ] ) );

$GLOBALS['opslag'] = array();
$GLOBALS['antwoord'] = array( 'code' => 200, 'body' => json_encode( array( 'version' => '2.0.0' ) ) );
$t = vraag_transient();
check( 'versiebestand zonder downloadadres geeft geen update', ! isset( $t->response[ $BESTAND ] ) );

$GLOBALS['opslag'] = array();
$GLOBALS['antwoord'] = new WP_Error();
$t = vraag_transient();
check( 'netwerkfout geeft geen update', ! isset( $t->response[ $BESTAND ] ) );

/* --- 6. Niet bij elk beheerbezoek opnieuw ophalen --------------------------- */

echo "\n[6] Het antwoord wordt bewaard\n";
stel_manifest_in( '2.0.0', $HOST . '/chiro-fysio-exit-popup.zip' );
vraag_transient();
$GLOBALS['antwoord'] = new WP_Error(); // volgende oproep zou falen
$t = vraag_transient();
check( 'tweede keer komt uit het geheugen, niet van het net',
	isset( $t->response[ $BESTAND ] ) );

echo "\n" . ( 0 === $fout ? "Updater in orde ({$goed} controles)." : "{$fout} controle(s) mislukt." ) . "\n";
exit( 0 === $fout ? 0 : 1 );
