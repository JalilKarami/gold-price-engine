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
		add_action( 'wp_ajax_goldmate_admin_similar', array( __CLASS__, 'ajax_similar_products' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_calculator_assets' ) );

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

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		$tabs = Goldmate_Settings::tabs();

		return isset( $tabs[ $tab ] ) ? $tab : 'general';
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

		if ( preg_match( '/^apply_rate_item_(\d+)$/', $action, $m ) ) {
			Goldmate_Admin_Items::save_from_post( $_POST );
			$result = Goldmate_Fetcher::fetch_and_apply( (int) $m[1], true );
			if ( ! empty( $result['ok'] ) ) {
				self::notice( 'success', 'نرخ اعمال شد: ' . wp_strip_all_tags( wc_price( $result['rate'] ) ) );
			} else {
				self::notice( 'error', 'اعمال ناموفق: ' . ( ! empty( $result['error'] ) ? $result['error'] : 'خطای نامشخص' ) );
			}
			return;
		}

		if ( 'fetch_due_rate_items' === $action ) {
			Goldmate_Admin_Items::save_from_post( $_POST );
			$due = Goldmate_Rate_Items::due_for_fetch();
			$ok  = 0;
			$fail = 0;
			foreach ( $due as $item ) {
				$r = Goldmate_Fetcher::fetch_and_apply( $item, false );
				if ( ! empty( $r['ok'] ) ) {
					$ok++;
				} else {
					$fail++;
				}
			}
			self::notice(
				'success',
				sprintf( 'فراخوانی موعد‌دار: %d موفق، %d ناموفق از %d آیتم.', $ok, $fail, count( $due ) )
			);
			return;
		}

		if ( preg_match( '/^fetch_rate_item_(\d+)$/', $action, $m ) ) {
			// Persist grid edits for this item first so the test uses current form values.
			Goldmate_Admin_Items::save_from_post( $_POST );
			$item   = Goldmate_Rate_Items::get( (int) $m[1] );
			$result = Goldmate_Fetcher::fetch_item( $item ? $item : array() );
			if ( ! empty( $result['ok'] ) ) {
				self::notice( 'success', 'دریافت آزمایشی: ' . wp_strip_all_tags( wc_price( $result['rate'] ) ) . ' (اعمال نشد).' );
			} else {
				self::notice( 'error', 'ناموفق: ' . ( ! empty( $result['error'] ) ? $result['error'] : 'خطای نامشخص' ) );
			}
			return;
		}

		if ( preg_match( '/^dup_rate_item_(\d+)$/', $action, $m ) ) {
			Goldmate_Rate_Items::duplicate( (int) $m[1] );
			self::notice( 'success', 'آیتم کپی شد.' );
			return;
		}

		if ( preg_match( '/^del_rate_item_(\d+)$/', $action, $m ) ) {
			if ( Goldmate_Rate_Items::delete( (int) $m[1] ) ) {
				self::notice( 'success', 'آیتم حذف شد.' );
			} else {
				self::notice( 'error', 'حذف آیتم ممکن نیست.' );
			}
			return;
		}

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

				if ( 'shortcodes' === $tab ) {
					Goldmate_Shortcodes::save_titles( $_POST );
				}

				if ( 'components' === $tab ) {
					Goldmate_Components::save_from_post( $_POST );
				}

				self::notice( 'success', 'تنظیمات ذخیره شد.' );
				break;

			case 'clear_rate_history':
				Goldmate_General::clear_rate_history();
				self::notice( 'success', 'سوابق تغییرات قیمت حذف شد.' );
				break;

			case 'prune_rate_history':
				Goldmate_General::prune_rate_history();
				self::notice( 'success', 'سوابق قیمت بر اساس مدت نگهداری به‌روز شد.' );
				break;

			case 'tool_set_rate_item':
				$n = Goldmate_Tools::set_rate_item( $_POST );
				self::notice( 'success', sprintf( 'آیتم نرخ روی %d محصول اعمال شد.', $n ) );
				break;

			case 'tool_set_wage':
				$n = Goldmate_Tools::set_wage( $_POST );
				self::notice( 'success', sprintf( 'اجرت روی %d محصول اعمال شد.', $n ) );
				break;

			case 'tool_set_karat':
				$n = Goldmate_Tools::set_karat( $_POST );
				self::notice( 'success', sprintf( 'عیار روی %d محصول اعمال شد.', $n ) );
				break;

			case 'tool_convert_wage':
				$n = Goldmate_Tools::convert_wage_modes();
				self::notice( 'success', sprintf( 'حالت اجرت %d محصول تبدیل شد.', $n ) );
				break;

			case 'save_discounts_global':
				Goldmate_Discounts::save_global_from_post( $_POST );
				self::notice( 'success', 'تخفیف‌های سراسری ذخیره شد.' );
				break;

			case 'save_rate_items':
				Goldmate_Admin_Items::save_from_post( $_POST );
				self::notice( 'success', 'آیتم‌های نرخ ذخیره شد.' );
				break;

			case 'add_rate_item':
				Goldmate_Rate_Items::insert(
					array(
						'slug'  => 'item-' . wp_generate_password( 6, false, false ),
						'label' => 'آیتم جدید',
					)
				);
				self::notice( 'success', 'آیتم جدید اضافه شد.' );
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
						'دریافت آزمایشی موفق بود. قیمت خوانده‌شده: %s%s (اعمال نشد).',
						wp_strip_all_tags( wc_price( $result['rate'] ) ),
						! empty( $result['fallback_used'] )
							? ' از منبع جایگزین (' . Goldmate_Rates::provider_label( isset( $result['provider'] ) ? $result['provider'] : '' ) . ')'
							: ( ! empty( $result['provider'] ) ? ' — ' . Goldmate_Rates::provider_label( $result['provider'] ) : '' )
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
			} elseif ( 'calculator' === $tab ) {
				self::render_calculator_tab();
			} elseif ( 'shortcodes' === $tab ) {
				self::render_shortcodes_tab();
			} elseif ( 'components' === $tab ) {
				self::render_components_tab();
			} elseif ( 'fetch' === $tab ) {
				self::render_fetch_items_tab();
			} elseif ( 'discounts' === $tab ) {
				self::render_discounts_tab();
			} elseif ( 'tools' === $tab ) {
				self::render_tools_tab();
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
	 * Rate items fetch grid tab.
	 */
	protected static function render_fetch_items_tab() {
		?>
		<form method="post" action="<?php echo esc_url( self::url( 'fetch' ) ); ?>">
			<?php wp_nonce_field( 'goldmate_admin' ); ?>
			<input type="hidden" name="goldmate_action" value="save_rate_items">
			<?php Goldmate_Admin_Items::render(); ?>
		</form>
		<?php
	}

	/**
	 * Global discounts tab (Phase C).
	 */
	protected static function render_discounts_tab() {
		Goldmate_Discounts::render_admin_tab();
	}

	/**
	 * Bulk tools tab (Phase E).
	 */
	protected static function render_tools_tab() {
		Goldmate_Tools::render_admin_tab();
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

			<?php submit_button( 'ذخیره تغییرات' ); ?>
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

		$id    = isset( $field['id'] ) ? $field['id'] : '';
		$value = $id ? goldmate_option( $id ) : '';

		$row_attrs = '';

		if ( ! empty( $field['depends'] ) ) {
			$row_attrs = sprintf(
				' data-goldmate-depends="%s"',
				esc_attr( wp_json_encode( $field['depends'] ) )
			);
		}
		?>
		<tr<?php echo $row_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_attr above. ?>>
			<th scope="row">
				<?php if ( $id ) : ?>
					<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['title'] ); ?></label>
				<?php else : ?>
					<?php echo esc_html( $field['title'] ); ?>
				<?php endif; ?>
			</th>
			<td>
				<?php
				switch ( $field['type'] ) {

					case 'submit_action':
						printf(
							'<button type="submit" class="%s" name="goldmate_action" value="%s">%s</button>',
							esc_attr( isset( $field['class'] ) ? $field['class'] : 'button' ),
							esc_attr( $field['action'] ),
							esc_html( $field['label'] )
						);
						break;

					case 'checkbox':
						printf(
							'<label><input type="checkbox" id="%1$s" name="%1$s" value="yes" %2$s> %3$s</label>',
							esc_attr( $id ),
							checked( 'yes', $value, false ),
							esc_html( isset( $field['label'] ) ? $field['label'] : '' )
						);
						break;

					case 'multiselect':
						if ( ! is_array( $value ) ) {
							$value = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
						}
						echo '<div style="display:flex;flex-wrap:wrap;gap:8px 16px;">';
						foreach ( $field['options'] as $key => $label ) {
							printf(
								'<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label>',
								esc_attr( $id ),
								esc_attr( $key ),
								checked( in_array( (string) $key, array_map( 'strval', $value ), true ), true, false ),
								esc_html( $label )
							);
						}
						echo '</div>';
						break;

					case 'select':
						printf( '<select id="%1$s" name="%1$s">', esc_attr( $id ) );
						foreach ( $field['options'] as $key => $label ) {
							printf(
								'<option value="%s" %s>%s</option>',
								esc_attr( $key ),
								selected( (string) $key, (string) $value, false ),
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

					case 'signed_number':
						printf(
							'<input type="number" id="%1$s" name="%1$s" value="%2$s" step="%3$s" class="regular-text">',
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
	 * Renders the custom price-components admin tab.
	 */
	protected static function render_components_tab() {

		$items = Goldmate_Components::all();
		$modes = Goldmate_Components::calc_modes();
		?>
		<style>
			.goldmate-comp-wrap { max-width: 1100px; margin-top: 8px; }
			.goldmate-comp-table { background:#fff; border:1px solid #c3c4c7; border-radius:8px; overflow:hidden; }
			.goldmate-comp-table table { margin:0; border:0; }
			.goldmate-comp-table th { font-weight:600; }
			.goldmate-comp-table input[type="text"],
			.goldmate-comp-table input[type="number"],
			.goldmate-comp-table select { width:100%; max-width:100%; }
			.goldmate-comp-actions { margin-top:16px; display:flex; gap:10px; align-items:center; }
		</style>

		<div class="goldmate-comp-wrap">
			<p class="description" style="max-width:900px;">
				اجزای سفارشی به فرمول قیمت طلا اضافه می‌شوند (مثل هزینه بسته‌بندی، حکاکی، یا هر قلم دلخواه).
				پس از ذخیره، فیلد هر جزء در تب «نرخ خودکار» محصول ظاهر می‌شود.
			</p>

			<form method="post" action="<?php echo esc_url( self::url( 'components' ) ); ?>" id="goldmate-components-form">
				<?php wp_nonce_field( 'goldmate_admin' ); ?>
				<input type="hidden" name="goldmate_action" value="save">

				<div class="goldmate-comp-table">
					<table class="widefat striped" id="goldmate-comp-table">
						<thead>
							<tr>
								<th style="width:8%;">فعال</th>
								<th style="width:22%;">عنوان</th>
								<th style="width:22%;">نوع محاسبه</th>
								<th style="width:14%;">مقدار پیش‌فرض</th>
								<th style="width:12%;">مشمول مالیات</th>
								<th style="width:12%;">داخل سود</th>
								<th style="width:10%;"></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $items ) ) : ?>
								<tr class="goldmate-comp-row">
									<td><input type="checkbox" name="goldmate_comp_enabled[0]" value="1" checked></td>
									<td>
										<input type="hidden" name="goldmate_comp_id[0]" value="">
										<input type="text" name="goldmate_comp_label[0]" placeholder="مثلاً بسته‌بندی">
									</td>
									<td>
										<select name="goldmate_comp_calc[0]">
											<?php foreach ( $modes as $key => $label ) : ?>
												<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
									<td><input type="number" name="goldmate_comp_default[0]" min="0" step="0.01" value="0"></td>
									<td><input type="checkbox" name="goldmate_comp_taxable[0]" value="1"></td>
									<td><input type="checkbox" name="goldmate_comp_in_profit[0]" value="1"></td>
									<td><button type="button" class="button link-delete goldmate-comp-remove">حذف</button></td>
								</tr>
							<?php else : ?>
								<?php foreach ( $items as $i => $item ) : ?>
									<tr class="goldmate-comp-row">
										<td><input type="checkbox" name="goldmate_comp_enabled[<?php echo (int) $i; ?>]" value="1" <?php checked( $item['enabled'], 'yes' ); ?>></td>
										<td>
											<input type="hidden" name="goldmate_comp_id[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $item['id'] ); ?>">
											<input type="text" name="goldmate_comp_label[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $item['label'] ); ?>">
										</td>
										<td>
											<select name="goldmate_comp_calc[<?php echo (int) $i; ?>]">
												<?php foreach ( $modes as $key => $label ) : ?>
													<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $item['calc'], $key ); ?>><?php echo esc_html( $label ); ?></option>
												<?php endforeach; ?>
											</select>
										</td>
										<td><input type="number" name="goldmate_comp_default[<?php echo (int) $i; ?>]" min="0" step="0.01" value="<?php echo esc_attr( $item['default'] ); ?>"></td>
										<td><input type="checkbox" name="goldmate_comp_taxable[<?php echo (int) $i; ?>]" value="1" <?php checked( $item['taxable'], 'yes' ); ?>></td>
										<td><input type="checkbox" name="goldmate_comp_in_profit[<?php echo (int) $i; ?>]" value="1" <?php checked( $item['in_profit'], 'yes' ); ?>></td>
										<td><button type="button" class="button link-delete goldmate-comp-remove">حذف</button></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<p class="goldmate-comp-actions">
					<button type="button" class="button" id="goldmate-comp-add">+ افزودن جزء</button>
					<button type="submit" class="button button-primary">ذخیره تغییرات</button>
				</p>
			</form>
		</div>

		<script type="text/template" id="goldmate-comp-row-tpl">
			<tr class="goldmate-comp-row">
				<td><input type="checkbox" name="goldmate_comp_enabled[__i__]" value="1" checked></td>
				<td>
					<input type="hidden" name="goldmate_comp_id[__i__]" value="">
					<input type="text" name="goldmate_comp_label[__i__]" placeholder="عنوان جزء">
				</td>
				<td>
					<select name="goldmate_comp_calc[__i__]">
						<?php foreach ( $modes as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
				<td><input type="number" name="goldmate_comp_default[__i__]" min="0" step="0.01" value="0"></td>
				<td><input type="checkbox" name="goldmate_comp_taxable[__i__]" value="1"></td>
				<td><input type="checkbox" name="goldmate_comp_in_profit[__i__]" value="1"></td>
				<td><button type="button" class="button link-delete goldmate-comp-remove">حذف</button></td>
			</tr>
		</script>
		<script>
		( function () {
			var table = document.getElementById( 'goldmate-comp-table' );
			var addBtn = document.getElementById( 'goldmate-comp-add' );
			var tpl = document.getElementById( 'goldmate-comp-row-tpl' );
			if ( ! table || ! addBtn || ! tpl ) { return; }

			function reindex() {
				table.querySelectorAll( 'tbody tr' ).forEach( function ( row, i ) {
					row.querySelectorAll( 'input, select' ).forEach( function ( input ) {
						if ( ! input.name ) { return; }
						input.name = input.name.replace( /\[\d+\]/, '[' + i + ']' );
					} );
				} );
			}

			addBtn.addEventListener( 'click', function () {
				var i = table.querySelectorAll( 'tbody tr' ).length;
				var html = tpl.innerHTML.replace( /__i__/g, String( i ) );
				table.querySelector( 'tbody' ).insertAdjacentHTML( 'beforeend', html );
				reindex();
			} );

			table.addEventListener( 'click', function ( e ) {
				if ( e.target && e.target.classList.contains( 'goldmate-comp-remove' ) ) {
					var rows = table.querySelectorAll( 'tbody tr' );
					if ( rows.length <= 1 ) {
						var row = rows[0];
						row.querySelectorAll( 'input[type="text"]' ).forEach( function ( input ) { input.value = ''; } );
						row.querySelectorAll( 'input[type="hidden"]' ).forEach( function ( input ) { input.value = ''; } );
						row.querySelectorAll( 'input[type="number"]' ).forEach( function ( input ) { input.value = '0'; } );
						return;
					}
					e.target.closest( 'tr' ).remove();
					reindex();
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Renders the shortcodes reference / titles tab (Ratesbox-style).
	 */
	protected static function render_shortcodes_tab() {

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

			<form method="post" action="<?php echo esc_url( self::url( 'shortcodes' ) ); ?>" id="goldmate-shortcodes-form">
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

	/**
	 * Enqueues assets for the admin calculator tab.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_calculator_assets( $hook ) {

		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		if ( 'calculator' !== self::current_tab() ) {
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

		$rate = goldmate_positive_float( goldmate_option( 'goldmate_rate_per_gram' ) );

		wp_localize_script(
			'goldmate-admin-calculator',
			'goldmateAdminCalc',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'goldmate_admin_calc' ),
				'currency'  => 'تومان',
				'roundTo'   => goldmate_positive_float( goldmate_option( 'goldmate_round_to' ) ),
				'roundMode' => (string) goldmate_option( 'goldmate_round_mode' ),
				'rate18'    => $rate,
			)
		);
	}

	/**
	 * Renders the Ratesbox-style gold price calculator tab.
	 */
	protected static function render_calculator_tab() {

		$rate_18     = goldmate_positive_float( goldmate_option( 'goldmate_rate_per_gram' ) );
		$profit_pct  = goldmate_positive_float( goldmate_option( 'goldmate_profit_pct' ) );
		$tax_pct     = goldmate_positive_float( goldmate_option( 'goldmate_tax_pct' ) );
		$tax_acc     = 'yes' === goldmate_option( 'goldmate_tax_accessories' );
		$wage_mode   = Goldmate_Calculator::normalize_wage_mode( goldmate_option( 'goldmate_default_wage_mode' ) );
		?>
		<div class="goldmate-calc-wrap" id="goldmate-admin-calc">
			<h2 class="goldmate-calc-title">ماشین حساب محاسبه‌گر قیمت</h2>

			<?php if ( $rate_18 <= 0 ) : ?>
				<div class="notice notice-warning inline"><p>قیمت روز طلا هنوز تنظیم نشده است. ابتدا در تب «قیمت‌گذاری» یا «دریافت خودکار» نرخ ۱۸ عیار را وارد کنید.</p></div>
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
	 * AJAX: products with a similar gold weight for the calculator tab.
	 */
	public static function ajax_similar_products() {

		if ( ! current_user_can( self::CAPABILITY ) ) {
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

	/**
	 * Renders the status and tools tab.
	 */
	protected static function render_status_tab() {

		$rate           = goldmate_positive_float( goldmate_option( 'goldmate_rate_per_gram' ) );
		$updated        = (int) get_option( 'goldmate_rate_updated_at', 0 );
		$checked        = (int) get_option( 'goldmate_rate_checked_at', 0 );
		$error          = (string) get_option( 'goldmate_rate_last_error', '' );
		$pending        = goldmate_positive_float( get_option( 'goldmate_pending_rate', 0 ) );
		$count          = Goldmate_Pricing::count_enabled();
		$progress       = Goldmate_Batch::get_progress();
		$is_stale       = Goldmate_Rates::is_stale();
		$scheduler      = Goldmate_Batch::has_scheduler() ? 'Action Scheduler' : 'WP-Cron';
		$source         = (string) goldmate_option( 'goldmate_rate_source' );
		$fallback       = (string) goldmate_option( 'goldmate_fallback_source' );
		$last_provider  = (string) get_option( 'goldmate_rate_last_provider', '' );
		$fallback_at    = (int) get_option( 'goldmate_rate_last_fallback_at', 0 );
		$adjust_mode    = (string) goldmate_option( 'goldmate_rate_adjust_mode' );
		$adjust_value   = goldmate_signed_float( goldmate_option( 'goldmate_rate_adjust_value' ) );
		$live_interval  = (int) goldmate_option( 'goldmate_live_interval' );
		$change_pct     = Goldmate_Live::change_pct();
		$summary        = Goldmate_Rates::log_summary();
		?>
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
				<div style="font-size:12px;color:#646970;">سلامت دریافت (۲۴س)</div>
				<div style="font-size:1.2em;font-weight:600;margin-top:4px;">
					<?php
					printf(
						'%s موفق / %s ناموفق',
						esc_html( number_format_i18n( $summary['ok'] + $summary['skipped'] ) ),
						esc_html( number_format_i18n( $summary['failed'] ) )
					);
					?>
				</div>
				<div style="margin-top:4px;font-size:12px;color:#646970;">
					<?php echo esc_html( number_format_i18n( $summary['total'] ) ); ?> دریافت
					<?php if ( $summary['retried'] > 0 ) : ?>
						— <?php echo esc_html( number_format_i18n( $summary['retried'] ) ); ?> با تلاش دوم
					<?php endif; ?>
				</div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:14px 16px;">
				<div style="font-size:12px;color:#646970;">منبع فعال</div>
				<div style="font-size:1.1em;font-weight:600;margin-top:4px;">
					<?php echo esc_html( Goldmate_Rates::provider_label( $source ) ? Goldmate_Rates::provider_label( $source ) : $source ); ?>
				</div>
				<div style="margin-top:4px;font-size:12px;color:#646970;">
					<?php if ( $last_provider ) : ?>
						آخرین موفق: <?php echo esc_html( Goldmate_Rates::provider_label( $last_provider ) ); ?>
					<?php else : ?>
						هنوز دریافتی ثبت نشده
					<?php endif; ?>
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
			<p><a class="button" href="<?php echo esc_url( self::url( 'fetch' ) ); ?>">مدیریت فراخوانی قیمت</a></p>
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
					<th>منبع اصلی</th>
					<td><?php echo esc_html( Goldmate_Rates::provider_label( $source ) ? Goldmate_Rates::provider_label( $source ) : $source ); ?></td>
				</tr>
				<tr>
					<th>منبع جایگزین</th>
					<td>
						<?php
						if ( 'none' === $fallback || '' === $fallback ) {
							echo 'غیرفعال';
						} else {
							echo esc_html( Goldmate_Rates::provider_label( $fallback ) ? Goldmate_Rates::provider_label( $fallback ) : $fallback );
							if ( $fallback_at > 0 ) {
								echo ' — آخرین استفاده: ' . esc_html( goldmate_format_time( $fallback_at ) );
							}
						}
						?>
					</td>
				</tr>
				<tr>
					<th>تنظیم روی نرخ دریافتی</th>
					<td>
						<?php
						if ( 'none' === $adjust_mode || 0.0 === $adjust_value ) {
							echo 'بدون تغییر';
						} elseif ( 'pct' === $adjust_mode ) {
							printf( 'درصدی: %s٪', esc_html( wc_format_localized_decimal( $adjust_value ) ) );
						} else {
							printf( 'مبلغ ثابت: %s تومان', esc_html( wc_format_localized_decimal( $adjust_value ) ) );
						}
						?>
					</td>
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

		<?php if ( $pending > 0 ) : ?>
			<div class="notice notice-warning" style="max-width:960px;margin-top:16px;">
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

		<h2 style="margin-top:24px;">دریافت قیمت</h2>
		<p>
			<?php self::button( 'test_fetch', 'دریافت آزمایشی (بدون اعمال)' ); ?>
			<?php self::button( 'fetch_now', 'دریافت و اعمال همین حالا', 'button-primary' ); ?>
		</p>

		<h2 style="margin-top:24px;">تاریخچه‌ی قیمت روز</h2>
		<p class="description" style="max-width:960px;">فقط تغییرات واقعی قیمت ثبت می‌شود. برای دیدن همه‌ی دریافت‌ها، گزارش پایین را ببینید.</p>
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
		<table class="widefat striped" style="max-width:960px;">
			<thead>
				<tr>
					<th style="width:140px;">زمان</th>
					<th style="width:150px;">نتیجه</th>
					<th style="width:120px;">منبع</th>
					<th style="width:140px;">مقدار خوانده‌شده</th>
					<th>توضیح</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_slice( $log, 0, 50 ) as $entry ) : ?>
					<?php
					$ok      = 'applied' === $entry['outcome'];
					$neutral = 'small' === $entry['outcome'];
					$color   = $ok ? '#1e7e34' : ( $neutral ? '#666' : '#b32d2e' );
					$prov    = isset( $entry['provider'] ) ? (string) $entry['provider'] : '';
					$prov_l  = Goldmate_Rates::provider_label( $prov );
					?>
					<tr>
						<td><?php echo esc_html( goldmate_format_time( $entry['at'] ) ); ?></td>
						<td style="color:<?php echo esc_attr( $color ); ?>;">
							<?php echo esc_html( Goldmate_Rates::outcome_label( $entry['outcome'] ) ); ?>
							<?php if ( ! empty( $entry['attempts'] ) && $entry['attempts'] > 1 ) : ?>
								<span style="color:#666;font-weight:400;"> (تلاش <?php echo esc_html( number_format_i18n( $entry['attempts'] ) ); ?>)</span>
							<?php endif; ?>
							<?php if ( ! empty( $entry['fallback_used'] ) ) : ?>
								<span style="color:#996800;font-weight:400;"> — جایگزین</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $prov_l ? $prov_l : ( $prov ? $prov : '—' ) ); ?></td>
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

		if ( class_exists( 'Goldmate_Install' ) && ! Goldmate_Install::tables_exist() ) {
			printf(
				'<div class="notice notice-error"><p>جداول آیتم نرخ گلدمیت نصب نشده‌اند. افزونه را یک‌بار غیرفعال و دوباره فعال کنید یا صفحه <a href="%s">وضعیت</a> را باز کنید.</p></div>',
				esc_url( self::url( 'status' ) )
			);
		} elseif ( class_exists( 'Goldmate_Rate_Items' ) ) {
			$default = Goldmate_Rate_Items::get_default();
			if ( $default && (float) $default['rate'] <= 0 ) {
				printf(
					'<div class="notice notice-warning"><p>نرخ آیتم پیش‌فرض (<code>gold18</code>) هنوز صفر است. از <a href="%s">تنظیمات فراخوانی قیمت</a> نرخ را وارد یا دریافت کنید.</p></div>',
					esc_url( self::url( 'fetch' ) )
				);
			}
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
