<?php
/**
 * Alle instellingen van de pop-up, op één plek.
 *
 * Hiervoor stonden deze waarden in het JavaScript-bestand, en moest je dus in
 * de code duiken om een tekst of een kleur te wijzigen. Nu komen ze uit de
 * database en zijn ze allemaal vanuit het dashboard aan te passen.
 *
 * De standaardwaarden hieronder zijn de waarden waarmee de pop-up is
 * ontworpen: de teksten in de toon van de praktijk, en de tijden onderbouwd
 * met het Analytics-rapport van juli 2026.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF_Exit_Popup_Options {

	const OPTIE = 'cf_exit_popup_settings';

	/**
	 * Waar alles op staat als er nog niets is ingesteld.
	 */
	public static function defaults() {
		return array(

			/* --- Contact ------------------------------------------------- */
			'phone_display'   => '024 - 355 88 30',
			'phone_href'      => '+31243558830',
			'whatsapp'        => '31614798722',
			'whatsapp_text'   => 'Hallo, ik heb een vraag naar aanleiding van jullie website.',
			'appointment_url' => '/uw-afspraak/',
			'help_url'        => '/kosten-en-vergoedingen/',
			// Was een vraagzin toen dit nog een klein tekstlinkje was. Nu het een
			// knop is, hoort er een handeling op te staan.
			'help_label'      => 'Kosten en vergoeding bekijken',

			/* --- Teksten ------------------------------------------------- */
			'txt_question'      => 'Heeft u gevonden wat u zocht?',
			'txt_question_sub'  => 'We horen het graag - zo kunnen we de site verbeteren.',
			'txt_question_ret'  => 'Kunnen we u ergens mee helpen?',
			'txt_question_ret_sub' => 'U bent hier eerder geweest - stelt u de vraag gerust rechtstreeks.',
			'txt_yes'           => 'Ja, gelukt',
			'txt_no'            => 'Nee, nog niet',
			'txt_yes_title'     => 'Fijn om te horen!',
			'txt_yes_body'      => 'Bedankt voor uw bezoek. Tot ziens in de praktijk.',
			'txt_appointment'   => 'Direct een afspraak maken',
			'txt_no_title'      => 'Dat lossen we even op',
			// Noemde eerst alleen bellen. Dat sloot niet aan bij wat bezoekers doen.
			'txt_no_body'       => 'Plan gerust direct een afspraak, of stel uw vraag - we denken graag met u mee.',
			'txt_call'          => 'Bel',
			'txt_whatsapp'      => 'Stuur een WhatsApp',
			'txt_hours'         => 'Maandag t/m vrijdag bereikbaar tijdens openingstijden.',
			'txt_close'         => 'Sluiten',

			/* --- Gedrag -------------------------------------------------- */
			'arm_after'          => 6000,
			'exit_grace'         => 400,
			'cooldown_days'      => 7,
			'require_interaction' => 1,
			'enable_mobile'      => 1,
			'mobile_idle'        => 45000,
			'returning_from'     => 2,
			'exclude_paths'      => "contact\nafspraak\nbedankt\nvacature",
			'enabled'            => 1,

			/* --- Huisstijl ------------------------------------------------ */
			'brand_primary'      => '#1b6a63',
			'brand_primary_dark' => '#145049',
			'brand_accent'       => '#e8f3f1',
			'brand_text'         => '#17242b',
			'brand_muted'        => '#5c6f78',
			'brand_radius'       => 14,
			'brand_btn_radius'   => 10,
			'brand_font'         => '',
			'brand_logo'         => 0,
			'brand_logo_height'  => 34,
		);
	}

	/**
	 * Alle instellingen, met de standaardwaarden ingevuld waar niets staat.
	 */
	public static function all() {
		$opgeslagen = get_option( self::OPTIE );

		if ( ! is_array( $opgeslagen ) ) {
			$opgeslagen = array();
		}

		return array_merge( self::defaults(), $opgeslagen );
	}

	public static function get( $sleutel ) {
		$alles = self::all();

		return isset( $alles[ $sleutel ] ) ? $alles[ $sleutel ] : null;
	}

	/**
	 * Slaat een binnengekomen formulier op, veld voor veld geschoond.
	 *
	 * Alles wat niet in de standaardlijst staat wordt genegeerd, zodat een
	 * verdwaald veld nooit in de database belandt.
	 */
	public static function save( $ruw ) {
		$defaults = self::defaults();
		$schoon   = array();

		// Velden die HTML mogen bevatten bestaan hier niet: alle teksten komen
		// als platte tekst in de pop-up terecht.
		$tekstvelden = array(
			'phone_display', 'whatsapp_text', 'help_label',
			'txt_question', 'txt_question_sub', 'txt_question_ret', 'txt_question_ret_sub',
			'txt_yes', 'txt_no', 'txt_yes_title', 'txt_yes_body', 'txt_appointment',
			'txt_no_title', 'txt_no_body', 'txt_call', 'txt_whatsapp', 'txt_hours', 'txt_close',
		);

		foreach ( $tekstvelden as $veld ) {
			if ( isset( $ruw[ $veld ] ) ) {
				$schoon[ $veld ] = sanitize_text_field( wp_unslash( $ruw[ $veld ] ) );
			}
		}

		// Telefoonnummer voor de belknop: alleen cijfers en een plus.
		if ( isset( $ruw['phone_href'] ) ) {
			$schoon['phone_href'] = preg_replace( '/[^0-9+]/', '', wp_unslash( $ruw['phone_href'] ) );
		}

		// WhatsApp: alleen cijfers, want wa.me accepteert niets anders.
		if ( isset( $ruw['whatsapp'] ) ) {
			$schoon['whatsapp'] = preg_replace( '/[^0-9]/', '', wp_unslash( $ruw['whatsapp'] ) );
		}

		foreach ( array( 'appointment_url', 'help_url' ) as $veld ) {
			if ( isset( $ruw[ $veld ] ) ) {
				$waarde = trim( wp_unslash( $ruw[ $veld ] ) );
				// Een pad binnen de site mag, een volledig adres ook.
				$schoon[ $veld ] = ( '' === $waarde || '/' === substr( $waarde, 0, 1 ) )
					? sanitize_text_field( $waarde )
					: esc_url_raw( $waarde );
			}
		}

		// Getallen, elk met een verstandige onder- en bovengrens.
		$grenzen = array(
			'arm_after'         => array( 0, 60000 ),
			'exit_grace'        => array( 0, 3000 ),
			'cooldown_days'     => array( 0, 365 ),
			'mobile_idle'       => array( 0, 300000 ),
			'returning_from'    => array( 0, 20 ),
			'brand_radius'      => array( 0, 40 ),
			'brand_btn_radius'  => array( 0, 40 ),
			'brand_logo_height' => array( 16, 90 ),
		);

		foreach ( $grenzen as $veld => $bereik ) {
			if ( isset( $ruw[ $veld ] ) ) {
				$schoon[ $veld ] = max( $bereik[0], min( $bereik[1], absint( $ruw[ $veld ] ) ) );
			}
		}

		// Schakelaars: aanwezig betekent aan.
		foreach ( array( 'require_interaction', 'enable_mobile', 'enabled' ) as $veld ) {
			$schoon[ $veld ] = isset( $ruw[ $veld ] ) ? 1 : 0;
		}

		// Kleuren moeten een geldige hexcode zijn, anders houden we de oude.
		foreach ( array( 'brand_primary', 'brand_primary_dark', 'brand_accent', 'brand_text', 'brand_muted' ) as $veld ) {
			if ( isset( $ruw[ $veld ] ) ) {
				$kleur = sanitize_hex_color( wp_unslash( $ruw[ $veld ] ) );
				if ( $kleur ) {
					$schoon[ $veld ] = $kleur;
				}
			}
		}

		if ( isset( $ruw['brand_font'] ) ) {
			// Een letterfamilie is een lijstje namen; aanhalingstekens en komma's
			// mogen, verder niets.
			$schoon['brand_font'] = preg_replace(
				'/[^a-zA-Z0-9 ,\'"\-]/',
				'',
				wp_unslash( $ruw['brand_font'] )
			);
		}

		if ( isset( $ruw['brand_logo'] ) ) {
			$schoon['brand_logo'] = absint( $ruw['brand_logo'] );
		}

		// Uitgesloten pagina's: één term per regel.
		if ( isset( $ruw['exclude_paths'] ) ) {
			$regels = preg_split( '/[\r\n]+/', wp_unslash( $ruw['exclude_paths'] ) );
			$termen = array();
			foreach ( $regels as $regel ) {
				$term = trim( sanitize_text_field( $regel ) );
				// Schuine strepen weghalen: 'afspraak' dekt meer dan '/afspraak'.
				$term = trim( $term, '/' );
				if ( '' !== $term ) {
					$termen[] = $term;
				}
			}
			$schoon['exclude_paths'] = implode( "\n", array_unique( $termen ) );
		}

		update_option( self::OPTIE, array_merge( $defaults, self::all(), $schoon ) );

		return self::all();
	}

	public static function reset() {
		delete_option( self::OPTIE );
	}

	/**
	 * De instellingen in de vorm die het JavaScript verwacht.
	 */
	public static function for_script() {
		$o = self::all();

		$paden = array_values( array_filter( preg_split( '/[\r\n]+/', $o['exclude_paths'] ) ) );

		return array(
			'phoneDisplay'       => $o['phone_display'],
			'phoneHref'          => $o['phone_href'],
			'whatsapp'           => $o['whatsapp'],
			'whatsappText'       => $o['whatsapp_text'],
			'appointmentUrl'     => $o['appointment_url'],
			'helpUrl'            => $o['help_url'],
			'helpLabel'          => $o['help_label'],
			'armAfterMs'         => (int) $o['arm_after'],
			'exitGraceMs'        => (int) $o['exit_grace'],
			'cooldownDays'       => (int) $o['cooldown_days'],
			'requireInteraction' => (bool) $o['require_interaction'],
			'enableMobile'       => (bool) $o['enable_mobile'],
			'mobileIdleMs'       => (int) $o['mobile_idle'],
			'returningFromVisit' => (int) $o['returning_from'],
			'excludePaths'       => $paden,
			'logo'               => self::logo_url(),
			'logoHeight'         => (int) $o['brand_logo_height'],
			'text'               => array(
				'question'             => $o['txt_question'],
				'questionSub'          => $o['txt_question_sub'],
				'questionReturning'    => $o['txt_question_ret'],
				'questionSubReturning' => $o['txt_question_ret_sub'],
				'yes'                  => $o['txt_yes'],
				'no'                   => $o['txt_no'],
				'yesTitle'             => $o['txt_yes_title'],
				'yesBody'              => $o['txt_yes_body'],
				'appointmentLabel'     => $o['txt_appointment'],
				'noTitle'              => $o['txt_no_title'],
				'noBody'               => $o['txt_no_body'],
				'callLabel'            => $o['txt_call'],
				'whatsappLabel'        => $o['txt_whatsapp'],
				'hours'                => $o['txt_hours'],
				'close'                => $o['txt_close'],
			),
		);
	}

	public static function logo_url() {
		$id = (int) self::get( 'brand_logo' );

		if ( ! $id ) {
			return '';
		}

		$src = wp_get_attachment_image_url( $id, 'medium' );

		return $src ? $src : '';
	}

	/**
	 * De huisstijl als CSS-variabelen.
	 *
	 * De opmaak van de pop-up is volledig op deze variabelen gebouwd, dus één
	 * kleur wijzigen werkt meteen door in knoppen, randen en accenten.
	 */
	public static function brand_css() {
		$o = self::all();

		$regels = array(
			'--cf-primary'      => $o['brand_primary'],
			'--cf-primary-dark' => $o['brand_primary_dark'],
			'--cf-accent'       => $o['brand_accent'],
			'--cf-text'         => $o['brand_text'],
			'--cf-muted'        => $o['brand_muted'],
			'--cf-radius'       => (int) $o['brand_radius'] . 'px',
			'--cf-btn-radius'   => (int) $o['brand_btn_radius'] . 'px',
		);

		if ( '' !== trim( (string) $o['brand_font'] ) ) {
			$regels['--cf-font'] = $o['brand_font'];
		}

		$uit = '';
		foreach ( $regels as $naam => $waarde ) {
			$uit .= $naam . ':' . $waarde . ';';
		}

		return '.cf-exit{' . $uit . '}';
	}
}
