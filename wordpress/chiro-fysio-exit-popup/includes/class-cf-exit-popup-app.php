<?php
/**
 * De installeerbare app.
 *
 * Het dashboard is ook te openen buiten het WordPress-beheer, op een eigen
 * adres. Die pagina is zo opgezet dat de browser aanbiedt hem te installeren:
 * je krijgt dan een eigen icoon op het bureaublad en een eigen venster zonder
 * adresbalk. Op Windows, Mac, Android en iPhone werkt dat allemaal, zonder
 * installatiebestand en zonder app store.
 *
 * Toegang kan op twee manieren:
 *
 *   1. Ingelogd zijn in WordPress met de juiste rechten. Er is een aparte rol
 *      "Exit-pop-up kijker" voor collega's die alleen de cijfers hoeven te zien.
 *   2. Via een geheime link. Die staat standaard uit, en de sleutel is met één
 *      klik te vernieuwen zodat een gedeelde link meteen vervalt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF_Exit_Popup_App {

	/** Het pad waarop de app draait. */
	const PAD = 'exit-dashboard';

	/** Recht dat iemand nodig heeft om de cijfers te zien. */
	const CAP = 'cf_view_popup_stats';

	/** Naam van de rol voor collega's die alleen mogen kijken. */
	const ROL = 'cf_popup_viewer';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 5 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'no_canonical_redirect' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/* ======================================================================
	   Adressen
	   ====================================================================== */

	public static function add_rewrite() {
		add_rewrite_rule( '^' . self::PAD . '/?$', 'index.php?cf_dash=1', 'top' );
		add_rewrite_rule( '^' . self::PAD . '/manifest\.webmanifest$', 'index.php?cf_dash=manifest', 'top' );
		add_rewrite_rule( '^' . self::PAD . '/sw\.js$', 'index.php?cf_dash=sw', 'top' );
	}

	public static function add_query_vars( $vars ) {
		$vars[] = 'cf_dash';
		return $vars;
	}

	/**
	 * Het adres van de app. Werkt ook als mooie URL's uit staan.
	 */
	public static function url( $extra = array() ) {
		if ( get_option( 'permalink_structure' ) ) {
			$basis = home_url( '/' . self::PAD . '/' );
		} else {
			$basis = home_url( '/?cf_dash=1' );
		}

		return $extra ? add_query_arg( $extra, $basis ) : $basis;
	}

	/**
	 * Houdt de adressen van de app precies zoals ze zijn.
	 *
	 * WordPress zet standaard een schuine streep achter adressen die het voor
	 * een pagina aanziet. Bij het achtergrondscript is dat fataal: een browser
	 * weigert een script te installeren dat via een omleiding binnenkomt.
	 */
	public static function no_canonical_redirect( $redirect ) {
		return get_query_var( 'cf_dash' ) ? false : $redirect;
	}

	/* ======================================================================
	   Toegang
	   ====================================================================== */

	/**
	 * De geheime sleutel. Wordt pas aangemaakt zodra iemand hem opvraagt.
	 */
	public static function key() {
		$sleutel = get_option( 'cf_exit_popup_share_key' );

		if ( ! $sleutel ) {
			$sleutel = self::new_key();
		}

		return $sleutel;
	}

	public static function new_key() {
		$sleutel = wp_generate_password( 40, false, false );
		update_option( 'cf_exit_popup_share_key', $sleutel, false );

		return $sleutel;
	}

	public static function sharing_on() {
		return (bool) get_option( 'cf_exit_popup_share_enabled' );
	}

	/**
	 * De deelbare link, inclusief sleutel.
	 */
	public static function share_url() {
		return self::url( array( 'sleutel' => self::key() ) );
	}

	/**
	 * Mag deze bezoeker de cijfers zien?
	 *
	 * @return bool
	 */
	public static function may_view() {
		if ( is_user_logged_in() && current_user_can( self::CAP ) ) {
			return true;
		}

		if ( ! self::sharing_on() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- een sleutel in de link, geen formulier
		$aangeboden = isset( $_GET['sleutel'] ) ? sanitize_text_field( wp_unslash( $_GET['sleutel'] ) ) : '';

		if ( '' === $aangeboden ) {
			return false;
		}

		// hash_equals vergelijkt in gelijke tijd, zodat de sleutel niet teken
		// voor teken te raden is.
		return hash_equals( self::key(), $aangeboden );
	}

	/* ======================================================================
	   Rollen
	   ====================================================================== */

	/**
	 * Maakt de kijkersrol aan en geeft beheerders het recht. Draait bij
	 * activatie en na een versieverhoging.
	 */
	public static function setup_roles() {
		if ( ! get_role( self::ROL ) ) {
			add_role(
				self::ROL,
				'Exit-pop-up kijker',
				array(
					'read'      => true,
					self::CAP   => true,
				)
			);
		}

		foreach ( array( 'administrator', 'editor' ) as $rolnaam ) {
			$rol = get_role( $rolnaam );
			if ( $rol && ! $rol->has_cap( self::CAP ) ) {
				$rol->add_cap( self::CAP );
			}
		}
	}

	/* ======================================================================
	   Uitleveren
	   ====================================================================== */

	public static function maybe_serve() {
		$wat = get_query_var( 'cf_dash' );

		if ( ! $wat ) {
			return;
		}

		// De app en alles eromheen horen nooit in een zoekmachine.
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );

		switch ( $wat ) {
			case 'manifest':
				self::serve_manifest();
				exit;

			case 'sw':
				self::serve_service_worker();
				exit;

			default:
				self::serve_app();
				exit;
		}
	}

	/**
	 * Het bestand dat de browser vertelt hoe de app heet en hoe hij eruitziet.
	 */
	private static function serve_manifest() {
		$naam = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$url  = plugin_dir_url( CF_EXIT_POPUP_FILE ) . 'assets/';

		// De sleutel gaat mee in het startadres, anders opent de geïnstalleerde
		// app op een pagina waar hij niet meer binnenkomt.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sleutel = isset( $_GET['sleutel'] ) ? sanitize_text_field( wp_unslash( $_GET['sleutel'] ) ) : '';
		$start   = $sleutel ? self::url( array( 'sleutel' => $sleutel ) ) : self::url();

		$manifest = array(
			'name'             => 'Exit-pop-up - ' . $naam,
			'short_name'       => 'Exit-pop-up',
			'description'      => 'Wie stond er op het punt te vertrekken, en hadden we die kunnen helpen?',
			'start_url'        => $start,
			'scope'            => self::url(),
			'display'          => 'standalone',
			'orientation'      => 'any',
			'background_color' => '#f4f5f3',
			'theme_color'      => '#0a9184',
			'lang'             => 'nl',
			'dir'              => 'ltr',
			'icons'            => array(
				array(
					'src'   => $url . 'icon-192.png',
					'sizes' => '192x192',
					'type'  => 'image/png',
				),
				array(
					'src'   => $url . 'icon-512.png',
					'sizes' => '512x512',
					'type'  => 'image/png',
				),
				array(
					'src'     => $url . 'icon-maskable.png',
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'maskable',
				),
			),
		);

		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		echo wp_json_encode( $manifest );
	}

	/**
	 * Het achtergrondscript dat de app laat werken zonder verbinding.
	 *
	 * De opzet is bewust simpel: de pagina zelf komt zo veel mogelijk van het
	 * net, maar wordt ook bewaard. Lukt ophalen niet, dan tonen we de laatst
	 * bewaarde versie, zodat je onderweg nog steeds je cijfers ziet.
	 */
	private static function serve_service_worker() {
		$versie = CF_EXIT_POPUP_VERSION;
		$assets = plugin_dir_url( CF_EXIT_POPUP_FILE ) . 'assets/';

		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		?>
/* Achtergrondscript van de Exit-pop-up app. */
var CACHE = 'cf-exit-dashboard-<?php echo esc_js( $versie ); ?>';
var VAST = [
	'<?php echo esc_js( $assets ); ?>dashboard.css',
	'<?php echo esc_js( $assets ); ?>dashboard.js',
	'<?php echo esc_js( $assets ); ?>app.js'
];

self.addEventListener('install', function (e) {
	e.waitUntil(caches.open(CACHE).then(function (c) {
		return c.addAll(VAST);
	}).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
	// Oude versies opruimen, anders blijft er van alles staan.
	e.waitUntil(caches.keys().then(function (namen) {
		return Promise.all(namen.map(function (n) {
			return n === CACHE ? null : caches.delete(n);
		}));
	}).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
	if (e.request.method !== 'GET') return;

	e.respondWith(
		fetch(e.request).then(function (antwoord) {
			// Gelukt: bewaren voor als er straks geen verbinding is.
			if (antwoord && antwoord.status === 200 && antwoord.type === 'basic') {
				var kopie = antwoord.clone();
				caches.open(CACHE).then(function (c) { c.put(e.request, kopie); });
			}
			return antwoord;
		}).catch(function () {
			return caches.match(e.request).then(function (bewaard) {
				return bewaard || caches.match(e.request, { ignoreSearch: true });
			});
		})
	);
});
		<?php
	}

	/**
	 * De app-pagina zelf.
	 */
	private static function serve_app() {
		if ( ! self::may_view() ) {
			self::serve_denied();
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sleutel = isset( $_GET['sleutel'] ) ? sanitize_text_field( wp_unslash( $_GET['sleutel'] ) ) : '';
		$dagen   = self::days_from_request();

		$totals = CF_Exit_Popup_Storage::totals( $dagen );
		$recent = CF_Exit_Popup_Storage::recent( $dagen );
		$health = CF_Exit_Popup_Health::report();
		$assets = plugin_dir_url( CF_EXIT_POPUP_FILE ) . 'assets/';

		$perioden = array(
			7   => '7 dagen',
			30  => '30 dagen',
			90  => '90 dagen',
			365 => '12 maanden',
		);

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow, noarchive">
	<meta name="theme-color" content="#0a9184">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-title" content="Exit-pop-up">
	<title>Exit-pop-up - <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
	<link rel="manifest" href="<?php echo esc_url( self::url() . 'manifest.webmanifest' . ( $sleutel ? '?sleutel=' . rawurlencode( $sleutel ) : '' ) ); ?>">
	<link rel="apple-touch-icon" href="<?php echo esc_url( $assets . 'icon-192.png' ); ?>">
	<link rel="icon" href="<?php echo esc_url( $assets . 'icon-192.png' ); ?>" sizes="192x192">
	<link rel="stylesheet" href="<?php echo esc_url( $assets . 'dashboard.css?ver=' . CF_EXIT_POPUP_VERSION ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( $assets . 'app.css?ver=' . CF_EXIT_POPUP_VERSION ); ?>">
</head>
<body class="cf-app">

	<header class="cf-app__bar">
		<span class="cf-app__title">Exit-pop-up</span>
		<span class="cf-app__site"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>

		<span class="cf-app__state" id="cf-app-state" hidden></span>

		<button type="button" class="cf-app__install" id="cf-app-install" hidden>
			Installeren
		</button>
		<button type="button" class="cf-app__refresh" id="cf-app-refresh" title="Cijfers verversen" aria-label="Cijfers verversen">
			<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true">
				<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
					d="M20 11a8 8 0 1 0-.6 4M20 5v6h-6"/>
			</svg>
		</button>
	</header>

	<main class="cf-dash cf-app__main">

		<div class="cf-dash__head">
			<div>
				<h1 class="cf-dash__title">Wat deden bezoekers die weg wilden?</h1>
				<p class="cf-dash__sub">Wie stond er op het punt te vertrekken, en hadden we die kunnen helpen?</p>
			</div>
			<div class="cf-dash__periods">
				<?php foreach ( $perioden as $waarde => $label ) : ?>
					<a
						class="cf-dash__period<?php echo ( $waarde === $dagen ) ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( self::url( array_filter( array( 'periode' => $waarde, 'sleutel' => $sleutel ) ) ) ); ?>"
						<?php echo ( $waarde === $dagen ) ? 'aria-current="page"' : ''; ?>
					><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>

		<?php CF_Exit_Popup_View::health_card( $health ); ?>

		<?php if ( 0 === (int) $totals['shown'] ) : ?>
			<?php CF_Exit_Popup_View::empty_state(); ?>
		<?php else : ?>
			<?php
			CF_Exit_Popup_View::tiles( $totals );
			CF_Exit_Popup_View::charts( $totals );
			CF_Exit_Popup_View::recent_table( $recent );
			?>
		<?php endif; ?>

		<?php CF_Exit_Popup_View::privacy_note(); ?>
	</main>

	<script>
		window.CF_DASH = <?php echo wp_json_encode( self::collect( $dagen ) ); ?>;
		window.CF_APP = <?php echo wp_json_encode(
			array(
				'stats'   => rest_url( 'cf-exit-popup/v1/stats' ),
				// WordPress accepteert een ingelogde gebruiker bij de REST-koppeling
				// alleen met deze extra sleutel erbij. Zonder deze regel kreeg de
				// app een 401, ook als je gewoon ingelogd was.
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'sleutel' => $sleutel,
				'dagen'   => $dagen,
				'sw'      => self::url() . 'sw.js' ,
				'scope'   => wp_parse_url( self::url(), PHP_URL_PATH ),
			)
		); ?>;
	</script>
	<script src="<?php echo esc_url( $assets . 'dashboard.js?ver=' . CF_EXIT_POPUP_VERSION ); ?>"></script>
	<script src="<?php echo esc_url( $assets . 'app.js?ver=' . CF_EXIT_POPUP_VERSION ); ?>"></script>
</body>
</html>
		<?php
	}

	/**
	 * Wat een bezoeker te zien krijgt die er niet bij mag.
	 *
	 * Bewust karig: geen hint of de sleutel bijna goed was, en geen verschil
	 * tussen "delen staat uit" en "verkeerde sleutel".
	 */
	private static function serve_denied() {
		status_header( 403 );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Geen toegang</title>
	<style>
		body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
			font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
			background:#f4f5f3; color:#14201f; padding:24px; }
		div { max-width:44ch; text-align:center; }
		h1 { font-size:1.3rem; margin:0 0 8px; }
		p { color:#55635f; line-height:1.6; margin:0 0 18px; }
		a { color:#0a7d72; }
	</style>
</head>
<body>
	<div>
		<h1>Geen toegang</h1>
		<p>Deze pagina is alleen te openen met een geldige link of met een account dat de cijfers mag inzien.</p>
		<p><a href="<?php echo esc_url( wp_login_url( self::url() ) ); ?>">Inloggen</a></p>
	</div>
</body>
</html>
		<?php
	}

	private static function days_from_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$dagen = isset( $_GET['periode'] ) ? absint( $_GET['periode'] ) : 30;

		return in_array( $dagen, array( 7, 30, 90, 365 ), true ) ? $dagen : 30;
	}

	/**
	 * Alles wat de grafieken nodig hebben.
	 */
	public static function collect( $dagen ) {
		return array(
			'days'     => $dagen,
			'totals'   => CF_Exit_Popup_Storage::totals( $dagen ),
			'perDay'   => CF_Exit_Popup_Storage::per_day( min( $dagen, 90 ) ),
			'topPages' => CF_Exit_Popup_Storage::top_pages( $dagen ),
			'devices'  => CF_Exit_Popup_Storage::breakdown( $dagen, 'device' ),
			'triggers' => CF_Exit_Popup_Storage::breakdown( $dagen, 'trigger_type' ),
			'visitors' => CF_Exit_Popup_Storage::breakdown( $dagen, 'visitor' ),
			'perHour'  => CF_Exit_Popup_Storage::per_hour( $dagen ),
		);
	}

	/* ======================================================================
	   Cijfers ophalen vanuit de app
	   ====================================================================== */

	public static function register_routes() {
		register_rest_route(
			'cf-exit-popup/v1',
			'/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_stats' ),
				'permission_callback' => array( __CLASS__, 'may_view' ),
				'args'                => array(
					'periode' => array(
						'default'           => 30,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public static function rest_stats( WP_REST_Request $request ) {
		$dagen = (int) $request->get_param( 'periode' );
		$dagen = in_array( $dagen, array( 7, 30, 90, 365 ), true ) ? $dagen : 30;

		$antwoord = self::collect( $dagen );
		$antwoord['health']    = CF_Exit_Popup_Health::report();
		$antwoord['opgehaald'] = current_time( 'mysql' );

		$response = new WP_REST_Response( $antwoord, 200 );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
