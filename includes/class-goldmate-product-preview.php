<?php
/**
 * Live price preview on the product edit screen.
 *
 * While the shop manager types weight, wage or a discount, the gold tab shows
 * the price the product will get once saved. Nothing is written: the form's
 * unsaved values are run through Goldmate_Calculator, so the preview follows
 * the same formula, rate item, components, discounts and rounding as the
 * storefront.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Product_Preview {

	/**
	 * Registers the preview assets and AJAX endpoint.
	 */
	public static function init() {

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_goldmate_product_preview', array( __CLASS__, 'ajax_preview' ) );
	}

	/**
	 * Enqueues the preview script and styles on the product edit screen.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue_assets( $hook ) {

		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'goldmate-product-preview',
			GOLDMATE_URL . 'assets/css/product-preview.css',
			array(),
			self::asset_version( 'assets/css/product-preview.css' )
		);

		wp_enqueue_script(
			'goldmate-product-preview',
			GOLDMATE_URL . 'assets/js/product-preview.js',
			array( 'jquery' ),
			self::asset_version( 'assets/js/product-preview.js' ),
			true
		);

		wp_localize_script(
			'goldmate-product-preview',
			'goldmateProductPreview',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'goldmate_product_preview' ),
			)
		);
	}

	/**
	 * Cache-busting version for an asset: the file's mtime, so edits show up
	 * without bumping the plugin version.
	 *
	 * @param string $relative Path inside the plugin.
	 * @return string
	 */
	protected static function asset_version( $relative ) {

		$mtime = @filemtime( GOLDMATE_PATH . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return $mtime ? GOLDMATE_VERSION . '.' . $mtime : GOLDMATE_VERSION;
	}

	/**
	 * Prints the preview box. The script fills it in.
	 */
	public static function render_box() {
		?>
		<div class="goldmate-preview" aria-live="polite">
			<div class="goldmate-preview-head">
				<span class="goldmate-preview-title">پیش‌نمایش قیمت</span>
				<span class="goldmate-preview-total" data-preview="total">—</span>
			</div>
			<div class="goldmate-preview-body" data-preview="body">
				<p class="goldmate-preview-message">برای دیدن قیمت، وزن را وارد کنید.</p>
			</div>
			<p class="goldmate-preview-note">قیمت با مقادیر فعلی فرم و نرخ روز محاسبه می‌شود؛ تا «بروزرسانی» نزنید ذخیره نمی‌شود.</p>
		</div>
		<?php
	}

	/**
	 * AJAX: prices the product from the edit form's unsaved values.
	 */
	public static function ajax_preview() {

		check_ajax_referer( 'goldmate_product_preview', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ) );
		}

		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field is sanitised where it is read.

		$type    = isset( $post['product_type'] ) ? sanitize_key( $post['product_type'] ) : 'simple';
		$inputs  = self::inputs_from_form( $post );
		$formula = self::formula_from_form( $post );
		$layer   = self::discount_layer_from_form( $post );

		if ( 'variable' === $type ) {
			$rows = isset( $post[ Goldmate_Variation_Table::FIELD ] ) && is_array( $post[ Goldmate_Variation_Table::FIELD ] )
				? $post[ Goldmate_Variation_Table::FIELD ]
				: array();
			wp_send_json_success( self::variable_payload( $post_id, $inputs, $formula, $layer, $rows ) );
		}

		if ( $inputs['weight'] <= 0 ) {
			wp_send_json_error( array( 'message' => 'برای دیدن قیمت، وزن را وارد کنید.' ) );
		}

		$inputs['discounts'] = self::discounts_for( $post_id, $layer );
		$breakdown           = Goldmate_Calculator::calculate_with_formula( $inputs, $formula, $post_id );

		if ( false === $breakdown ) {
			wp_send_json_error( array( 'message' => 'قیمت روز طلا تنظیم نشده است.' ) );
		}

		wp_send_json_success(
			array(
				'total' => goldmate_plain_price( $breakdown['total'] ),
				'html'  => self::breakdown_html( $breakdown, $inputs['discounts'] ),
			)
		);
	}

	/**
	 * Builds calculator inputs from the gold tab's fields, mirroring what
	 * Goldmate_Product_Fields::save_product_object() would store.
	 *
	 * @param array $post Unslashed request.
	 * @return array
	 */
	protected static function inputs_from_form( $post ) {

		$num = function ( $key ) use ( $post ) {
			return isset( $post[ $key ] ) ? goldmate_positive_float( wc_clean( $post[ $key ] ) ) : 0.0;
		};

		$mode = Goldmate_Product_Fields::derive_wage_mode(
			isset( $post['_goldmate_wage_pct'] ) ? $post['_goldmate_wage_pct'] : '',
			isset( $post['_goldmate_wage_fixed'] ) ? $post['_goldmate_wage_fixed'] : '',
			goldmate_option( 'goldmate_default_wage_mode' )
		);

		$profit = '';
		if ( isset( $post['_goldmate_profit_pct'] ) && '' !== trim( (string) $post['_goldmate_profit_pct'] ) ) {
			$profit = $num( '_goldmate_profit_pct' );
		}

		$components = array();
		if ( isset( $post['_goldmate_component_values'] ) && is_array( $post['_goldmate_component_values'] ) ) {
			foreach ( $post['_goldmate_component_values'] as $id => $value ) {
				$id = sanitize_key( $id );
				if ( '' !== $id ) {
					$components[ $id ] = goldmate_positive_float( $value );
				}
			}
		}

		$karat   = $num( '_goldmate_karat' );
		$stone   = $num( '_goldmate_stone' );
		$leather = $num( '_goldmate_leather' );

		return array(
			'weight'           => $num( '_goldmate_weight' ),
			'karat'            => $karat > 0 ? $karat : 18,
			'wage_mode'        => Goldmate_Calculator::normalize_wage_mode( $mode ),
			'wage_pct'         => $num( '_goldmate_wage_pct' ),
			'wage_fixed'       => $num( '_goldmate_wage_fixed' ),
			'stone'            => $stone,
			'leather'          => $leather,
			'accessories'      => $stone + $leather,
			'profit_pct'       => $profit,
			'tax_exempt'       => ! empty( $post['_goldmate_tax_exempt'] ),
			'component_values' => $components,
		);
	}

	/**
	 * The formula picked in the form, falling back like Goldmate_Formulas::for_product().
	 *
	 * @param array $post Unslashed request.
	 * @return array|null
	 */
	protected static function formula_from_form( $post ) {

		if ( ! class_exists( 'Goldmate_Formulas' ) ) {
			return null;
		}

		$slug = isset( $post['_goldmate_formula'] ) ? sanitize_title( $post['_goldmate_formula'] ) : '';

		if ( '' !== $slug ) {
			$formula = Goldmate_Formulas::get_by_slug( $slug );
			if ( $formula && 'active' === $formula['status'] ) {
				return $formula;
			}
		}

		return Goldmate_Formulas::get_default();
	}

	/**
	 * The discount tab's unsaved product layer.
	 *
	 * @param array $post Unslashed request.
	 * @return array
	 */
	protected static function discount_layer_from_form( $post ) {

		$rows = Goldmate_Product_Fields::discount_rows_from_post( isset( $post['_goldmate_discounts'] ) ? $post['_goldmate_discounts'] : array() );

		return Goldmate_Discounts::normalize(
			array(
				'enabled'   => $rows['enabled'] ? 'yes' : 'no',
				'items'     => $rows['items'],
				'date_from' => isset( $post['_goldmate_discounts_from'] ) ? $post['_goldmate_discounts_from'] : '',
				'date_to'   => isset( $post['_goldmate_discounts_to'] ) ? $post['_goldmate_discounts_to'] : '',
			)
		);
	}

	/**
	 * Effective discounts: the form's product layer, else category, else global.
	 *
	 * @param int   $product_id Product or variation ID.
	 * @param array $layer      Unsaved product layer.
	 * @return array
	 */
	protected static function discounts_for( $product_id, $layer ) {

		// Always a non-empty array, so the calculator never falls back to the stored discounts.
		return Goldmate_Discounts::resolve( $product_id, $layer );
	}

	/**
	 * Prices every variation from the form: the variation table's typed values
	 * over the gold tab's parent values.
	 *
	 * @param int        $parent_id Parent product ID.
	 * @param array      $parent    Inputs from the gold tab.
	 * @param array|null $formula   Formula from the gold tab.
	 * @param array      $layer     Discount layer from the gold tab.
	 * @param array      $posted    Raw variation-table rows keyed by variation ID.
	 * @return array{total:string,html:string,prices:array<int,string>}
	 */
	protected static function variable_payload( $parent_id, $parent, $formula, $layer, $posted ) {

		$ids = Goldmate_Variation_Table::variation_ids( $parent_id );

		if ( empty( $ids ) ) {
			return array(
				'total'  => '—',
				'html'   => '<p class="goldmate-preview-message">هنوز متغیری ساخته نشده است.</p>',
				'prices' => array(),
			);
		}

		$discounts = self::discounts_for( $parent_id, $layer );
		$prices    = array();
		$totals    = array();

		foreach ( $ids as $id ) {

			// A row missing from the form (variation added since the page loaded)
			// is priced from its saved values.
			$row = isset( $posted[ $id ] ) ? Goldmate_Variation_Table::parse_row( $posted[ $id ] ) : self::saved_row( $id );

			if ( $row['excluded'] ) {
				$prices[ $id ] = 'قیمت دستی';
				continue;
			}

			$inputs = self::variation_inputs( $id, $parent, $row );

			if ( $inputs['weight'] <= 0 ) {
				$prices[ $id ] = 'وزن ندارد';
				continue;
			}

			$inputs['discounts'] = $discounts;
			$breakdown           = Goldmate_Calculator::calculate_with_formula( $inputs, $formula, $id );

			if ( false === $breakdown ) {
				$prices[ $id ] = '—';
				continue;
			}

			$totals[]      = (float) $breakdown['total'];
			$prices[ $id ] = goldmate_plain_price( $breakdown['total'] );
		}

		$total = '—';
		if ( $totals ) {
			$min   = min( $totals );
			$max   = max( $totals );
			$total = $min === $max
				? goldmate_plain_price( $min )
				: goldmate_plain_price( $min ) . ' – ' . goldmate_plain_price( $max );
		}

		return array(
			'total'  => $total,
			'html'   => '<p class="goldmate-preview-message">قیمت هر متغیر در جدول متغیرها، پایین همین تب.</p>' . self::discount_note( $discounts ),
			'prices' => $prices,
		);
	}

	/**
	 * A variation's saved values in the shape Goldmate_Variation_Table::parse_row() returns.
	 *
	 * @param int $variation_id Variation ID.
	 * @return array
	 */
	protected static function saved_row( $variation_id ) {

		$row = array( 'excluded' => 'yes' === get_post_meta( $variation_id, '_goldmate_excluded', true ) ? 'yes' : '' );

		foreach ( array_merge( array( 'weight' => '_goldmate_weight', 'karat' => '_goldmate_karat' ), Goldmate_Variation_Table::OVERRIDES ) as $key => $meta ) {
			$row[ $key ] = (string) get_post_meta( $variation_id, $meta, true );
		}

		return Goldmate_Variation_Table::parse_row( $row );
	}

	/**
	 * A variation's inputs: its row's overrides over the parent's form values,
	 * the same inheritance Goldmate_Calculator::resolve_inputs() applies once saved.
	 *
	 * @param int   $variation_id Variation ID.
	 * @param array $parent       Parent inputs from the form.
	 * @param array $row          Parsed variation row.
	 * @return array
	 */
	protected static function variation_inputs( $variation_id, $parent, $row ) {

		$inputs = $parent;

		foreach ( array( 'weight', 'karat', 'wage_pct', 'wage_fixed', 'stone', 'leather' ) as $key ) {
			if ( null !== $row[ $key ] ) {
				$inputs[ $key ] = $row[ $key ];
			}
		}

		if ( $inputs['karat'] <= 0 ) {
			$inputs['karat'] = 18;
		}

		$mode = Goldmate_Variation_Table::row_wage_mode( $row, $parent['wage_pct'], $parent['wage_fixed'], $parent['wage_mode'] );
		if ( '' !== $mode ) {
			$inputs['wage_mode'] = Goldmate_Calculator::normalize_wage_mode( $mode );
		}

		// Stored on the variation with no form field; the table leaves it alone.
		$profit = get_post_meta( $variation_id, '_goldmate_profit_pct', true );
		if ( '' !== trim( (string) $profit ) ) {
			$inputs['profit_pct'] = goldmate_positive_float( $profit );
		}

		$inputs['accessories'] = $inputs['stone'] + $inputs['leather'];

		// A variation's own component values win over the parent form's.
		$own_components = get_post_meta( $variation_id, '_goldmate_component_values', true );
		if ( is_array( $own_components ) ) {
			foreach ( $own_components as $id => $value ) {
				if ( '' !== trim( (string) $value ) ) {
					$inputs['component_values'][ sanitize_key( $id ) ] = goldmate_positive_float( $value );
				}
			}
		}

		return $inputs;
	}

	/**
	 * The itemised breakdown for a simple product.
	 *
	 * @param array $b         Breakdown.
	 * @param array $discounts Effective discounts.
	 * @return string
	 */
	protected static function breakdown_html( $b, $discounts ) {

		$html = '<table class="goldmate-preview-rows"><tbody>';

		foreach ( Goldmate_Calculator::breakdown_rows( $b ) as $row ) {
			// The discount-total row is a note with a zero amount, not a price line.
			$amount = ( 0.0 === (float) $row[1] && 0 === strpos( $row[0], 'جمع تخفیف' ) ) ? '' : goldmate_plain_price( $row[1] );
			$html  .= '<tr><td>' . esc_html( $row[0] ) . '</td><td>' . esc_html( $amount ) . '</td></tr>';
		}

		$html .= '<tr class="is-total"><td>قیمت نهایی</td><td>' . esc_html( goldmate_plain_price( $b['total'] ) ) . '</td></tr>';
		$html .= '</tbody></table>';

		return $html . self::discount_note( $discounts );
	}

	/**
	 * Says where an applied discount comes from when it is not this product's own.
	 *
	 * @param array $discounts Effective discounts.
	 * @return string
	 */
	protected static function discount_note( $discounts ) {

		$source = isset( $discounts['source'] ) ? $discounts['source'] : 'none';

		if ( 'category' === $source ) {
			return '<p class="goldmate-preview-message">تخفیف دسته‌بندی محصول اعمال شده است.</p>';
		}

		if ( 'global' === $source ) {
			return '<p class="goldmate-preview-message">تخفیف سراسری فروشگاه اعمال شده است.</p>';
		}

		return '';
	}
}
