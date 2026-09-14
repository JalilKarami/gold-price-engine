<?php
/**
 * Schema install / upgrades for rate items tables.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Install {

	const DB_VERSION = '3.0.0';
	const DB_OPTION  = 'goldmate_db_version';

	/**
	 * Creates or upgrades tables and runs one-time migrations.
	 */
	public static function install() {
		self::create_tables();
		self::maybe_migrate_v3();
		self::maybe_migrate_legacy_api_to_gold18();
		self::maybe_seed_provider_gold18_items();
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Ensures schema is current on every boot after a plugin update.
	 */
	public static function maybe_upgrade() {
		$current = (string) get_option( self::DB_OPTION, '' );
		if ( version_compare( $current, self::DB_VERSION, '<' ) || ! self::tables_exist() ) {
			self::install();
		} elseif ( class_exists( 'Goldmate_Rate_Items' ) ) {
			Goldmate_Rate_Items::seed_defaults();
			self::maybe_migrate_legacy_api_to_gold18();
			self::maybe_seed_provider_gold18_items();
		}
	}

	/**
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;
		$items = self::items_table();
		$hist  = self::history_table();
		$a     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $items ) );
		$b     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hist ) );
		return ( $a === $items && $b === $hist );
	}

	/**
	 * @return string
	 */
	public static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'goldmate_rate_items';
	}

	/**
	 * @return string
	 */
	public static function history_table() {
		global $wpdb;
		return $wpdb->prefix . 'goldmate_rate_history';
	}

	/**
	 * dbDelta for rate items + history.
	 */
	protected static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$items   = self::items_table();
		$hist    = self::history_table();

		$sql_items = "CREATE TABLE {$items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(64) NOT NULL,
			label varchar(191) NOT NULL DEFAULT '',
			priority int(11) NOT NULL DEFAULT 10,
			rate double NOT NULL DEFAULT 0,
			updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
			checked_at bigint(20) unsigned NOT NULL DEFAULT 0,
			source_type varchar(32) NOT NULL DEFAULT 'manual',
			source_config longtext NULL,
			unit varchar(16) NOT NULL DEFAULT 'toman',
			multiplier double NOT NULL DEFAULT 1,
			adjust_mode varchar(16) NOT NULL DEFAULT 'none',
			adjust_value double NOT NULL DEFAULT 0,
			adjust_buy_mode varchar(16) NOT NULL DEFAULT 'none',
			adjust_buy_value double NOT NULL DEFAULT 0,
			adjust_sell_mode varchar(16) NOT NULL DEFAULT 'none',
			adjust_sell_value double NOT NULL DEFAULT 0,
			tax_pct double NOT NULL DEFAULT 0,
			profit_pct double NOT NULL DEFAULT 0,
			fetch_interval int(11) NOT NULL DEFAULT 60,
			auto_fetch tinyint(1) NOT NULL DEFAULT 0,
			show_in_calc tinyint(1) NOT NULL DEFAULT 1,
			show_in_product_list tinyint(1) NOT NULL DEFAULT 1,
			formula_type varchar(32) NOT NULL DEFAULT 'weight',
			is_18k_reference tinyint(1) NOT NULL DEFAULT 0,
			last_error text NULL,
			last_provider varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY auto_fetch (auto_fetch),
			KEY priority (priority)
		) {$charset};";

		$sql_hist = "CREATE TABLE {$hist} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			item_id bigint(20) unsigned NOT NULL,
			rate double NOT NULL DEFAULT 0,
			recorded_at bigint(20) unsigned NOT NULL DEFAULT 0,
			source varchar(32) NOT NULL DEFAULT 'manual',
			provider varchar(64) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY item_at (item_id, recorded_at)
		) {$charset};";

		dbDelta( $sql_items );
		dbDelta( $sql_hist );
	}

	/**
	 * Seeds gold pack and migrates legacy single-rate option into gold18.
	 */
	protected static function maybe_migrate_v3() {
		if ( ! class_exists( 'Goldmate_Rate_Items' ) ) {
			return;
		}

		Goldmate_Rate_Items::seed_defaults();

		$legacy = goldmate_positive_float( get_option( 'goldmate_rate_per_gram', 0 ) );
		$item   = Goldmate_Rate_Items::get_by_slug( 'gold18' );

		if ( ! $item ) {
			return;
		}

		if ( $legacy > 0 && (float) $item['rate'] <= 0 ) {
			Goldmate_Rate_Items::set_rate(
				(int) $item['id'],
				$legacy,
				'manual',
				array(
					'skip_batch' => true,
					'provider'   => '',
				)
			);
		}

		// Copy legacy option history into gold18 history once.
		$flag = 'goldmate_migrated_history_v3';
		if ( get_option( $flag ) ) {
			return;
		}

		$old = get_option( 'goldmate_rate_history', array() );
		if ( is_array( $old ) && ! empty( $old ) ) {
			foreach ( array_reverse( $old ) as $row ) {
				Goldmate_Rate_Items::record_history(
					(int) $item['id'],
					isset( $row['rate'] ) ? (float) $row['rate'] : 0,
					isset( $row['source'] ) ? (string) $row['source'] : 'manual',
					isset( $row['provider'] ) ? (string) $row['provider'] : '',
					isset( $row['user'] ) ? (int) $row['user'] : 0,
					isset( $row['at'] ) ? (int) $row['at'] : time()
				);
			}
		}

		update_option( $flag, 1, false );
	}

	/**
	 * Copies legacy single-API options (pre–rate-items UI) onto gold18 once.
	 * Skips if gold18 already has a non-manual source or a configured URL.
	 */
	public static function maybe_migrate_legacy_api_to_gold18() {
		if ( ! class_exists( 'Goldmate_Rate_Items' ) ) {
			return;
		}

		$flag = 'goldmate_migrated_legacy_api_v3';
		if ( get_option( $flag ) ) {
			return;
		}

		$url = trim( (string) get_option( 'goldmate_api_url', '' ) );
		if ( '' === $url ) {
			update_option( $flag, 1, false );
			return;
		}

		$item = Goldmate_Rate_Items::get_by_slug( 'gold18' );
		if ( ! $item ) {
			return;
		}

		$cfg     = isset( $item['source_config'] ) && is_array( $item['source_config'] ) ? $item['source_config'] : array();
		$has_url = ! empty( $cfg['url'] );
		$type    = isset( $item['source_type'] ) ? (string) $item['source_type'] : 'manual';

		if ( $has_url || ! in_array( $type, array( 'manual', '' ), true ) ) {
			update_option( $flag, 1, false );
			return;
		}

		$interval = (int) get_option( 'goldmate_fetch_interval', 60 );
		if ( $interval < 1 ) {
			$interval = 60;
		}

		$mult = (float) get_option( 'goldmate_api_multiplier', 1 );
		if ( $mult <= 0 ) {
			$mult = 1;
		}

		Goldmate_Rate_Items::update(
			(int) $item['id'],
			array(
				'source_type'   => 'json',
				'source_config' => array(
					'url'            => $url,
					'path'           => trim( (string) get_option( 'goldmate_api_path', '' ) ),
					'selector'       => '',
					'api_key'        => trim( (string) get_option( 'goldmate_api_key', '' ) ),
					'api_key_header' => trim( (string) get_option( 'goldmate_api_key_header', 'X-API-Key' ) ),
					'time_path'      => trim( (string) get_option( 'goldmate_api_time_path', '' ) ),
					'max_age'        => max( 0, (int) get_option( 'goldmate_api_max_age', 0 ) ),
					'decimal_sep'    => '.',
					'thousands_sep'  => ',',
				),
				'multiplier'     => $mult,
				'auto_fetch'     => 1,
				'fetch_interval' => $interval,
				'unit'           => 'toman',
			)
		);

		update_option( $flag, 1, false );
	}

	/**
	 * Ensures each API provider has its own طلای ۱۸ item (not shared fallbacks).
	 */
	public static function maybe_seed_provider_gold18_items() {
		if ( ! class_exists( 'Goldmate_Rate_Items' ) ) {
			return;
		}

		$flag = 'goldmate_seeded_provider_gold18';
		if ( get_option( $flag ) ) {
			// Still insert any missing seed rows on later boots.
			Goldmate_Rate_Items::seed_defaults();
			return;
		}

		Goldmate_Rate_Items::seed_defaults();

		$interval = max( 1, (int) get_option( 'goldmate_fetch_interval', 60 ) );
		$atn_key  = trim( (string) get_option( 'goldmate_api_key', '' ) );

		$providers = array(
			'gold18'        => array(
				'label'       => 'طلای ۱۸ عیار (ATN)',
				'source_type' => 'atn',
				'auto_fetch'  => 1,
				'is_ref'      => 1,
				'config'      => array(
					'url'            => 'http://77.104.95.33/api/v1/prices/atn',
					'path'           => 'atn.products[code=melted_gold_tomorrow].gram_sell',
					'api_key'        => $atn_key,
					'api_key_header' => 'X-API-Key',
					'time_path'      => 'atn.updated_at',
					'max_age'        => 1440,
					'fallbacks'      => '',
					'brsapi_key'     => '',
					'decimal_sep'    => '.',
					'thousands_sep'  => ',',
					'selector'       => '',
				),
				'multiplier'  => 1,
			),
			'gold18_brsapi' => array(
				'label'       => 'طلای ۱۸ عیار (BrsApi)',
				'source_type' => 'brsapi',
				'auto_fetch'  => 0,
				'is_ref'      => 0,
				'config'      => array(
					'url'            => 'https://Api.BrsApi.ir/Market/Gold_Currency.php?key={KEY}',
					'path'           => 'gold[symbol=IR_GOLD_18K].price',
					'api_key'        => '',
					'api_key_header' => '',
					'time_path'      => 'gold[symbol=IR_GOLD_18K].time_unix',
					'max_age'        => 1440,
					'fallbacks'      => '',
					'brsapi_key'     => '',
					'decimal_sep'    => '.',
					'thousands_sep'  => ',',
					'selector'       => '',
				),
				'multiplier'  => 1,
			),
			'gold18_tgju'   => array(
				'label'       => 'طلای ۱۸ عیار (TGJU)',
				'source_type' => 'tgju',
				'auto_fetch'  => 1,
				'is_ref'      => 0,
				'config'      => array(
					'url'            => 'https://call1.tgju.org/ajax.json',
					'path'           => 'current.geram18.p',
					'api_key'        => '',
					'api_key_header' => '',
					'time_path'      => '',
					'max_age'        => 0,
					'fallbacks'      => '',
					'brsapi_key'     => '',
					'decimal_sep'    => '.',
					'thousands_sep'  => ',',
					'selector'       => '',
				),
				'multiplier'  => 0.1,
			),
		);

		foreach ( $providers as $slug => $def ) {
			$item = Goldmate_Rate_Items::get_by_slug( $slug );
			if ( ! $item ) {
				continue;
			}

			$cfg = isset( $item['source_config'] ) && is_array( $item['source_config'] ) ? $item['source_config'] : array();

			if ( 'gold18' === $slug && ! empty( $cfg['api_key'] ) ) {
				$def['config']['api_key'] = $cfg['api_key'];
			}
			if ( 'gold18_brsapi' === $slug ) {
				if ( ! empty( $cfg['api_key'] ) ) {
					$def['config']['api_key'] = $cfg['api_key'];
					$def['auto_fetch']        = 1;
				}
				if ( ! empty( $cfg['brsapi_key'] ) ) {
					$def['config']['brsapi_key'] = $cfg['brsapi_key'];
					$def['config']['api_key']    = $cfg['brsapi_key'];
					$def['auto_fetch']           = 1;
				}
			}

			Goldmate_Rate_Items::update(
				(int) $item['id'],
				array(
					'label'                => $def['label'],
					'source_type'          => $def['source_type'],
					'source_config'        => $def['config'],
					'multiplier'           => $def['multiplier'],
					'auto_fetch'           => $def['auto_fetch'],
					'fetch_interval'       => $interval,
					'unit'                 => 'toman',
					'is_18k_reference'     => $def['is_ref'],
					'show_in_calc'         => 1,
					'show_in_product_list' => 1,
					'formula_type'         => 'weight',
				)
			);
		}

		update_option( $flag, 1, false );
	}
}
