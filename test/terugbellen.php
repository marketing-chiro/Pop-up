<?php
/**
 * Test het opschonen van telefoonnummers, zonder WordPress.
 *
 * Dit is het stukje waar een fout je precies het gesprek kost waar het om
 * begonnen was: een formulier dat een geldig nummer weigert. Mensen schrijven
 * een nummer op tien manieren op, en die moeten er allemaal doorheen.
 *
 * Draaien:  php test/terugbellen.php
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

// Alleen de functie die we willen testen; de rest van de klasse heeft
// WordPress nodig en doet hier niet mee.
function opschonen( $ruw ) {
	$nummer = preg_replace( '/[^0-9+]/', '', (string) $ruw );
	$nummer = preg_replace( '/(?<!^)\+/', '', $nummer );

	$cijfers = strlen( preg_replace( '/[^0-9]/', '', $nummer ) );

	if ( $cijfers < 9 || $cijfers > 15 ) {
		return '';
	}

	return $nummer;
}

// Controleer dat bovenstaande kopie gelijk is aan wat de plugin doet, anders
// test je iets anders dan er draait.
$bron = file_get_contents( __DIR__ . '/../wordpress/chiro-fysio-exit-popup/includes/class-cf-exit-popup-callbacks.php' );
foreach ( array(
	"preg_replace( '/[^0-9+]/', '', (string) \$ruw )",
	"preg_replace( '/(?<!^)\\+/', '', \$nummer )",
	'$cijfers < 9 || $cijfers > 15',
) as $stuk ) {
	if ( false === strpos( $bron, $stuk ) ) {
		echo "  FAIL  de test loopt uit de pas met de plugin: {$stuk}\n";
		exit( 1 );
	}
}

echo "\n[1] Zoals mensen het echt opschrijven\n";

$moet_werken = array(
	'0614798722'        => '0614798722',
	'06 14 79 87 22'    => '0614798722',
	'06-14798722'       => '0614798722',
	'024-3558830'       => '0243558830',
	'024 355 88 30'     => '0243558830',
	'+31614798722'      => '+31614798722',
	'+31 6 14 79 87 22' => '+31614798722',
	'0031614798722'     => '0031614798722',
	'(024) 3558830'     => '0243558830',
);

foreach ( $moet_werken as $ingevoerd => $verwacht ) {
	$uit = opschonen( $ingevoerd );
	check( "\"{$ingevoerd}\" wordt {$verwacht}", $uit === $verwacht, "kreeg \"{$uit}\"" );
}

echo "\n[2] Wat geen nummer is, komt er niet door\n";

foreach ( array(
	''                      => 'leeg',
	'0612'                  => 'te kort',
	'geen idee'             => 'tekst',
	'12345678'              => 'acht cijfers',
	'1234567890123456789'   => 'veel te lang',
) as $ingevoerd => $waarom ) {
	check( "{$waarom} wordt geweigerd", '' === opschonen( $ingevoerd ), "kreeg \"" . opschonen( $ingevoerd ) . "\"" );
}

echo "\n[3] Rommel eromheen wordt weggehaald\n";

check( 'een plus midden in het nummer verdwijnt', '0614798722' === opschonen( '06147+98722' ),
	opschonen( '06147+98722' ) );
check( 'letters tussen de cijfers verdwijnen', '0614798722' === opschonen( '06a14b79c87d22' ),
	opschonen( '06a14b79c87d22' ) );
check( 'spaties voor en na verdwijnen', '0614798722' === opschonen( '  0614798722  ' ) );

echo "\n" . ( 0 === $fout ? "Nummers in orde ({$goed} controles).\n" : "{$fout} controle(s) mislukt.\n" );
exit( 0 === $fout ? 0 : 1 );
