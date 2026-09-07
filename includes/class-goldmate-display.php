<?php
/**
 * Customer-facing price breakdown, the products-list column, and stale-rate handling.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Display {

	/**
	 * Registers front-end and product-list output.
	 */
	public static function init() {

		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_single_breakdown' ), 11 );
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'add_variation_breakdown' ), 10, 3 );
		add_action( 'wp_footer', array( __CLASS__, 'print_variation_script' ) );

		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );

		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'maybe_block_purchase' ), 20, 2 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_stale_notice' ), 9 );
	}

	/**
	 * True when the breakdown table should be shown at all.
	 *
	 * @return bool
	 */
	protected static function breakdown_enabled() {
		return 'yes' === goldmate_option( 'goldmate_show_breakdown' );
	}

	/**
	 * True when the formula line should be shown under the table.
	 *
	 * @return bool
	 */
	protected static function formula_enabled() {
		return 'yes' === goldmate_option( 'goldmate_show_formula' );
	}

	/**
	 * True when the product page has anything to print at all.
	 *
	 * @return bool
	 */
	protected static function anything_enabled() {
		return self::breakdown_enabled() || self::formula_enabled();
	}

	/**
	 * Builds the formula, both in words and with this product's numbers.
	 *
	 * @param array $b Breakdown from the calculator.
	 * @return string
	 */
	public static function render_formula( $b ) {

		$html = '<div class="goldmate-formula" style="margin:0 0 18px;font-size:.86em;line-height:1.9;opacity:.85;">';

		$html .= sprintf(
			'<div class="goldmate-formula-symbolic" style="font-weight:600;">%s</div>',
			esc_html( Goldmate_Calculator::formula_symbolic( $b ) )
		);

		// Isolating the expression keeps the mixed digits, parentheses and
		// currency words from reordering on an RTL page. A <bdi> would say the
		// same thing, but wp_kses_post() strips it.
		$html .= sprintf(
			'<div class="goldmate-formula-numeric"><span dir="auto" style="unicode-bidi:isolate;">%s</span></div>',
			esc_html( Goldmate_Calculator::formula_numeric( $b ) )
		);

		$html .= '</div>';

		return $html;
	}

	/**
	 * Builds everything shown for one product: the table, the formula, or both.
	 *
	 * @param array $b Breakdown from the calculator.
	 * @return string
	 */
	public static function render_block( $b ) {

		$html = '';

		if ( self::breakdown_enabled() ) {
			$html .= self::render_table( $b );
		}

		if ( self::formula_enabled() ) {
			$html .= self::render_formula( $b );
		}

		return $html;
	}

	/**
	 * Builds the breakdown table markup.
	 *
	 * @param array $b Breakdown from the calculator.
	 * @return string
	 */
	public static function render_table( $b ) {

		$html = '<table class="goldmate-breakdown shop_attributes" style="margin:12px 0 18px;width:100%;font-size:.92em;">';

		foreach ( Goldmate_Calculator::breakdown_rows( $b ) as $row ) {
			$html .= sprintf(
				'<tr><th style="text-align:start;font-weight:400;padding:4px 0;">%s</th><td style="text-align:end;padding:4px 0;">%s</td></tr>',
				esc_html( $row[0] ),
				wp_kses_post( wc_price( $row[1] ) )
			);
		}

		$html .= sprintf(
			'<tr><th style="text-align:start;font-weight:700;padding:6px 0;border-top:1px solid rgba(0,0,0,.12);">قیمت نهایی</th><td style="text-align:end;font-weight:700;padding:6px 0;border-top:1px solid rgba(0,0,0,.12);">%s</td></tr>',
			wp_kses_post( wc_price( $b['total'] ) )
		);

		$html .= '</table>';

		return $html;
	}

	/**
	 * Prints the breakdown for simple products, or the container for variable ones.
	 */
	public static function render_single_breakdown() {

		if ( ! self::anything_enabled() ) {
			return;
		}

		global $product;

		if ( ! $product ) {
			return;
		}

		if ( $product->is_type( 'variable' ) ) {
			// Filled in by JavaScript as the shopper picks a variation.
			echo '<div class="goldmate-breakdown-wrap" data-goldmate-wrap="1"></div>';
			return;
		}

		$b = Goldmate_Calculator::calculate( $product->get_id() );

		if ( false === $b ) {
			return;
		}

		echo wp_kses_post( self::render_block( $b ) );
	}

	/**
	 * Attaches each variation's breakdown markup to the variation form data.
	 *
	 * @param array                $data      Variation data sent to the browser.
	 * @param WC_Product_Variable  $parent    Parent product.
	 * @param WC_Product_Variation $variation Variation.
	 * @return array
	 */
	public static function add_variation_breakdown( $data, $parent, $variation ) {

		if ( ! self::anything_enabled() ) {
			return $data;
		}

		$b = Goldmate_Calculator::calculate( $variation->get_id() );

		$data['goldmate_breakdown_html'] = ( false === $b ) ? '' : self::render_block( $b );

		return $data;
	}

	/**
	 * Swaps the breakdown table in and out as the shopper changes variation.
	 */
	public static function print_variation_script() {

		if ( ! self::anything_enabled() || ! is_product() ) {
			return;
		}

		global $product;

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return;
		}
		?>
		<script>
		( function ( $ ) {
			if ( ! $ ) {
				return;
			}
			$( function () {
				var $wrap = $( '[data-goldmate-wrap]' );
				if ( ! $wrap.length ) {
					return;
				}
				$( '.variations_form' )
					.on( 'show_variation', function ( event, variation ) {
						$wrap.html( variation.goldmate_breakdown_html || '' );
					} )
					.on( 'hide_variation reset_data', function () {
						$wrap.empty();
					} );
			} );
		} )( window.jQuery );
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 *  Stale rate handling
	 * ------------------------------------------------------------------ */

	/**
	 * Blocks purchases of gold products while the rate is stale, if configured.
	 *
	 * @param bool       $purchasable Current state.
	 * @param WC_Product $product     Product.
	 * @return bool
	 */
	public static function maybe_block_purchase( $purchasable, $product ) {

		if ( ! $purchasable || 'block' !== goldmate_option( 'goldmate_stale_action' ) ) {
			return $purchasable;
		}

		if ( ! Goldmate_Rates::is_stale() ) {
			return $purchasable;
		}

		if ( false === Goldmate_Calculator::calculate( $product->get_id() ) ) {
			return $purchasable;
		}

		return false;
	}

	/**
	 * Explains on the product page why a gold product cannot be bought right now.
	 */
	public static function render_stale_notice() {

		if ( 'block' !== goldmate_option( 'goldmate_stale_action' ) || ! Goldmate_Rates::is_stale() ) {
			return;
		}

		global $product;

		if ( ! $product || false === Goldmate_Calculator::calculate( $product->get_id() ) ) {
			return;
		}

		echo '<p class="goldmate-stale-notice woocommerce-info" style="margin:10px 0;">قیمت روز طلا به‌روز نیست. برای خرید این محصول لطفاً تماس بگیرید.</p>';
	}

	/* ---------------------------------------------------------------------
	 *  Products list column
	 * ------------------------------------------------------------------ */

	/**
	 * Adds the gold column to the products list.
	 *
	 * @param array $cols Existing columns.
	 * @return array
	 */
	public static function add_column( $cols ) {

		$cols['goldmate'] = 'طلا';

		return $cols;
	}

	/**
	 * Renders the gold column for one product row.
	 *
	 * @param string $col     Column key.
	 * @param int    $post_id Product ID.
	 */
	public static function render_column( $col, $post_id ) {

		if ( 'goldmate' !== $col ) {
			return;
		}

		if ( 'yes' !== get_post_meta( $post_id, '_goldmate_enabled', true ) ) {
			echo '—';
			return;
		}

		$product = wc_get_product( $post_id );
		$weight  = goldmate_meta_float( $post_id, '_goldmate_weight' );
		$wage    = goldmate_meta_float( $post_id, '_goldmate_wage_pct' );

		if ( $product && $product->is_type( 'variable' ) ) {
			printf(
				'<span title="وزن هر متغیر جداگانه تنظیم می‌شود">%s متغیر / اجرت %s٪</span>',
				esc_html( number_format_i18n( count( $product->get_children() ) ) ),
				esc_html( wc_format_localized_decimal( $wage ) )
			);
			return;
		}

		printf(
			'%s گرم / اجرت %s٪',
			esc_html( wc_format_localized_decimal( $weight ) ),
			esc_html( wc_format_localized_decimal( $wage ) )
		);
	}
}
