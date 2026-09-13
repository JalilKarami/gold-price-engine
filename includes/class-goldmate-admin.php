<?php
/**
 * The dedicated "قیمت طلا" admin screen: settings, tools and background-job status.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Admin {

	const SLUG       = 'goldmate';
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Messages queued during the POST handler, rendered after the header.
	 *
	 * @var array<int,array{type:string,text:string}>
	 */
	protected static $notices = array();

	/**
	 * Registers the admin screen, its assets and its AJAX endpoints.
	 */
	public static function init() {

		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_notices', array( __CLASS__, 'global_notices' ) );
		add_action( 'wp_ajax_goldmate_progress', array( __CLASS__, 'ajax_progress' ) );

		// The 1.x settings lived in a WooCommerce tab; point anyone who lands there at the new screen.
		add_filter( 'woocommerce_settings_tabs_array', array( __CLASS__, 'add_wc_tab' ), 60 );
		add_action( 'woocommerce_settings_tabs_goldmate', array( __CLASS__, 'render_wc_tab_redirect' ) );
	}

	/**
	 * Adds the top-level menu entry.
	 */
	public static function register_menu() {

		add_menu_page(
			'قیمت طلا',
			'قیمت طلا',
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-line',
			56
		);
	}

	/**
	 * Keeps a WooCommerce tab that simply links to the real screen.
	 *
	 * @param array $tabs Existing tabs.
	 * @return array
	 */
	public static function add_wc_tab( $tabs ) {

		$tabs['goldmate'] = 'قیمت طلا';

		return $tabs;
	}

	/**
	 * Renders the pointer shown inside the legacy WooCommerce tab.
	 */
	public static function render_wc_tab_redirect() {

		printf(
			'<p style="padding:20px 0;">تنظیمات قیمت طلا به صفحه‌ی اختصاصی خود منتقل شده است. <a class="button button-primary" href="%s">رفتن به صفحه‌ی قیمت طلا</a></p>',
			esc_url( self::url() )
		);
	}

	/**
	 * URL of the admin screen.
	 *
	 * @param string $tab Optional tab key.
	 * @return string
	 */
	public static function url( $tab = '' ) {

		$args = array( 'page' => self::SLUG );

		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Currently selected tab.
	 *
	 * @return string
	 */
	protected static function current_tab() {

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'pricing';
		$tabs = Goldmate_Settings::tabs();

		return isset( $tabs[ $tab ] ) ? $tab : 'pricing';
	}

	/* ---------------------------------------------------------------------
	 *  POST handling
	 * ------------------------------------------------------------------ */

	/**
	 * Processes settings saves and tool buttons.
	 */
	public static function handle_post() {

		if ( ! isset( $_POST['goldmate_action'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'دسترسی لازم را ندارید.' );
		}

		check_admin_referer( 'goldmate_admin' );

		$action = sanitize_key( wp_unslash( $_POST['goldmate_action'] ) );
		$tab    = self::current_tab();

		switch ( $action ) {

			case 'save':
				$warnings = Goldmate_Settings::save( $tab, $_POST );

				foreach ( $warnings as $warning ) {
					self::notice( 'warning', $warning );
				}

				if ( 'pricing' === $tab && isset( $_POST['goldmate_rate_per_gram'] ) ) {
					self::save_rate( wp_unslash( $_POST['goldmate_rate_per_gram'] ) );
				}

				if ( 'fetch' === $tab ) {
					Goldmate_Rates::reschedule();
				}

				self::notice( 'success', 'تنظیمات ذخیره شد.' );
				break;

			case 'recalculate':
				Goldmate_Batch::start( 'اجرای دستی' );
				self::notice( 'success', 'به‌روزرسانی قیمت‌ها در پس‌زمینه آغاز شد.' );
				break;

			case 'cancel_batch':
				Goldmate_Batch::cancel();
				$progress             = Goldmate_Batch::get_progress();
				$progress['finished'] = time();
				update_option( Goldmate_Batch::PROGRESS_KEY, $progress, false );
				self::notice( 'warning', 'به‌روزرسانی دسته‌ای متوقف شد. ممکن است بخشی از محصولات با قیمت قبلی مانده باشند.' );
				break;

			case 'test_fetch':
				$result = Goldmate_Rates::fetch();

				if ( '' !== $result['error'] ) {
					self::notice( 'error', 'دریافت آزمایشی ناموفق بود: ' . $result['error'] );
					if ( '' !== $result['raw'] ) {
						self::notice( 'info', 'ابتدای پاسخ سرویس: ' . $result['raw'] );
					}
					break;
				}

				self::notice(
					'success',
					sprintf(
						'دریافت آزمایشی موفق بود. قیمت خوانده‌شده: %s (اعمال نشد).',
						wp_strip_all_tags( wc_price( $result['rate'] ) )
					)
				);
				break;

			case 'fetch_now':
				$result = Goldmate_Rates::run_fetch( false );

				if ( '' !== $result['error'] ) {
					self::notice( 'error', $result['error'] );
					break;
				}

				self::notice( 'success', 'قیمت روز دریافت و اعمال شد.' );
				break;

			case 'accept_pending':
				$pending = goldmate_positive_float( get_option( 'goldmate_pending_rate', 0 ) );

				if ( $pending <= 0 ) {
					self::notice( 'error', 'قیمتی در انتظار تأیید نیست.' );
					break;
				}

				Goldmate_Rates::set_rate( $pending, 'manual' );
				self::notice( 'success', 'قیمت در انتظار تأیید اعمال شد.' );
				break;

			case 'discard_pending':
				delete_option( 'goldmate_pending_rate' );
				update_option( 'goldmate_rate_last_error', '', false );
				self::notice( 'success', 'قیمت در انتظار تأیید نادیده گرفته شد.' );
				break;

			case 'clear_log':
				Goldmate_Rates::clear_log();
				self::notice( 'success', 'گزارش دریافت‌ها پاک شد.' );
				break;
		}
	}

	/**
	 * Stores a manually entered rate through the rate writer.
	 *
	 * @param mixed $raw Submitted value.
	 */
	protected static function save_rate( $raw ) {

		$rate    = goldmate_positive_float( $raw );
		$current = goldmate_positive_float( goldmate_option( 'goldmate_rate_per_gram' ) );

		if ( $rate <= 0 ) {
			update_option( 'goldmate_rate_per_gram', 0 );
			return;
		}

		if ( abs( $rate - $current ) < 0.0001 ) {
			return;
		}

		Goldmate_Rates::set_rate( $rate, 'manual' );

		self::notice( 'info', 'قیمت روز تغییر کرد؛ به‌روزرسانی قیمت محصولات در پس‌زمینه آغاز شد.' );
	}

	/**
	 * Queues a notice for the current request.
	 *
	 * @param string $type success|error|warning|info.
	 * @param string $text Message.
	 */
	protected static function notice( $type, $text ) {
		self::$notices[] = array(
			'type' => $type,
			'text' => $text,
		);
	}

	/* ---------------------------------------------------------------------
	 *  Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the whole admin screen.
	 */
	public static function render_page() {

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tab  = self::current_tab();
		$tabs = Goldmate_Settings::tabs();
		?>
		<div class="wrap goldmate-wrap">
			<h1>قیمت طلا</h1>

			<nav class="nav-tab-wrapper" style="margin-bottom:16px;">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>"
					   class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php foreach ( self::$notices as $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>">
					<p><?php echo esc_html( $notice['text'] ); ?></p>
				</div>
			<?php endforeach; ?>

			<?php
			if ( 'status' === $tab ) {
				self::render_status_tab();
			} else {
				self::render_settings_tab( $tab );
			}
			?>
		</div>
		<?php
		self::print_styles();
		self::print_scripts();
	}

	/**
	 * Renders a settings tab from its field definitions.
	 *
	 * @param string $tab Tab key.
	 */
	protected static function render_settings_tab( $tab ) {

		$fields = Goldmate_Settings::fields( $tab );
		?>
		<form method="post" action="<?php echo esc_url( self::url( $tab ) ); ?>">
			<?php wp_nonce_field( 'goldmate_admin' ); ?>
			<input type="hidden" name="goldmate_action" value="save">

			<?php
			$open = false;

			foreach ( $fields as $field ) {

				if ( 'section' === $field['type'] ) {

					if ( $open ) {
						echo '</table>';
					}

					printf( '<h2 style="margin-top:24px;">%s</h2>', esc_html( $field['title'] ) );

					if ( ! empty( $field['desc'] ) ) {
						printf( '<p class="description">%s</p>', esc_html( $field['desc'] ) );
					}

					echo '<table class="form-table" role="presentation">';
					$open = true;
					continue;
				}

				if ( ! $open ) {
					echo '<table class="form-table" role="presentation">';
					$open = true;
				}

				self::render_field( $field );
			}

			if ( $open ) {
				echo '</table>';
			}
			?>

			<?php submit_button( 'ذخیره تنظیمات' ); ?>
		</form>
		<?php
		if ( 'fetch' === $tab ) {
			self::print_presets();
		}
	}

	/**
	 * Ships the endpoint presets to the browser so picking a source can prefill
	 * the blank connection fields.
	 */
	protected static function print_presets() {

		$presets = Goldmate_Rates::presets();
		?>
		<div id="goldmate-preset-note" class="notice notice-info inline" style="display:none;max-width:820px;"><p></p></div>
		<script type="application/json" id="goldmate-presets"><?php echo wp_json_encode( $presets ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON in a non-executing script tag. ?></script>
		<script>
		( function () {
			var holder = document.getElementById( 'goldmate-presets' );
			var source = document.getElementById( 'goldmate_rate_source' );
			var note = document.getElementById( 'goldmate-preset-note' );
			if ( ! holder || ! source ) {
				return;
			}
			var presets = JSON.parse( holder.textContent );
			var map = {
				url: 'goldmate_api_url',
				path: 'goldmate_api_path',
				multiplier: 'goldmate_api_multiplier',
				key_header: 'goldmate_api_key_header',
				time_path: 'goldmate_api_time_path',
				max_age: 'goldmate_api_max_age'
			};

			function showNote( preset ) {
				if ( ! note ) {
					return;
				}
				note.style.display = preset && preset.note ? '' : 'none';
				note.querySelector( 'p' ).textContent = preset && preset.note ? preset.note : '';
			}

			source.addEventListener( 'change', function () {
				var preset = presets[ source.value ];
				showNote( preset );
				if ( ! preset ) {
					return;
				}

				var keys = Object.keys( map );
				var stale = keys.filter( function ( key ) {
					var field = document.getElementById( map[ key ] );
					return field && field.value && preset[ key ] && String( field.value ) !== String( preset[ key ] );
				} );

				// Switching provider by hand is the whole failover story here, so
				// the fields have to follow the choice. Values left from the old
				// provider are replaced only with a confirmation, since a custom
				// endpoint someone tuned themselves must not vanish on a misclick.
				var replace = stale.length === 0 || window.confirm(
					'تنظیمات فعلی با سرویس انتخاب‌شده جایگزین شود؟\n\n' +
					'اگر «لغو» را بزنید فقط فیلدهای خالی پر می‌شوند و باید بقیه را دستی اصلاح کنید.'
				);

				keys.forEach( function ( key ) {
					var field = document.getElementById( map[ key ] );
					if ( ! field || ! preset[ key ] ) {
						return;
					}
					if ( ! field.value || replace ) {
						field.value = preset[ key ];
					}
				} );
			} );

			showNote( presets[ source.value ] );
		} )();
		</script>
		<?php
	}

	/**
	 * Renders one settings row.
	 *
	 * @param array $field Field definition.
	 */
	protected static function render_field( $field ) {

		$id    = $field['id'];
		$value = goldmate_option( $id );

		$row_attrs = '';

		if ( ! empty( $field['depends'] ) ) {
			$row_attrs = sprintf(
				' data-goldmate-depends="%s"',
				esc_attr( wp_json_encode( $field['depends'] ) )
			);
		}
		?>
		<tr<?php echo $row_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_attr above. ?>>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['title'] ); ?></label></th>
			<td>
				<?php
				switch ( $field['type'] ) {

					case 'checkbox':
						printf(
							'<label><input type="checkbox" id="%1$s" name="%1$s" value="yes" %2$s> %3$s</label>',
							esc_attr( $id ),
							checked( 'yes', $value, false ),
							esc_html( isset( $field['label'] ) ? $field['label'] : '' )
						);
						break;

					case 'select':
						printf( '<select id="%1$s" name="%1$s">', esc_attr( $id ) );
						foreach ( $field['options'] as $key => $label ) {
							printf(
								'<option value="%s" %s>%s</option>',
								esc_attr( $key ),
								selected( $key, $value, false ),
								esc_html( $label )
							);
						}
						echo '</select>';
						break;

					case 'number':
						printf(
							'<input type="number" id="%1$s" name="%1$s" value="%2$s" step="%3$s" min="0" class="regular-text">',
							esc_attr( $id ),
							esc_attr( $value ),
							esc_attr( isset( $field['step'] ) ? $field['step'] : '1' )
						);
						break;

					case 'password':
						printf(
							'<input type="password" id="%1$s" name="%1$s" value="%2$s" class="regular-text" autocomplete="off">',
							esc_attr( $id ),
							esc_attr( $value )
						);
						break;

					case 'text_rtl':
						printf(
							'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="large-text" dir="rtl">',
							esc_attr( $id ),
							esc_attr( $value )
						);
						break;

					default:
						printf(
							'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="large-text" dir="ltr">',
							esc_attr( $id ),
							esc_attr( $value )
						);
				}

				if ( ! empty( $field['desc'] ) ) {
					printf( '<p class="description">%s</p>', esc_html( $field['desc'] ) );
				}
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders the status and tools tab.
	 */
	protected static function render_status_tab() {

		$rate      = goldmate_positive_float( goldmate_option( 'goldmate_rate_per_gram' ) );
		$updated   = (int) get_option( 'goldmate_rate_updated_at', 0 );
		$checked   = (int) get_option( 'goldmate_rate_checked_at', 0 );
		$error     = (string) get_option( 'goldmate_rate_last_error', '' );
		$pending   = goldmate_positive_float( get_option( 'goldmate_pending_rate', 0 ) );
		$count     = Goldmate_Pricing::count_enabled();
		$progress  = Goldmate_Batch::get_progress();
		$is_stale  = Goldmate_Rates::is_stale();
		$scheduler = Goldmate_Batch::has_scheduler() ? 'Action Scheduler' : 'WP-Cron';
		?>
		<h2 style="margin-top:24px;">وضعیت فعلی</h2>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr>
					<th style="width:260px;">قیمت روز هر گرم ۱۸ عیار</th>
					<td><?php echo $rate > 0 ? wp_kses_post( wc_price( $rate ) ) : '—'; ?></td>
				</tr>
				<tr>
					<th>آخرین تغییر قیمت</th>
					<td>
						<?php echo esc_html( goldmate_format_time( $updated ) ); ?>
						<?php if ( $is_stale ) : ?>
							<strong style="color:#b32d2e;"> — کهنه</strong>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th>آخرین بررسی سرویس</th>
					<td><?php echo esc_html( goldmate_format_time( $checked ) ); ?></td>
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

		<?php if ( $pending > 0 ) : ?>
			<div class="notice notice-warning" style="max-width:820px;margin-top:16px;">
				<p>
					قیمت <strong><?php echo wp_kses_post( wc_price( $pending ) ); ?></strong>
					به دلیل اختلاف زیاد با قیمت فعلی اعمال نشده است.
				</p>
				<p>
					<?php self::button( 'accept_pending', 'اعمال همین قیمت', 'button-primary' ); ?>
					<?php self::button( 'discard_pending', 'نادیده بگیر' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<h2 style="margin-top:24px;">به‌روزرسانی دسته‌ای</h2>
		<div id="goldmate-progress" data-running="<?php echo Goldmate_Batch::is_running() ? '1' : '0'; ?>" style="max-width:820px;">
			<?php self::render_progress( $progress ); ?>
		</div>
		<p>
			<?php self::button( 'recalculate', 'محاسبه‌ی دوباره‌ی همه‌ی قیمت‌ها', 'button-primary' ); ?>
			<?php if ( Goldmate_Batch::is_running() ) : ?>
				<?php self::button( 'cancel_batch', 'توقف' ); ?>
			<?php endif; ?>
		</p>

		<h2 style="margin-top:24px;">دریافت قیمت</h2>
		<p>
			<?php self::button( 'test_fetch', 'دریافت آزمایشی (بدون اعمال)' ); ?>
			<?php self::button( 'fetch_now', 'دریافت و اعمال همین حالا', 'button-primary' ); ?>
		</p>

		<h2 style="margin-top:24px;">تاریخچه‌ی قیمت روز</h2>
		<p class="description" style="max-width:820px;">فقط تغییرات واقعی قیمت ثبت می‌شود. برای دیدن همه‌ی دریافت‌ها، گزارش پایین را ببینید.</p>
		<?php self::render_history(); ?>

		<h2 style="margin-top:24px;">گزارش دریافت‌ها</h2>
		<?php self::render_log(); ?>
		<?php
	}

	/**
	 * Renders the fetch log with a one-line health summary above it.
	 */
	protected static function render_log() {

		$log     = Goldmate_Rates::log();
		$summary = Goldmate_Rates::log_summary();
		$days    = goldmate_positive_float( goldmate_option( 'goldmate_log_days' ) );

		if ( $summary['total'] > 0 ) {
			printf(
				'<p style="max-width:820px;">۲۴ ساعت گذشته: <strong>%s دریافت</strong> — %s اعمال شد%s، <span style="color:%s;">%s ناموفق</span>%s</p>',
				esc_html( number_format_i18n( $summary['total'] ) ),
				esc_html( number_format_i18n( $summary['ok'] ) ),
				$summary['skipped'] > 0
					? sprintf( '، %s بدون تغییر کافی', esc_html( number_format_i18n( $summary['skipped'] ) ) )
					: '',
				$summary['failed'] > 0 ? '#b32d2e' : 'inherit',
				esc_html( number_format_i18n( $summary['failed'] ) ),
				$summary['retried'] > 0
					? sprintf( ' — %s مورد با تلاش دوم موفق شد.', esc_html( number_format_i18n( $summary['retried'] ) ) )
					: ''
			);
		}

		if ( empty( $log ) ) {
			echo '<p class="description">هنوز دریافتی ثبت نشده است.</p>';
			return;
		}
		?>
		<table class="widefat striped" style="max-width:820px;">
			<thead>
				<tr>
					<th style="width:150px;">زمان</th>
					<th style="width:170px;">نتیجه</th>
					<th style="width:150px;">مقدار خوانده‌شده</th>
					<th>توضیح</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_slice( $log, 0, 50 ) as $entry ) : ?>
					<?php
					$ok      = 'applied' === $entry['outcome'];
					$neutral = 'small' === $entry['outcome'];
					$color   = $ok ? '#1e7e34' : ( $neutral ? '#666' : '#b32d2e' );
					?>
					<tr>
						<td><?php echo esc_html( goldmate_format_time( $entry['at'] ) ); ?></td>
						<td style="color:<?php echo esc_attr( $color ); ?>;">
							<?php echo esc_html( Goldmate_Rates::outcome_label( $entry['outcome'] ) ); ?>
							<?php if ( ! empty( $entry['attempts'] ) && $entry['attempts'] > 1 ) : ?>
								<span style="color:#666;font-weight:400;"> (تلاش <?php echo esc_html( number_format_i18n( $entry['attempts'] ) ); ?>)</span>
							<?php endif; ?>
						</td>
						<td><?php echo $entry['rate'] > 0 ? wp_kses_post( wc_price( $entry['rate'] ) ) : '—'; ?></td>
						<td style="color:#666;"><?php echo esc_html( $entry['error'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<?php if ( count( $log ) > 50 ) : ?>
				<span class="description">۵۰ مورد آخر از <?php echo esc_html( number_format_i18n( count( $log ) ) ); ?> مورد ثبت‌شده نمایش داده شده است. </span>
			<?php endif; ?>
			<?php if ( $days > 0 ) : ?>
				<span class="description">موارد قدیمی‌تر از <?php echo esc_html( number_format_i18n( $days ) ); ?> روز خودکار پاک می‌شوند.</span>
			<?php endif; ?>
		</p>
		<p><?php self::button( 'clear_log', 'پاک کردن گزارش' ); ?></p>
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

		$history = Goldmate_Rates::history();

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
				<?php foreach ( array_slice( $history, 0, 30 ) as $entry ) : ?>
					<?php
					$user = ! empty( $entry['user'] ) ? get_userdata( $entry['user'] ) : false;

					// Rows recorded before the provider column existed carry no
					// `provider` key at all; they just show as plain «خودکار».
					$provider_label = 'auto' === $entry['source']
						? Goldmate_Rates::provider_label( isset( $entry['provider'] ) ? $entry['provider'] : '' )
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
		<form method="post" action="<?php echo esc_url( self::url( self::current_tab() ) ); ?>" style="display:inline-block;margin-inline-end:6px;">
			<?php wp_nonce_field( 'goldmate_admin' ); ?>
			<input type="hidden" name="goldmate_action" value="<?php echo esc_attr( $action ); ?>">
			<button type="submit" class="button <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 *  Assets and AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * Inline styles for the screen.
	 */
	protected static function print_styles() {
		?>
		<style>
			.goldmate-wrap .form-table th { width: 260px; }
			.goldmate-wrap .description { max-width: 640px; }
		</style>
		<?php
	}

	/**
	 * Conditional-field toggling and progress polling.
	 */
	protected static function print_scripts() {
		?>
		<script>
		( function () {
			var rows = document.querySelectorAll( '[data-goldmate-depends]' );

			function sync() {
				rows.forEach( function ( row ) {
					var rules = JSON.parse( row.getAttribute( 'data-goldmate-depends' ) );
					var show = true;
					Object.keys( rules ).forEach( function ( id ) {
						var field = document.getElementById( id );
						if ( ! field ) {
							return;
						}
						if ( rules[ id ].indexOf( field.value ) === -1 ) {
							show = false;
						}
					} );
					row.style.display = show ? '' : 'none';
				} );
			}

			if ( rows.length ) {
				sync();
				document.querySelectorAll( 'select, input' ).forEach( function ( el ) {
					el.addEventListener( 'change', sync );
				} );
			}

			var box = document.getElementById( 'goldmate-progress' );
			if ( box && box.getAttribute( 'data-running' ) === '1' ) {
				setInterval( function () {
					var request = new XMLHttpRequest();
					request.open( 'GET', ajaxurl + '?action=goldmate_progress', true );
					request.onload = function () {
						if ( request.status !== 200 ) {
							return;
						}
						var payload = JSON.parse( request.responseText );
						if ( ! payload.success ) {
							return;
						}
						box.innerHTML = payload.data.html;
						if ( ! payload.data.running ) {
							window.location.reload();
						}
					};
					request.send();
				}, 4000 );
			}
		} )();
		</script>
		<?php
	}

	/**
	 * Returns the current batch progress markup for polling.
	 */
	public static function ajax_progress() {

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error();
		}

		$progress = Goldmate_Batch::get_progress();

		ob_start();
		self::render_progress( $progress );
		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'html'    => $html,
				'running' => Goldmate_Batch::is_running(),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 *  Global notices
	 * ------------------------------------------------------------------ */

	/**
	 * Warns about a stale rate or a running batch anywhere in wp-admin.
	 */
	public static function global_notices() {

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( $screen && self::SLUG === $screen->parent_base ) {
			return;
		}

		if ( Goldmate_Batch::is_running() ) {
			printf(
				'<div class="notice notice-info"><p>به‌روزرسانی قیمت محصولات طلا در پس‌زمینه در حال اجراست. <a href="%s">مشاهده‌ی وضعیت</a></p></div>',
				esc_url( self::url( 'status' ) )
			);
		}

		if ( 'none' !== goldmate_option( 'goldmate_stale_action' ) && Goldmate_Rates::is_stale() ) {
			printf(
				'<div class="notice notice-warning"><p>قیمت روز طلا به‌روز نیست. <a href="%s">بررسی وضعیت</a></p></div>',
				esc_url( self::url( 'status' ) )
			);
		}
	}
}
