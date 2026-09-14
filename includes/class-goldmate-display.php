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

		add_shortcode( 'goldmate_price_banner', array( __CLASS__, 'render_price_banner' ) );
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
		if ( class_exists( 'Goldmate_General' ) && ! Goldmate_General::can_see_details() ) {
			return false;
		}
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
	 *  Site-wide price banner
	 * ------------------------------------------------------------------ */

	/**
	 * The rate that every product in the catalogue is currently priced at.
	 *
	 * `goldmate_rate_per_gram` is updated the instant a new rate is accepted,
	 * but Goldmate_Batch then takes some time — background chunks, not one
	 * blocking request — to actually reprice every product against it. Reading
	 * the option directly during that window would show a number ahead of what
	 * shoppers can actually buy at, so while a reprice is running this falls
	 * back to the previous rate, which by definition already reached every
	 * product before the new one was accepted.
	 *
	 * @return float Zero when no rate has ever finished applying to the catalogue.
	 */
	protected static function applied_rate() {

		if ( class_exists( 'Goldmate_Live' ) ) {
			return Goldmate_Live::applied_rate();
		}

		if ( ! Goldmate_Batch::is_running() ) {
			return goldmate_positive_float( goldmate_option( 'goldmate_rate_per_gram' ) );
		}

		$history = Goldmate_Rates::history();

		return isset( $history[1]['rate'] ) ? goldmate_positive_float( $history[1]['rate'] ) : 0.0;
	}

	/**
	 * Renders the [goldmate_price_banner] shortcode.
	 *
	 * Always reflects what the catalogue is actually priced at right now —
	 * never the shop's raw API feed, and never a rate whose reprice hasn't
	 * finished reaching every product yet — so it can never disagree with the
	 * prices shoppers see on product pages.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_price_banner( $atts ) {

		$atts = shortcode_atts(
			array(
				'label'     => 'قیمت هر گرم طلای ۱۸ عیار',
				'show_time' => 'yes',
			),
			$atts,
			'goldmate_price_banner'
		);

		$updating = Goldmate_Batch::is_running();
		$rate     = self::applied_rate();

		if ( $rate <= 0 ) {
			return '';
		}

		$stale = ! $updating && Goldmate_Rates::is_stale();

		$dot_color = $updating ? '#fa941a' : ( $stale ? '#ef5350' : '#26a69a' );

		$html  = sprintf(
			'<div class="goldmate-price-banner%s" style="display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;padding:6px 12px;font-size:.9em;line-height:1.6;">',
			$updating ? ' goldmate-price-banner--updating' : ( $stale ? ' goldmate-price-banner--stale' : '' )
		);

		$html .= sprintf(
			'<span class="goldmate-price-banner-dot" style="width:8px;height:8px;border-radius:50%%;display:inline-block;background:%s;"></span>',
			$dot_color
		);

		$html .= sprintf( '<span class="goldmate-price-banner-label">%s:</span>', esc_html( $atts['label'] ) );
		$html .= sprintf( '<strong class="goldmate-price-banner-value">%s</strong>', wp_kses_post( wc_price( $rate ) ) );

		if ( 'yes' === $atts['show_time'] && ! $updating ) {
			$updated = (int) get_option( 'goldmate_rate_updated_at', 0 );
			$html   .= sprintf(
				'<span class="goldmate-price-banner-time" style="opacity:.7;">به‌روزرسانی: %s</span>',
				esc_html( goldmate_format_time( $updated ) )
			);
		}

		if ( $updating ) {
			$html .= '<span class="goldmate-price-banner-updating-label" style="color:#fa941a;">(در حال به‌روزرسانی قیمت محصولات…)</span>';
		} elseif ( $stale ) {
			$html .= '<span class="goldmate-price-banner-stale-label" style="color:#ef5350;">(قیمت به‌روز نیست)</span>';
		}

		$html .= '</div>';

		return $html;
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
		$mode    = get_post_meta( $post_id, '_goldmate_wage_mode', true );
		$mode    = Goldmate_Calculator::normalize_wage_mode(
			'' !== trim( (string) $mode ) ? $mode : goldmate_option( 'goldmate_default_wage_mode' )
		);
		$wage_label = ( 'fixed' === $mode )
			? sprintf( 'اجرت %s ت/گ', wc_format_localized_decimal( goldmate_meta_float( $post_id, '_goldmate_wage_fixed' ) ) )
			: sprintf( 'اجرت %s٪', wc_format_localized_decimal( $wage ) );

		if ( $product && $product->is_type( 'variable' ) ) {
			printf(
				'<span title="وزن هر متغیر جداگانه تنظیم می‌شود">%s متغیر / %s</span>',
				esc_html( number_format_i18n( count( $product->get_children() ) ) ),
				esc_html( $wage_label )
			);
			return;
		}

		printf(
			'%s گرم / %s',
			esc_html( wc_format_localized_decimal( $weight ) ),
			esc_html( $wage_label )
		);
	}
}
