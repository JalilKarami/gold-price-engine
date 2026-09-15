<?php
/**
 * Bulk tools: wage / formula / karat by category.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Tools {

	/**
	 * Admin tools tab UI.
	 */
	public static function render_admin_tab() {
		$cats      = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		$formulas = class_exists( 'Goldmate_Formulas' ) ? Goldmate_Formulas::choices( true ) : array();
		?>
		<form method="post" action="<?php echo esc_url( Goldmate_Admin::url( 'tools' ) ); ?>">
			<?php wp_nonce_field( 'goldmate_admin' ); ?>

			<h2>تغییر گروهی فرمول قیمت</h2>
			<table class="form-table">
				<tr>
					<th>دسته‌بندی</th>
					<td>
						<select name="goldmate_tool_cats[]" multiple size="6" style="min-width:260px;">
							<?php if ( ! is_wp_error( $cats ) ) : ?>
								<?php foreach ( $cats as $cat ) : ?>
									<option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?> (<?php echo (int) $cat->count; ?>)</option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th>فرمول</th>
					<td>
						<select name="goldmate_tool_formula">
							<?php foreach ( $formulas as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<button type="submit" class="button button-primary" name="goldmate_action" value="tool_set_formula">اعمال فرمول</button>

			<hr>

			<h2>تغییر گروهی اجرت ساخت</h2>
			<table class="form-table">
				<tr>
					<th>دسته‌بندی</th>
					<td>
						<select name="goldmate_tool_wage_cats[]" multiple size="6" style="min-width:260px;">
							<?php if ( ! is_wp_error( $cats ) ) : ?>
								<?php foreach ( $cats as $cat ) : ?>
									<option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th>نوع اجرت</th>
					<td>
						<select name="goldmate_tool_wage_mode">
							<option value="pct">درصدی</option>
							<option value="fixed">ثابت</option>
							<option value="combined">ترکیبی</option>
							<option value="">بدون تغییر نوع</option>
						</select>
					</td>
				</tr>
				<tr>
					<th>اجرت درصدی</th>
					<td><input type="number" step="0.01" name="goldmate_tool_wage_pct" value=""></td>
				</tr>
				<tr>
					<th>اجرت ثابت (تومان/گرم)</th>
					<td><input type="number" step="1" name="goldmate_tool_wage_fixed" value=""></td>
				</tr>
			</table>
			<button type="submit" class="button button-primary" name="goldmate_action" value="tool_set_wage">اعمال اجرت</button>

			<hr>

			<h2>تغییر گروهی عیار</h2>
			<table class="form-table">
				<tr>
					<th>دسته‌بندی</th>
					<td>
						<select name="goldmate_tool_karat_cats[]" multiple size="6" style="min-width:260px;">
							<?php if ( ! is_wp_error( $cats ) ) : ?>
								<?php foreach ( $cats as $cat ) : ?>
									<option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th>عیار</th>
					<td>
						<select name="goldmate_tool_karat">
							<option value="18">۱۸</option>
							<option value="21">۲۱</option>
							<option value="22">۲۲</option>
							<option value="24">۲۴</option>
						</select>
					</td>
				</tr>
			</table>
			<button type="submit" class="button button-primary" name="goldmate_action" value="tool_set_karat">اعمال عیار</button>

			<hr>

			<h2>تبدیل اجرت ساخت (سازگاری)</h2>
			<p class="description">محصولاتی که فقط اجرت درصدی دارند و حالت خالی است را روی حالت «درصدی» تنظیم می‌کند.</p>
			<button type="submit" class="button" name="goldmate_action" value="tool_convert_wage">تبدیل</button>
		</form>
		<?php
	}

	/**
	 * @param array $post POST.
	 * @return int Count.
	 */
	public static function set_formula( $post ) {
		$cats = isset( $post['goldmate_tool_cats'] ) ? array_map( 'intval', (array) $post['goldmate_tool_cats'] ) : array();
		$slug = isset( $post['goldmate_tool_formula'] ) ? sanitize_title( wp_unslash( $post['goldmate_tool_formula'] ) ) : '';
		if ( ! $cats || ! $slug || ! class_exists( 'Goldmate_Formulas' ) ) {
			return 0;
		}
		if ( ! Goldmate_Formulas::get_by_slug( $slug ) ) {
			return 0;
		}
		return self::map_products(
			$cats,
			function ( $product_id ) use ( $slug ) {
				update_post_meta( $product_id, '_goldmate_formula', $slug );
				delete_post_meta( $product_id, '_goldmate_rate_item' );
			}
		);
	}

	/**
	 * @deprecated 3.2.0 Use set_formula().
	 * @param array $post POST.
	 * @return int Count.
	 */
	public static function set_rate_item( $post ) {
		if ( isset( $post['goldmate_tool_rate_item'] ) && ! isset( $post['goldmate_tool_formula'] ) ) {
			$rate_slug = sanitize_title( wp_unslash( $post['goldmate_tool_rate_item'] ) );
			$formula   = class_exists( 'Goldmate_Formulas' )
				? Goldmate_Formulas::ensure_for_rate_slug( $rate_slug )
				: null;
			if ( $formula ) {
				$post['goldmate_tool_formula'] = $formula['slug'];
			}
		}
		return self::set_formula( $post );
	}

	/**
	 * @param array $post POST.
	 * @return int
	 */
	public static function set_wage( $post ) {
		$cats = isset( $post['goldmate_tool_wage_cats'] ) ? array_map( 'intval', (array) $post['goldmate_tool_wage_cats'] ) : array();
		$mode = isset( $post['goldmate_tool_wage_mode'] ) ? sanitize_key( wp_unslash( $post['goldmate_tool_wage_mode'] ) ) : '';
		$pct  = isset( $post['goldmate_tool_wage_pct'] ) && '' !== $post['goldmate_tool_wage_pct']
			? goldmate_positive_float( wp_unslash( $post['goldmate_tool_wage_pct'] ) ) : null;
		$fixed = isset( $post['goldmate_tool_wage_fixed'] ) && '' !== $post['goldmate_tool_wage_fixed']
			? goldmate_positive_float( wp_unslash( $post['goldmate_tool_wage_fixed'] ) ) : null;

		if ( ! $cats ) {
			return 0;
		}

		return self::map_products(
			$cats,
			function ( $product_id ) use ( $mode, $pct, $fixed ) {
				if ( $mode ) {
					update_post_meta( $product_id, '_goldmate_wage_mode', $mode );
				}
				if ( null !== $pct ) {
					update_post_meta( $product_id, '_goldmate_wage_pct', $pct );
				}
				if ( null !== $fixed ) {
					update_post_meta( $product_id, '_goldmate_wage_fixed', $fixed );
				}
			}
		);
	}

	/**
	 * @param array $post POST.
	 * @return int
	 */
	public static function set_karat( $post ) {
		$cats  = isset( $post['goldmate_tool_karat_cats'] ) ? array_map( 'intval', (array) $post['goldmate_tool_karat_cats'] ) : array();
		$karat = isset( $post['goldmate_tool_karat'] ) ? goldmate_positive_float( wp_unslash( $post['goldmate_tool_karat'] ) ) : 0;
		if ( ! $cats || $karat <= 0 ) {
			return 0;
		}
		return self::map_products(
			$cats,
			function ( $product_id ) use ( $karat ) {
				update_post_meta( $product_id, '_goldmate_karat', $karat );
			}
		);
	}

	/**
	 * @return int
	 */
	public static function convert_wage_modes() {
		$q = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_goldmate_enabled',
				'meta_value'     => 'yes',
			)
		);
		$n = 0;
		foreach ( $q->posts as $id ) {
			$mode = get_post_meta( $id, '_goldmate_wage_mode', true );
			if ( '' === trim( (string) $mode ) ) {
				update_post_meta( $id, '_goldmate_wage_mode', 'pct' );
				$n++;
			}
		}
		return $n;
	}

	/**
	 * @param int[]    $term_ids Categories.
	 * @param callable $cb       Callback(product_id).
	 * @return int
	 */
	protected static function map_products( $term_ids, $cb ) {
		$q = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => $term_ids,
					),
				),
				'meta_key'       => '_goldmate_enabled',
				'meta_value'     => 'yes',
			)
		);
		$n = 0;
		foreach ( $q->posts as $id ) {
			call_user_func( $cb, (int) $id );
			if ( class_exists( 'Goldmate_Pricing' ) ) {
				Goldmate_Pricing::apply( (int) $id );
			}
			$n++;
		}
		return $n;
	}
}
