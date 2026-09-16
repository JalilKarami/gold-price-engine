<?php
/**
 * Variation table inside the "نرخ خودکار" tab.
 *
 * A variable product's per-variation gold values — weight, karat, wage, stone,
 * leather and the manual-price switch — are edited in one table instead of
 * inside every variation's accordion. The table posts with the product form
 * and is saved before Goldmate_Pricing reprices the variations.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Variation_Table {

	/**
	 * Posted field holding the rows, keyed by variation ID.
	 */
	const FIELD = 'goldmate_variations';

	/**
	 * Per-variation numeric overrides: row key => meta key.
	 */
	const OVERRIDES = array(
		'wage_pct'   => '_goldmate_wage_pct',
		'wage_fixed' => '_goldmate_wage_fixed',
		'stone'      => '_goldmate_stone',
		'leather'    => '_goldmate_leather',
	);

	/**
	 * Registers the reload endpoint and the accordion pointer.
	 */
	public static function init() {

		add_action( 'wp_ajax_goldmate_variation_table', array( __CLASS__, 'ajax_table' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'render_accordion_pointer' ), 10, 3 );
	}

	/**
	 * Variation IDs in the order the Variations tab lists them.
	 *
	 * @param int $parent_id Parent product ID.
	 * @return int[]
	 */
	public static function variation_ids( $parent_id ) {

		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'   => 'product_variation',
					'post_parent' => (int) $parent_id,
					'post_status' => array( 'publish', 'private' ),
					'orderby'     => array(
						'menu_order' => 'ASC',
						'ID'         => 'DESC',
					),
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * Tells the accordion where the gold values went.
	 *
	 * @param int     $loop           Variation index.
	 * @param array   $variation_data Legacy variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function render_accordion_pointer( $loop, $variation_data, $variation ) {

		echo '<p class="form-row form-row-full goldmate-variation-pointer" style="clear:both;color:#646970;">وزن، عیار و اجرت این متغیر در تب «نرخ خودکار» وارد می‌شود.</p>';
	}

	/**
	 * AJAX: the table's HTML, for reloading after variations change.
	 */
	public static function ajax_table() {

		check_ajax_referer( 'goldmate_product_preview', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error();
		}

		ob_start();
		self::render( $post_id );

		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/**
	 * Prints the table.
	 *
	 * @param int $parent_id Parent product ID.
	 */
	public static function render( $parent_id ) {

		$ids = self::variation_ids( $parent_id );

		echo '<div class="goldmate-var-table-wrap">';

		if ( empty( $ids ) ) {
			echo '<p class="goldmate-hint description">هنوز متغیری ساخته نشده است. متغیرها را در تب «متغیرها» بسازید؛ جدول وزن‌ها اینجا نمایش داده می‌شود.</p>';
			echo '</div>';
			return;
		}

		$karats = array( '' => 'مثل محصول اصلی' );
		foreach ( array( '24', '22', '21', '18', '14', '10', '9' ) as $k ) {
			$karats[ $k ] = $k;
		}
		?>
		<table class="goldmate-var-table">
			<thead>
				<tr>
					<th>متغیر</th>
					<th>وزن (گرم)</th>
					<th>عیار</th>
					<th>قیمت</th>
					<th>قیمت دستی</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $ids as $id ) :
					$variation = wc_get_product( $id );
					$label     = $variation ? wc_get_formatted_variation( $variation, true, false ) : '';
					$label     = '' !== $label ? $label : '#' . $id;
					$name      = self::FIELD . '[' . $id . ']';
					$excluded  = 'yes' === get_post_meta( $id, '_goldmate_excluded', true );
					$karat     = get_post_meta( $id, '_goldmate_karat', true );
					$karat     = '' !== trim( (string) $karat ) ? (string) ( 0 + (float) $karat ) : '';
					$choices   = $karats;
					if ( '' !== $karat && ! isset( $choices[ $karat ] ) ) {
						$choices[ $karat ] = $karat;
					}

					$overrides = 0;
					foreach ( self::OVERRIDES as $meta ) {
						if ( '' !== trim( (string) get_post_meta( $id, $meta, true ) ) ) {
							$overrides++;
						}
					}
					?>
					<tr class="goldmate-var-row<?php echo $excluded ? ' is-excluded' : ''; ?>" data-id="<?php echo esc_attr( $id ); ?>">
						<td class="goldmate-var-label">
							<?php echo esc_html( $label ); ?>
							<input type="hidden" name="<?php echo esc_attr( $name ); ?>[present]" value="1">
						</td>
						<td>
							<input type="number" name="<?php echo esc_attr( $name ); ?>[weight]" value="<?php echo esc_attr( get_post_meta( $id, '_goldmate_weight', true ) ); ?>" step="0.001" min="0">
						</td>
						<td>
							<select name="<?php echo esc_attr( $name ); ?>[karat]">
								<?php foreach ( $choices as $value => $text ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $karat, (string) $value ); ?>><?php echo esc_html( $text ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td class="goldmate-var-price" data-var-price="<?php echo esc_attr( $id ); ?>">—</td>
						<td>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[excluded]" value="yes" <?php checked( $excluded ); ?> title="قیمت این متغیر دستی بماند و محاسبه نشود">
						</td>
						<td>
							<button type="button" class="button-link goldmate-var-more" aria-expanded="<?php echo $overrides ? 'true' : 'false'; ?>">
								اجرت و سنگ<?php echo $overrides ? ' (' . esc_html( (string) $overrides ) . ')' : ''; ?>
							</button>
						</td>
					</tr>
					<tr class="goldmate-var-extra" data-id="<?php echo esc_attr( $id ); ?>"<?php echo $overrides ? '' : ' hidden'; ?>>
						<td colspan="6">
							<div class="goldmate-var-extra-grid">
								<?php
								$fields = array(
									'wage_pct'   => array( 'اجرت (٪)', '0.01' ),
									'wage_fixed' => array( 'اجرت ثابت (تومان/گرم)', '1' ),
									'stone'      => array( 'قیمت سنگ', '1' ),
									'leather'    => array( 'قیمت چرم', '1' ),
								);
								foreach ( $fields as $key => $field ) :
									?>
									<label>
										<span><?php echo esc_html( $field[0] ); ?></span>
										<input type="number"
											name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>"
											value="<?php echo esc_attr( get_post_meta( $id, self::OVERRIDES[ $key ], true ) ); ?>"
											data-inherit="<?php echo esc_attr( $key ); ?>"
											step="<?php echo esc_attr( $field[1] ); ?>"
											min="0">
									</label>
								<?php endforeach; ?>
							</div>
							<p class="description">خالی = مثل محصول اصلی.</p>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		echo '</div>';
	}

	/**
	 * Reads one posted row into overrides: null means "same as the parent".
	 *
	 * @param array $row Unslashed posted row.
	 * @return array{weight:?float,karat:?float,wage_pct:?float,wage_fixed:?float,stone:?float,leather:?float,excluded:bool}
	 */
	public static function parse_row( $row ) {

		$row = is_array( $row ) ? $row : array();

		$value = function ( $key ) use ( $row ) {
			if ( ! isset( $row[ $key ] ) || '' === trim( (string) wc_clean( $row[ $key ] ) ) ) {
				return null;
			}
			return goldmate_positive_float( wc_clean( $row[ $key ] ) );
		};

		$parsed = array(
			'weight'   => $value( 'weight' ),
			'karat'    => $value( 'karat' ),
			'excluded' => isset( $row['excluded'] ) && 'yes' === $row['excluded'],
		);

		foreach ( array_keys( self::OVERRIDES ) as $key ) {
			$parsed[ $key ] = $value( $key );
		}

		return $parsed;
	}

	/**
	 * The wage mode a variation needs, or '' to follow the parent.
	 *
	 * With no wage override the variation inherits the parent's mode. With one,
	 * the mode is worked out from the wage it actually ends up with, the same
	 * rule the parent's fields follow.
	 *
	 * @param array  $row          Parsed row.
	 * @param float  $parent_pct   Parent wage percent.
	 * @param float  $parent_fixed Parent fixed wage.
	 * @param string $parent_mode  Parent wage mode.
	 * @return string
	 */
	public static function row_wage_mode( $row, $parent_pct, $parent_fixed, $parent_mode ) {

		if ( null === $row['wage_pct'] && null === $row['wage_fixed'] ) {
			return '';
		}

		return Goldmate_Product_Fields::derive_wage_mode(
			null !== $row['wage_pct'] ? $row['wage_pct'] : $parent_pct,
			null !== $row['wage_fixed'] ? $row['wage_fixed'] : $parent_fixed,
			$parent_mode
		);
	}

	/**
	 * Saves the posted rows onto the variations.
	 *
	 * Runs from Goldmate_Product_Fields::save_product_object(), before the
	 * product saves and Goldmate_Pricing reprices its variations.
	 *
	 * @param WC_Product $product Parent product, with this request's gold meta already set.
	 */
	public static function save( $product ) {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC verifies before this hook.
		if ( ! $product->is_type( 'variable' ) || ! isset( $_POST[ self::FIELD ] ) || ! is_array( $_POST[ self::FIELD ] ) ) {
			return;
		}

		$parent_id    = $product->get_id();
		$parent_pct   = goldmate_positive_float( $product->get_meta( '_goldmate_wage_pct', true ) );
		$parent_fixed = goldmate_positive_float( $product->get_meta( '_goldmate_wage_fixed', true ) );
		$parent_mode  = (string) $product->get_meta( '_goldmate_wage_mode', true );
		if ( '' === $parent_mode ) {
			$parent_mode = goldmate_option( 'goldmate_default_wage_mode' );
		}
		$parent_mode    = Goldmate_Calculator::normalize_wage_mode( $parent_mode );
		$parent_stone   = goldmate_positive_float( $product->get_meta( '_goldmate_stone', true ) );
		$parent_leather = goldmate_positive_float( $product->get_meta( '_goldmate_leather', true ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field in parse_row().
		foreach ( wp_unslash( $_POST[ self::FIELD ] ) as $id => $raw ) {

			$id = absint( $id );

			// Only this product's own variations.
			if ( ! $id || wp_get_post_parent_id( $id ) !== $parent_id || 'product_variation' !== get_post_type( $id ) ) {
				continue;
			}

			$row = self::parse_row( $raw );

			update_post_meta( $id, '_goldmate_excluded', $row['excluded'] ? 'yes' : 'no' );

			$meta = array_merge(
				array(
					'weight' => '_goldmate_weight',
					'karat'  => '_goldmate_karat',
				),
				self::OVERRIDES
			);

			foreach ( $meta as $key => $meta_key ) {
				if ( null === $row[ $key ] ) {
					delete_post_meta( $id, $meta_key );
				} else {
					update_post_meta( $id, $meta_key, $row[ $key ] );
				}
			}

			$mode = self::row_wage_mode( $row, $parent_pct, $parent_fixed, $parent_mode );
			if ( '' === $mode ) {
				delete_post_meta( $id, '_goldmate_wage_mode' );
			} else {
				update_post_meta( $id, '_goldmate_wage_mode', $mode );
			}

			// The calculator reads a variation's own accessories total before the
			// parent's, so it must cover the inherited half of stone/leather too.
			if ( null === $row['stone'] && null === $row['leather'] ) {
				delete_post_meta( $id, '_goldmate_accessories' );
			} else {
				update_post_meta(
					$id,
					'_goldmate_accessories',
					( null !== $row['stone'] ? $row['stone'] : $parent_stone ) + ( null !== $row['leather'] ? $row['leather'] : $parent_leather )
				);
			}
		}
	}
}
