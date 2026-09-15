<?php
/**
 * The status tab: current rate, batch progress and the rate history table.
 *
 * Split out of Goldmate_Admin, which was rendering four tabs inline. The
 * progress bar lives here too, because the status tab owns it; Goldmate_Admin's
 * AJAX endpoint just calls back into this class to re-render it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Status {

	/**
	 * Renders the status and tools tab.
	 */
	public static function render_admin_tab() {

		$ref            = class_exists( 'Goldmate_Rate_Items' ) ? Goldmate_Rate_Items::reference_item() : null;
		$rate           = goldmate_reference_rate();
		$updated        = $ref ? (int) $ref['updated_at'] : (int) get_option( 'goldmate_rate_updated_at', 0 );
		$checked        = $ref ? (int) $ref['checked_at'] : (int) get_option( 'goldmate_rate_checked_at', 0 );
		$error          = $ref ? (string) $ref['last_error'] : (string) get_option( 'goldmate_rate_last_error', '' );
		$count          = Goldmate_Pricing::count_enabled();
		$progress       = Goldmate_Batch::get_progress();
		$is_stale       = class_exists( 'Goldmate_Rate_Items' ) ? Goldmate_Rate_Items::is_reference_stale() : false;
		$scheduler      = Goldmate_Batch::has_scheduler() ? 'Action Scheduler' : 'WP-Cron';
		$live_interval  = (int) goldmate_option( 'goldmate_live_interval' );
		$change_pct     = Goldmate_Live::change_pct();

		$all_items  = class_exists( 'Goldmate_Rate_Items' ) ? Goldmate_Rate_Items::all() : array();
		$auto_items = class_exists( 'Goldmate_Rate_Items' ) ? Goldmate_Rate_Items::all( array( 'auto_fetch' => true ) ) : array();
		$auto_count = count( $auto_items );
		$ok_count   = 0;
		$err_count  = 0;
		foreach ( $all_items as $it ) {
			if ( empty( $it['last_error'] ) ) {
				$ok_count++;
			} else {
				$err_count++;
			}
		}
		?>
		<?php if ( $auto_count > 1 ) : ?>
			<div class="notice notice-warning" style="max-width:960px;margin:12px 0;">
				<p>
					<?php echo esc_html( number_format_i18n( $auto_count ) ); ?>
					آیتم با «فراخوانی خودکار» فعال است
					(<?php echo esc_html( implode( '، ', wp_list_pluck( $auto_items, 'slug' ) ) ); ?>).
					برای نرخ ۱۸ عیار معمولاً فقط یک آیتم مرجع (مثلاً gold18) باید خودکار باشد؛ بقیه را دستی کنید تا نرخ‌ها روی هم ننویسند.
				</p>
			</div>
		<?php endif; ?>
		<div class="goldmate-dashboard" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;max-width:960px;margin:16px 0 8px;">
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:14px 16px;">
				<div style="font-size:12px;color:#646970;">نرخ ۱۸ عیار</div>
				<div style="font-size:1.35em;font-weight:600;margin-top:4px;"><?php echo $rate > 0 ? wp_kses_post( wc_price( $rate ) ) : '—'; ?></div>
				<?php if ( null !== $change_pct ) : ?>
					<div style="margin-top:4px;color:<?php echo $change_pct > 0 ? '#1e7e34' : ( $change_pct < 0 ? '#b32d2e' : '#646970' ); ?>;">
						<?php echo esc_html( Goldmate_Live::format_change_pct( $change_pct ) ); ?> نسبت به قبل
					</div>
				<?php endif; ?>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:14px 16px;">
				<div style="font-size:12px;color:#646970;">سلامت دریافت</div>
				<div style="font-size:1.2em;font-weight:600;margin-top:4px;">
					<?php
					printf(
						'%s بدون خطا / %s با خطا',
						esc_html( number_format_i18n( $ok_count ) ),
						esc_html( number_format_i18n( $err_count ) )
					);
					?>
				</div>
				<div style="margin-top:4px;font-size:12px;color:#646970;">
					<?php echo esc_html( number_format_i18n( count( $all_items ) ) ); ?> آیتم نرخ
				</div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:14px 16px;">
				<div style="font-size:12px;color:#646970;">آیتم‌های خودکار</div>
				<div style="font-size:1.1em;font-weight:600;margin-top:4px;">
					<?php echo esc_html( number_format_i18n( $auto_count ) ); ?>
				</div>
				<div style="margin-top:4px;font-size:12px;color:#646970;">
					فراخوانی از تب تنظیمات فراخوانی قیمت
				</div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:14px 16px;">
				<div style="font-size:12px;color:#646970;">به‌روزرسانی دسته‌ای</div>
				<div style="font-size:1.1em;font-weight:600;margin-top:4px;">
					<?php echo Goldmate_Batch::is_running() ? 'در حال اجرا' : 'آماده'; ?>
				</div>
				<div style="margin-top:4px;font-size:12px;color:#646970;">
					<?php echo esc_html( number_format_i18n( $count ) ); ?> محصول طلا
				</div>
			</div>
		</div>

		<?php if ( class_exists( 'Goldmate_Rate_Items' ) && Goldmate_Install::tables_exist() ) : ?>
			<h2 style="margin-top:24px;">آیتم‌های نرخ</h2>
			<table class="widefat striped" style="max-width:960px;">
				<thead>
					<tr>
						<th>آیتم</th>
						<th>نرخ</th>
						<th>منبع</th>
						<th>آخرین به‌روزرسانی</th>
						<th>خودکار</th>
						<th>خطا</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( Goldmate_Rate_Items::all() as $item ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $item['label'] ); ?></strong><br><code><?php echo esc_html( $item['slug'] ); ?></code></td>
						<td><?php echo $item['rate'] > 0 ? wp_kses_post( wc_price( $item['rate'] ) ) : '—'; ?></td>
						<td><?php echo esc_html( $item['source_type'] ); ?></td>
						<td><?php echo $item['updated_at'] ? esc_html( goldmate_format_time( $item['updated_at'] ) ) : '—'; ?></td>
						<td><?php echo $item['auto_fetch'] ? 'بله' : 'خیر'; ?></td>
						<td style="color:#b32d2e;"><?php echo $item['last_error'] ? esc_html( mb_substr( $item['last_error'], 0, 80 ) ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><a class="button" href="<?php echo esc_url( Goldmate_Admin::url( 'fetch' ) ); ?>">مدیریت فراخوانی قیمت</a></p>
		<?php endif; ?>

		<h2 style="margin-top:24px;">وضعیت فعلی</h2>
		<table class="widefat striped" style="max-width:960px;">
			<tbody>
				<tr>
					<th style="width:260px;">قیمت روز هر گرم ۱۸ عیار</th>
					<td>
						<?php echo $rate > 0 ? wp_kses_post( wc_price( $rate ) ) : '—'; ?>
						<?php if ( $is_stale ) : ?>
							<strong style="color:#b32d2e;"> — کهنه</strong>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th>آخرین تغییر قیمت</th>
					<td><?php echo esc_html( goldmate_format_time( $updated ) ); ?></td>
				</tr>
				<tr>
					<th>آخرین بررسی سرویس</th>
					<td><?php echo esc_html( goldmate_format_time( $checked ) ); ?></td>
				</tr>
				<tr>
					<th>بروزرسانی آنی فرانت</th>
					<td>
						<?php
						echo $live_interval > 0
							? esc_html( sprintf( 'هر %s ثانیه', number_format_i18n( $live_interval ) ) )
							: 'غیرفعال';
						?>
					</td>
				</tr>
				<tr>
					<th>محصولات با محاسبه‌ی خودکار</th>
					<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
				</tr>
				<tr>
					<th>زمان‌بند پس‌زمینه</th>
					<td><?php echo esc_html( $scheduler ); ?></td>
				</tr>
				<?php if ( '' !== $error ) : ?>
					<tr>
						<th>آخرین خطا</th>
						<td style="color:#b32d2e;"><?php echo esc_html( $error ); ?></td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<h2 style="margin-top:24px;">شورت‌کدها</h2>
		<table class="widefat striped" style="max-width:960px;">
			<tbody>
				<tr>
					<th style="width:260px;"><code>[goldmate_price_banner]</code></th>
					<td>بنر نرخ ۱۸ عیار (هم‌تراز با قیمت‌های کاتالوگ)</td>
				</tr>
				<tr>
					<th><code>[goldmate_rate_board]</code></th>
					<td>تابلو نرخ + درصد تغییر + زمان به‌روزرسانی</td>
				</tr>
				<tr>
					<th><code>[goldmate_calculator]</code></th>
					<td>ماشین‌حساب برآورد قیمت برای بازدیدکننده</td>
				</tr>
			</tbody>
		</table>

		<h2 style="margin-top:24px;">به‌روزرسانی دسته‌ای</h2>
		<div id="goldmate-progress" data-running="<?php echo Goldmate_Batch::is_running() ? '1' : '0'; ?>" style="max-width:960px;">
			<?php self::render_progress( $progress ); ?>
		</div>
		<p>
			<?php self::button( 'recalculate', 'محاسبه‌ی دوباره‌ی همه‌ی قیمت‌ها', 'button-primary' ); ?>
			<?php if ( Goldmate_Batch::is_running() ) : ?>
				<?php self::button( 'cancel_batch', 'توقف' ); ?>
			<?php endif; ?>
		</p>

		<h2 style="margin-top:24px;">تاریخچه‌ی قیمت روز</h2>
		<p class="description" style="max-width:960px;">فقط تغییرات واقعی قیمت مرجع (gold18) ثبت می‌شود.</p>
		<?php self::render_history(); ?>
		<?php
	}

	/**
	 * Renders the batch progress bar.
	 *
	 * @param array $progress Progress state.
	 */
	public static function render_progress( $progress ) {

		if ( empty( $progress ) ) {
			echo '<p class="description">تاکنون به‌روزرسانی دسته‌ای اجرا نشده است.</p>';
			return;
		}

		$total   = max( 1, (int) ( $progress['total'] ?? 0 ) );
		$done    = (int) ( $progress['done'] ?? 0 );
		$percent = min( 100, (int) round( $done / $total * 100 ) );
		$running = empty( $progress['finished'] );
		?>
		<div style="background:#e5e5e5;border-radius:4px;height:18px;overflow:hidden;margin-bottom:8px;">
			<div style="background:<?php echo $running ? '#2271b1' : '#00a32a'; ?>;height:100%;width:<?php echo (int) $percent; ?>%;transition:width .3s;"></div>
		</div>
		<p class="description">
			<?php
			printf(
				'%s از %s محصول بررسی شد (%s قیمت‌گذاری شد). وضعیت: %s',
				esc_html( number_format_i18n( $done ) ),
				esc_html( number_format_i18n( (int) ( $progress['total'] ?? 0 ) ) ),
				esc_html( number_format_i18n( (int) ( $progress['priced'] ?? 0 ) ) ),
				$running ? 'در حال اجرا' : esc_html( 'پایان‌یافته در ' . goldmate_format_time( (int) $progress['finished'] ) )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Renders the rate history table.
	 */
	protected static function render_history() {

		$history = class_exists( 'Goldmate_Rate_Items' )
			? Goldmate_Rate_Items::reference_history( 30 )
			: array();

		if ( empty( $history ) ) {
			echo '<p class="description">هنوز تغییری ثبت نشده است.</p>';
			return;
		}
		?>
		<table class="widefat striped" style="max-width:820px;">
			<thead>
				<tr>
					<th style="width:180px;">زمان</th>
					<th>قیمت هر گرم ۱۸ عیار</th>
					<th style="width:140px;">منبع</th>
					<th style="width:180px;">کاربر</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $history as $entry ) : ?>
					<?php
					$user = ! empty( $entry['user'] ) ? get_userdata( $entry['user'] ) : false;

					$provider_label = 'auto' === $entry['source']
						? Goldmate_Fetcher::provider_label( isset( $entry['provider'] ) ? $entry['provider'] : '' )
						: '';
					?>
					<tr>
						<td><?php echo esc_html( goldmate_format_time( $entry['at'] ) ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $entry['rate'] ) ); ?></td>
						<td>
							<?php if ( 'auto' === $entry['source'] ) : ?>
								خودکار<?php echo '' !== $provider_label ? ' — ' . esc_html( $provider_label ) : ''; ?>
							<?php else : ?>
								دستی
							<?php endif; ?>
						</td>
						<td><?php echo $user ? esc_html( $user->display_name ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders a self-submitting tool button.
	 *
	 * @param string $action Action key.
	 * @param string $label  Button label.
	 * @param string $class  Extra CSS classes.
	 */
	protected static function button( $action, $label, $class = 'button-secondary' ) {
		?>
		<form method="post" action="<?php echo esc_url( Goldmate_Admin::url( 'status' ) ); ?>" style="display:inline-block;margin-inline-end:6px;">
			<?php wp_nonce_field( 'goldmate_admin' ); ?>
			<input type="hidden" name="goldmate_action" value="<?php echo esc_attr( $action ); ?>">
			<button type="submit" class="button <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}
}
