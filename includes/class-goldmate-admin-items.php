<?php
/**
 * Admin UI for the rate-items fetch grid (Ratesbox-style).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Admin_Items {

	/**
	 * Renders the Ratesbox-style items grid.
	 */
	public static function render() {
		$items = Goldmate_Rate_Items::all();
		self::print_styles();
		?>
		<div class="gm-items">
			<div class="gm-items__toolbar">
				<h2 class="gm-items__title">تنظیمات قیمت فلزات گرانبها</h2>
				<div class="gm-items__actions">
					<button type="submit" class="button button-primary" name="goldmate_action" value="save_rate_items">ذخیره همه</button>
					<button type="submit" class="button" name="goldmate_action" value="fetch_due_rate_items">فراخوانی موعد‌دار</button>
				</div>
			</div>

			<div class="gm-items__scroll">
				<table class="gm-items__table">
					<thead>
						<tr>
							<th class="gm-col-item">آیتم</th>
							<th class="gm-col-price">قیمت</th>
							<th class="gm-col-extract">استخراج قیمت</th>
							<th class="gm-col-tax">سود و مالیات</th>
							<th class="gm-col-cron">فراخوانی خودکار</th>
							<th class="gm-col-opts">تنظیمات</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $items as $item ) : ?>
						<?php self::render_row( $item ); ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="gm-items__toolbar gm-items__toolbar--footer">
				<div class="gm-items__actions">
					<button type="submit" class="button button-primary" name="goldmate_action" value="save_rate_items">ذخیره همه</button>
				</div>
			</div>
			<p class="description gm-items__hint">سه منبع قیمت طلای ۱۸ عیار (ATN / BrsApi / TGJU)، هر کدام یک آیتم. قیمت روز، سود ٪ و مالیات ٪ فروشگاه فقط از همین تب خوانده می‌شوند؛ محصولات از آیتم فرمول خود (پیش‌فرض: gold18) پیروی می‌کنند. اگر دریافت gold18 ناموفق باشد، منابع دیگر به ترتیب «اولویت» امتحان می‌شوند و اولین نرخ موفق (با همان محافظ‌های حداکثر انحراف و حداقل تغییر) روی gold18 اعمال می‌شود. فقط یکی را «مرجع مقیاس عیار» کنید.</p>
		</div>
		<?php
		self::print_preset_script();
	}

	/**
	 * Compact Ratesbox-like styles.
	 */
	protected static function print_styles() {
		?>
		<style>
			.gm-items { margin: 8px 0 24px; }
			.gm-items__toolbar {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 12px;
				flex-wrap: wrap;
				margin-bottom: 12px;
			}
			.gm-items__toolbar--footer { margin-top: 12px; margin-bottom: 0; }
			.gm-items__title {
				margin: 0;
				font-size: 16px;
				font-weight: 600;
				color: #1d2327;
			}
			.gm-items__actions { display: flex; gap: 8px; flex-wrap: wrap; }
			.gm-items__scroll {
				overflow-x: auto;
				border: 1px solid #c3c4c7;
				border-radius: 2px;
				background: #fff;
			}
			.gm-items__table {
				width: 100%;
				min-width: 1180px;
				border-collapse: collapse;
				font-size: 12px;
				line-height: 1.35;
			}
			.gm-items__table th {
				background: #e8f0fe;
				color: #1a73e8;
				font-weight: 600;
				text-align: right;
				padding: 10px 12px;
				border-bottom: 1px solid #c3c4c7;
				border-left: 1px solid #d8e2f0;
				white-space: nowrap;
			}
			.gm-items__table th:last-child,
			.gm-items__table td:last-child { border-left: 0; }
			.gm-items__table td {
				vertical-align: top;
				padding: 10px 12px;
				border-bottom: 1px solid #e2e4e7;
				border-left: 1px solid #eef0f2;
				background: #fff;
			}
			.gm-items__table tbody tr:nth-child(even) td { background: #f7f8fa; }
			.gm-items__table tbody tr:hover td { background: #f0f6ff; }
			.gm-col-item { width: 160px; }
			.gm-col-price { width: 200px; }
			.gm-col-extract { width: 340px; }
			.gm-col-tax { width: 110px; }
			.gm-col-cron { width: 120px; }
			.gm-col-opts { width: 170px; }

			.gm-field { margin: 0 0 7px; }
			.gm-field:last-child { margin-bottom: 0; }
			.gm-field > span {
				display: block;
				color: #646970;
				font-size: 11px;
				margin-bottom: 2px;
			}
			.gm-field input[type=text],
			.gm-field input[type=number],
			.gm-field select {
				width: 100%;
				max-width: 100%;
				height: 28px;
				min-height: 28px;
				padding: 2px 6px;
				font-size: 12px;
				border-radius: 2px;
				box-sizing: border-box;
			}
			.gm-field--inline {
				display: grid;
				grid-template-columns: 1fr 1fr;
				gap: 6px;
			}
			.gm-field--inline > div > span {
				display: block;
				color: #646970;
				font-size: 11px;
				margin-bottom: 2px;
			}
			.gm-check {
				display: flex;
				align-items: center;
				gap: 6px;
				margin: 0 0 6px;
				font-size: 12px;
				color: #1d2327;
			}
			.gm-check:last-child { margin-bottom: 0; }
			.gm-check input { margin: 0; }

			.gm-item-slug {
				font-family: Consolas, Monaco, monospace;
				font-size: 12px;
				direction: ltr;
				text-align: left;
			}
			.gm-item-actions {
				display: flex;
				flex-wrap: wrap;
				gap: 4px;
				margin-top: 8px;
			}
			.gm-item-actions .button {
				height: 24px;
				line-height: 22px;
				padding: 0 8px;
				min-height: 24px;
				font-size: 11px;
			}
			.gm-rate-now {
				font-size: 15px;
				font-weight: 700;
				direction: ltr;
				text-align: left;
				color: #1d2327;
				letter-spacing: 0.2px;
			}
			.gm-rate-meta {
				color: #646970;
				font-size: 11px;
				margin: 2px 0 8px;
			}
			.gm-rate-unit { color: #1a73e8; font-weight: 500; }
			.gm-error {
				margin: 6px 0 0;
				color: #b32d2e;
				font-size: 11px;
				line-height: 1.4;
			}
			.gm-advanced {
				margin-top: 8px;
				border-top: 1px dashed #dcdcde;
				padding-top: 6px;
			}
			.gm-advanced > summary {
				cursor: pointer;
				color: #2271b1;
				font-size: 11px;
				user-select: none;
			}
			.gm-advanced[open] > summary { margin-bottom: 6px; }
			.gm-items__hint { margin-top: 10px; max-width: 920px; }
		</style>
		<?php
	}

	/**
	 * Prefills connection fields when a named preset is chosen.
	 */
	protected static function print_preset_script() {
		$presets = class_exists( 'Goldmate_Fetcher' ) ? Goldmate_Fetcher::presets() : array();
		?>
		<script type="application/json" id="goldmate-item-presets"><?php echo wp_json_encode( $presets ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
		<script>
		(function () {
			var holder = document.getElementById('goldmate-item-presets');
			if (!holder) return;
			var presets = JSON.parse(holder.textContent || '{}');
			document.querySelectorAll('.goldmate-source-type').forEach(function (sel) {
				sel.addEventListener('change', function () {
					var preset = presets[sel.value];
					if (!preset || !preset.url) return;
					var row = sel.closest('tr');
					if (!row) return;
					var set = function (cls, val) {
						var el = row.querySelector(cls);
						if (el && val !== undefined && val !== null) el.value = val;
					};
					set('.goldmate-f-url', preset.url || '');
					set('.goldmate-f-path', preset.path || '');
					set('.goldmate-f-key-header', preset.key_header || '');
					set('.goldmate-f-time-path', preset.time_path || '');
					set('.goldmate-f-max-age', preset.max_age || 0);
					set('.goldmate-f-multiplier', preset.multiplier != null ? preset.multiplier : 1);
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * @param string $label Label.
	 * @param string $html  Control HTML.
	 */
	protected static function field( $label, $html ) {
		echo '<label class="gm-field"><span>' . esc_html( $label ) . '</span>' . $html . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller escapes.
	}

	/**
	 * @param array $item Item.
	 */
	protected static function render_row( $item ) {
		$id  = (int) $item['id'];
		$cfg = is_array( $item['source_config'] ) ? $item['source_config'] : array();
		$p   = 'goldmate_items[' . $id . ']';
		$locked = ( 'gold18' === $item['slug'] );

		$updated = '';
		if ( ! empty( $item['updated_at'] ) ) {
			$updated = human_time_diff( (int) $item['updated_at'], time() ) . ' پیش';
		} else {
			$updated = 'هنوز به‌روز نشده';
		}

		$unit_label = 'toman' === $item['unit'] ? 'تومان' : ( 'rial' === $item['unit'] ? 'ریال' : 'دلار' );
		?>
		<tr>
			<td class="gm-col-item">
				<input type="hidden" name="<?php echo esc_attr( $p ); ?>[id]" value="<?php echo esc_attr( $id ); ?>">
				<?php
				self::field(
					'شناسه',
					'<input type="text" class="gm-item-slug" name="' . esc_attr( $p ) . '[slug]" value="' . esc_attr( $item['slug'] ) . '" ' . disabled( $locked, true, false ) . '>'
				);
				self::field(
					'عنوان',
					'<input type="text" name="' . esc_attr( $p ) . '[label]" value="' . esc_attr( $item['label'] ) . '">'
				);
				self::field(
					'اولویت',
					'<input type="number" name="' . esc_attr( $p ) . '[priority]" value="' . esc_attr( $item['priority'] ) . '" min="0" step="1">'
				);
				?>
				<div class="gm-item-actions">
					<button type="submit" class="button" name="goldmate_action" value="fetch_rate_item_<?php echo esc_attr( $id ); ?>">آزمایش</button>
					<button type="submit" class="button button-primary" name="goldmate_action" value="apply_rate_item_<?php echo esc_attr( $id ); ?>">اعمال</button>
				</div>
			</td>

			<td class="gm-col-price">
				<div class="gm-rate-now"><?php echo $item['rate'] > 0 ? esc_html( number_format_i18n( $item['rate'] ) ) : '—'; ?></div>
				<div class="gm-rate-meta">
					<span class="gm-rate-unit"><?php echo esc_html( $unit_label ); ?></span>
					· <?php echo esc_html( $updated ); ?>
				</div>
				<?php
				self::field(
					'قیمت دستی',
					'<input type="number" step="1" name="' . esc_attr( $p ) . '[rate]" value="' . esc_attr( $item['rate'] ) . '">'
				);
				?>
				<div class="gm-field gm-field--inline">
					<div>
						<span>افزایش / کاهش</span>
						<select name="<?php echo esc_attr( $p ); ?>[adjust_mode]">
							<option value="none" <?php selected( $item['adjust_mode'], 'none' ); ?>>بدون تغییر</option>
							<option value="pct" <?php selected( $item['adjust_mode'], 'pct' ); ?>>درصدی</option>
							<option value="fixed" <?php selected( $item['adjust_mode'], 'fixed' ); ?>>ثابت</option>
						</select>
					</div>
					<div>
						<span>مقدار</span>
						<input type="number" step="0.01" name="<?php echo esc_attr( $p ); ?>[adjust_value]" value="<?php echo esc_attr( $item['adjust_value'] ); ?>">
					</div>
				</div>
				<div class="gm-field gm-field--inline">
					<div>
						<span>قیمت خرید</span>
						<select name="<?php echo esc_attr( $p ); ?>[adjust_buy_mode]">
							<option value="none" <?php selected( $item['adjust_buy_mode'], 'none' ); ?>>بدون تغییر</option>
							<option value="pct" <?php selected( $item['adjust_buy_mode'], 'pct' ); ?>>درصدی</option>
							<option value="fixed" <?php selected( $item['adjust_buy_mode'], 'fixed' ); ?>>ثابت</option>
						</select>
					</div>
					<div>
						<span>مقدار</span>
						<input type="number" step="0.01" name="<?php echo esc_attr( $p ); ?>[adjust_buy_value]" value="<?php echo esc_attr( $item['adjust_buy_value'] ); ?>">
					</div>
				</div>
				<div class="gm-field gm-field--inline">
					<div>
						<span>قیمت فروش</span>
						<select name="<?php echo esc_attr( $p ); ?>[adjust_sell_mode]">
							<option value="none" <?php selected( $item['adjust_sell_mode'], 'none' ); ?>>بدون تغییر</option>
							<option value="pct" <?php selected( $item['adjust_sell_mode'], 'pct' ); ?>>درصدی</option>
							<option value="fixed" <?php selected( $item['adjust_sell_mode'], 'fixed' ); ?>>ثابت</option>
						</select>
					</div>
					<div>
						<span>مقدار</span>
						<input type="number" step="0.01" name="<?php echo esc_attr( $p ); ?>[adjust_sell_value]" value="<?php echo esc_attr( $item['adjust_sell_value'] ); ?>">
					</div>
				</div>
				<?php if ( ! empty( $item['last_error'] ) ) : ?>
					<p class="gm-error"><?php echo esc_html( $item['last_error'] ); ?></p>
				<?php endif; ?>
			</td>

			<td class="gm-col-extract">
				<?php
				self::field(
					'نوع منبع',
					'<select class="goldmate-source-type" name="' . esc_attr( $p ) . '[source_type]">
						<option value="manual"' . selected( $item['source_type'], 'manual', false ) . '>دستی</option>
						<option value="atn"' . selected( $item['source_type'], 'atn', false ) . '>ATN</option>
						<option value="brsapi"' . selected( $item['source_type'], 'brsapi', false ) . '>BrsApi.ir</option>
						<option value="tgju"' . selected( $item['source_type'], 'tgju', false ) . '>TGJU</option>
						<option value="json"' . selected( $item['source_type'], 'json', false ) . '>JSON سفارشی</option>
						<option value="html"' . selected( $item['source_type'], 'html', false ) . '>HTML / سلکتور</option>
					</select>'
				);
				self::field(
					'آدرس',
					'<input type="text" class="goldmate-f-url" name="' . esc_attr( $p ) . '[url]" value="' . esc_attr( isset( $cfg['url'] ) ? $cfg['url'] : '' ) . '" dir="ltr">'
				);
				?>
				<div class="gm-field gm-field--inline">
					<div>
						<span>مسیر JSON / سلکتور</span>
						<input type="text" class="goldmate-f-path" name="<?php echo esc_attr( $p ); ?>[path]" value="<?php echo esc_attr( isset( $cfg['path'] ) ? $cfg['path'] : '' ); ?>" dir="ltr" placeholder="JSON path">
					</div>
					<div>
						<span>سلکتور HTML</span>
						<input type="text" name="<?php echo esc_attr( $p ); ?>[selector]" value="<?php echo esc_attr( isset( $cfg['selector'] ) ? $cfg['selector'] : '' ); ?>" dir="ltr" placeholder=".price:eq(0)">
					</div>
				</div>
				<div class="gm-field gm-field--inline">
					<div>
						<span>واحد منبع</span>
						<select name="<?php echo esc_attr( $p ); ?>[unit]">
							<option value="toman" <?php selected( $item['unit'], 'toman' ); ?>>تومان</option>
							<option value="rial" <?php selected( $item['unit'], 'rial' ); ?>>ریال</option>
							<option value="usd" <?php selected( $item['unit'], 'usd' ); ?>>دلار</option>
						</select>
					</div>
					<div>
						<span>ضریب</span>
						<input type="number" step="0.0001" class="goldmate-f-multiplier" name="<?php echo esc_attr( $p ); ?>[multiplier]" value="<?php echo esc_attr( $item['multiplier'] ); ?>">
					</div>
				</div>
				<div class="gm-field gm-field--inline">
					<div>
						<span>جداکننده اعشار</span>
						<input type="text" name="<?php echo esc_attr( $p ); ?>[decimal_sep]" value="<?php echo esc_attr( isset( $cfg['decimal_sep'] ) ? $cfg['decimal_sep'] : '.' ); ?>">
					</div>
					<div>
						<span>جداکننده هزارگان</span>
						<input type="text" name="<?php echo esc_attr( $p ); ?>[thousands_sep]" value="<?php echo esc_attr( isset( $cfg['thousands_sep'] ) ? $cfg['thousands_sep'] : ',' ); ?>">
					</div>
				</div>
				<details class="gm-advanced">
					<summary>تنظیمات پیشرفته API</summary>
					<?php
					self::field(
						'کلید API',
						'<input type="text" class="goldmate-f-key" name="' . esc_attr( $p ) . '[api_key]" value="' . esc_attr( isset( $cfg['api_key'] ) ? $cfg['api_key'] : '' ) . '" dir="ltr" autocomplete="off">'
					);
					self::field(
						'هدر کلید',
						'<input type="text" class="goldmate-f-key-header" name="' . esc_attr( $p ) . '[api_key_header]" value="' . esc_attr( isset( $cfg['api_key_header'] ) ? $cfg['api_key_header'] : '' ) . '" dir="ltr">'
					);
					?>
					<div class="gm-field gm-field--inline">
						<div>
							<span>مسیر زمان</span>
							<input type="text" class="goldmate-f-time-path" name="<?php echo esc_attr( $p ); ?>[time_path]" value="<?php echo esc_attr( isset( $cfg['time_path'] ) ? $cfg['time_path'] : '' ); ?>" dir="ltr">
						</div>
						<div>
							<span>حداکثر قدمت (دقیقه)</span>
							<input type="number" class="goldmate-f-max-age" name="<?php echo esc_attr( $p ); ?>[max_age]" value="<?php echo esc_attr( isset( $cfg['max_age'] ) ? (int) $cfg['max_age'] : 0 ); ?>" min="0">
						</div>
					</div>
				</details>
			</td>

			<td class="gm-col-tax">
				<?php
				self::field(
					'مالیات ٪',
					'<input type="number" step="0.01" name="' . esc_attr( $p ) . '[tax_pct]" value="' . esc_attr( $item['tax_pct'] ) . '">'
				);
				self::field(
					'سود ٪',
					'<input type="number" step="0.01" name="' . esc_attr( $p ) . '[profit_pct]" value="' . esc_attr( $item['profit_pct'] ) . '">'
				);
				?>
			</td>

			<td class="gm-col-cron">
				<label class="gm-check">
					<input type="checkbox" name="<?php echo esc_attr( $p ); ?>[auto_fetch]" value="1" <?php checked( $item['auto_fetch'] ); ?>>
					فعال
				</label>
				<?php
				self::field(
					'فاصله (دقیقه)',
					'<input type="number" name="' . esc_attr( $p ) . '[fetch_interval]" value="' . esc_attr( $item['fetch_interval'] ) . '" min="1">'
				);
				?>
			</td>

			<td class="gm-col-opts">
				<label class="gm-check">
					<input type="checkbox" name="<?php echo esc_attr( $p ); ?>[show_in_product_list]" value="1" <?php checked( $item['show_in_product_list'] ); ?>>
					در لیست محصول
				</label>
				<label class="gm-check">
					<input type="checkbox" name="<?php echo esc_attr( $p ); ?>[show_in_calc]" value="1" <?php checked( $item['show_in_calc'] ); ?>>
					در ماشین‌حساب
				</label>
				<label class="gm-check">
					<input type="checkbox" name="<?php echo esc_attr( $p ); ?>[is_18k_reference]" value="1" <?php checked( $item['is_18k_reference'] ); ?>>
					مرجع مقیاس عیار (۱۸)
				</label>
				<?php
				self::field(
					'نوع فرمول',
					'<select name="' . esc_attr( $p ) . '[formula_type]">
						<option value="weight"' . selected( $item['formula_type'], 'weight', false ) . '>بر اساس وزن</option>
					</select>'
				);
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Saves the posted items grid.
	 *
	 * @param array $post $_POST.
	 */
	public static function save_from_post( $post ) {
		if ( empty( $post['goldmate_items'] ) || ! is_array( $post['goldmate_items'] ) ) {
			return;
		}

		foreach ( $post['goldmate_items'] as $id => $row ) {
			$id = (int) $id;
			if ( $id <= 0 || ! is_array( $row ) ) {
				continue;
			}

			$existing = Goldmate_Rate_Items::get( $id );
			if ( ! $existing ) {
				continue;
			}

			$config = array(
				'url'            => isset( $row['url'] ) ? goldmate_sanitize_endpoint_url( wp_unslash( $row['url'] ) ) : '',
				'path'           => isset( $row['path'] ) ? sanitize_text_field( wp_unslash( $row['path'] ) ) : '',
				'selector'       => isset( $row['selector'] ) ? sanitize_text_field( wp_unslash( $row['selector'] ) ) : '',
				'api_key'        => isset( $row['api_key'] ) ? sanitize_text_field( wp_unslash( $row['api_key'] ) ) : '',
				'api_key_header' => isset( $row['api_key_header'] ) ? sanitize_text_field( wp_unslash( $row['api_key_header'] ) ) : '',
				'time_path'      => isset( $row['time_path'] ) ? sanitize_text_field( wp_unslash( $row['time_path'] ) ) : '',
				'max_age'        => isset( $row['max_age'] ) ? max( 0, (int) $row['max_age'] ) : 0,
				'fallbacks'      => '',
				'brsapi_key'     => '',
				'decimal_sep'    => isset( $row['decimal_sep'] ) ? sanitize_text_field( wp_unslash( $row['decimal_sep'] ) ) : '.',
				'thousands_sep'  => isset( $row['thousands_sep'] ) ? sanitize_text_field( wp_unslash( $row['thousands_sep'] ) ) : ',',
			);

			$slug = isset( $row['slug'] ) ? sanitize_title( wp_unslash( $row['slug'] ) ) : $existing['slug'];
			if ( 'gold18' === $existing['slug'] ) {
				$slug = 'gold18';
			}

			$data = array(
				'slug'                 => $slug,
				'label'                => isset( $row['label'] ) ? sanitize_text_field( wp_unslash( $row['label'] ) ) : $existing['label'],
				'priority'             => isset( $row['priority'] ) ? (int) $row['priority'] : $existing['priority'],
				'source_type'          => isset( $row['source_type'] ) ? sanitize_key( wp_unslash( $row['source_type'] ) ) : 'manual',
				'source_config'        => $config,
				'unit'                 => isset( $row['unit'] ) ? sanitize_key( wp_unslash( $row['unit'] ) ) : 'toman',
				'multiplier'           => isset( $row['multiplier'] ) ? (float) $row['multiplier'] : 1,
				'adjust_mode'          => isset( $row['adjust_mode'] ) ? sanitize_key( wp_unslash( $row['adjust_mode'] ) ) : 'none',
				'adjust_value'         => isset( $row['adjust_value'] ) ? (float) $row['adjust_value'] : 0,
				'adjust_buy_mode'      => isset( $row['adjust_buy_mode'] ) ? sanitize_key( wp_unslash( $row['adjust_buy_mode'] ) ) : 'none',
				'adjust_buy_value'     => isset( $row['adjust_buy_value'] ) ? (float) $row['adjust_buy_value'] : 0,
				'adjust_sell_mode'     => isset( $row['adjust_sell_mode'] ) ? sanitize_key( wp_unslash( $row['adjust_sell_mode'] ) ) : 'none',
				'adjust_sell_value'    => isset( $row['adjust_sell_value'] ) ? (float) $row['adjust_sell_value'] : 0,
				'tax_pct'              => isset( $row['tax_pct'] ) ? (float) $row['tax_pct'] : 0,
				'profit_pct'           => isset( $row['profit_pct'] ) ? (float) $row['profit_pct'] : 0,
				'fetch_interval'       => isset( $row['fetch_interval'] ) ? max( 1, (int) $row['fetch_interval'] ) : 60,
				'auto_fetch'           => ! empty( $row['auto_fetch'] ) ? 1 : 0,
				'show_in_calc'         => ! empty( $row['show_in_calc'] ) ? 1 : 0,
				'show_in_product_list' => ! empty( $row['show_in_product_list'] ) ? 1 : 0,
				'is_18k_reference'     => ! empty( $row['is_18k_reference'] ) ? 1 : 0,
				'formula_type'         => isset( $row['formula_type'] ) ? sanitize_key( wp_unslash( $row['formula_type'] ) ) : 'weight',
			);

			Goldmate_Rate_Items::update( $id, $data );

			if ( isset( $row['rate'] ) ) {
				$new_rate = goldmate_positive_float( wp_unslash( $row['rate'] ) );
				if ( $new_rate > 0 && abs( $new_rate - (float) $existing['rate'] ) > 0.0001 ) {
					Goldmate_Rate_Items::set_rate( $id, $new_rate, 'manual' );
				}
			}
		}
	}
}
