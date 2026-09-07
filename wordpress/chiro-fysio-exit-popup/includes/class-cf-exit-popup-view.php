<?php
/**
 * De opmaak van het dashboard, op één plek.
 *
 * Deze zelfde markup wordt op twee plekken gebruikt: op de pagina in het
 * WordPress-beheer en in de installeerbare app. Door dat te delen kan er geen
 * verschil ontstaan tussen wat je in het beheer ziet en wat er in de app staat.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF_Exit_Popup_View {

	/**
	 * De statuskaart bovenaan.
	 */
	public static function health_card( $health ) {
		$labels = array(
			'goed'     => array( 'Alles draait', 'goed' ),
			'wachten'  => array( 'Klaar, wacht op de eerste bezoeker', 'wachten' ),
			'let op'   => array( 'Let op', 'letop' ),
			'probleem' => array( 'Er is iets mis', 'probleem' ),
		);
		list( $text, $class ) = $labels[ $health['status'] ];

		$regels = array(
			array( 'Tabel met metingen', $health['tabel_bestaat'], number_format_i18n( $health['regels'] ) . ' regels' ),
			array( 'Bestanden van de pop-up', $health['bestanden'], $health['bestanden'] ? 'aanwezig' : 'ontbreken' ),
			array( 'Meetpunt bereikbaar', $health['meetpunt']['ok'], 'antwoord ' . (int) $health['meetpunt']['code'] ),
			array( 'Metingen afgelopen 24 uur', true, number_format_i18n( $health['events_24u'] ) ),
			array( 'Metingen afgelopen 7 dagen', true, number_format_i18n( $health['events_7d'] ) ),
			array( 'Dagelijkse controle ingepland', (bool) $health['cron_controle'], $health['cron_controle'] ? wp_date( 'j M H:i', $health['cron_controle'] ) : 'niet ingepland' ),
			array( 'Wekelijkse mail ingepland', (bool) $health['cron_weekmail'], $health['cron_weekmail'] ? wp_date( 'j M H:i', $health['cron_weekmail'] ) : 'niet ingepland' ),
			array( 'Opruimen oude metingen', (bool) $health['cron_opruimen'], $health['cron_opruimen'] ? wp_date( 'j M H:i', $health['cron_opruimen'] ) : 'niet ingepland' ),
		);
		?>
		<details class="cf-health cf-health--<?php echo esc_attr( $class ); ?>">
			<summary class="cf-health__summary">
				<span class="cf-health__lamp" aria-hidden="true"></span>
				<span class="cf-health__label"><?php echo esc_html( $text ); ?></span>
				<span class="cf-health__meta">
					<?php
					if ( $health['laatste_event'] ) {
						echo esc_html(
							'laatste meting ' . human_time_diff( strtotime( $health['laatste_event'] ), current_time( 'timestamp' ) ) . ' geleden'
						);
					} else {
						echo 'nog geen metingen';
					}
					?>
				</span>
				<span class="cf-health__toggle">details</span>
			</summary>

			<div class="cf-health__body">
				<ul class="cf-health__list">
					<?php foreach ( $regels as $r ) : ?>
						<li class="cf-health__item">
							<span class="cf-health__dot cf-health__dot--<?php echo $r[1] ? 'ok' : 'nok'; ?>" aria-hidden="true"></span>
							<span class="cf-health__key"><?php echo esc_html( $r[0] ); ?></span>
							<span class="cf-health__val"><?php echo esc_html( $r[2] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>

				<?php if ( $health['db_fout'] ) : ?>
					<p class="cf-health__error">
						<strong>Databasefout op <?php echo esc_html( $health['db_fout']['when'] ); ?>:</strong>
						<?php echo esc_html( $health['db_fout']['message'] ); ?>
					</p>
				<?php endif; ?>

				<p class="cf-health__hint">
					Meldingen gaan naar <?php echo esc_html( get_option( 'admin_email' ) ); ?>.
					Valt de stroom metingen stil terwijl er wel bezoek is, dan krijg je vanzelf bericht -
					hooguit eens per week, zolang het probleem blijft. Elke maandagochtend komt de
					weeksamenvatting binnen. Blijft het stil terwijl de site het doet, dan is het meestal
					een cache- of optimalisatieplugin; die legen lost het vaak op.
				</p>
			</div>
		</details>
		<?php
	}

	/**
	 * De rij met kerncijfers.
	 */
	public static function tiles( $totals ) {
		// Percentage van de mensen die daadwerkelijk antwoord gaven. Dat is een
		// eerlijker noemer dan alle vertoningen: wie niets invult, weten we niet.
		$answered      = (int) $totals['found'] + (int) $totals['not_found'];
		$pct_not_found = $answered > 0 ? round( $totals['not_found'] / $answered * 100 ) : 0;
		$pct_rescued   = $totals['not_found'] > 0 ? round( $totals['rescued'] / $totals['not_found'] * 100 ) : 0;
		$pct_repeat    = $totals['shown'] > 0 ? round( $totals['repeat_shown'] / $totals['shown'] * 100 ) : 0;
		?>
		<div class="cf-dash__tiles">
			<div class="cf-tile">
				<span class="cf-tile__label">Pop-up getoond</span>
				<span class="cf-tile__value"><?php echo esc_html( number_format_i18n( $totals['shown'] ) ); ?></span>
				<span class="cf-tile__note">bezoekers die weg wilden gaan</span>
			</div>

			<div class="cf-tile">
				<span class="cf-tile__label">Vond niet wat die zocht</span>
				<span class="cf-tile__value"><?php echo esc_html( number_format_i18n( $totals['not_found'] ) ); ?></span>
				<span class="cf-tile__note"><?php echo esc_html( $pct_not_found ); ?>% van wie antwoord gaf</span>
			</div>

			<div class="cf-tile cf-tile--good">
				<span class="cf-tile__label">
					<span class="cf-dot cf-dot--good" aria-hidden="true"></span>
					Nam alsnog contact op
				</span>
				<span class="cf-tile__value"><?php echo esc_html( number_format_i18n( $totals['rescued'] ) ); ?></span>
				<span class="cf-tile__note">
					<?php echo esc_html( $pct_rescued ); ?>% van wie het niet vond -
					<?php echo esc_html( number_format_i18n( $totals['calls'] ) ); ?>x gebeld<?php
					if ( $totals['whatsapps'] > 0 ) {
						echo ', ' . esc_html( number_format_i18n( $totals['whatsapps'] ) ) . 'x WhatsApp';
					}
					?>
				</span>
			</div>

			<div class="cf-tile cf-tile--bad">
				<span class="cf-tile__label">
					<span class="cf-dot cf-dot--bad" aria-hidden="true"></span>
					Toch weg, zonder contact
				</span>
				<span class="cf-tile__value"><?php echo esc_html( number_format_i18n( $totals['lost'] ) ); ?></span>
				<span class="cf-tile__note">hier lag werk dat we misliepen</span>
			</div>

			<div class="cf-tile">
				<span class="cf-tile__label">Was er al eerder</span>
				<span class="cf-tile__value"><?php echo esc_html( number_format_i18n( $totals['repeat_shown'] ) ); ?></span>
				<span class="cf-tile__note">
					<?php echo esc_html( $pct_repeat ); ?>% van de vertoningen<?php
					if ( $totals['repeat_shown'] > 0 ) {
						echo ' - ' . esc_html( number_format_i18n( $totals['repeat_not_found'] ) ) . 'x zonder resultaat';
					}
					?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Openstaande terugbelverzoeken, bovenaan het dashboard.
	 *
	 * Bewust boven de cijfers: dit is het enige onderdeel waar iemand op wacht.
	 * Een grafiek kan tot morgen wachten, een patiënt die zijn nummer achterliet
	 * niet.
	 */
	public static function callbacks( $verzoeken ) {
		if ( empty( $verzoeken ) ) {
			return;
		}

		$onderwerpen = array(
			'kosten'   => 'kosten of vergoeding',
			'klacht'   => 'een klacht of behandeling',
			'afspraak' => 'een afspraak maken',
			'anders'   => 'iets anders',
		);
		?>
		<section class="cf-terugbel">
			<h2 class="cf-terugbel__kop">
				<?php
				printf(
					'%d %s',
					count( $verzoeken ),
					count( $verzoeken ) === 1 ? 'iemand wacht op een telefoontje' : 'mensen wachten op een telefoontje'
				);
				?>
			</h2>

			<ul class="cf-terugbel__lijst">
				<?php foreach ( $verzoeken as $v ) : ?>
					<li class="cf-terugbel__rij">
						<div class="cf-terugbel__wie">
							<strong><?php echo esc_html( $v['name'] ); ?></strong>
							<a class="cf-terugbel__nr" href="tel:<?php echo esc_attr( $v['phone'] ); ?>">
								<?php echo esc_html( $v['phone'] ); ?>
							</a>
						</div>
						<div class="cf-terugbel__meta">
							<?php
							$wanneer = human_time_diff( strtotime( $v['created_at'] ), (int) current_time( 'timestamp' ) );
							echo esc_html( $wanneer . ' geleden' );

							if ( isset( $onderwerpen[ $v['reason'] ] ) ) {
								echo ' &middot; vraag over ' . esc_html( $onderwerpen[ $v['reason'] ] );
							}

							// Ging de melding niet de deur uit, dan staat dit verzoek
							// alleen hier. Dat hoor je te zien op het moment dat je
							// ernaar kijkt, niet achteraf.
							if ( isset( $v['mailed'] ) && ! $v['mailed'] ) {
								echo ' &middot; <span class="cf-terugbel__waarschuwing">niet gemaild</span>';
							}
							?>
						</div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="cf_exit_popup_callback_status">
							<input type="hidden" name="verzoek" value="<?php echo esc_attr( $v['id'] ); ?>">
							<?php wp_nonce_field( 'cf_exit_popup_callback_status' ); ?>
							<button type="submit" class="button">Afgehandeld</button>
						</form>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	/**
	 * De grafieken. De vakken worden hier alleen neergezet; dashboard.js vult ze.
	 */
	public static function charts( $totals ) {
		?>
		<div class="cf-dash__row cf-dash__row--split">
			<section class="cf-card">
				<h2 class="cf-card__title">Hoe liep het af?</h2>
				<p class="cf-card__sub">Alle <?php echo esc_html( number_format_i18n( $totals['shown'] ) ); ?> vertoningen in deze periode.</p>
				<div id="cf-chart-outcome" class="cf-chart"></div>
				<?php
				/*
				 * De splitsing van "geen antwoord" staat hier als regel en niet als
				 * vijfde balkje. Twee grijstinten die dicht genoeg bij elkaar liggen
				 * om allebei "neutraal" te lezen, zijn met een kleurverschil van 12,9
				 * niet uit elkaar te houden - ook niet met normaal zicht. En er een
				 * echte kleur van maken zou het verschil groter voorstellen dan het
				 * is: beide groepen gaven immers geen antwoord.
				 */
				?>
				<p id="cf-outcome-note" class="cf-chart__note" hidden></p>
			</section>

			<section class="cf-card">
				<h2 class="cf-card__title">Verloop per dag</h2>
				<p class="cf-card__sub">Getoond tegenover "niet gevonden".</p>
				<div id="cf-chart-trend" class="cf-chart"></div>
			</section>
		</div>

		<section class="cf-card">
			<h2 class="cf-card__title">Waar ging hun vraag over?</h2>
			<p class="cf-card__sub">
				Aangetikt door de bezoeker zelf, direct nadat die aangaf iets niet gevonden te
				hebben. Een onderwerp dat vaak voorkomt en zelden tot contact leidt, wijst op een
				pagina die zijn werk niet doet.
			</p>
			<div id="cf-chart-reasons" class="cf-chart"></div>
		</section>

		<section class="cf-card">
			<h2 class="cf-card__title">Op welke pagina's liepen bezoekers vast?</h2>
			<p class="cf-card__sub">
				Dit is het lijstje om mee aan de slag te gaan: hier stelden bezoekers een vraag
				die de pagina niet beantwoordde.
			</p>
			<div id="cf-chart-pages" class="cf-chart"></div>
		</section>

		<div class="cf-dash__row cf-dash__row--split">
			<section class="cf-card">
				<h2 class="cf-card__title">Wanneer op de dag?</h2>
				<p class="cf-card__sub">Vertoningen per uur. Buiten openingstijden is bellen geen optie.</p>
				<div id="cf-chart-hours" class="cf-chart"></div>
			</section>

			<section class="cf-card">
				<h2 class="cf-card__title">Waar kwamen ze vandaan?</h2>
				<p class="cf-card__sub">Apparaat, of ze er al eerder waren, en het signaal waarop de pop-up verscheen.</p>
				<div id="cf-chart-devices" class="cf-chart"></div>
			</section>
		</div>
		<?php
	}

	/**
	 * De tabel met de laatste vertoningen.
	 *
	 * @param array       $recent     Regels uit de database.
	 * @param string|null
	 * @param string      $export_url Adres van de CSV-download, leeg om te verbergen.
	 */
	public static function recent_table( $recent, $export_url = '' ) {
		?>
		<section class="cf-card">
			<div class="cf-card__head">
				<div>
					<h2 class="cf-card__title">Laatste vertoningen</h2>
					<p class="cf-card__sub">De 50 meest recente, om een gevoel te krijgen bij de cijfers.</p>
				</div>
				<?php if ( $export_url ) : ?>
					<a class="button cf-button" href="<?php echo esc_url( $export_url ); ?>">Alles downloaden (CSV)</a>
				<?php endif; ?>
			</div>

			<div class="cf-tablewrap">
				<table class="cf-table">
					<thead>
						<tr>
							<th scope="col">Wanneer</th>
							<th scope="col">Pagina</th>
							<th scope="col">Apparaat</th>
							<th scope="col">Bezoeker</th>
							<th scope="col">Antwoord</th>
							<th scope="col">Actie</th>
						</tr>
					</thead>
					<tbody>
					<?php
					$acties = array(
						'call'        => 'gebeld',
						'whatsapp'    => 'WhatsApp',
						'appointment' => 'afspraak',
						'help'        => 'kosten bekeken',
					);
					foreach ( $recent as $row ) :
						?>
						<tr>
							<td class="cf-table__when"><?php echo esc_html( mysql2date( 'j M H:i', $row['created_at'] ) ); ?></td>
							<td class="cf-table__page"><?php echo esc_html( $row['page'] ); ?></td>
							<td><?php echo esc_html( $row['device'] ); ?></td>
							<td><?php echo '' === $row['visitor'] ? '<span class="cf-muted">-</span>' : esc_html( $row['visitor'] ); ?></td>
							<td>
								<?php
								if ( 'nee' === $row['answer'] ) {
									echo '<span class="cf-pill cf-pill--bad">niet gevonden</span>';
								} elseif ( 'ja' === $row['answer'] ) {
									echo '<span class="cf-pill cf-pill--good">gevonden</span>';
								} else {
									echo '<span class="cf-pill">geen antwoord</span>';
								}
								?>
							</td>
							<td>
								<?php
								echo isset( $acties[ $row['action_taken'] ] )
									? '<span class="cf-pill cf-pill--good">' . esc_html( $acties[ $row['action_taken'] ] ) . '</span>'
									: '<span class="cf-muted">-</span>';
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}

	/**
	 * Het bericht als er nog niets gemeten is.
	 */
	public static function empty_state() {
		?>
		<div class="cf-dash__empty">
			<h2>Nog geen metingen in deze periode</h2>
			<p>
				Zodra de eerste bezoeker de pop-up te zien krijgt, verschijnen de cijfers hier.
				Wil je zelf controleren of het werkt? Open de site met
				<code><?php echo esc_html( home_url( '/?cf-popup=test' ) ); ?></code>
				en klik de pop-up door.
			</p>
		</div>
		<?php
	}

	/**
	 * De voettekst over wat er wel en niet wordt vastgelegd.
	 */
	public static function privacy_note() {
		?>
		<p class="cf-dash__privacy">
			Er worden geen persoonsgegevens vastgelegd: geen IP-adres, geen naam en geen
			e-mailadres. Voor "nieuw" en "terugkerend" houdt de browser van de bezoeker zelf
			een bezoekteller bij; wij ontvangen daarvan alleen het label, nooit het aantal.
			Er staat dus niets in de tabel waarmee twee regels aan dezelfde persoon te knopen
			zijn. Metingen ouder dan een jaar worden automatisch verwijderd.
		</p>
		<?php
	}
}
