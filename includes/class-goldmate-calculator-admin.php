<?php
/**
 * Admin-only price calculator: its tab, its assets and its lookup endpoint.
 *
 * Split out of Goldmate_Admin, which was rendering four tabs inline. The tab
 * itself owns nothing the rest of the admin screen needs, so it lives here
 * alongside Goldmate_Discounts and Goldmate_Tools, which already work this way.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Calculator_Admin {

	/**
	 * Registers the calculator's assets and AJAX endpoint.
	 */
	public static function init() {

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_goldmate_admin_calc', array( __CLASS__, 'ajax_calculate' ) );
		add_action( 'wp_ajax_goldmate_admin_similar', array( __CLASS__, 'ajax_similar_products' ) );
	}

	/**
	 * Enqueues assets for the admin calculator tab.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {

		if ( 'toplevel_page_' . Goldmate_Admin::SLUG !== $hook ) {
			return;
		}

		if ( 'calculator' !== Goldmate_Admin::current_tab() ) {
			return;
		}

		wp_enqueue_style(
			'goldmate-admin-calculator',
			GOLDMATE_URL . 'assets/css/admin-calculator.css',
			array(),
			GOLDMATE_VERSION
		);

		wp_enqueue_script(
			'goldmate-admin-calculator',
			GOLDMATE_URL . 'assets/js/admin-calculator.js',
			array(),
			GOLDMATE_VERSION,
			true
		);

		// The breakdown itself is computed server-side by Goldmate_Calculator, so the
		// script only needs somewhere to post to.
		wp_localize_script(
			'goldmate-admin-calculator',
			'goldmateAdminCalc',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'goldmate_admin_calc' ),
			)
		);
	}

	/**
	 * Renders the Ratesbox-style gold price calculator tab.
	 */
	public static function render_admin_tab() {

		// Starting values mirror the fetch tab's gold18 item, so an untouched form
		// prices exactly like a product on the default formula.
		$rate_18     = goldmate_reference_rate();
		$shop        = goldmate_shop_percentages();
		$profit_pct  = $shop['profit_pct'];
		$tax_pct     = $shop['tax_pct'];
		$tax_acc     = 'yes' === goldmate_option( 'goldmate_tax_accessories' );
		$wage_mode   = Goldmate_Calculator::normalize_wage_mode( goldmate_option( 'goldmate_default_wage_mode' ) );
		?>
		<div class="goldmate-calc-wrap" id="goldmate-admin-calc">
			<h2 class="goldmate-calc-title">ماشین حساب محاسبه‌گر قیمت</h2>

			<?php if ( $rate_18 <= 0 ) : ?>
				<div class="notice notice-warning inline"><p>قیمت روز طلا هنوز تنظیم نشده است. ابتدا در تب «تنظیمات فراخوانی قیمت» نرخ آیتم gold18 را دریافت یا وارد کنید.</p></div>
			<?php endif; ?>

			<div class="goldmate-calc-card">
				<div class="goldmate-calc-grid">
					<div class="goldmate-calc-field">
						<label for="goldmate_calc_karat">نوع آیتم</label>
						<div class="goldmate-calc-control">
							<select id="goldmate_calc_karat" name="karat">
								<option value="24">طلای ۲۴ عیار</option>
								<option value="22">طلای ۲۲ عیار</option>
								<option value="21">طلای ۲۱ عیار</option>
								<option value="18" selected>طلای ۱۸ عیار</option>
								<option value="14">طلای ۱۴ عیار</option>
								<option value="10">طلای ۱۰ عیار</option>
								<option value="9">طلای ۹ عیار</option>
							</select>
						</div>
					</div>

					<div class="goldmate-calc-field">
						<label for="goldmate_calc_rate_display">قیمت هر گرم (متناسب با عیار)</label>
						<div class="goldmate-calc-control">
							<input type="text" id="goldmate_calc_rate_display" name="rate_display" value="<?php echo esc_attr( $rate_18 > 0 ? number_format( $rate_18, 0, '.', ',' ) : '' ); ?>" readonly>
							<span class="goldmate-calc-unit">تومان</span>
						</div>
					</div>

					<div class="goldmate-calc-field">
						<label for="goldmate_calc_rate_18">قیمت روز ۱۸ عیار</label>
						<div class="goldmate-calc-control">
							<input type="number" id="goldmate_calc_rate_18" name="rate_18" min="0" step="1" value="<?php echo esc_attr( $rate_18 ); ?>">
							<span class="goldmate-calc-unit">تومان</span>
						</div>
					</div>

					<div class="goldmate-calc-field">
						<label for="goldmate_calc_weight">وزن</label>
						<div class="goldmate-calc-control">
							<input type="number" id="goldmate_calc_weight" name="weight" min="0" step="0.001" value="2.43">
							<span class="goldmate-calc-unit">گرم</span>
						</div>
					</div>

					<div class="goldmate-calc-field">
						<label for="goldmate_calc_wage_mode">نوع اجرت ساخت</label>
						<div class="goldmate-calc-control">
							<select id="goldmate_calc_wage_mode" name="wage_mode">
								<option value="pct" <?php selected( $wage_mode, 'pct' ); ?>>درصدی</option>
								<option value="fixed" <?php selected( $wage_mode, 'fixed' ); ?>>ثابت تومان/گرم</option>
								<option value="combined" <?php selected( $wage_mode, 'combined' ); ?>>ترکیبی</option>
							</select>
						</div>
					</div>

					<div class="goldmate-calc-field goldmate-calc-wage-pct-wrap">
						<label for="goldmate_calc_wage_pct">اجرت ساخت</label>
						<div class="goldmate-calc-control">
							<input type="number" id="goldmate_calc_wage_pct" name="wage_pct" min="0" step="0.01" value="23">
							<span class="goldmate-calc-unit">٪</span>
						</div>
					</div>

					<div class="goldmate-calc-field goldmate-calc-wage-fixed-wrap">
						<label for="goldmate_calc_wage_fixed">اجرت ثابت</label>
						<div class="goldmate-calc-control">
							<input type="number" id="goldmate_calc_wage_fixed" name="wage_fixed" min="0" step="1" value="0">
							<span class="goldmate-calc-unit">تومان/گرم</span>
						</div>
					</div>

					<div class="goldmate-calc-field">
						<label for="goldmate_calc_profit">سود</label>
						<div class="goldmate-calc-control">
							<input type="number" id="goldmate_calc_profit" name="profit_pct" min="0" step="0.01" value="<?php echo esc_attr( $profit_pct ); ?>">
							<span class="goldmate-calc-unit">٪</span>
						</div>
					</div>

					<div class="goldmate-calc-field">
						<label for="goldmate_calc_accessories">ملحقات</label>
						<div class="goldmate-calc-control">
							<input type="number" id="goldmate_calc_accessories" name="accessories" min="0" step="1" value="2740000">
							<span class="goldmate-calc-unit">تومان</span>
						</div>
					</div>

					<div class="goldmate-calc-field">
						<label for="goldmate_calc_tax">نرخ مالیات</label>
						<div class="goldmate-calc-control">
							<input type="number" id="goldmate_calc_tax" name="tax_pct" min="0" step="0.01" value="<?php echo esc_attr( $tax_pct ); ?>">
							<span class="goldmate-calc-unit">٪</span>
						</div>
					</div>
				</div>

				<div class="goldmate-calc-options">
					<label>
						<input type="checkbox" name="profit_accessories" value="1" checked>
						سود روی ملحقات هم اعمال شود
					</label>
					<label>
						<input type="checkbox" name="tax_accessories" value="1" <?php checked( $tax_acc ); ?>>
						مالیات روی ملحقات هم اعمال شود
					</label>
				</div>

				<div class="goldmate-calc-result" aria-live="polite">
					<div class="goldmate-calc-result-row">
						<span class="label">قیمت طلا (<span data-out="karat_label">عیار ۱۸</span>)</span>
						<span class="amount" data-out="gold">—</span>
						<span class="formula" data-out="gold_formula"></span>
					</div>
					<div class="goldmate-calc-result-row">
						<span class="label">اجرت ساخت</span>
						<span class="amount" data-out="wage">—</span>
						<span class="formula" data-out="wage_formula"></span>
					</div>
					<div class="goldmate-calc-result-row">
						<span class="label">سود فروش</span>
						<span class="amount" data-out="profit">—</span>
						<span class="formula" data-out="profit_formula"></span>
					</div>
					<div class="goldmate-calc-result-row">
						<span class="label">ملحقات</span>
						<span class="amount" data-out="accessories">—</span>
						<span class="formula">قیمت سنگ، چرم و متعلقات</span>
					</div>
					<div data-out="components"></div>
					<div class="goldmate-calc-result-row">
						<span class="label">مالیات</span>
						<span class="amount" data-out="tax">—</span>
						<span class="formula" data-out="tax_formula"></span>
					</div>
					<div class="goldmate-calc-result-row is-total">
						<span class="label">قیمت نهایی محصول</span>
						<span class="amount" data-out="total">—</span>
						<span class="formula">نرخ واحد: <span data-out="rate_unit">—</span></span>
					</div>
				</div>
			</div>

			<div class="goldmate-calc-similar">
				<h3>محصولات مشابه در همین وزن</h3>
				<div data-similar>
					<p class="description">پس از وارد کردن وزن، محصولات طلای نزدیک به آن وزن اینجا نشان داده می‌شوند.</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: runs the shop's own pricing formula over the tab's form values.
	 *
	 * The tab is a what-if scratchpad, so the tax rate and the two "apply to
	 * accessories" switches come from the form rather than the shop settings;
	 * everything else — components, discounts, the tax base and rounding — is
	 * whatever Goldmate_Calculator would do to a real product.
	 */
	public static function ajax_calculate() {

		if ( ! current_user_can( Goldmate_Admin::CAPABILITY ) ) {
			wp_send_json_error();
		}

		check_ajax_referer( 'goldmate_admin_calc', 'nonce' );

		$post  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field is sanitised below.
		$karat = isset( $post['karat'] ) ? goldmate_positive_float( $post['karat'] ) : 0;

		$inputs = array(
			'weight'             => isset( $post['weight'] ) ? goldmate_positive_float( $post['weight'] ) : 0,
			'karat'              => $karat > 0 ? $karat : 18,
			'wage_mode'          => isset( $post['wage_mode'] ) ? sanitize_text_field( $post['wage_mode'] ) : 'pct',
			'wage_pct'           => isset( $post['wage_pct'] ) ? goldmate_positive_float( $post['wage_pct'] ) : 0,
			'wage_fixed'         => isset( $post['wage_fixed'] ) ? goldmate_positive_float( $post['wage_fixed'] ) : 0,
			'accessories'        => isset( $post['accessories'] ) ? goldmate_positive_float( $post['accessories'] ) : 0,
			'profit_pct'         => isset( $post['profit_pct'] ) ? goldmate_positive_float( $post['profit_pct'] ) : 0,
			'tax_pct'            => isset( $post['tax_pct'] ) ? goldmate_positive_float( $post['tax_pct'] ) : 0,
			'tax_accessories'    => ! empty( $post['tax_accessories'] ),
			'profit_accessories' => ! empty( $post['profit_accessories'] ),
		);

		$rate_18   = isset( $post['rate_18'] ) ? goldmate_positive_float( $post['rate_18'] ) : 0;
		$breakdown = Goldmate_Calculator::calculate_from_inputs( $inputs, $rate_18, 0 );

		if ( false === $breakdown ) {
			wp_send_json_error( array( 'message' => 'نرخ روز و وزن را وارد کنید.' ) );
		}

		wp_send_json_success(
			array(
				'out'        => self::output_fields( $breakdown ),
				'components' => self::components_html( $breakdown ),
				'unitRate'   => number_format( round( (float) $breakdown['rate'] ), 0, '.', ',' ),
			)
		);
	}

	/**
	 * Maps a breakdown onto the tab's `data-out` slots.
	 *
	 * @param array $b Breakdown from Goldmate_Calculator.
	 * @return array<string,string>
	 */
	protected static function output_fields( $b ) {

		return array(
			'karat_label'    => 'عیار ' . self::decimal( $b['karat'], 1 ),
			'rate_unit'      => self::money( $b['rate'] ),
			'gold'           => self::money( $b['gold'] ),
			'gold_formula'   => self::decimal( $b['weight'], 3 ) . ' گرم × ' . self::money( $b['rate'] ),
			'wage'           => self::money( $b['wage'] ),
			'wage_formula'   => self::wage_formula( $b ),
			'profit'         => self::money( $b['profit'] ),
			'profit_formula' => '(' . ( ! empty( $b['profit_accessories'] ) ? 'طلا + اجرت + ملحقات' : 'طلا + اجرت' )
				. ') × ' . self::decimal( $b['profit_pct'], 2 ) . '٪',
			'accessories'    => self::money( $b['accessories'] ),
			'tax'            => self::money( $b['tax'] ),
			'tax_formula'    => self::tax_formula( $b ),
			'total'          => self::money( $b['total'] ),
		);
	}

	/**
	 * The wage row's working, in the shape the chosen wage mode takes.
	 *
	 * @param array $b Breakdown.
	 * @return string
	 */
	protected static function wage_formula( $b ) {

		$weight = self::decimal( $b['weight'], 3 );

		if ( 'fixed' === $b['wage_mode'] ) {
			return $weight . ' گرم × ' . self::money( $b['wage_fixed'] ) . ' /گرم';
		}

		if ( 'combined' === $b['wage_mode'] ) {
			return '(' . $weight . ' × ' . self::money( $b['wage_fixed'] ) . ') + ('
				. self::money( $b['gold'] ) . ' × ' . self::decimal( $b['wage_pct'], 2 ) . '٪)';
		}

		return 'قیمت طلا × ' . self::decimal( $b['wage_pct'], 2 ) . '٪';
	}

	/**
	 * The tax row's working, naming only the parts the shop actually taxes.
	 *
	 * @param array $b Breakdown.
	 * @return string
	 */
	protected static function tax_formula( $b ) {

		$parts = array();

		if ( ! empty( $b['tax_on_wage'] ) ) {
			$parts[] = 'اجرت';
		}
		if ( ! empty( $b['tax_on_profit'] ) ) {
			$parts[] = 'سود';
		}
		if ( ! empty( $b['tax_accessories'] ) ) {
			$parts[] = 'ملحقات';
		}

		if ( ! $parts ) {
			return 'پایه مالیاتی خالی است';
		}

		return '(' . implode( ' + ', $parts ) . ') × ' . self::decimal( $b['tax_pct'], 2 ) . '٪';
	}

	/**
	 * Extra result rows: shop-defined price components, then the rounding step.
	 *
	 * Without these the listed amounts would not add up to the total shown.
	 *
	 * @param array $b Breakdown.
	 * @return string
	 */
	protected static function components_html( $b ) {

		$rows = array();

		if ( ! empty( $b['components'] ) && is_array( $b['components'] ) ) {
			foreach ( $b['components'] as $line ) {
				$rows[] = array( $line['label'], $line['amount'], 'جزء قیمت سفارشی' );
			}
		}

		$rounding = Goldmate_Calculator::rounding_difference( $b );

		if ( 0.0 !== $rounding ) {
			$rows[] = array( 'گرد کردن', $rounding, '' );
		}

		$html = '';

		foreach ( $rows as $row ) {
			$html .= '<div class="goldmate-calc-result-row">'
				. '<span class="label">' . esc_html( $row[0] ) . '</span>'
				. '<span class="amount">' . esc_html( self::money( $row[1] ) ) . '</span>'
				. '<span class="formula">' . esc_html( $row[2] ) . '</span>'
				. '</div>';
		}

		return $html;
	}

	/**
	 * Formats a Toman amount the way the result panel shows it.
	 *
	 * @param float $amount Raw amount.
	 * @return string
	 */
	protected static function money( $amount ) {
		return number_format( round( (float) $amount ), 0, '.', ',' ) . ' تومان';
	}

	/**
	 * Formats a plain number, dropping decimals that are all zeros.
	 *
	 * @param float $value  Raw value.
	 * @param int   $digits Maximum decimal places.
	 * @return string
	 */
	protected static function decimal( $value, $digits = 2 ) {

		$formatted = number_format( (float) $value, $digits, '.', ',' );

		if ( false === strpos( $formatted, '.' ) ) {
			return $formatted;
		}

		return rtrim( rtrim( $formatted, '0' ), '.' );
	}

	/**
	 * AJAX: products with a similar gold weight for the calculator tab.
	 */
	public static function ajax_similar_products() {

		if ( ! current_user_can( Goldmate_Admin::CAPABILITY ) ) {
			wp_send_json_error();
		}

		check_ajax_referer( 'goldmate_admin_calc', 'nonce' );

		$weight = isset( $_GET['weight'] ) ? goldmate_positive_float( wp_unslash( $_GET['weight'] ) ) : 0;

		if ( $weight <= 0 ) {
			wp_send_json_success( array( 'html' => '<p class="description">وزن نامعتبر است.</p>' ) );
		}

		$tolerance = max( 0.05, $weight * 0.15 );

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 8,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_goldmate_enabled',
						'value' => 'yes',
					),
					array(
						'key'     => '_goldmate_weight',
						'value'   => array( $weight - $tolerance, $weight + $tolerance ),
						'type'    => 'DECIMAL(10,3)',
						'compare' => 'BETWEEN',
					),
				),
			)
		);

		if ( ! $query->have_posts() ) {
			wp_send_json_success(
				array(
					'html' => '<p class="description">محصول طلایی با وزن نزدیک به ' . esc_html( wc_format_localized_decimal( $weight ) ) . ' گرم پیدا نشد.</p>',
				)
			);
		}

		ob_start();
		echo '<div class="goldmate-calc-similar-grid">';

		while ( $query->have_posts() ) {
			$query->the_post();
			$product = wc_get_product( get_the_ID() );
			if ( ! $product ) {
				continue;
			}

			$w = goldmate_meta_float( $product->get_id(), '_goldmate_weight' );
			$thumb = $product->get_image( 'woocommerce_thumbnail', array( 'alt' => esc_attr( $product->get_name() ) ) );
			?>
			<a class="goldmate-calc-product" href="<?php echo esc_url( get_edit_post_link( $product->get_id() ) ); ?>">
				<?php echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC image HTML. ?>
				<div class="meta">
					<div class="title"><?php echo esc_html( $product->get_name() ); ?></div>
					<div class="price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>
					<div class="weight"><?php echo esc_html( wc_format_localized_decimal( $w ) ); ?> گرم</div>
				</div>
			</a>
			<?php
		}

		echo '</div>';
		wp_reset_postdata();

		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}
}
