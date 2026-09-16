<?php
/**
 * CRUD and rate writes for named rate items (gold18, gold24, …).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Rate_Items {

	const DEFAULT_SLUG = 'gold18';

	/**
	 * @return string
	 */
	public static function table() {
		return Goldmate_Install::items_table();
	}

	/**
	 * @return string
	 */
	public static function history_table() {
		return Goldmate_Install::history_table();
	}

	/**
	 * Default gold seed pack.
	 *
	 * @return array[]
	 */
	public static function seed_definitions() {
		return array(
			array(
				'slug'                 => 'gold18',
				'label'                => 'طلای ۱۸ عیار (ATN)',
				'priority'             => 10,
				'is_18k_reference'     => 1,
				'show_in_calc'         => 1,
				'show_in_product_list' => 1,
				'formula_type'         => 'weight',
			),
			array(
				'slug'                 => 'gold18_brsapi',
				'label'                => 'طلای ۱۸ عیار (BrsApi)',
				'priority'             => 11,
				'is_18k_reference'     => 0,
				'show_in_calc'         => 1,
				'show_in_product_list' => 1,
				'formula_type'         => 'weight',
			),
			array(
				'slug'                 => 'gold18_tgju',
				'label'                => 'طلای ۱۸ عیار (TGJU)',
				'priority'             => 12,
				'is_18k_reference'     => 0,
				'show_in_calc'         => 1,
				'show_in_product_list' => 1,
				'formula_type'         => 'weight',
			),
		);
	}

	/**
	 * Inserts missing seed rows.
	 */
	public static function seed_defaults() {
		global $wpdb;
		$table = self::table();

		foreach ( self::seed_definitions() as $def ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $def['slug'] )
			);
			if ( $exists ) {
				continue;
			}
			self::insert(
				array_merge(
					array(
						'rate'            => 0,
						'source_type'     => 'manual',
						'source_config'   => array(),
						'unit'            => 'toman',
						'multiplier'      => 1,
						'adjust_mode'     => 'none',
						'fetch_interval'  => 60,
						'auto_fetch'      => 0,
						'tax_pct'         => (float) goldmate_option( 'goldmate_tax_pct' ),
						'profit_pct'      => (float) goldmate_option( 'goldmate_profit_pct' ),
					),
					$def
				)
			);
		}
	}

	/**
	 * @param array $data Row data.
	 * @return int|false Insert ID.
	 */
	public static function insert( $data ) {
		global $wpdb;

		$row = self::normalize_row( $data, true );
		$ok  = $wpdb->insert( self::table(), $row['columns'], $row['formats'] );

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * @param int   $id   Item ID.
	 * @param array $data Fields to update.
	 * @return bool
	 */
	public static function update( $id, $data ) {
		global $wpdb;

		$id  = (int) $id;
		$row = self::normalize_row( $data, false );

		if ( empty( $row['columns'] ) ) {
			return false;
		}

		return false !== $wpdb->update(
			self::table(),
			$row['columns'],
			array( 'id' => $id ),
			$row['formats'],
			array( '%d' )
		);
	}

	/**
	 * @param int $id Item ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id   = (int) $id;
		$item = self::get( $id );
		if ( ! $item ) {
			return false;
		}
		if ( self::DEFAULT_SLUG === $item['slug'] ) {
			return false;
		}

		$wpdb->delete( self::history_table(), array( 'item_id' => $id ), array( '%d' ) );
		return false !== $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Duplicate an item with a new slug.
	 *
	 * @param int    $id   Source ID.
	 * @param string $slug New slug.
	 * @return int|false
	 */
	public static function duplicate( $id, $slug = '' ) {
		$item = self::get( $id );
		if ( ! $item ) {
			return false;
		}

		unset( $item['id'] );
		$base = $slug ? $slug : $item['slug'] . '-copy';
		$n    = 1;
		$try  = $base;
		while ( self::get_by_slug( $try ) ) {
			$try = $base . '-' . $n;
			$n++;
		}
		$item['slug']  = sanitize_title( $try );
		$item['label'] = $item['label'] . ' (کپی)';

		return self::insert( $item );
	}

	/**
	 * @param int $id Item ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * @param string $slug Slug.
	 * @return array|null
	 */
	public static function get_by_slug( $slug ) {
		global $wpdb;
		$slug = sanitize_title( $slug );
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE slug = %s', $slug ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Default catalog item (gold18).
	 *
	 * @return array|null
	 */
	public static function get_default() {
		$item = self::get_by_slug( self::DEFAULT_SLUG );
		if ( $item ) {
			return $item;
		}
		$all = self::all();
		return $all ? $all[0] : null;
	}

	/**
	 * @param array $args Optional filters.
	 * @return array[]
	 */
	public static function all( $args = array() ) {
		global $wpdb;

		$sql = 'SELECT * FROM ' . self::table() . ' WHERE 1=1';
		$params = array();

		if ( ! empty( $args['show_in_calc'] ) ) {
			$sql .= ' AND show_in_calc = 1';
		}
		if ( ! empty( $args['show_in_product_list'] ) ) {
			$sql .= ' AND show_in_product_list = 1';
		}
		if ( ! empty( $args['auto_fetch'] ) ) {
			$sql .= ' AND auto_fetch = 1';
		}

		$sql .= ' ORDER BY priority ASC, id ASC';

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( __CLASS__, 'hydrate' ), $rows );
	}

	/**
	 * Slug => label for selects.
	 *
	 * @param bool $product_list Only items flagged for product list.
	 * @return array<string,string>
	 */
	public static function choices( $product_list = true ) {
		$args  = $product_list ? array( 'show_in_product_list' => true ) : array();
		$out   = array();
		foreach ( self::all( $args ) as $item ) {
			$out[ $item['slug'] ] = $item['label'];
		}
		if ( empty( $out ) ) {
			$out[ self::DEFAULT_SLUG ] = 'طلای ۱۸ عیار';
		}
		return $out;
	}

	/**
	 * Resolve rate item for a product via its named formula.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return array|null
	 */
	public static function for_product( $product_id ) {
		$product_id = (int) $product_id;

		if ( $product_id > 0 && class_exists( 'Goldmate_Formulas' ) ) {
			$formula = Goldmate_Formulas::for_product( $product_id );
			if ( $formula ) {
				$item = self::get_by_slug( $formula['rate_slug'] );
				if ( $item ) {
					return $item;
				}
			}
		}

		$item = self::get_by_slug( self::DEFAULT_SLUG );
		return $item ? $item : self::get_default();
	}

	/**
	 * Rate to use as calculator rate_18 base for an item + product karat.
	 *
	 * For 18k-reference items: return item rate (calculator scales by karat/18).
	 * For other items: return a synthetic 18k-equivalent so karat scaling cancels when karat matches item purity,
	 * or simply the item rate when treating the item rate as already per-gram for that purity.
	 *
	 * Locked rule: karat scaling applies only when item is_18k_reference.
	 * Non-reference items: rate is used as-is (force karat factor = 1 by passing rate_18 = rate and karat = 18 in calculator caller).
	 *
	 * @param array $item Item row.
	 * @return float Toman per gram of 18k-equivalent input for calculate_from_inputs.
	 */
	public static function rate_18_for_calculator( $item ) {
		if ( empty( $item ) ) {
			return goldmate_reference_rate();
		}
		return goldmate_positive_float( $item['rate'] );
	}

	/**
	 * The gold18 reference item row, or null.
	 *
	 * @return array|null
	 */
	public static function reference_item() {
		return self::get_by_slug( self::DEFAULT_SLUG );
	}

	/**
	 * True when the gold18 reference rate is older than goldmate_stale_hours.
	 *
	 * @return bool
	 */
	public static function is_reference_stale() {

		$hours = (float) goldmate_option( 'goldmate_stale_hours' );
		if ( $hours <= 0 ) {
			return false;
		}

		$item = self::reference_item();
		$updated = $item ? (int) $item['updated_at'] : (int) get_option( 'goldmate_rate_updated_at', 0 );

		if ( $updated <= 0 ) {
			return true;
		}

		return ( time() - $updated ) > (int) ( $hours * HOUR_IN_SECONDS );
	}

	/**
	 * Newest-first history for the gold18 reference item (admin / live fallback).
	 *
	 * @param int $limit Rows.
	 * @return array[]
	 */
	public static function reference_history( $limit = 30 ) {
		$item = self::reference_item();
		if ( ! $item ) {
			return array();
		}
		return self::history( (int) $item['id'], $limit );
	}

	/**
	 * Delete history rows older than N hours (all items).
	 *
	 * @param float $hours Retention hours; <=0 skips.
	 */
	public static function prune_history_older_than( $hours ) {
		global $wpdb;

		$hours = (float) $hours;
		if ( $hours <= 0 ) {
			return;
		}

		$cutoff = time() - (int) ( $hours * HOUR_IN_SECONDS );
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::history_table() . ' WHERE recorded_at < %d',
				$cutoff
			)
		);
	}

	/**
	 * Whether calculator should scale karat against 18.
	 *
	 * @param array $item Item.
	 * @return bool
	 */
	public static function uses_karat_scale( $item ) {
		return ! empty( $item['is_18k_reference'] );
	}

	/**
	 * Apply a new rate to an item; sync legacy option for gold18; maybe batch.
	 *
	 * @param int    $item_id Item ID.
	 * @param float  $rate    New rate (shop currency per gram after multiplier).
	 * @param string $source  auto|manual.
	 * @param array  $args    provider, skip_batch.
	 * @return bool
	 */
	public static function set_rate( $item_id, $rate, $source = 'manual', $args = array() ) {
		$rate = goldmate_positive_float( $rate );
		if ( $rate <= 0 ) {
			return false;
		}

		$item = self::get( $item_id );
		if ( ! $item ) {
			return false;
		}

		$previous = goldmate_positive_float( $item['rate'] );
		$now      = time();

		self::update(
			$item_id,
			array(
				'rate'         => $rate,
				'updated_at'   => $now,
				'checked_at'   => $now,
				'last_error'   => '',
				'last_provider'=> isset( $args['provider'] ) ? (string) $args['provider'] : $item['last_provider'],
			)
		);

		if ( abs( $rate - $previous ) > 0.0001 ) {
			self::record_history(
				$item_id,
				$rate,
				$source,
				isset( $args['provider'] ) ? (string) $args['provider'] : '',
				get_current_user_id(),
				$now
			);
		}

		// Backward-compatible shop option for WoodMart / old code paths.
		if ( self::DEFAULT_SLUG === $item['slug'] ) {
			update_option( 'goldmate_rate_per_gram', $rate );
			update_option( 'goldmate_rate_updated_at', $now, false );
			update_option( 'goldmate_rate_checked_at', $now, false );
			update_option( 'goldmate_rate_last_error', '', false );
			delete_option( 'goldmate_pending_rate' );
			if ( ! empty( $args['provider'] ) ) {
				update_option( 'goldmate_rate_last_provider', (string) $args['provider'], false );
			}
		}

		if ( ! empty( $args['skip_batch'] ) ) {
			return true;
		}

		if ( abs( $rate - $previous ) <= 0.0001 ) {
			return true;
		}

		$allow_batch = false;
		if ( 'auto' === $source && 'yes' === goldmate_option( 'goldmate_reprice_on_cron' ) ) {
			$allow_batch = true;
		}
		if ( 'manual' === $source && 'yes' === goldmate_option( 'goldmate_reprice_on_manual_fetch' ) ) {
			$allow_batch = true;
		}
		if ( $allow_batch && 'yes' === goldmate_option( 'goldmate_reprice_on_all_items_save' ) && class_exists( 'Goldmate_Batch' ) ) {
			Goldmate_Batch::start(
				'auto' === $source
					? 'دریافت خودکار: ' . $item['label']
					: 'تغییر دستی: ' . $item['label']
			);
		}

		return true;
	}

	/**
	 * @param int    $item_id     Item ID.
	 * @param float  $rate        Rate.
	 * @param string $source      Source.
	 * @param string $provider    Provider.
	 * @param int    $user_id     User.
	 * @param int    $recorded_at Timestamp.
	 */
	public static function record_history( $item_id, $rate, $source, $provider = '', $user_id = 0, $recorded_at = 0 ) {
		global $wpdb;

		$rate = goldmate_positive_float( $rate );
		if ( $rate <= 0 ) {
			return;
		}

		$wpdb->insert(
			self::history_table(),
			array(
				'item_id'     => (int) $item_id,
				'rate'        => $rate,
				'recorded_at' => $recorded_at ? (int) $recorded_at : time(),
				'source'      => sanitize_key( $source ),
				'provider'    => sanitize_text_field( $provider ),
				'user_id'     => (int) $user_id,
			),
			array( '%d', '%f', '%d', '%s', '%s', '%d' )
		);

		// Trim old rows for this item (keep 100).
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . self::history_table() . ' WHERE item_id = %d ORDER BY recorded_at DESC, id DESC',
				(int) $item_id
			)
		);
		if ( is_array( $ids ) && count( $ids ) > 100 ) {
			$drop = array_map( 'intval', array_slice( $ids, 100 ) );
			$in   = implode( ',', $drop );
			$wpdb->query( "DELETE FROM " . self::history_table() . " WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	/**
	 * Newest-first history for an item.
	 *
	 * @param int $item_id Item ID.
	 * @param int $limit   Limit.
	 * @return array[]
	 */
	public static function history( $item_id, $limit = 30 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT rate, recorded_at AS at, source, provider, user_id AS user FROM ' . self::history_table() .
				' WHERE item_id = %d ORDER BY recorded_at DESC, id DESC LIMIT %d',
				(int) $item_id,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Clear history for one item or all.
	 *
	 * @param int $item_id 0 = all.
	 */
	public static function clear_history( $item_id = 0 ) {
		global $wpdb;
		if ( $item_id > 0 ) {
			$wpdb->delete( self::history_table(), array( 'item_id' => (int) $item_id ), array( '%d' ) );
			return;
		}
		$wpdb->query( 'TRUNCATE TABLE ' . self::history_table() ); // phpcs:ignore
	}

	/**
	 * Items due for auto-fetch.
	 *
	 * @return array[]
	 */
	public static function due_for_fetch() {
		$now  = time();
		$due  = array();
		foreach ( self::all( array( 'auto_fetch' => true ) ) as $item ) {
			if ( in_array( $item['source_type'], array( 'manual', '' ), true ) ) {
				continue;
			}
			$interval = max( 1, (int) $item['fetch_interval'] ) * MINUTE_IN_SECONDS;
			$checked  = (int) $item['checked_at'];
			if ( $checked <= 0 || ( $now - $checked ) >= $interval ) {
				$due[] = $item;
			}
		}
		return $due;
	}

	/**
	 * Hydrate DB row.
	 *
	 * @param array $row Raw.
	 * @return array
	 */
	protected static function hydrate( $row ) {
		$config = array();
		if ( ! empty( $row['source_config'] ) ) {
			$decoded = json_decode( (string) $row['source_config'], true );
			$config  = is_array( $decoded ) ? $decoded : array();
		}

		$row['id']                   = (int) $row['id'];
		$row['priority']             = (int) $row['priority'];
		$row['rate']                 = (float) $row['rate'];
		$row['updated_at']           = (int) $row['updated_at'];
		$row['checked_at']           = (int) $row['checked_at'];
		$row['source_config']        = $config;
		$row['multiplier']           = (float) $row['multiplier'];
		$row['adjust_value']         = (float) $row['adjust_value'];
		$row['adjust_buy_value']     = (float) $row['adjust_buy_value'];
		$row['adjust_sell_value']    = (float) $row['adjust_sell_value'];
		$row['tax_pct']              = (float) $row['tax_pct'];
		$row['profit_pct']           = (float) $row['profit_pct'];
		$row['fetch_interval']       = (int) $row['fetch_interval'];
		$row['auto_fetch']           = (int) $row['auto_fetch'];
		$row['show_in_calc']         = (int) $row['show_in_calc'];
		$row['show_in_product_list'] = (int) $row['show_in_product_list'];
		$row['is_18k_reference']     = (int) $row['is_18k_reference'];

		return $row;
	}

	/**
	 * Normalize insert/update payload.
	 *
	 * @param array $data   Input.
	 * @param bool  $insert Full row required.
	 * @return array{columns:array,formats:array}
	 */
	protected static function normalize_row( $data, $insert ) {
		$map = array(
			'slug'                 => '%s',
			'label'                => '%s',
			'priority'             => '%d',
			'rate'                 => '%f',
			'updated_at'           => '%d',
			'checked_at'           => '%d',
			'source_type'          => '%s',
			'source_config'        => '%s',
			'unit'                 => '%s',
			'multiplier'           => '%f',
			'adjust_mode'          => '%s',
			'adjust_value'         => '%f',
			'adjust_buy_mode'      => '%s',
			'adjust_buy_value'     => '%f',
			'adjust_sell_mode'     => '%s',
			'adjust_sell_value'    => '%f',
			'tax_pct'              => '%f',
			'profit_pct'           => '%f',
			'fetch_interval'       => '%d',
			'auto_fetch'           => '%d',
			'show_in_calc'         => '%d',
			'show_in_product_list' => '%d',
			'formula_type'         => '%s',
			'is_18k_reference'     => '%d',
			'last_error'           => '%s',
			'last_provider'        => '%s',
		);

		$columns = array();
		$formats = array();

		foreach ( $map as $key => $format ) {
			if ( ! array_key_exists( $key, $data ) && ! $insert ) {
				continue;
			}
			$value = array_key_exists( $key, $data ) ? $data[ $key ] : self::default_field( $key );

			if ( 'slug' === $key ) {
				$value = sanitize_title( (string) $value );
			} elseif ( 'source_config' === $key ) {
				$value = is_string( $value ) ? $value : wp_json_encode( $value ? $value : array() );
			} elseif ( 'label' === $key || 'last_error' === $key || 'last_provider' === $key || 'source_type' === $key || 'unit' === $key || 'formula_type' === $key || 'adjust_mode' === $key || 'adjust_buy_mode' === $key || 'adjust_sell_mode' === $key ) {
				$value = sanitize_text_field( (string) $value );
			} elseif ( '%d' === $format ) {
				$value = (int) $value;
			} elseif ( '%f' === $format ) {
				$value = (float) $value;
			}

			$columns[ $key ] = $value;
			$formats[]       = $format;
		}

		return array(
			'columns' => $columns,
			'formats' => $formats,
		);
	}

	/**
	 * @param string $key Field.
	 * @return mixed
	 */
	protected static function default_field( $key ) {
		$defaults = array(
			'slug'                 => '',
			'label'                => '',
			'priority'             => 10,
			'rate'                 => 0,
			'updated_at'           => 0,
			'checked_at'           => 0,
			'source_type'          => 'manual',
			'source_config'        => array(),
			'unit'                 => 'toman',
			'multiplier'           => 1,
			'adjust_mode'          => 'none',
			'adjust_value'         => 0,
			'adjust_buy_mode'      => 'none',
			'adjust_buy_value'     => 0,
			'adjust_sell_mode'     => 'none',
			'adjust_sell_value'    => 0,
			'tax_pct'              => 0,
			'profit_pct'           => 0,
			'fetch_interval'       => 60,
			'auto_fetch'           => 0,
			'show_in_calc'         => 1,
			'show_in_product_list' => 1,
			'formula_type'         => 'weight',
			'is_18k_reference'     => 0,
			'last_error'           => '',
			'last_provider'        => '',
		);
		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}
}
