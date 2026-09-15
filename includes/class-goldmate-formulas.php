<?php
/**
 * CRUD and lookup for named pricing formulas.
 *
 * A formula is a named recipe that tells the calculator which rate item to use
 * and how to turn the product inputs into a price. For now only the
 * Ratesbox-style "weight" formula is supported; free-form expressions are
 * intentionally deferred.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Formulas {

	/**
	 * @return string
	 */
	public static function table() {
		return Goldmate_Install::formulas_table();
	}

	/**
	 * Default seed formulas.
	 *
	 * @return array[]
	 */
	public static function seed_definitions() {
		return array(
			array(
				'slug'         => 'default',
				'label'        => 'طلای ۱۸ عیار (پیش‌فرض)',
				'formula_type' => 'weight',
				'rate_slug'    => 'gold18',
				'is_default'   => 1,
				'status'       => 'active',
				'priority'     => 10,
			),
		);
	}

	/**
	 * Inserts missing seed rows and ensures one default exists.
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
						'params'     => array(),
						'created_at' => time(),
						'updated_at' => time(),
					),
					$def
				)
			);
		}

		// If no default exists, promote the first active formula.
		$default = $wpdb->get_var( "SELECT id FROM {$table} WHERE is_default = 1 LIMIT 1" );
		if ( ! $default ) {
			$first = $wpdb->get_var( "SELECT id FROM {$table} WHERE status = 'active' ORDER BY priority ASC, id ASC LIMIT 1" );
			if ( $first ) {
				self::set_default( (int) $first );
			}
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
	 * @param int   $id   Formula ID.
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

		$was_default = ! empty( $data['is_default'] );
		if ( $was_default ) {
			$table = self::table();
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_default = 0 WHERE id != %d", $id ) );
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
	 * @param int $id Formula ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id = (int) $id;

		$item = self::get( $id );
		if ( ! $item ) {
			return false;
		}
		if ( $item['is_default'] ) {
			return false;
		}

		return false !== $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Make one formula the default.
	 *
	 * @param int $id Formula ID.
	 * @return bool
	 */
	public static function set_default( $id ) {
		global $wpdb;

		$id    = (int) $id;
		$table = self::table();

		$wpdb->query( "UPDATE {$table} SET is_default = 0" );

		return false !== $wpdb->update(
			$table,
			array(
				'is_default' => 1,
				'updated_at' => time(),
			),
			array( 'id' => $id ),
			array( '%d', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * @param int $id Formula ID.
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
	 * @param string $slug Formula slug.
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
	 * @return array|null
	 */
	public static function get_default() {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row(
			"SELECT * FROM {$table} WHERE is_default = 1 AND status = 'active' ORDER BY id ASC LIMIT 1",
			ARRAY_A
		);

		if ( $row ) {
			return self::hydrate( $row );
		}

		$row = $wpdb->get_row(
			"SELECT * FROM {$table} WHERE status = 'active' ORDER BY priority ASC, id ASC LIMIT 1",
			ARRAY_A
		);

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * @param array $args Optional filters.
	 * @return array[]
	 */
	public static function all( $args = array() ) {
		global $wpdb;

		$table = self::table();
		$sql   = "SELECT * FROM {$table} WHERE 1=1";
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$sql .= ' AND status = %s';
			$params[] = (string) $args['status'];
		}
		if ( isset( $args['active'] ) ) {
			$sql .= ' AND status = %s';
			$params[] = $args['active'] ? 'active' : 'inactive';
		}

		$sql .= ' ORDER BY priority ASC, id ASC';

		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A )
			: $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( __CLASS__, 'hydrate' ), $rows );
	}

	/**
	 * Slug => label for selects.
	 *
	 * @param bool $active_only Only active formulas.
	 * @return array<string,string>
	 */
	public static function choices( $active_only = true ) {
		$args  = $active_only ? array( 'status' => 'active' ) : array();
		$out   = array();
		foreach ( self::all( $args ) as $item ) {
			$out[ $item['slug'] ] = $item['label'];
		}
		return $out;
	}

	/**
	 * Finds or creates an active formula that points at the given rate item slug.
	 *
	 * Used by bulk tools and the one-time migration off `_goldmate_rate_item`.
	 *
	 * @param string $rate_slug Rate item slug.
	 * @param string $label     Optional label when creating.
	 * @return array|null Formula row.
	 */
	public static function ensure_for_rate_slug( $rate_slug, $label = '' ) {
		$rate_slug = sanitize_title( $rate_slug );
		if ( '' === $rate_slug ) {
			return self::get_default();
		}

		// Prefer the seeded default when targeting gold18.
		if ( 'gold18' === $rate_slug ) {
			$default = self::get_default();
			if ( $default && 'gold18' === $default['rate_slug'] ) {
				return $default;
			}
		}

		foreach ( self::all( array( 'status' => 'active' ) ) as $formula ) {
			if ( $rate_slug === $formula['rate_slug'] ) {
				return $formula;
			}
		}

		if ( '' === $label && class_exists( 'Goldmate_Rate_Items' ) ) {
			$item  = Goldmate_Rate_Items::get_by_slug( $rate_slug );
			$label = $item ? (string) $item['label'] : $rate_slug;
		}
		if ( '' === $label ) {
			$label = $rate_slug;
		}

		$formula_slug = $rate_slug;
		if ( self::get_by_slug( $formula_slug ) ) {
			$formula_slug = 'rate-' . $rate_slug;
		}

		$id = self::insert(
			array(
				'slug'         => $formula_slug,
				'label'        => $label,
				'formula_type' => 'weight',
				'rate_slug'    => $rate_slug,
				'is_default'   => 0,
				'status'       => 'active',
				'priority'     => 20,
				'params'       => array(),
				'created_at'   => time(),
				'updated_at'   => time(),
			)
		);

		return $id ? self::get( (int) $id ) : null;
	}

	/**
	 * Resolve the formula for a product ID.
	 *
	 * Priority:
	 *   1. Product's own _goldmate_formula meta.
	 *   2. Parent product's _goldmate_formula meta (for variations).
	 *   3. The global default formula.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return array|null
	 */
	public static function for_product( $product_id ) {
		$product_id = (int) $product_id;
		$slug       = '';

		if ( $product_id > 0 ) {
			$slug = (string) get_post_meta( $product_id, '_goldmate_formula', true );
			if ( '' === $slug ) {
				$parent = wp_get_post_parent_id( $product_id );
				if ( $parent ) {
					$slug = (string) get_post_meta( $parent, '_goldmate_formula', true );
				}
			}
		}

		if ( '' !== $slug ) {
			$formula = self::get_by_slug( $slug );
			if ( $formula && 'active' === $formula['status'] ) {
				return $formula;
			}
		}

		return self::get_default();
	}

	/**
	 * Hydrate DB row.
	 *
	 * @param array $row Raw.
	 * @return array
	 */
	protected static function hydrate( $row ) {
		$params = array();
		if ( ! empty( $row['params'] ) ) {
			$decoded = json_decode( (string) $row['params'], true );
			$params  = is_array( $decoded ) ? $decoded : array();
		}

		$row['id']          = (int) $row['id'];
		$row['priority']    = (int) $row['priority'];
		$row['is_default']  = (int) $row['is_default'];
		$row['created_at']  = (int) $row['created_at'];
		$row['updated_at']  = (int) $row['updated_at'];
		$row['params']      = $params;

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
			'slug'         => '%s',
			'label'        => '%s',
			'formula_type' => '%s',
			'rate_slug'    => '%s',
			'is_default'   => '%d',
			'status'       => '%s',
			'priority'     => '%d',
			'params'       => '%s',
			'created_at'   => '%d',
			'updated_at'   => '%d',
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
			} elseif ( 'params' === $key ) {
				$value = is_string( $value ) ? $value : wp_json_encode( $value ? $value : array() );
			} elseif ( 'label' === $key || 'formula_type' === $key || 'status' === $key ) {
				$value = sanitize_text_field( (string) $value );
			} elseif ( '%d' === $format ) {
				$value = (int) $value;
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
			'slug'         => '',
			'label'        => '',
			'formula_type' => 'weight',
			'rate_slug'    => 'gold18',
			'is_default'   => 0,
			'status'       => 'active',
			'priority'     => 10,
			'params'       => array(),
			'created_at'   => 0,
			'updated_at'   => 0,
		);
		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}

	/**
	 * Human-readable label for the formula type.
	 *
	 * @param string $type Type key.
	 * @return string
	 */
	public static function type_label( $type ) {
		$labels = array(
			'weight' => 'بر اساس وزن (طلای آبشده / جواهر)',
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	/**
	 * Available formula types.
	 *
	 * @return array<string,string>
	 */
	public static function type_options() {
		return array(
			'weight' => self::type_label( 'weight' ),
		);
	}
}
