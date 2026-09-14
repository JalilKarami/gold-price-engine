<?php
/**
 * Storefront shortcodes: gold rate board and visitor price calculator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Widgets {

	/**
	 * Registers shortcodes and calculator AJAX.
	 */
	public static function init() {

		add_shortcode( 'goldmate_rate_board', array( __CLASS__, 'render_rate_board' ) );
		add_shortcode( 'goldmate_calculator', array( __CLASS__, 'render_calculator' ) );

		add_action( 'wp_ajax_goldmate_calc', array( __CLASS__, 'ajax_calc' ) );
		add_action( 'wp_ajax_nopriv_goldmate_calc', array( __CLASS__, 'ajax_calc' ) );
	}

	/**
	 * [goldmate_rate_board] — current 18k rate, updated-at, change %.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_rate_board( $atts ) {

		$atts = shortcode_atts(
			array(
				'title' => 'نرخ طلای ۱۸ عیار',
			),
			$atts,
			'goldmate_rate_board'
		);

		$data = Goldmate_Live::payload();

		if ( $data['rate'] <= 0 ) {
			return '';
		}

		$change = $data['change_pct'];
		$dir    = null === $change ? 'flat' : ( $change > 0 ? 'up' : ( $change < 0 ? 'down' : 'flat' ) );
		$color  = 'up' === $dir ? '#1e7e34' : ( 'down' === $dir ? '#b32d2e' : '#666' );

		ob_start();
		?>
		<div class="goldmate-rate-board" data-goldmate-board="1" style="padding:14px 16px;border:1px solid rgba(0,0,0,.08);border-radius:8px;max-width:420px;line-height:1.7;">
			<div style="font-weight:600;margin-bottom:6px;"><?php echo esc_html( $atts['title'] ); ?></div>
			<div data-goldmate-board-rate style="font-size:1.35em;font-weight:700;"><?php echo wp_kses_post( $data['rate_html'] ); ?></div>
			<div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:8px;font-size:.9em;opacity:.9;">
				<span>تغییر: <strong data-goldmate-board-change data-dir="<?php echo esc_attr( $dir ); ?>" style="color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $data['change_pct_html'] ? $data['change_pct_html'] : '—' ); ?></strong></span>
				<span>به‌روزرسانی: <span data-goldmate-board-time><?php echo esc_html( $data['updated_human'] ); ?></span></span>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [goldmate_calculator] — visitor estimate using the same shop formula.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_calculator( $atts ) {

		$atts = shortcode_atts(
			array(
				'title'       => 'محاسبه‌گر قیمت طلا',
				'wage_mode'   => goldmate_option( 'goldmate_default_wage_mode' ),
				'wage_pct'    => '15',
				'wage_fixed'  => '0',
				'karat'       => '18',
				'orientation' => 'vertical',
			),
			$atts,
			'goldmate_calculator'
		);

		$wage_mode   = Goldmate_Calculator::normalize_wage_mode( $atts['wage_mode'] );
		$rate        = goldmate_positive_float( goldmate_option( 'goldmate_rate_per_gram' ) );
		$id          = 'goldmate-calc-' . wp_unique_id();
		$orientation = ( 'horizontal' === $atts['orientation'] ) ? 'horizontal' : 'vertical';
		$grid_style  = ( 'horizontal' === $orientation )
			? 'display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;align-items:end;'
			: 'display:grid;gap:10px;';

		ob_start();
		?>
		<div class="goldmate-calculator goldmate-calculator--<?php echo esc_attr( $orientation ); ?>" id="<?php echo esc_attr( $id ); ?>" style="padding:16px;border:1px solid rgba(0,0,0,.08);border-radius:8px;max-width:<?php echo 'horizontal' === $orientation ? '960px' : '480px'; ?>;">
			<div style="font-weight:600;margin-bottom:12px;"><?php echo esc_html( $atts['title'] ); ?></div>
			<?php if ( $rate <= 0 ) : ?>
				<p class="description">قیمت روز طلا هنوز تنظیم نشده است.</p>
			<?php else : ?>
				<p style="margin:0 0 12px;font-size:.9em;opacity:.85;">نرخ فعلی ۱۸ عیار: <strong><?php echo wp_kses_post( wc_price( $rate ) ); ?></strong></p>
				<form class="goldmate-calculator-form" style="<?php echo esc_attr( $grid_style ); ?>">
					<label>وزن (گرم)
						<input type="number" name="weight" min="0" step="0.001" value="1" required style="width:100%;">
					</label>
					<label>عیار
						<input type="number" name="karat" min="0" step="0.1" value="<?php echo esc_attr( $atts['karat'] ); ?>" style="width:100%;">
					</label>
					<label>نوع اجرت
						<select name="wage_mode" style="width:100%;">
							<option value="pct" <?php selected( $wage_mode, 'pct' ); ?>>درصدی از مبلغ طلا</option>
							<option value="fixed" <?php selected( $wage_mode, 'fixed' ); ?>>ثابت تومان/گرم</option>
							<option value="combined" <?php selected( $wage_mode, 'combined' ); ?>>ترکیبی</option>
						</select>
					</label>
					<label class="goldmate-calc-wage-pct">اجرت (٪)
						<input type="number" name="wage_pct" min="0" step="0.01" value="<?php echo esc_attr( $atts['wage_pct'] ); ?>" style="width:100%;">
					</label>
					<label class="goldmate-calc-wage-fixed">اجرت ثابت (تومان/گرم)
						<input type="number" name="wage_fixed" min="0" step="1" value="<?php echo esc_attr( $atts['wage_fixed'] ); ?>" style="width:100%;">
					</label>
					<label>متعلقات (تومان)
						<input type="number" name="accessories" min="0" step="1" value="0" style="width:100%;">
					</label>
					<button type="submit" class="button" style="margin-top:4px;">محاسبه</button>
				</form>
				<div class="goldmate-calculator-result" style="margin-top:14px;display:none;"></div>
				<script>
				( function () {
					var root = document.getElementById( <?php echo wp_json_encode( $id ); ?> );
					if ( ! root ) { return; }
					var form = root.querySelector( '.goldmate-calculator-form' );
					var result = root.querySelector( '.goldmate-calculator-result' );
					var mode = form.querySelector( '[name="wage_mode"]' );
					function syncMode() {
						var modeVal = mode.value;
						root.querySelector( '.goldmate-calc-wage-pct' ).style.display = ( modeVal === 'pct' || modeVal === 'combined' ) ? '' : 'none';
						root.querySelector( '.goldmate-calc-wage-fixed' ).style.display = ( modeVal === 'fixed' || modeVal === 'combined' ) ? '' : 'none';
					}
					mode.addEventListener( 'change', syncMode );
					syncMode();
					form.addEventListener( 'submit', function ( e ) {
						e.preventDefault();
						var body = new FormData( form );
						body.append( 'action', 'goldmate_calc' );
						var xhr = new XMLHttpRequest();
						xhr.open( 'POST', <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?> );
						xhr.onload = function () {
							try {
								var payload = JSON.parse( xhr.responseText );
								if ( ! payload.success ) {
									result.style.display = '';
									result.textContent = payload.data && payload.data.message ? payload.data.message : 'خطا در محاسبه';
									return;
								}
								result.style.display = '';
								result.innerHTML = payload.data.html;
							} catch ( err ) {
								result.style.display = '';
								result.textContent = 'خطا در پاسخ سرور';
							}
						};
						xhr.send( body );
					} );
				} )();
				</script>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX handler for the visitor calculator.
	 */
	public static function ajax_calc() {

		$inputs = array(
			'weight'      => isset( $_POST['weight'] ) ? goldmate_positive_float( wp_unslash( $_POST['weight'] ) ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- public estimator.
			'karat'       => isset( $_POST['karat'] ) ? goldmate_positive_float( wp_unslash( $_POST['karat'] ) ) : 18,
			'wage_mode'   => isset( $_POST['wage_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['wage_mode'] ) ) : 'pct',
			'wage_pct'    => isset( $_POST['wage_pct'] ) ? goldmate_positive_float( wp_unslash( $_POST['wage_pct'] ) ) : 0,
			'wage_fixed'  => isset( $_POST['wage_fixed'] ) ? goldmate_positive_float( wp_unslash( $_POST['wage_fixed'] ) ) : 0,
			'accessories' => isset( $_POST['accessories'] ) ? goldmate_positive_float( wp_unslash( $_POST['accessories'] ) ) : 0,
		);

		$rate = goldmate_positive_float( goldmate_option( 'goldmate_rate_per_gram' ) );
		$b    = Goldmate_Calculator::calculate_from_inputs( $inputs, $rate, 0 );

		if ( false === $b ) {
			wp_send_json_error( array( 'message' => 'ورودی‌ها یا نرخ طلا نامعتبر است.' ) );
		}

		$html  = Goldmate_Display::render_table( $b );
		$html .= '<p style="margin:8px 0 0;font-size:.85em;opacity:.8;">این یک برآورد است؛ قیمت نهایی محصول ممکن است متفاوت باشد.</p>';

		wp_send_json_success(
			array(
				'total' => $b['total'],
				'html'  => $html,
			)
		);
	}
}
