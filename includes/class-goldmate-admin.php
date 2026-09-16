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
	public static function current_tab() {

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

		if ( preg_match( '/^set_default_formula_(\d+)$/', $action, $m ) ) {
			if ( Goldmate_Formulas::set_default( (int) $m[1] ) ) {
				self::notice( 'success', 'فرمول پیش‌فرض تنظیم شد.' );
			} else {
				self::notice( 'error', 'تنظیم فرمول پیش‌فرض ممکن نیست.' );
			}
			return;
		}

		if ( preg_match( '/^del_formula_(\d+)$/', $action, $m ) ) {
			if ( Goldmate_Formulas::delete( (int) $m[1] ) ) {
				self::notice( 'success', 'فرمول حذف شد.' );
			} else {
				self::notice( 'error', 'حذف فرمول ممکن نیست (ممکن است پیش‌فرض باشد).' );
			}
			return;
		}

		switch ( $action ) {

			case 'save':
				$warnings = Goldmate_Settings::save( $tab, $_POST );

				foreach ( $warnings as $warning ) {
					self::notice( 'warning', $warning );
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

			case 'tool_set_formula':
				$n = Goldmate_Tools::set_formula( $_POST );
				self::notice( 'success', sprintf( 'فرمول قیمت روی %d محصول اعمال شد.', $n ) );
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

		case 'save_formulas':
			Goldmate_Admin_Formulas::save_from_post( $_POST );
			self::notice( 'success', 'فرمول‌ها ذخیره شد.' );
			break;

		case 'add_formula':
			$rates = class_exists( 'Goldmate_Rate_Items' ) ? Goldmate_Rate_Items::choices( false ) : array( 'gold18' => '' );
			$rate_slug = '';
			foreach ( $rates as $slug => $label ) {
				$rate_slug = $slug;
				break;
			}
			Goldmate_Formulas::insert(
				array(
					'slug'      => 'formula-' . wp_generate_password( 6, false, false ),
					'label'     => 'فرمول جدید',
					'rate_slug' => $rate_slug ? $rate_slug : 'gold18',
				)
			);
			self::notice( 'success', 'فرمول جدید اضافه شد.' );
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
		}
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

		$tab          = self::current_tab();
		$tabs         = Goldmate_Settings::tabs();
		$descriptions = Goldmate_Settings::tab_descriptions();
		?>
		<div class="wrap goldmate-wrap">
			<h1>قیمت طلا</h1>

			<nav class="nav-tab-wrapper" style="margin-bottom:16px;">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>"
					   class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>"
					   <?php if ( ! empty( $descriptions[ $key ] ) ) : ?>title="<?php echo esc_attr( $descriptions[ $key ] ); ?>"<?php endif; ?>>
						<?php echo esc_html( $label ); ?>
						<?php if ( ! empty( $descriptions[ $key ] ) ) : ?>
							<span class="dashicons dashicons-info-outline goldmate-tab-info" aria-hidden="true"></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( ! empty( $descriptions[ $tab ] ) ) : ?>
				<div class="goldmate-tab-intro">
					<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
					<p><?php echo esc_html( $descriptions[ $tab ] ); ?></p>
				</div>
			<?php endif; ?>

			<?php foreach ( self::$notices as $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>">
					<p><?php echo esc_html( $notice['text'] ); ?></p>
				</div>
			<?php endforeach; ?>

			<?php
			if ( 'status' === $tab ) {
				Goldmate_Status::render_admin_tab();
			} elseif ( 'calculator' === $tab ) {
				Goldmate_Calculator_Admin::render_admin_tab();
			} elseif ( 'shortcodes' === $tab ) {
				Goldmate_Shortcodes::render_admin_tab();
			} elseif ( 'components' === $tab ) {
				Goldmate_Components::render_admin_tab();
			} elseif ( 'fetch' === $tab ) {
				self::render_fetch_items_tab();
			} elseif ( 'formulas' === $tab ) {
				self::render_formulas_tab();
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
	 * Named formulas tab.
	 */
	protected static function render_formulas_tab() {
		?>
		<form method="post" action="<?php echo esc_url( self::url( 'formulas' ) ); ?>">
			<?php wp_nonce_field( 'goldmate_admin' ); ?>
			<input type="hidden" name="goldmate_action" value="save_formulas">
			<?php Goldmate_Admin_Formulas::render(); ?>
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
				<?php if ( ! empty( $field['help'] ) ) : ?>
					<span class="goldmate-help dashicons dashicons-info-outline" tabindex="0" role="img"
						aria-label="<?php echo esc_attr( $field['help'] ); ?>"
						data-tip="<?php echo esc_attr( $field['help'] ); ?>"></span>
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
			.goldmate-wrap .goldmate-tab-info {
				font-size: 15px;
				width: 15px;
				height: 15px;
				vertical-align: middle;
				opacity: .55;
			}
			.goldmate-wrap .nav-tab-active .goldmate-tab-info { opacity: .9; }
			.goldmate-wrap .goldmate-tab-intro {
				display: flex;
				gap: 10px;
				align-items: flex-start;
				max-width: 960px;
				margin: 0 0 16px;
				padding: 10px 14px;
				background: #fff;
				border: 1px solid #c3c4c7;
				border-right: 4px solid #2271b1;
			}
			.goldmate-wrap .goldmate-tab-intro .dashicons { color: #2271b1; flex: 0 0 auto; margin-top: 2px; }
			.goldmate-wrap .goldmate-tab-intro p { margin: 0; line-height: 1.8; }
			.goldmate-wrap .goldmate-help {
				position: relative;
				font-size: 16px;
				width: 16px;
				height: 16px;
				margin-inline-start: 4px;
				vertical-align: middle;
				color: #2271b1;
				cursor: help;
			}
			.goldmate-wrap .goldmate-help:focus { outline: 2px solid #2271b1; outline-offset: 1px; border-radius: 50%; }
			.goldmate-wrap .goldmate-help::after {
				content: attr(data-tip);
				position: absolute;
				z-index: 100;
				top: calc(100% + 6px);
				inset-inline-start: -8px;
				width: 320px;
				max-width: 70vw;
				padding: 8px 10px;
				background: #1d2327;
				color: #fff;
				font-family: inherit;
				font-size: 12px;
				font-weight: 400;
				line-height: 1.8;
				white-space: normal;
				text-align: start;
				border-radius: 4px;
				box-shadow: 0 4px 14px rgba(0, 0, 0, .2);
				opacity: 0;
				visibility: hidden;
				pointer-events: none;
				transition: opacity .12s ease;
			}
			.goldmate-wrap .goldmate-help:hover::after,
			.goldmate-wrap .goldmate-help:focus::after { opacity: 1; visibility: visible; }
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
		Goldmate_Status::render_progress( $progress );
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

		if ( 'none' !== goldmate_option( 'goldmate_stale_action' ) && class_exists( 'Goldmate_Rate_Items' ) && Goldmate_Rate_Items::is_reference_stale() ) {
			printf(
				'<div class="notice notice-warning"><p>قیمت روز طلا به‌روز نیست. <a href="%s">بررسی وضعیت</a></p></div>',
				esc_url( self::url( 'status' ) )
			);
		}
	}
}
