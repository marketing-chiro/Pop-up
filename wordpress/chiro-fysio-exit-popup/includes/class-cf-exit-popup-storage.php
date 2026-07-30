<?php
/**
 * Opslag van de pop-up metingen.
 *
 * Er wordt één regel per vertoning bijgehouden, die daarna wordt bijgewerkt
 * zodra de bezoeker antwoordt of op een knop klikt. Zo is elke regel meteen
 * een compleet verhaal: waar was iemand, wat antwoordde die, en heeft die
 * daarna contact opgenomen?
 *
 * Wat er NIET in staat: geen IP-adres, geen naam, geen e-mail, geen cookie
 * waarmee iemand over meerdere bezoeken te volgen is.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF_Exit_Popup_Storage {

	/**
	 * Naam van de tabel, inclusief het voorvoegsel van deze WordPress-installatie.
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'cf_exit_popup_events';
	}

	/**
	 * Maakt of werkt de tabel bij. Draait bij activatie en na een versieverhoging.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sid char(16) NOT NULL,
			created_at datetime NOT NULL,
			page varchar(190) NOT NULL DEFAULT '',
			device varchar(20) NOT NULL DEFAULT '',
			trigger_type varchar(20) NOT NULL DEFAULT '',
			answer varchar(10) NOT NULL DEFAULT '',
			action_taken varchar(20) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY sid (sid),
			KEY created_at (created_at),
			KEY answer (answer)
		) {$collate};";

		dbDelta( $sql );

		update_option( 'cf_exit_popup_db_version', CF_EXIT_POPUP_DB_VERSION );
	}

	/**
	 * Verwerkt een binnengekomen gebeurtenis.
	 *
	 * @param array $data Al geschoonde velden uit de REST-aanvraag.
	 * @return bool
	 */
	public static function record( $data ) {
		global $wpdb;

		$table = self::table();
		$sid   = $data['sid'];
		$type  = $data['type'];

		if ( 'open' === $type ) {
			// Eerste gebeurtenis van een vertoning: nieuwe regel.
			// INSERT IGNORE want een dubbele beacon mag geen dubbele regel geven.
			$sql = $wpdb->prepare(
				"INSERT IGNORE INTO {$table} (sid, created_at, page, device, trigger_type, answer, action_taken)
				 VALUES (%s, %s, %s, %s, %s, '', '')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$sid,
				current_time( 'mysql' ),
				$data['page'],
				$data['device'],
				$data['trigger']
			);

			return (bool) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		if ( 'answer' === $type && in_array( $data['answer'], array( 'ja', 'nee' ), true ) ) {
			return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array( 'answer' => $data['answer'] ),
				array( 'sid' => $sid ),
				array( '%s' ),
				array( '%s' )
			);
		}

		if ( in_array( $type, array( 'call', 'whatsapp', 'appointment', 'help' ), true ) ) {
			return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array( 'action_taken' => $type ),
				array( 'sid' => $sid ),
				array( '%s' ),
				array( '%s' )
			);
		}

		// 'close' slaan we bewust niet op: het voegt niets toe aan het beeld dat
		// de andere gebeurtenissen al geven.
		return false;
	}

	/**
	 * De vier uitkomsten waar het dashboard op draait, in één query.
	 *
	 * @param int $days Aantal dagen terugkijken.
	 * @return array
	 */
	public static function totals( $days ) {
		global $wpdb;

		$table = self::table();
		$since = self::since( $days );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS shown,
					SUM(CASE WHEN answer = 'ja' THEN 1 ELSE 0 END) AS found,
					SUM(CASE WHEN answer = 'nee' THEN 1 ELSE 0 END) AS not_found,
					SUM(CASE WHEN answer = 'nee' AND action_taken <> '' THEN 1 ELSE 0 END) AS rescued,
					SUM(CASE WHEN answer = 'nee' AND action_taken = '' THEN 1 ELSE 0 END) AS lost,
					SUM(CASE WHEN answer = '' THEN 1 ELSE 0 END) AS no_answer,
					SUM(CASE WHEN action_taken = 'call' THEN 1 ELSE 0 END) AS calls,
					SUM(CASE WHEN action_taken = 'whatsapp' THEN 1 ELSE 0 END) AS whatsapps,
					SUM(CASE WHEN action_taken = 'appointment' THEN 1 ELSE 0 END) AS appointments
				 FROM {$table} WHERE created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);

		if ( ! $row ) {
			$row = array();
		}

		// SUM() geeft NULL terug op een lege tabel; nul leest prettiger.
		foreach ( array( 'shown', 'found', 'not_found', 'rescued', 'lost', 'no_answer', 'calls', 'whatsapps', 'appointments' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : 0;
		}

		return $row;
	}

	/**
	 * Verloop per dag, met lege dagen ingevuld op nul zodat de grafiek niet
	 * stiekem gaten overslaat.
	 */
	public static function per_day( $days ) {
		global $wpdb;

		$table = self::table();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day,
					COUNT(*) AS shown,
					SUM(CASE WHEN answer = 'nee' THEN 1 ELSE 0 END) AS not_found,
					SUM(CASE WHEN answer = 'nee' AND action_taken <> '' THEN 1 ELSE 0 END) AS rescued
				 FROM {$table}
				 WHERE created_at >= %s
				 GROUP BY DATE(created_at)
				 ORDER BY day ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::since( $days )
			),
			ARRAY_A
		);

		$byday = array();
		foreach ( $rows as $row ) {
			$byday[ $row['day'] ] = $row;
		}

		$out = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day = gmdate( 'Y-m-d', strtotime( current_time( 'mysql' ) ) - ( $i * DAY_IN_SECONDS ) );
			$out[] = array(
				'day'       => $day,
				'shown'     => isset( $byday[ $day ] ) ? (int) $byday[ $day ]['shown'] : 0,
				'not_found' => isset( $byday[ $day ] ) ? (int) $byday[ $day ]['not_found'] : 0,
				'rescued'   => isset( $byday[ $day ] ) ? (int) $byday[ $day ]['rescued'] : 0,
			);
		}

		return $out;
	}

	/**
	 * De pagina's waar bezoekers het vaakst "nee" antwoorden.
	 *
	 * Dit is het meest bruikbare lijstje van het hele dashboard: het wijst
	 * precies aan welke pagina's een vraag onbeantwoord laten.
	 */
	public static function top_pages( $days, $limit = 10 ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT page,
					COUNT(*) AS shown,
					SUM(CASE WHEN answer = 'nee' THEN 1 ELSE 0 END) AS not_found,
					SUM(CASE WHEN answer = 'nee' AND action_taken = '' THEN 1 ELSE 0 END) AS lost
				 FROM {$table}
				 WHERE created_at >= %s
				 GROUP BY page
				 HAVING not_found > 0
				 ORDER BY not_found DESC, shown DESC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::since( $days ),
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Verdeling over een kolom (apparaat of trigger).
	 *
	 * @param string $column Alleen 'device' of 'trigger_type' toegestaan.
	 */
	public static function breakdown( $days, $column ) {
		global $wpdb;

		// Een kolomnaam kan niet via prepare(), dus die controleren we hier
		// streng tegen een vaste lijst.
		$allowed = array( 'device', 'trigger_type' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return array();
		}

		$table = self::table();

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT {$column} AS label,
					COUNT(*) AS shown,
					SUM(CASE WHEN answer = 'nee' THEN 1 ELSE 0 END) AS not_found
				 FROM {$table}
				 WHERE created_at >= %s AND {$column} <> ''
				 GROUP BY {$column}
				 ORDER BY shown DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::since( $days )
			),
			ARRAY_A
		);
	}

	/**
	 * Verdeling over het uur van de dag - handig om te zien of mensen vooral
	 * buiten openingstijden vastlopen.
	 */
	public static function per_hour( $days ) {
		global $wpdb;

		$table = self::table();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT HOUR(created_at) AS hour,
					COUNT(*) AS shown,
					SUM(CASE WHEN answer = 'nee' THEN 1 ELSE 0 END) AS not_found
				 FROM {$table}
				 WHERE created_at >= %s
				 GROUP BY HOUR(created_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::since( $days )
			),
			ARRAY_A
		);

		$byhour = array();
		foreach ( $rows as $row ) {
			$byhour[ (int) $row['hour'] ] = $row;
		}

		$out = array();
		for ( $h = 0; $h < 24; $h++ ) {
			$out[] = array(
				'hour'      => $h,
				'shown'     => isset( $byhour[ $h ] ) ? (int) $byhour[ $h ]['shown'] : 0,
				'not_found' => isset( $byhour[ $h ] ) ? (int) $byhour[ $h ]['not_found'] : 0,
			);
		}

		return $out;
	}

	/**
	 * De laatste vertoningen, voor de tabel onderaan het dashboard.
	 */
	public static function recent( $days, $limit = 50 ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT created_at, page, device, trigger_type, answer, action_taken
				 FROM {$table}
				 WHERE created_at >= %s
				 ORDER BY created_at DESC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::since( $days ),
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Ruimt metingen op die ouder zijn dan een jaar.
	 *
	 * Bewaar niet langer dan nodig: na een jaar is een losse meting nergens
	 * meer voor nodig en hoort die opgeruimd te worden.
	 */
	public static function cleanup() {
		global $wpdb;

		$table = self::table();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - YEAR_IN_SECONDS )
			)
		);
	}

	/**
	 * Begintijdstip voor een periode van X dagen terug.
	 */
	private static function since( $days ) {
		$days = max( 1, (int) $days );
		return gmdate( 'Y-m-d 00:00:00', strtotime( current_time( 'mysql' ) ) - ( ( $days - 1 ) * DAY_IN_SECONDS ) );
	}
}
