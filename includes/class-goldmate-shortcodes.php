<?php
/**
 * Storefront shortcodes for gold price components, and the admin shortcodes registry.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Shortcodes {

	/**
	 * Registers shortcodes.
	 */
	public static function init() {

		add_shortcode( 'goldmate_details', array( __CLASS__, 'render_details' ) );
		add_shortcode( 'goldmate_price_field', array( __CLASS__, 'render_price_field' ) );
		add_shortcode( 'goldmate_price', array( __CLASS__, 'render_price_board' ) );
	}

	/**
	 * Default titles for editable shortcode labels.
	 *
	 * @return array<string,string>
	 */
	public static function default_titles() {
		return array(
			'weight'               => 'وزن:',
			'karat'                => 'عیار:',
			'gold'                 => 'مبلغ طلا:',
			'jewel'                => 'قیمت جواهر:',
			'wage'                 => 'اجرت ساخت:',
			'profit'               => 'سود:',
			'accessories'          => 'ملحقات:',
			'tax'                  => 'مالیات:',
			'total'                => 'قیمت نهایی:',
			'final_price'          => 'قیمت نهایی:',
			'final_price_per_gram' => 'قیمت نهایی هر گرم:',
			'raw_price_per_gram'   => 'قیمت هر گرم بدون متعلقات:',
			'last_calculation'     => 'زمان آخرین محاسبه قیمت:',
			'item_name'            => 'نوع آیتم:',
			'rate'                 => 'قیمت هر گرم:',
			'equal_to'             => 'معادل:',
		);
	}

	/**
	 * Saved title for a shortcode key, falling back to the default.
	 *
	 * @param string $key Title key.
	 * @return string
	 */
	public static function title( $key ) {

		$defaults = self::default_titles();
		$saved    = get_option( 'goldmate_shortcode_titles', array() );

		if ( is_array( $saved ) && isset( $saved[ $key ] ) && '' !== trim( (string) $saved[ $key ] ) ) {
			return (string) $saved[ $key ];
		}

		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}

	/**
	 * Persists editable shortcode titles from the admin tab.
	 *
	 * @param array $post Raw $_POST.
	 */
	public static function save_titles( $post ) {

		$defaults = self::default_titles();
		$saved    = array();

		foreach ( $defaults as $key => $default ) {
			$field = 'goldmate_sc_title_' . $key;
			if ( ! isset( $post[ $field ] ) ) {
				continue;
			}
			$saved[ $key ] = sanitize_text_field( wp_unslash( $post[ $field ] ) );
		}

		update_option( 'goldmate_shortcode_titles', $saved, false );
	}

	/**
	 * Registry used by the admin shortcodes tab.
	 *
	 * Each row: key, usage, editable title key (or empty), example shortcode builder.
	 *
	 * @return array[]
	 */
	public static function catalog() {

		$t = array(
			'weight'      => self::title( 'weight' ),
			'karat'       => self::title( 'karat' ),
			'gold'        => self::title( 'gold' ),
			'wage'        => self::title( 'wage' ),
			'profit'      => self::title( 'profit' ),
			'accessories' => self::title( 'accessories' ),
			'tax'         => self::title( 'tax' ),
			'total'       => self::title( 'total' ),
			'rate'        => self::title( 'rate' ),
			'equal_to'    => self::title( 'equal_to' ),
		);

		return array(
			array(
				'id'       => 'weight',
				'usage'    => 'نمایش وزن محصول',
				'title_key'=> 'weight',
				'shortcode'=> "[goldmate_details key='weight' title='{$t['weight']}']",
			),
			array(
				'id'       => 'karat',
				'usage'    => 'نمایش عیار محصول',
				'title_key'=> 'karat',
				'shortcode'=> "[goldmate_details key='karat' title='{$t['karat']}']",
			),
			array(
				'id'       => 'gold',
				'usage'    => 'نمایش مبلغ طلای محصول',
				'title_key'=> 'gold',
				'shortcode'=> "[goldmate_details key='gold' title='{$t['gold']}']",
			),
			array(
				'id'       => 'wage',
				'usage'    => 'نمایش اجرت ساخت محصول',
				'title_key'=> 'wage',
				'shortcode'=> "[goldmate_details key='wage' title='{$t['wage']}']",
			),
			array(
				'id'       => 'wage_equal',
				'usage'    => 'نمایش اجرت ساخت به‌همراه معادل مبلغی (اگر درصدی باشد)',
				'title_key'=> 'wage',
				'same_as'  => 'wage',
				'shortcode'=> "[goldmate_details key='wage' title='{$t['wage']}' show_equal_to='1' equal_to_label='{$t['equal_to']}']",
			),
			array(
				'id'       => 'profit',
				'usage'    => 'نمایش سود محصول',
				'title_key'=> 'profit',
				'shortcode'=> "[goldmate_details key='profit' title='{$t['profit']}']",
			),
			array(
				'id'       => 'profit_equal',
				'usage'    => 'نمایش سود به‌همراه معادل مبلغی',
				'title_key'=> 'profit',
				'same_as'  => 'profit',
				'shortcode'=> "[goldmate_details key='profit' title='{$t['profit']}' show_equal_to='1' equal_to_label='{$t['equal_to']}']",
			),
			array(
				'id'       => 'accessories',
				'usage'    => 'نمایش ملحقات (سنگ و چرم)',
				'title_key'=> 'accessories',
				'shortcode'=> "[goldmate_details key='accessories' title='{$t['accessories']}']",
			),
			array(
				'id'       => 'tax',
				'usage'    => 'نمایش مالیات محصول',
				'title_key'=> 'tax',
				'shortcode'=> "[goldmate_details key='tax' title='{$t['tax']}']",
			),
			array(
				'id'       => 'tax_equal',
				'usage'    => 'نمایش مالیات به‌همراه معادل مبلغی',
				'title_key'=> 'tax',
				'same_as'  => 'tax',
				'shortcode'=> "[goldmate_details key='tax' title='{$t['tax']}' show_equal_to='1' equal_to_label='{$t['equal_to']}']",
			),
			array(
				'id'       => 'rate',
				'usage'    => 'نمایش قیمت هر گرم (متناسب با عیار محصول)',
				'title_key'=> 'rate',
				'shortcode'=> "[goldmate_details key='rate' title='{$t['rate']}']",
			),
			array(
				'id'       => 'total',
				'usage'    => 'نمایش قیمت نهایی محصول',
				'title_key'=> 'total',
				'shortcode'=> "[goldmate_details key='total' title='{$t['total']}']",
			),
			array(
				'id'       => 'final_per_gram',
				'usage'    => 'قیمت نهایی هر گرم',
				'title_key'=> 'final_price_per_gram',
				'shortcode'=> "[goldmate_details key='final_price_per_gram' title='" . self::title( 'final_price_per_gram' ) . "']",
			),
			array(
				'id'       => 'raw_per_gram',
				'usage'    => 'قیمت هر گرم بدون متعلقات',
				'title_key'=> 'raw_price_per_gram',
				'shortcode'=> "[goldmate_details key='raw_price_per_gram' title='" . self::title( 'raw_price_per_gram' ) . "']",
			),
			array(
				'id'       => 'last_calc',
				'usage'    => 'زمان آخرین محاسبه قیمت محصول',
				'title_key'=> 'last_calculation',
				'shortcode'=> "[goldmate_details key='last_calculation' title='" . self::title( 'last_calculation' ) . "']",
			),
			array(
				'id'       => 'item_name',
				'usage'    => 'نام آیتم نرخ (از فرمول محصول)',
				'title_key'=> 'item_name',
				'shortcode'=> "[goldmate_details key='item_name' title='" . self::title( 'item_name' ) . "']",
			),
			array(
				'id'       => 'all_details',
				'usage'    => 'نمایش همه‌ی اجزای بالا با هم',
				'title_key'=> '',
				'shortcode'=> '[goldmate_details]',
			),
			array(
				'id'       => 'price_field',
				'usage'    => 'نمایش جدول کامل اجزای قیمت',
				'title_key'=> '',
				'shortcode'=> '[goldmate_price_field]',
			),
			array(
				'id'       => 'price_board',
				'usage'    => 'تابلو نرخ یک آیتم کاتالوگ با به‌روزرسانی آنی',
				'title_key'=> '',
				'shortcode'=> '[goldmate_price item="gold18" price="1" increase="1" decrease="1" title="1" currency="1" date="1" live_update="1" live_update_colorize="1"]',
			),
			array(
				'id'       => 'calculator',
				'usage'    => 'ماشین‌حساب طلا (عمودی)',
				'title_key'=> '',
				'shortcode'=> '[goldmate_calculator]',
			),
			array(
				'id'       => 'calculator_h',
				'usage'    => 'ماشین‌حساب طلا (افقی)',
				'title_key'=> '',
				'shortcode'=> "[goldmate_calculator orientation='horizontal' title='محاسبه‌گر طلا']",
			),
			array(
				'id'       => 'rate_board',
				'usage'    => 'تابلو نرخ طلای ۱۸ عیار',
				'title_key'=> '',
				'shortcode'=> '[goldmate_rate_board]',
			),
			array(
				'id'       => 'price_banner',
				'usage'    => 'بنر قیمت روز طلا',
				'title_key'=> '',
				'shortcode'=> '[goldmate_price_banner]',
			),
			array(
				'id'       => 'equal_label',
				'usage'    => 'برچسب پیش‌فرض «معادل» در شورتکدهای دارای show_equal_to',
				'title_key'=> 'equal_to',
				'shortcode'=> "equal_to_label='" . $t['equal_to'] . "'",
			),
		);
	}

	/**
	 * Resolves the current product for component shortcodes.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return int Product or variation ID, or 0.
	 */
	protected static function resolve_product_id( $atts ) {

		if ( ! empty( $atts['id'] ) ) {
			return absint( $atts['id'] );
		}

		global $product;

		if ( $product && is_a( $product, 'WC_Product' ) ) {
			if ( $product->is_type( 'variation' ) ) {
				return (int) $product->get_id();
			}
			if ( $product->is_type( 'variable' ) ) {
				// Prefer selected variation from request when present.
				if ( ! empty( $_REQUEST['variation_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					return absint( $_REQUEST['variation_id'] );
				}
				return (int) $product->get_id();
			}
			return (int) $product->get_id();
		}

		return get_the_ID() ? (int) get_the_ID() : 0;
	}

	/**
	 * [goldmate_details] — one component or all.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_details( $atts ) {

		$atts = shortcode_atts(
			array(
				'key'            => '',
				'title'          => '',
				'show_equal_to'  => '0',
				'equal_to_label' => self::title( 'equal_to' ),
				'id'             => '',
			),
			$atts,
			'goldmate_details'
		);

		$manual = ( '' === trim( (string) $atts['id'] ) && ! is_product() );

		if ( class_exists( 'Goldmate_General' ) && Goldmate_General::shortcodes_oos( $manual ) ) {
			return Goldmate_General::shortcode_oos_html();
		}

		$product_id = self::resolve_product_id( $atts );

		if ( $product_id && 'yes' === goldmate_option( 'goldmate_hide_shortcode_outofstock' ) ) {
			$product = wc_get_product( $product_id );
			if ( $product && ! $product->is_in_stock() ) {
				return '';
			}
		}

		$b = $product_id ? Goldmate_Calculator::calculate( $product_id ) : false;

		if ( false === $b ) {
			return '';
		}

		$key = sanitize_key( $atts['key'] );

		if ( '' === $key ) {
			return self::render_all_details( $b, $atts );
		}

		$row = self::component_row( $key, $b, $atts );

		if ( null === $row ) {
			return '';
		}

		return self::format_row_html( $row, 'goldmate-details goldmate-details--' . $key );
	}

	/**
	 * [goldmate_price_field] — full breakdown table.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_price_field( $atts ) {

		$atts = shortcode_atts(
			array(
				'id' => '',
			),
			$atts,
			'goldmate_price_field'
		);

		$manual = ( '' === trim( (string) $atts['id'] ) && ! is_product() );

		if ( class_exists( 'Goldmate_General' ) && Goldmate_General::shortcodes_oos( $manual ) ) {
			return Goldmate_General::shortcode_oos_html();
		}

		$product_id = self::resolve_product_id( $atts );

		if ( $product_id && 'yes' === goldmate_option( 'goldmate_hide_shortcode_outofstock' ) ) {
			$product = wc_get_product( $product_id );
			if ( $product && ! $product->is_in_stock() ) {
				return '';
			}
		}

		$b = $product_id ? Goldmate_Calculator::calculate( $product_id ) : false;

		if ( false === $b ) {
			return '';
		}

		// Explicit shortcode placement always shows the table, even when the
		// product-page "show breakdown" toggle is off.
		return Goldmate_Display::render_block( $b, true );
	}

	/**
	 * Builds every detail row.
	 *
	 * @param array $b    Breakdown.
	 * @param array $atts Attributes.
	 * @return string
	 */
	protected static function render_all_details( $b, $atts ) {

		$keys = array( 'weight', 'karat', 'rate', 'gold', 'wage', 'profit', 'accessories', 'tax', 'total' );
		$html = '<div class="goldmate-details goldmate-details--all">';

		foreach ( $keys as $key ) {
			$row_atts         = $atts;
			$row_atts['key']  = $key;
			$row_atts['title'] = self::title( $key );
			$row              = self::component_row( $key, $b, $row_atts );
			if ( null === $row ) {
				continue;
			}
			$html .= self::format_row_html( $row, 'goldmate-details-row goldmate-details--' . $key );
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * One component's label/value(/equal) data.
	 *
	 * @param string $key  Component key.
	 * @param array  $b    Breakdown.
	 * @param array  $atts Attributes.
	 * @return array{label:string,value:string,equal?:string}|null
	 */
	protected static function component_row( $key, $b, $atts ) {

		$label = '' !== trim( (string) $atts['title'] ) ? $atts['title'] : self::title( $key );
		$show_equal = ( '1' === (string) $atts['show_equal_to'] || 'yes' === $atts['show_equal_to'] );
		$equal_label = $atts['equal_to_label'];

		switch ( $key ) {
			case 'weight':
				return array(
					'label' => $label,
					'value' => wc_format_localized_decimal( $b['weight'] ) . ' گرم',
				);

			case 'karat':
				return array(
					'label' => $label,
					'value' => wc_format_localized_decimal( $b['karat'] ),
				);

			case 'rate':
				return array(
					'label' => $label,
					'value' => goldmate_plain_price( $b['rate'] ),
				);

			case 'gold':
				return array(
					'label' => $label,
					'value' => goldmate_plain_price( $b['gold'] ),
				);

			case 'wage':
				$row = array(
					'label' => $label,
					'value' => self::wage_display_value( $b ),
				);
				if ( $show_equal && 'pct' === Goldmate_Calculator::normalize_wage_mode( $b['wage_mode'] ) ) {
					$row['equal'] = $equal_label . ' ' . goldmate_plain_price( $b['wage'] );
				} elseif ( $show_equal && 'combined' === Goldmate_Calculator::normalize_wage_mode( $b['wage_mode'] ) ) {
					$row['equal'] = $equal_label . ' ' . goldmate_plain_price( $b['wage'] );
				}
				return $row;

			case 'profit':
				$row = array(
					'label' => $label,
					'value' => wc_format_localized_decimal( $b['profit_pct'] ) . '٪',
				);
				if ( $show_equal ) {
					$row['equal'] = $equal_label . ' ' . goldmate_plain_price( $b['profit'] );
				}
				return $row;

			case 'accessories':
			case 'jewel':
				if ( $b['accessories'] <= 0 ) {
					return null;
				}
				return array(
					'label' => $label ? $label : self::title( 'accessories' ),
					'value' => goldmate_plain_price( $b['accessories'] ),
				);

			case 'tax':
				$row = array(
					'label' => $label,
					'value' => wc_format_localized_decimal( $b['tax_pct'] ) . '٪',
				);
				if ( $show_equal ) {
					$row['equal'] = $equal_label . ' ' . goldmate_plain_price( $b['tax'] );
				}
				return $row;

			case 'total':
			case 'final_price':
				return array(
					'label' => $label ? $label : self::title( 'final_price' ),
					'value' => goldmate_plain_price( $b['total'] ),
				);

			case 'final_price_per_gram':
				$per = ( $b['weight'] > 0 ) ? ( $b['total'] / $b['weight'] ) : 0;
				return array(
					'label' => $label ? $label : self::title( 'final_price_per_gram' ),
					'value' => goldmate_plain_price( $per ),
				);

			case 'raw_price_per_gram':
				$raw = ( $b['weight'] > 0 ) ? ( ( $b['gold'] + $b['wage'] + $b['profit'] ) / $b['weight'] ) : 0;
				return array(
					'label' => $label ? $label : self::title( 'raw_price_per_gram' ),
					'value' => goldmate_plain_price( $raw ),
				);

			case 'last_calculation':
				$pid = ! empty( $b['product_id'] ) ? (int) $b['product_id'] : 0;
				$at  = $pid ? (int) get_post_meta( $pid, '_goldmate_priced_at', true ) : 0;
				return array(
					'label' => $label ? $label : self::title( 'last_calculation' ),
					'value' => $at ? goldmate_format_time( $at ) : '—',
				);

			case 'item_name':
				$slug = ! empty( $b['rate_item'] ) ? $b['rate_item'] : '';
				$name = $slug;
				if ( $slug && class_exists( 'Goldmate_Rate_Items' ) ) {
					$item = Goldmate_Rate_Items::get_by_slug( $slug );
					if ( $item ) {
						$name = $item['label'];
					}
				}
				return array(
					'label' => $label ? $label : self::title( 'item_name' ),
					'value' => $name ? $name : '—',
				);
		}

		return null;
	}

	/**
	 * [goldmate_price] — live rate board for a catalog item.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function render_price_board( $atts ) {

		$atts = shortcode_atts(
			array(
				'item'               => 'gold18',
				'price'              => '1',
				'increase'           => '1',
				'decrease'           => '1',
				'title'              => '1',
				'currency'           => '1',
				'date'               => '1',
				'live_update'        => '1',
				'live_update_colorize' => '1',
				'separator'          => ' ',
			),
			$atts,
			'goldmate_price'
		);

		$item = class_exists( 'Goldmate_Rate_Items' )
			? Goldmate_Rate_Items::get_by_slug( sanitize_title( $atts['item'] ) )
			: null;

		if ( ! $item || (float) $item['rate'] <= 0 ) {
			if ( class_exists( 'Goldmate_General' ) && Goldmate_General::shortcodes_oos( true ) ) {
				return Goldmate_General::shortcode_oos_html();
			}
			return '';
		}

		$change = Goldmate_Live::change_pct( (int) $item['id'] );
		if ( class_exists( 'Goldmate_General' ) && Goldmate_General::hide_price_change() ) {
			$change = null;
		}

		$dir   = null === $change ? 'flat' : ( $change > 0 ? 'up' : ( $change < 0 ? 'down' : 'flat' ) );
		$color = ( '1' === $atts['live_update_colorize'] )
			? ( 'up' === $dir ? '#1e7e34' : ( 'down' === $dir ? '#b32d2e' : '#666' ) )
			: '#666';

		$show_inc = '1' === $atts['increase'] && $change !== null && $change > 0;
		$show_dec = '1' === $atts['decrease'] && $change !== null && $change < 0;
		$show_chg = $show_inc || $show_dec || ( '1' === $atts['increase'] && '1' === $atts['decrease'] && null !== $change );

		ob_start();
		?>
		<div class="goldmate-price-board"
			data-goldmate-board="1"
			data-goldmate-item="<?php echo esc_attr( $item['slug'] ); ?>"
			data-live="<?php echo esc_attr( $atts['live_update'] ); ?>"
			style="padding:14px 16px;border:1px solid rgba(0,0,0,.08);border-radius:8px;max-width:420px;line-height:1.7;">
			<?php if ( '1' === $atts['title'] ) : ?>
				<div style="font-weight:600;margin-bottom:6px;"><?php echo esc_html( $item['label'] ); ?></div>
			<?php endif; ?>
			<?php if ( '1' === $atts['price'] ) : ?>
				<div data-goldmate-board-rate style="font-size:1.35em;font-weight:700;">
					<?php echo wp_kses_post( wc_price( $item['rate'] ) ); ?>
					<?php if ( '1' === $atts['currency'] ) : ?>
						<span style="font-size:.7em;font-weight:500;opacity:.8;"><?php echo esc_html( get_woocommerce_currency_symbol() ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:8px;font-size:.9em;opacity:.9;">
				<?php if ( $show_chg ) : ?>
					<span>تغییر: <strong data-goldmate-board-change data-dir="<?php echo esc_attr( $dir ); ?>" style="color:<?php echo esc_attr( $color ); ?>;">
						<?php echo esc_html( Goldmate_Live::format_change_pct( $change ) ); ?>
					</strong></span>
				<?php endif; ?>
				<?php if ( '1' === $atts['date'] ) : ?>
					<span>به‌روزرسانی: <span data-goldmate-board-time><?php echo esc_html( goldmate_format_time( (int) $item['updated_at'] ) ); ?></span></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Primary wage display (percent or fixed amount).
	 *
	 * @param array $b Breakdown.
	 * @return string
	 */
	protected static function wage_display_value( $b ) {

		$mode = Goldmate_Calculator::normalize_wage_mode( $b['wage_mode'] );

		if ( 'fixed' === $mode ) {
			return goldmate_plain_price( $b['wage_fixed'] ) . ' /گرم';
		}

		if ( 'combined' === $mode ) {
			return wc_format_localized_decimal( $b['wage_pct'] ) . '٪ + ' . goldmate_plain_price( $b['wage_fixed'] ) . ' /گرم';
		}

		return wc_format_localized_decimal( $b['wage_pct'] ) . '٪';
	}

	/**
	 * Formats one detail row as HTML.
	 *
	 * @param array  $row   Row data.
	 * @param string $class CSS classes.
	 * @return string
	 */
	protected static function format_row_html( $row, $class ) {

		$html = sprintf(
			'<div class="%s" style="margin:4px 0;line-height:1.7;"><span class="goldmate-details-label">%s</span> <span class="goldmate-details-value">%s</span>',
			esc_attr( $class ),
			esc_html( $row['label'] ),
			esc_html( $row['value'] )
		);

		if ( ! empty( $row['equal'] ) ) {
			$html .= sprintf(
				' <span class="goldmate-details-equal" style="opacity:.8;">(%s)</span>',
				esc_html( $row['equal'] )
			);
		}

		$html .= '</div>';

		return $html;
	}
	/**
	 * Renders the shortcodes reference / titles tab (Ratesbox-style).
	 */
	public static function render_admin_tab() {

		$catalog  = Goldmate_Shortcodes::catalog();
		$defaults = Goldmate_Shortcodes::default_titles();
		$seen     = array();
		?>
		<style>
			.goldmate-sc-wrap { max-width: 1100px; margin-top: 8px; }
			.goldmate-sc-table { background: #fff; border: 1px solid #c3c4c7; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
			.goldmate-sc-table table { margin: 0; border: 0; }
			.goldmate-sc-table th { font-weight: 600; }
			.goldmate-sc-table code,
			.goldmate-sc-code {
				display: inline-block;
				direction: ltr;
				text-align: left;
				background: #f6f7f7;
				padding: 6px 8px;
				border-radius: 4px;
				font-size: 12px;
				line-height: 1.5;
				word-break: break-all;
				max-width: 100%;
			}
			.goldmate-sc-table input.regular-text { width: 100%; max-width: 220px; }
			.goldmate-sc-hint { color: #646970; font-size: 12px; }
			.goldmate-sc-actions { margin-top: 16px; }
		</style>

		<div class="goldmate-sc-wrap">
			<p class="description" style="max-width:900px;">
				شورتکدها را در برگه، ویجت یا صفحه‌ی محصول قرار دهید. برای اجزای قیمت، شورتکد را داخل صفحه‌ی همان محصول بگذارید (یا با پارامتر <code>id</code> شناسه‌ی محصول را بدهید).
				با تغییر «عنوان پیش‌فرض» و ذخیره‌ی تغییرات، مقدار <code>title</code> در شورتکد به‌روز می‌شود.
			</p>

			<details open style="margin:12px 0 20px;padding:12px 16px;background:#fff;border:1px solid #c3c4c7;border-radius:8px;max-width:640px;">
				<summary style="cursor:pointer;font-weight:600;">سازنده شورتکد تابلو نرخ</summary>
				<div style="margin-top:12px;display:grid;gap:8px;">
					<label>آیتم
						<select id="gm-board-item">
							<?php foreach ( Goldmate_Rate_Items::choices( false ) as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><input type="checkbox" class="gm-board-opt" data-k="price" checked> نمایش قیمت</label>
					<label><input type="checkbox" class="gm-board-opt" data-k="increase" checked> افزایش قیمت</label>
					<label><input type="checkbox" class="gm-board-opt" data-k="decrease" checked> کاهش قیمت</label>
					<label><input type="checkbox" class="gm-board-opt" data-k="title" checked> نمایش عنوان</label>
					<label><input type="checkbox" class="gm-board-opt" data-k="currency" checked> واحد پول</label>
					<label><input type="checkbox" class="gm-board-opt" data-k="date" checked> تاریخ به‌روزرسانی</label>
					<label><input type="checkbox" class="gm-board-opt" data-k="live_update" checked> به‌روزرسانی آنی</label>
					<label><input type="checkbox" class="gm-board-opt" data-k="live_update_colorize" checked> رنگ‌آمیزی تغییر</label>
					<code id="gm-board-preview" class="goldmate-sc-code" style="display:block;margin-top:8px;"></code>
				</div>
				<script>
				(function(){
					function build(){
						var item = document.getElementById('gm-board-item').value;
						var parts = ['[goldmate_price item="'+item+'"'];
						document.querySelectorAll('.gm-board-opt').forEach(function(el){
							parts.push(el.getAttribute('data-k')+'="'+(el.checked?'1':'0')+'"');
						});
						parts.push(']');
						document.getElementById('gm-board-preview').textContent = parts.join(' ');
					}
					document.getElementById('gm-board-item').addEventListener('change', build);
					document.querySelectorAll('.gm-board-opt').forEach(function(el){ el.addEventListener('change', build); });
					build();
				})();
				</script>
			</details>

			<form method="post" action="<?php echo esc_url( Goldmate_Admin::url( 'shortcodes' ) ); ?>" id="goldmate-shortcodes-form">
				<?php wp_nonce_field( 'goldmate_admin' ); ?>
				<input type="hidden" name="goldmate_action" value="save">

				<div class="goldmate-sc-table">
					<table class="widefat striped">
						<thead>
							<tr>
								<th style="width:22%;">عنوان پیش‌فرض</th>
								<th style="width:40%;">شورتکد</th>
								<th>کاربرد</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $catalog as $row ) : ?>
								<?php
								$title_key = $row['title_key'];
								$same_as   = ! empty( $row['same_as'] );
								$show_input = $title_key && ! $same_as && empty( $seen[ $title_key ] );
								if ( $title_key && ! $same_as ) {
									$seen[ $title_key ] = true;
								}
								?>
								<tr data-goldmate-sc-row="<?php echo esc_attr( $row['id'] ); ?>"
									data-title-key="<?php echo esc_attr( $title_key ); ?>"
									data-same-as="<?php echo esc_attr( $same_as ? $row['same_as'] : '' ); ?>"
									data-template="<?php echo esc_attr( $row['shortcode'] ); ?>">
									<td>
										<?php if ( $show_input ) : ?>
											<input
												type="text"
												class="regular-text goldmate-sc-title"
												name="goldmate_sc_title_<?php echo esc_attr( $title_key ); ?>"
												data-title-key="<?php echo esc_attr( $title_key ); ?>"
												value="<?php echo esc_attr( Goldmate_Shortcodes::title( $title_key ) ); ?>"
												placeholder="<?php echo esc_attr( isset( $defaults[ $title_key ] ) ? $defaults[ $title_key ] : '' ); ?>"
											>
										<?php elseif ( $same_as ) : ?>
											<span class="goldmate-sc-hint">مشابه بالا</span>
										<?php else : ?>
											<span class="goldmate-sc-hint">—</span>
										<?php endif; ?>
									</td>
									<td>
										<code class="goldmate-sc-code" data-sc-preview><?php echo esc_html( $row['shortcode'] ); ?></code>
									</td>
									<td><?php echo esc_html( $row['usage'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<p class="goldmate-sc-actions">
					<button type="submit" class="button button-primary button-hero">ذخیره تغییرات</button>
				</p>
			</form>
		</div>

		<script>
		( function () {
			var form = document.getElementById( 'goldmate-shortcodes-form' );
			if ( ! form ) { return; }

			function titles() {
				var map = {};
				form.querySelectorAll( '.goldmate-sc-title' ).forEach( function ( input ) {
					map[ input.getAttribute( 'data-title-key' ) ] = input.value;
				} );
				return map;
			}

			function escapeAttr( value ) {
				return String( value || '' ).replace( /'/g, "\\'" );
			}

			function rebuild( template, map ) {
				var out = template;
				Object.keys( map ).forEach( function ( key ) {
					var reTitle = new RegExp( "title='[^']*'", 'g' );
					var reEqual = new RegExp( "equal_to_label='[^']*'", 'g' );
					if ( key === 'equal_to' ) {
						out = out.replace( reEqual, "equal_to_label='" + escapeAttr( map[ key ] ) + "'" );
						if ( out.indexOf( "equal_to_label=" ) === -1 && template.indexOf( 'equal_to_label' ) !== -1 ) {
							// keep as-is
						}
					} else {
						// Only replace title= in rows that belong to this key — handled per-row below.
					}
				} );
				return out;
			}

			function refresh() {
				var map = titles();
				form.querySelectorAll( '[data-goldmate-sc-row]' ).forEach( function ( row ) {
					var key = row.getAttribute( 'data-title-key' );
					var same = row.getAttribute( 'data-same-as' );
					var template = row.getAttribute( 'data-template' ) || '';
					var preview = row.querySelector( '[data-sc-preview]' );
					if ( ! preview ) { return; }

					var titleKey = same || key;
					var titleVal = titleKey && map[ titleKey ] !== undefined ? map[ titleKey ] : '';
					var equalVal = map.equal_to !== undefined ? map.equal_to : '';

					var next = template;
					if ( titleKey && next.indexOf( "title='" ) !== -1 ) {
						next = next.replace( /title='[^']*'/, "title='" + escapeAttr( titleVal ) + "'" );
					}
					if ( next.indexOf( "equal_to_label='" ) !== -1 ) {
						next = next.replace( /equal_to_label='[^']*'/, "equal_to_label='" + escapeAttr( equalVal ) + "'" );
					}
					if ( row.getAttribute( 'data-goldmate-sc-row' ) === 'equal_label' ) {
						next = "equal_to_label='" + escapeAttr( equalVal ) + "'";
					}
					preview.textContent = next;
				} );
			}

			form.addEventListener( 'input', function ( e ) {
				if ( e.target && e.target.classList.contains( 'goldmate-sc-title' ) ) {
					refresh();
				}
			} );
		} )();
		</script>
		<?php
	}
}
