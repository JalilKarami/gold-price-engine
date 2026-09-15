<?php
/**
 * Admin UI for named pricing formulas (Ratesbox-style grid).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Admin_Formulas {

	/**
	 * Renders the formulas grid.
	 */
	public static function render() {
		$items = Goldmate_Formulas::all();
		self::print_styles();
		$rate_choices = class_exists( 'Goldmate_Rate_Items' ) ? Goldmate_Rate_Items::choices( false ) : array();
		?>
		<div class="gm-items">
			<div class="gm-items__toolbar">
				<h2 class="gm-items__title">فرمول‌های قیمت‌گذاری</h2>
				<div class="gm-items__actions">
					<button type="submit" class="button button-primary" name="goldmate_action" value="save_formulas">ذخیره همه</button>
					<button type="submit" class="button" name="goldmate_action" value="add_formula">اضافه کردن فرمول</button>
				</div>
			</div>

			<div class="gm-items__scroll">
				<table class="gm-items__table">
					<thead>
						<tr>
							<th class="gm-col-item">فرمول</th>
							<th class="gm-col-price">نوع</th>
							<th class="gm-col-extract">آیتم نرخ</th>
							<th class="gm-col-cron">وضعیت / اولویت</th>
							<th class="gm-col-opts">تنظیمات</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $items as $item ) : ?>
						<?php self::render_row( $item, $rate_choices ); ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="gm-items__toolbar gm-items__toolbar--footer">
				<div class="gm-items__actions">
					<button type="submit" class="button button-primary" name="goldmate_action" value="save_formulas">ذخیره همه</button>
				</div>
			</div>
			<p class="description gm-items__hint">هر فرمول یک «دستور پخت» نام‌دار است: نوع محاسبه + آیتم نرخی که خوانده می‌شود. محصول فقط فرمول را انتخاب می‌کند و دیگر نمی‌فهمد کدام API پشت آن است.</p>
		</div>
		<?php
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
				min-width: 980px;
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
			.gm-col-item { width: 220px; }
			.gm-col-price { width: 180px; }
			.gm-col-extract { width: 240px; }
			.gm-col-cron { width: 160px; }
			.gm-col-opts { width: 120px; }

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
			.gm-items__hint { margin-top: 10px; max-width: 920px; }

			.gm-formula-badge {
				display: inline-block;
				font-size: 10px;
				font-weight: 600;
				padding: 2px 6px;
				border-radius: 2px;
				margin-inline-start: 6px;
				vertical-align: middle;
			}
			.gm-formula-badge--default { background: #e8f0fe; color: #1a73e8; }
			.gm-formula-badge--active { background: #edfaef; color: #1e7e34; }
			.gm-formula-badge--inactive { background: #fcf0f1; color: #b32d2e; }
		</style>
		<?php
	}

	/**
	 * @param array $item         Formula.
	 * @param array $rate_choices Rate item choices.
	 */
	protected static function render_row( $item, $rate_choices ) {
		$id     = (int) $item['id'];
		$p      = 'goldmate_formulas[' . $id . ']';
		$locked = (bool) $item['is_default'];

		$status_label = 'active' === $item['status']
			? '<span class="gm-formula-badge gm-formula-badge--active">فعال</span>'
			: '<span class="gm-formula-badge gm-formula-badge--inactive">غیرفعال</span>';

		$default_badge = $locked
			? '<span class="gm-formula-badge gm-formula-badge--default">پیش‌فرض</span>'
			: '';
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
				?>
				<div class="gm-item-actions">
					<?php if ( ! $locked ) : ?>
						<button type="submit" class="button button-primary" name="goldmate_action" value="set_default_formula_<?php echo esc_attr( $id ); ?>">پیش‌فرض</button>
					<?php endif; ?>
					<button type="submit" class="button button-link-delete" name="goldmate_action" value="del_formula_<?php echo esc_attr( $id ); ?>" onclick="return confirm('حذف این فرمول؟');" <?php disabled( $locked, true ); ?>>حذف</button>
				</div>
			</td>

			<td class="gm-col-price">
				<?php
				self::field(
					'نوع فرمول',
					'<select name="' . esc_attr( $p ) . '[formula_type]">' . self::options_html( Goldmate_Formulas::type_options(), $item['formula_type'] ) . '</select>'
				);
				echo '<p class="description">' . esc_html( Goldmate_Formulas::type_label( $item['formula_type'] ) ) . '</p>';
				?>
			</td>

			<td class="gm-col-extract">
				<?php
				self::field(
					'آیتم نرخ',
					'<select name="' . esc_attr( $p ) . '[rate_slug]">' . self::options_html( $rate_choices, $item['rate_slug'] ) . '</select>'
				);
				?>
				<p class="description">قیمت روز از این آیتم کاتالوگ خوانده می‌شود.</p>
			</td>

			<td class="gm-col-cron">
				<?php
				self::field(
					'وضعیت',
					'<select name="' . esc_attr( $p ) . '[status]">
						<option value="active" ' . selected( $item['status'], 'active', false ) . '>فعال</option>
						<option value="inactive" ' . selected( $item['status'], 'inactive', false ) . '>غیرفعال</option>
					</select>'
				);
				self::field(
					'اولویت',
					'<input type="number" name="' . esc_attr( $p ) . '[priority]" value="' . esc_attr( $item['priority'] ) . '" min="0" step="1">'
				);
				echo $status_label . $default_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above with esc_html.
				?>
			</td>

			<td class="gm-col-opts">
				<label class="gm-check">
					<input type="checkbox" name="<?php echo esc_attr( $p ); ?>[is_default]" value="1" <?php checked( $item['is_default'] ); ?> <?php disabled( $locked, true ); ?>>
					پیش‌فرض
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Builds option tags.
	 *
	 * @param array  $options Options.
	 * @param string $current Current value.
	 * @return string
	 */
	protected static function options_html( $options, $current ) {
		$out = '';
		foreach ( $options as $key => $label ) {
			$out .= sprintf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $key ),
				selected( (string) $key, (string) $current, false ),
				esc_html( $label )
			);
		}
		return $out;
	}

	/**
	 * @param string $label Label.
	 * @param string $html  Control HTML.
	 */
	protected static function field( $label, $html ) {
		echo '<label class="gm-field"><span>' . esc_html( $label ) . '</span>' . $html . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller escapes.
	}

	/**
	 * Saves the posted formulas grid.
	 *
	 * @param array $post $_POST.
	 */
	public static function save_from_post( $post ) {
		if ( empty( $post['goldmate_formulas'] ) || ! is_array( $post['goldmate_formulas'] ) ) {
			return;
		}

		$default_id = 0;

		foreach ( $post['goldmate_formulas'] as $id => $row ) {
			$id = (int) $id;
			if ( $id <= 0 || ! is_array( $row ) ) {
				continue;
			}

			$existing = Goldmate_Formulas::get( $id );
			if ( ! $existing ) {
				continue;
			}

			$slug = isset( $row['slug'] ) ? sanitize_title( wp_unslash( $row['slug'] ) ) : $existing['slug'];
			if ( $existing['is_default'] ) {
				$slug = $existing['slug'];
			}

			$is_default = ! empty( $row['is_default'] );
			if ( $is_default ) {
				$default_id = $id;
			}

			$data = array(
				'slug'         => $slug,
				'label'        => isset( $row['label'] ) ? sanitize_text_field( wp_unslash( $row['label'] ) ) : $existing['label'],
				'formula_type' => isset( $row['formula_type'] ) ? sanitize_key( wp_unslash( $row['formula_type'] ) ) : $existing['formula_type'],
				'rate_slug'    => isset( $row['rate_slug'] ) ? sanitize_title( wp_unslash( $row['rate_slug'] ) ) : $existing['rate_slug'],
				'status'       => isset( $row['status'] ) ? sanitize_key( wp_unslash( $row['status'] ) ) : $existing['status'],
				'priority'     => isset( $row['priority'] ) ? (int) $row['priority'] : $existing['priority'],
				'is_default'   => $is_default ? 1 : 0,
				'updated_at'   => time(),
			);

			Goldmate_Formulas::update( $id, $data );
		}

		if ( $default_id > 0 ) {
			Goldmate_Formulas::set_default( $default_id );
		}
	}
}
