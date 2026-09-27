<?php
/**
 * Layered discounts: product → category → global (Ratesbox-style).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Discounts {

	const GLOBAL_OPTION = 'goldmate_discounts_global';
	const TERM_META     = 'goldmate_discounts';
	const BOUNDARY_CRON = 'goldmate_discount_boundaries';

	/**
	 * Discount target definitions.
	 *
	 * `hint` tells the admin how each line interacts with the rest of the
	 * formula, since e.g. a rate discount also shrinks percentage wage and profit.
	 *
	 * @return array<string,array{label:string,default_type:string,hint:string}>
	 */
	public static function targets() {
		return array(
			'profit'               => array(
				'label'        => 'تخفیف روی سود',
				'default_type' => 'pct',
				'hint'         => 'فقط سود کم می‌شود.',
			),
			'wage'                 => array(
				'label'        => 'تخفیف روی اجرت ساخت',
				'default_type' => 'pct',
				'hint'         => 'فقط اجرت ساخت کم می‌شود.',
			),
			'rate'                 => array(
				'label'        => 'تخفیف روی قیمت آیتم / هر گرم',
				'default_type' => 'fixed',
				'hint'         => 'از نرخ هر گرم طلا کم می‌شود (مبلغ تخفیف × وزن)؛ اجرت و سود روی قیمت کامل طلا حساب می‌شوند.',
			),
			'accessories_per_gram' => array(
				'label'        => 'تخفیف روی قیمت جواهر بر حسب گرم',
				'default_type' => 'fixed',
				'hint'         => 'در حالت ثابت، مبلغ × وزن محصول از جواهر کم می‌شود.',
			),
			'gold'                 => array(
				'label'        => 'تخفیف روی قیمت محصول خام (مبلغ طلا)',
				'default_type' => 'fixed',
				'hint'         => 'فقط مبلغ طلا کم می‌شود؛ اجرت و سود روی مبلغ کامل طلا حساب می‌شوند.',
			),
			'accessories'          => array(
				'label'        => 'تخفیف روی قیمت جواهر / ملحقات',
				'default_type' => 'fixed',
				'hint'         => 'فقط مبلغ جواهر / ملحقات کم می‌شود.',
			),
			'gold_and_accessories' => array(
				'label'        => 'تخفیف روی قیمت محصول خام و جواهر',
				'default_type' => 'fixed',
				'hint'         => 'از جمع طلا و جواهر، به نسبت سهم هر کدام کم می‌شود.',
			),
			'total'                => array(
				'label'        => 'تخفیف روی قیمت کل',
				'default_type' => 'fixed',
				'hint'         => 'پیش از مالیات از جمع قیمت کم می‌شود؛ مالیات روی مبلغ تخفیف‌خورده حساب می‌شود.',
			),
		);
	}

	/**
	 * Boots category form hooks.
	 */
	public static function init() {
		add_action( 'product_cat_add_form_fields', array( __CLASS__, 'render_term_fields_add' ) );
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'render_term_fields_edit' ), 10, 1 );
		add_action( 'created_product_cat', array( __CLASS__, 'save_term' ) );
		add_action( 'edited_product_cat', array( __CLASS__, 'save_term' ) );

		add_action( self::BOUNDARY_CRON, array( __CLASS__, 'run_boundary_check' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * Schedules the daily discount-window check shortly after local midnight.
	 *
	 * Discounts are baked into the stored price, so a window that opens or
	 * closes only takes effect at the next reprice. Without this, a quiet day
	 * with no rate change would keep an expired discount on sale.
	 */
	public static function ensure_scheduled() {

		$first = ( new DateTime( 'tomorrow 00:05', wp_timezone() ) )->getTimestamp();

		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::BOUNDARY_CRON, array(), 'goldmate' ) ) {
				as_schedule_recurring_action( $first, DAY_IN_SECONDS, self::BOUNDARY_CRON, array(), 'goldmate' );
			}
			$ts = wp_next_scheduled( self::BOUNDARY_CRON );
			if ( $ts ) {
				wp_unschedule_event( $ts, self::BOUNDARY_CRON );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::BOUNDARY_CRON ) ) {
			wp_schedule_event( $first, 'daily', self::BOUNDARY_CRON );
		}
	}

	/**
	 * Removes the daily discount-window check.
	 */
	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BOUNDARY_CRON, null, 'goldmate' );
		}
		wp_unschedule_hook( self::BOUNDARY_CRON );
	}

	/**
	 * Reprices the catalogue when any discount window opened today or closed yesterday.
	 */
	public static function run_boundary_check() {

		// Not wp_date(): woodmart-plus rewrites it to Jalali, while the date
		// fields store Gregorian Y-m-d in the site timezone.
		$now       = new DateTime( 'now', wp_timezone() );
		$today     = $now->format( 'Y-m-d' );
		$yesterday = $now->modify( '-1 day' )->format( 'Y-m-d' );

		if ( self::has_boundary( $today, $yesterday ) ) {
			Goldmate_Batch::start( 'شروع یا پایان تخفیف' );
		}
	}

	/**
	 * True when a discount layer starts on `$today` or ended on `$yesterday`.
	 *
	 * @param string $today     Site-local Y-m-d.
	 * @param string $yesterday Site-local Y-m-d.
	 * @return bool
	 */
	public static function has_boundary( $today, $yesterday ) {
		global $wpdb;

		$crosses = function ( $layer ) use ( $today, $yesterday ) {
			return $today === $layer['date_from'] || $yesterday === $layer['date_to'];
		};

		if ( $crosses( self::get_global() ) ) {
			return true;
		}

		$term_ids = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_key'   => self::TERM_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( is_array( $term_ids ) ? $term_ids : array() as $term_id ) {
			if ( $crosses( self::get_term( $term_id ) ) ) {
				return true;
			}
		}

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->postmeta}
				  WHERE ( meta_key = '_goldmate_discounts_from' AND meta_value = %s )
				     OR ( meta_key = '_goldmate_discounts_to' AND meta_value = %s )
				  LIMIT 1",
				$today,
				$yesterday
			)
		);
	}

	/**
	 * Resolve effective discounts for a product: product → category → global.
	 *
	 * Among several categories, the deepest one with an applicable discount wins.
	 *
	 * @param int        $product_id    Product or variation ID.
	 * @param array|null $product_layer Unsaved product layer (edit-screen preview) used instead of the stored one.
	 * @return array{enabled:bool,items:array,source:string,date_from:string,date_to:string}
	 */
	public static function resolve( $product_id, $product_layer = null ) {
		$product = is_array( $product_layer ) ? $product_layer : self::get( $product_id );
		if ( self::layer_applies( $product ) ) {
			$product['source'] = 'product';
			return $product;
		}

		$terms = self::product_category_ids( $product_id );
		foreach ( $terms as $term_id ) {
			$cat = self::get_term( $term_id );
			if ( self::layer_applies( $cat ) ) {
				$cat['source'] = 'category';
				return $cat;
			}
		}

		$global = self::get_global();
		if ( self::layer_applies( $global ) ) {
			$global['source'] = 'global';
			return $global;
		}

		return array(
			'enabled'   => false,
			'items'     => self::empty_items(),
			'source'    => 'none',
			'date_from' => '',
			'date_to'   => '',
		);
	}

	/**
	 * @param array $layer Layer.
	 * @return bool
	 */
	protected static function layer_applies( $layer ) {
		if ( empty( $layer['enabled'] ) ) {
			return false;
		}
		if ( ! self::within_dates( isset( $layer['date_from'] ) ? $layer['date_from'] : '', isset( $layer['date_to'] ) ? $layer['date_to'] : '' ) ) {
			return false;
		}
		foreach ( $layer['items'] as $row ) {
			if ( ! empty( $row['enabled'] ) && goldmate_positive_float( $row['amount'] ) > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $from Y-m-d or empty.
	 * @param string $to   Y-m-d or empty.
	 * @return bool
	 */
	public static function within_dates( $from, $to ) {
		// Product date fields are site-local; compare in site timezone, not UTC.
		$today = function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		if ( $from && $today < $from ) {
			return false;
		}
		if ( $to && $today > $to ) {
			return false;
		}
		return true;
	}

	/**
	 * @return array
	 */
	protected static function empty_items() {
		$items = array();
		foreach ( self::targets() as $key => $def ) {
			$items[ $key ] = array(
				'enabled' => false,
				'type'    => $def['default_type'],
				'amount'  => 0.0,
			);
		}
		return $items;
	}

	/**
	 * Normalize stored payload.
	 *
	 * @param mixed $raw Raw.
	 * @return array
	 */
	public static function normalize( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$items = array();
		foreach ( self::targets() as $key => $def ) {
			$row = ( isset( $raw['items'][ $key ] ) && is_array( $raw['items'][ $key ] ) )
				? $raw['items'][ $key ]
				: ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ? $raw[ $key ] : array() );
			$items[ $key ] = array(
				'enabled' => ! empty( $row['enabled'] ) && ( 'yes' === $row['enabled'] || true === $row['enabled'] || '1' === (string) $row['enabled'] ),
				'type'    => ( isset( $row['type'] ) && 'pct' === $row['type'] ) ? 'pct' : 'fixed',
				'amount'  => goldmate_positive_float( isset( $row['amount'] ) ? $row['amount'] : 0 ),
			);
			if ( empty( $row ) ) {
				$items[ $key ]['type'] = $def['default_type'];
			}
		}

		$enabled = ! empty( $raw['enabled'] ) && (
			true === $raw['enabled'] || 'yes' === $raw['enabled'] || '1' === (string) $raw['enabled']
		);

		return array(
			'enabled'   => $enabled,
			'items'     => $items,
			'date_from' => isset( $raw['date_from'] ) ? sanitize_text_field( $raw['date_from'] ) : '',
			'date_to'   => isset( $raw['date_to'] ) ? sanitize_text_field( $raw['date_to'] ) : '',
		);
	}

	/**
	 * Reads product discount settings (raw product layer, not resolved).
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function get( $product_id ) {
		$product_id = (int) $product_id;
		$parent_id  = wp_get_post_parent_id( $product_id );
		$owner_id   = $parent_id ? $parent_id : $product_id;

		$enabled = 'yes' === get_post_meta( $owner_id, '_goldmate_discounts_enabled', true );
		$raw     = get_post_meta( $owner_id, '_goldmate_discounts', true );
		$layer   = self::normalize(
			array(
				'enabled'   => $enabled ? 'yes' : 'no',
				'items'     => is_array( $raw ) ? $raw : array(),
				'date_from' => (string) get_post_meta( $owner_id, '_goldmate_discounts_from', true ),
				'date_to'   => (string) get_post_meta( $owner_id, '_goldmate_discounts_to', true ),
			)
		);
		return $layer;
	}

	/**
	 * @return array
	 */
	public static function get_global() {
		return self::normalize( get_option( self::GLOBAL_OPTION, array() ) );
	}

	/**
	 * @param int $term_id Term ID.
	 * @return array
	 */
	public static function get_term( $term_id ) {
		return self::normalize( get_term_meta( (int) $term_id, self::TERM_META, true ) );
	}

	/**
	 * @param int $product_id Product ID.
	 * @return int[]
	 */
	protected static function product_category_ids( $product_id ) {
		$parent = wp_get_post_parent_id( $product_id );
		$id     = $parent ? $parent : $product_id;
		$terms  = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		// Most specific category first ("Rings > Wedding" before "Rings"), so its
		// discount wins; equal depths keep WordPress's term order. usort() is
		// stable on PHP 8, and the index tiebreak keeps it stable on 7.4 too.
		$ranked = array();
		foreach ( array_values( array_map( 'intval', $terms ) ) as $i => $term_id ) {
			$ranked[] = array( $term_id, count( get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ), $i );
		}
		usort(
			$ranked,
			function ( $a, $b ) {
				return $b[1] <=> $a[1] ?: $a[2] <=> $b[2];
			}
		);

		return array_column( $ranked, 0 );
	}

	/**
	 * @param float  $amount Base.
	 * @param string $type   pct|fixed.
	 * @param float  $value  Value.
	 * @return array{amount:float,discount:float}
	 */
	public static function apply_one( $amount, $type, $value ) {
		$amount = (float) $amount;
		$value  = goldmate_positive_float( $value );

		if ( $amount <= 0 || $value <= 0 ) {
			return array(
				'amount'   => max( 0, $amount ),
				'discount' => 0.0,
			);
		}

		if ( 'pct' === $type ) {
			$discount = $amount * ( $value / 100 );
		} else {
			$discount = min( $amount, $value );
		}

		return array(
			'amount'   => max( 0, $amount - $discount ),
			'discount' => $discount,
		);
	}

	/**
	 * @param array  $discounts From resolve/get.
	 * @param string $key       Target.
	 * @return bool
	 */
	public static function is_active( $discounts, $key ) {
		return ! empty( $discounts['enabled'] )
			&& ! empty( $discounts['items'][ $key ]['enabled'] )
			&& goldmate_positive_float( $discounts['items'][ $key ]['amount'] ) > 0
			&& self::within_dates(
				isset( $discounts['date_from'] ) ? $discounts['date_from'] : '',
				isset( $discounts['date_to'] ) ? $discounts['date_to'] : ''
			);
	}

	/**
	 * Parse POST matrix into storage array.
	 *
	 * @param array  $post   POST.
	 * @param string $prefix Field prefix.
	 * @return array
	 */
	public static function parse_post( $post, $prefix = 'goldmate_disc' ) {
		$enabled = ! empty( $post[ $prefix . '_enabled' ] );
		$items   = array();
		foreach ( self::targets() as $key => $def ) {
			$items[ $key ] = array(
				'enabled' => ! empty( $post[ $prefix . '_on' ][ $key ] ) ? 'yes' : 'no',
				'type'    => ( isset( $post[ $prefix . '_type' ][ $key ] ) && 'pct' === $post[ $prefix . '_type' ][ $key ] ) ? 'pct' : 'fixed',
				'amount'  => isset( $post[ $prefix . '_amount' ][ $key ] ) ? goldmate_positive_float( wp_unslash( $post[ $prefix . '_amount' ][ $key ] ) ) : 0,
			);
		}
		return array(
			'enabled'   => $enabled ? 'yes' : 'no',
			'items'     => $items,
			'date_from' => isset( $post[ $prefix . '_from' ] ) ? sanitize_text_field( wp_unslash( $post[ $prefix . '_from' ] ) ) : '',
			'date_to'   => isset( $post[ $prefix . '_to' ] ) ? sanitize_text_field( wp_unslash( $post[ $prefix . '_to' ] ) ) : '',
		);
	}

	/**
	 * Admin global tab.
	 */
	public static function render_admin_tab() {
		$layer = self::get_global();
		?>
		<form method="post" action="<?php echo esc_url( Goldmate_Admin::url( 'discounts' ) ); ?>">
			<?php wp_nonce_field( 'goldmate_admin' ); ?>
			<input type="hidden" name="goldmate_action" value="save_discounts_global">
			<?php self::render_matrix( $layer, 'goldmate_disc' ); ?>
			<?php submit_button( 'ذخیره تغییرات' ); ?>
		</form>
		<?php
	}

	/**
	 * @param array  $layer  Layer data.
	 * @param string $prefix Input prefix.
	 */
	public static function render_matrix( $layer, $prefix ) {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th>فعال کردن تخفیف‌ها</th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>_enabled" value="1" <?php checked( ! empty( $layer['enabled'] ) ); ?>> فعال</label></td>
			</tr>
			<tr>
				<th>فعال از تاریخ</th>
				<td><input type="date" name="<?php echo esc_attr( $prefix ); ?>_from" value="<?php echo esc_attr( $layer['date_from'] ); ?>"></td>
			</tr>
			<tr>
				<th>فعال تا تاریخ</th>
				<td><input type="date" name="<?php echo esc_attr( $prefix ); ?>_to" value="<?php echo esc_attr( $layer['date_to'] ); ?>"></td>
			</tr>
		</table>
		<table class="widefat striped" style="max-width:900px;">
			<thead>
				<tr>
					<th>تخفیف روی</th>
					<th>فعال</th>
					<th>نوع تخفیف</th>
					<th>مقدار تخفیف</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( self::targets() as $key => $def ) : ?>
				<?php $row = $layer['items'][ $key ]; ?>
				<tr>
					<td><?php echo esc_html( $def['label'] ); ?><br><span class="description"><?php echo esc_html( $def['hint'] ); ?></span></td>
					<td><input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>_on[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $row['enabled'] ) ); ?>></td>
					<td>
						<select name="<?php echo esc_attr( $prefix ); ?>_type[<?php echo esc_attr( $key ); ?>]">
							<option value="pct" <?php selected( $row['type'], 'pct' ); ?>>درصدی</option>
							<option value="fixed" <?php selected( $row['type'], 'fixed' ); ?>>ثابت</option>
						</select>
					</td>
					<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $prefix ); ?>_amount[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $row['amount'] ); ?>"></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Category add screen.
	 */
	public static function render_term_fields_add() {
		echo '<div class="form-field"><h3>تخفیف گلدمیت</h3>';
		self::render_matrix( self::normalize( array() ), 'goldmate_disc' );
		echo '</div>';
	}

	/**
	 * @param WP_Term $term Term.
	 */
	public static function render_term_fields_edit( $term ) {
		$layer = self::get_term( $term->term_id );
		echo '<tr class="form-field"><th colspan="2"><h3>تخفیف گلدمیت</h3>';
		self::render_matrix( $layer, 'goldmate_disc' );
		echo '</th></tr>';
	}

	/**
	 * @param int $term_id Term ID.
	 */
	public static function save_term( $term_id ) {
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}
		$parsed = self::parse_post( $_POST, 'goldmate_disc' ); // phpcs:ignore
		update_term_meta( (int) $term_id, self::TERM_META, $parsed );
	}

	/**
	 * Save global from admin.
	 *
	 * @param array $post POST.
	 */
	public static function save_global_from_post( $post ) {
		update_option( self::GLOBAL_OPTION, self::parse_post( $post, 'goldmate_disc' ), false );
	}
}
