<?php
/**
 * Per-product and per-variation calculation inputs.
 *
 * UX mirrors Ratesbox: a "نرخ خودکار" checkbox next to Virtual/Downloadable
 * unlocks a dedicated product-data tab (not a separate WooCommerce product type).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Product_Fields {

	/**
	 * Registers the product and variation field UI and their save handlers.
	 */
	public static function init() {

		add_filter( 'product_type_options', array( __CLASS__, 'product_type_option' ) );
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_data_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_product_panel' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_discount_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_object' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );

		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_fields' ), 10, 2 );
		add_filter(
			'woocommerce_available_variation',
			array( __CLASS__, 'add_goldmate_variation_data' ),
			10,
			3
		);
	}

	/**
	 * Adds "نرخ خودکار" beside Virtual / Downloadable.
	 *
	 * This is NOT a new product type — it is a product option that toggles
	 * automatic gold pricing on simple and variable products.
	 *
	 * @param array $options Existing type options.
	 * @return array
	 */
	public static function product_type_option( $options ) {

		// Array key must be "goldmate_enabled" so WooCommerce checks meta `_goldmate_enabled`
		// (it looks up '_' . $key — not the checkbox id).
		$options['goldmate_enabled'] = array(
			'id'            => '_goldmate_enabled',
			'wrapper_class' => 'show_if_simple show_if_variable',
			'label'         => 'نرخ خودکار',
			'description'   => 'قیمت این محصول از روی وزن، عیار، اجرت و قیمت روز طلا محاسبه شود.',
			'default'       => 'no',
		);

		return $options;
	}

	/**
	 * Adds the dedicated "نرخ خودکار" product data tab.
	 *
	 * @param array $tabs Existing tabs.
	 * @return array
	 */
	public static function product_data_tab( $tabs ) {

		$tabs['goldmate'] = array(
			'label'    => 'نرخ خودکار',
			'target'   => 'goldmate_product_data',
			'class'    => array( 'show_if_simple', 'show_if_variable', 'show_if_goldmate' ),
			'priority' => 65,
		);

		$tabs['goldmate_discount'] = array(
			'label'    => 'تخفیف',
			'target'   => 'goldmate_discount_data',
			'class'    => array( 'show_if_simple', 'show_if_variable', 'show_if_goldmate' ),
			'priority' => 66,
		);

		return $tabs;
	}

	/**
	 * Enqueues small admin CSS/JS so the tab behaves like Ratesbox.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function admin_assets( $hook ) {

		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_add_inline_style(
			'woocommerce_admin_styles',
			'
			#woocommerce-product-data ul.wc-tabs li.goldmate_options a::before { content: "\f185"; font-family: dashicons; }
			#woocommerce-product-data ul.wc-tabs li.goldmate_discount_options a::before { content: "\f323"; font-family: dashicons; }
			#goldmate_product_data .goldmate-panel-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
			#goldmate_product_data .goldmate-panel-grid .form-field { float: none; width: auto; padding: 5px 0 5px 162px !important; margin: 0; }
			#goldmate_product_data .options_group { border-top: 1px solid #eee; }
			#goldmate_discount_data .goldmate-discount-table { width: 100%; border-collapse: collapse; margin: 12px; }
			#goldmate_discount_data .goldmate-discount-table th,
			#goldmate_discount_data .goldmate-discount-table td { padding: 10px 8px; border-bottom: 1px solid #eee; text-align: right; vertical-align: middle; }
			#goldmate_discount_data .goldmate-discount-table th { background: #f6f7f7; font-weight: 600; }
			#goldmate_discount_data .goldmate-discount-table select,
			#goldmate_discount_data .goldmate-discount-table input[type="number"] { width: 100%; max-width: 160px; }
			#goldmate_discount_data .goldmate-discount-table input[type="checkbox"] {
				width: 18px !important;
				height: 18px !important;
				min-width: 18px !important;
				margin: 0 !important;
				float: none !important;
				position: static !important;
				cursor: pointer;
				pointer-events: auto !important;
			}
			#goldmate_discount_data ._goldmate_discounts_enabled_field .description {
				display: block;
				clear: both;
				margin-top: 6px;
				max-width: 100%;
			}
			#goldmate_discount_data ._goldmate_discounts_enabled_field input.checkbox {
				position: relative;
				z-index: 2;
				pointer-events: auto !important;
			}
			#goldmate_discount_data.goldmate-discounts-off .goldmate-discount-table {
				opacity: 0.55;
			}
			#goldmate_discount_data.goldmate-discounts-off .goldmate-discount-table select,
			#goldmate_discount_data.goldmate-discounts-off .goldmate-discount-table input[type="number"] {
				pointer-events: none;
			}
			@media (max-width: 900px) {
				#goldmate_product_data .goldmate-panel-grid { grid-template-columns: 1fr; }
			}
			'
		);

		wp_add_inline_script(
			'woocommerce_admin',
			"
			jQuery( function ( $ ) {
				function syncGoldmateWageFields() {
					var mode = $( '#_goldmate_wage_mode' ).val();
					$( '._goldmate_wage_pct_field' ).toggle( mode === 'pct' || mode === 'combined' );
					$( '._goldmate_wage_fixed_field' ).toggle( mode === 'fixed' || mode === 'combined' );
				}
				$( document.body ).on( 'change', '#_goldmate_wage_mode', syncGoldmateWageFields );
				syncGoldmateWageFields();

				function syncGoldmateDiscountPanel() {
					var on = $( '#_goldmate_discounts_enabled' ).is( ':checked' );
					$( '#goldmate_discount_data' ).toggleClass( 'goldmate-discounts-off', ! on );
				}
				$( document.body ).on( 'change', '#_goldmate_discounts_enabled', syncGoldmateDiscountPanel );
				// Row checkboxes stay clickable even when master is off; turn master on when enabling a row.
				$( document.body ).on( 'change', '#goldmate_discount_data .goldmate-discount-table input[type=checkbox]', function () {
					if ( $( this ).is( ':checked' ) && ! $( '#_goldmate_discounts_enabled' ).is( ':checked' ) ) {
						$( '#_goldmate_discounts_enabled' ).prop( 'checked', true ).trigger( 'change' );
					}
				} );
				syncGoldmateDiscountPanel();

				// Hide manual regular/sale prices while automatic rate is on,
				// and show/hide GoldMate tabs (WC only auto-handles virtual/downloadable).
				function syncGoldmatePricingVisibility() {
					var on = $( '#_goldmate_enabled' ).is( ':checked' );
					$( '._regular_price_field, ._sale_price_field' ).closest( '.options_group' ).toggle( ! on );
					$( '._regular_price_field, ._sale_price_field' ).toggle( ! on );
					$( '.show_if_goldmate' ).each( function () {
						var \$el = $( this );
						if ( \$el.is( 'li' ) ) {
							\$el.toggle( on );
						}
					} );
				}
				$( document.body ).on( 'change', '#_goldmate_enabled', syncGoldmatePricingVisibility );
				$( document.body ).on( 'woocommerce-product-type-change', syncGoldmatePricingVisibility );
				$( '#woocommerce-product-data' ).on( 'woocommerce_variations_loaded', syncGoldmatePricingVisibility );
				syncGoldmatePricingVisibility();
			} );
			"
		);
	}

	/**
	 * Renders the "نرخ خودکار" product data panel.
	 */
	public static function render_product_panel() {

		global $post;

		$post_id   = $post ? (int) $post->ID : 0;
		$wage_mode = get_post_meta( $post_id, '_goldmate_wage_mode', true );
		if ( '' === trim( (string) $wage_mode ) ) {
			$wage_mode = goldmate_option( 'goldmate_default_wage_mode' );
		}
		$wage_mode = Goldmate_Calculator::normalize_wage_mode( $wage_mode );

		$karat = get_post_meta( $post_id, '_goldmate_karat', true );
		if ( '' === trim( (string) $karat ) ) {
			$karat = '18';
		}

		$stone   = get_post_meta( $post_id, '_goldmate_stone', true );
		$leather = get_post_meta( $post_id, '_goldmate_leather', true );
		// Legacy single accessories field still shown if stone/leather empty.
		$legacy_acc = get_post_meta( $post_id, '_goldmate_accessories', true );
		if ( '' === trim( (string) $stone ) && '' === trim( (string) $leather ) && '' !== trim( (string) $legacy_acc ) ) {
			$stone = $legacy_acc;
		}

		$profit = get_post_meta( $post_id, '_goldmate_profit_pct', true );
		$tax_ex = get_post_meta( $post_id, '_goldmate_tax_exempt', true );

		$formula         = get_post_meta( $post_id, '_goldmate_formula', true );
		$formula_choices = class_exists( 'Goldmate_Formulas' )
			? array_merge( array( '' => '— پیش‌فرض فروشگاه —' ), Goldmate_Formulas::choices( true ) )
			: array();
		?>
		<div id="goldmate_product_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<p style="margin:12px 12px 0;font-weight:600;">تنظیمات نرخ خودکار طلا</p>
				<p class="form-field show_if_simple" style="padding-left:12px !important;">
					<span class="description">وزن و اجرت را اینجا وارد کنید؛ قیمت نهایی بر اساس فرمول انتخاب‌شده محاسبه می‌شود.</span>
				</p>
				<p class="form-field show_if_variable" style="padding-left:12px !important;">
					<span class="description">عیار، اجرت، سنگ و سود را اینجا یک‌بار برای همه‌ی متغیرها وارد کنید. وزن را برای هر متغیر جداگانه در تب «متغیرها» وارد کنید. اگر یک متغیر عیار، اجرت یا سنگ متفاوتی دارد، همان‌جا روی آن متغیر وارد کنید.</span>
				</p>
			</div>

			<div class="options_group goldmate-panel-grid">
				<?php
				if ( ! empty( $formula_choices ) ) {
					woocommerce_wp_select(
						array(
							'id'          => '_goldmate_formula',
							'label'       => 'فرمول قیمت',
							'options'     => $formula_choices,
							'value'       => (string) $formula,
							'desc_tip'    => true,
							'description' => 'فرمول مشخص می‌کند کدام آیتم نرخ استفاده شود. خالی = فرمول پیش‌فرض فروشگاه.',
						)
					);
				}

				woocommerce_wp_text_input(
					array(
						'id'                => '_goldmate_weight',
						'label'             => 'وزن (گرم)',
						'type'              => 'number',
						// Variable products take a weight per variation from the table below.
						'wrapper_class'     => 'show_if_simple',
						'custom_attributes' => array(
							'step' => '0.001',
							'min'  => '0',
						),
						'desc_tip'          => true,
						'description'       => 'وزن خالص طلا بدون سنگ و چرم.',
					)
				);

				woocommerce_wp_select(
					array(
						'id'          => '_goldmate_karat',
						'label'       => 'نوع آیتم / عیار',
						'options'     => self::karat_options(),
						'value'       => (string) $karat,
						'desc_tip'    => true,
						'description' => 'برای آیتم مرجع ۱۸ عیار، نرخ به‌صورت خطی با عیار مقیاس می‌شود.',
					)
				);

				woocommerce_wp_select(
					array(
						'id'          => '_goldmate_wage_mode',
						'label'       => 'نوع محاسبه اجرت ساخت',
						'options'     => array(
							'pct'      => 'درصدی از مبلغ طلا',
							'fixed'    => 'رقم ثابت به ازای هر گرم',
							'combined' => 'ترکیبی (ثابت + درصد)',
						),
						'value'       => $wage_mode,
						'desc_tip'    => true,
						'description' => 'ترکیبی: اجرت = (وزن × ثابت) + (مبلغ طلا × درصد).',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'                => '_goldmate_wage_fixed',
						'label'             => 'اجرت ساخت (رقم ثابت)',
						'type'              => 'number',
						'wrapper_class'     => '_goldmate_wage_fixed_field',
						'custom_attributes' => array(
							'step' => '1',
							'min'  => '0',
						),
						'desc_tip'          => true,
						'description'       => 'تومان به ازای هر گرم.',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'                => '_goldmate_wage_pct',
						'label'             => 'اجرت ساخت (درصد)',
						'type'              => 'number',
						'wrapper_class'     => '_goldmate_wage_pct_field',
						'custom_attributes' => array(
							'step' => '0.01',
							'min'  => '0',
						),
						'desc_tip'          => true,
						'description'       => 'درصد نسبت به مبلغ طلا.',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'                => '_goldmate_stone',
						'label'             => 'قیمت سنگ',
						'type'              => 'number',
						'value'             => $stone,
						'custom_attributes' => array(
							'step' => '1',
							'min'  => '0',
						),
						'desc_tip'          => true,
						'description'       => 'تومان. مشمول سود نمی‌شود.',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'                => '_goldmate_leather',
						'label'             => 'قیمت چرم و یراق',
						'type'              => 'number',
						'value'             => $leather,
						'custom_attributes' => array(
							'step' => '1',
							'min'  => '0',
						),
						'desc_tip'          => true,
						'description'       => 'تومان. مشمول سود نمی‌شود.',
					)
				);

				woocommerce_wp_select(
					array(
						'id'          => '_goldmate_profit_mode',
						'label'       => 'نوع محاسبه سود',
						'options'     => array(
							'shop' => 'پیش‌فرض فروشگاه',
							'pct'  => 'درصد اختصاصی این محصول',
						),
						'value'       => ( '' !== trim( (string) $profit ) ) ? 'pct' : 'shop',
						'desc_tip'    => true,
						'description' => 'اگر «پیش‌فرض فروشگاه» باشد، درصد سود از تنظیمات گلدمیت خوانده می‌شود.',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'                => '_goldmate_profit_pct',
						'label'             => 'سود (٪)',
						'type'              => 'number',
						'value'             => ( '' !== trim( (string) $profit ) ) ? $profit : goldmate_shop_percentages( $post_id )['profit_pct'],
						'custom_attributes' => array(
							'step' => '0.01',
							'min'  => '0',
						),
						'desc_tip'          => true,
						'description'       => 'فقط وقتی نوع سود «اختصاصی» است ذخیره می‌شود.',
					)
				);
				?>
			</div>

			<div class="options_group">
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => '_goldmate_tax_exempt',
						'label'       => 'معاف از مالیات؟',
						'value'       => $tax_ex ? $tax_ex : 'no',
						'cbvalue'     => 'yes',
						'description' => 'مالیات و عوارض به قیمت این محصول اضافه نشود.',
					)
				);
				?>
			</div>

			<?php
			$components = class_exists( 'Goldmate_Components' ) ? Goldmate_Components::all() : array();
			$comp_vals  = class_exists( 'Goldmate_Components' ) ? Goldmate_Components::product_values( $post_id ) : array();
			if ( ! empty( $components ) ) :
				?>
			<div class="options_group">
				<p style="margin:12px 12px 0;font-weight:600;">اجزای قیمت سفارشی</p>
				<p class="form-field" style="padding-left:12px !important;">
					<span class="description">مقادیر تعریف‌شده در تب «اجزای قیمت» افزونه. خالی = مقدار پیش‌فرض فروشگاه.</span>
				</p>
				<div class="goldmate-panel-grid">
					<?php foreach ( $components as $comp ) : ?>
						<?php if ( 'yes' !== $comp['enabled'] ) { continue; } ?>
						<?php
						$modes = Goldmate_Components::calc_modes();
						$hint  = isset( $modes[ $comp['calc'] ] ) ? $modes[ $comp['calc'] ] : $comp['calc'];
						$val   = array_key_exists( $comp['id'], $comp_vals ) ? $comp_vals[ $comp['id'] ] : $comp['default'];
						woocommerce_wp_text_input(
							array(
								'id'                => '_goldmate_comp_' . $comp['id'],
								'name'              => '_goldmate_component_values[' . $comp['id'] . ']',
								'label'             => $comp['label'],
								'type'              => 'number',
								'value'             => $val,
								'custom_attributes' => array(
									'step' => '0.01',
									'min'  => '0',
								),
								'desc_tip'          => true,
								'description'       => $hint . ( $comp['default'] > 0 ? ' — پیش‌فرض: ' . $comp['default'] : '' ),
							)
						);
						?>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the product تخفیف tab.
	 */
	public static function render_discount_panel() {

		global $post;
		$post_id   = $post ? (int) $post->ID : 0;
		$discounts = Goldmate_Discounts::get( $post_id );
		?>
		<div id="goldmate_discount_data" class="panel woocommerce_options_panel hidden show_if_simple show_if_variable show_if_goldmate">
			<div class="options_group">
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'            => '_goldmate_discounts_enabled',
						'label'         => 'فعال کردن تخفیف‌ها',
						'value'         => $discounts['enabled'] ? 'yes' : 'no',
						'cbvalue'       => 'yes',
						'desc_tip'      => true,
						'description'   => 'تخفیف‌های زیر فقط وقتی این گزینه روشن باشد اعمال می‌شوند و بر تخفیف دسته/سراسری اولویت دارند.',
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'          => '_goldmate_discounts_from',
						'label'       => 'فعال از تاریخ',
						'type'        => 'date',
						'value'       => isset( $discounts['date_from'] ) ? $discounts['date_from'] : '',
						'desc_tip'    => true,
						'description' => 'خالی = بدون محدودیت شروع',
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'          => '_goldmate_discounts_to',
						'label'       => 'فعال تا تاریخ',
						'type'        => 'date',
						'value'       => isset( $discounts['date_to'] ) ? $discounts['date_to'] : '',
						'desc_tip'    => true,
						'description' => 'خالی = بدون محدودیت پایان',
					)
				);
				?>
			</div>

			<table class="goldmate-discount-table">
				<thead>
					<tr>
						<th style="width:34%;">تخفیف روی</th>
						<th style="width:14%;">فعال باشد؟</th>
						<th style="width:22%;">نوع تخفیف</th>
						<th style="width:30%;">مقدار تخفیف</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( Goldmate_Discounts::targets() as $key => $def ) : ?>
						<?php $row = $discounts['items'][ $key ]; ?>
						<tr>
							<td><?php echo esc_html( $def['label'] ); ?></td>
							<td>
								<label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
									<input type="checkbox"
										class="checkbox"
										id="_goldmate_discount_<?php echo esc_attr( $key ); ?>_enabled"
										name="_goldmate_discounts[<?php echo esc_attr( $key ); ?>][enabled]"
										value="yes"
										<?php checked( ! empty( $row['enabled'] ) ); ?>
									>
									<span>فعال</span>
								</label>
							</td>
							<td>
								<select name="_goldmate_discounts[<?php echo esc_attr( $key ); ?>][type]">
									<option value="pct" <?php selected( $row['type'], 'pct' ); ?>>درصدی</option>
									<option value="fixed" <?php selected( $row['type'], 'fixed' ); ?>>ثابت</option>
								</select>
							</td>
							<td>
								<input type="number"
									name="_goldmate_discounts[<?php echo esc_attr( $key ); ?>][amount]"
									value="<?php echo esc_attr( $row['amount'] ); ?>"
									min="0"
									step="0.01"
								>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="form-field" style="padding:0 12px 16px !important;">
				<span class="description">برای نوع ثابت، مقدار به تومان است. برای درصدی، عدد درصد را وارد کنید (مثلاً ۲ یعنی ۲٪).</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Common karat choices for the item-type dropdown.
	 *
	 * @return array<string,string>
	 */
	protected static function karat_options() {
		return array(
			'24' => 'طلای ۲۴ عیار',
			'22' => 'طلای ۲۲ عیار',
			'21' => 'طلای ۲۱ عیار',
			'18' => 'طلای ۱۸ عیار',
			'14' => 'طلای ۱۴ عیار',
			'10' => 'طلای ۱۰ عیار',
			'9'  => 'طلای ۹ عیار',
		);
	}

	/**
	 * Saves product-level gold fields from the dedicated tab + type option.
	 *
	 * WooCommerce already persists `_goldmate_enabled` from the type-option
	 * checkbox when the id matches; we still normalise it here for safety.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public static function save_product_object( $product ) {

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$enabled = isset( $_POST['_goldmate_enabled'] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC verifies.
		$product->update_meta_data( '_goldmate_enabled', $enabled );

		if ( isset( $_POST['_goldmate_wage_mode'] ) ) {
			$product->update_meta_data(
				'_goldmate_wage_mode',
				Goldmate_Calculator::normalize_wage_mode(
					sanitize_text_field( wp_unslash( $_POST['_goldmate_wage_mode'] ) )
				)
			);
		}

		if ( isset( $_POST['_goldmate_formula'] ) ) {
			$formula_slug = sanitize_title( wp_unslash( $_POST['_goldmate_formula'] ) );
			if ( '' === $formula_slug ) {
				$product->delete_meta_data( '_goldmate_formula' );
			} else {
				$product->update_meta_data( '_goldmate_formula', $formula_slug );
			}
			$product->delete_meta_data( '_goldmate_rate_item' );
		}

		if ( isset( $_POST['_goldmate_karat'] ) ) {
			$product->update_meta_data(
				'_goldmate_karat',
				goldmate_positive_float( wp_unslash( $_POST['_goldmate_karat'] ) )
			);
		}

		foreach ( array( '_goldmate_weight', '_goldmate_wage_pct', '_goldmate_wage_fixed', '_goldmate_stone', '_goldmate_leather' ) as $key ) {

			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			$raw = wc_clean( wp_unslash( $_POST[ $key ] ) );

			if ( '' === trim( (string) $raw ) ) {
				$product->update_meta_data( $key, '' );
				continue;
			}

			$product->update_meta_data( $key, goldmate_positive_float( $raw ) );
		}

		// Keep legacy accessories in sync = stone + leather for older code paths.
		$stone   = goldmate_positive_float( $product->get_meta( '_goldmate_stone', true ) );
		$leather = goldmate_positive_float( $product->get_meta( '_goldmate_leather', true ) );
		$product->update_meta_data( '_goldmate_accessories', $stone + $leather );

		$profit_mode = isset( $_POST['_goldmate_profit_mode'] )
			? sanitize_text_field( wp_unslash( $_POST['_goldmate_profit_mode'] ) )
			: 'shop';

		if ( 'pct' === $profit_mode && isset( $_POST['_goldmate_profit_pct'] ) ) {
			$product->update_meta_data(
				'_goldmate_profit_pct',
				goldmate_positive_float( wp_unslash( $_POST['_goldmate_profit_pct'] ) )
			);
		} else {
			$product->update_meta_data( '_goldmate_profit_pct', '' );
		}

		$product->update_meta_data(
			'_goldmate_tax_exempt',
			isset( $_POST['_goldmate_tax_exempt'] ) ? 'yes' : 'no'
		);

		$product->update_meta_data(
			'_goldmate_discounts_enabled',
			isset( $_POST['_goldmate_discounts_enabled'] ) ? 'yes' : 'no'
		);

		$discount_rows = array();
		$posted_disc   = isset( $_POST['_goldmate_discounts'] ) && is_array( $_POST['_goldmate_discounts'] )
			? wp_unslash( $_POST['_goldmate_discounts'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		foreach ( Goldmate_Discounts::targets() as $key => $def ) {
			$row = isset( $posted_disc[ $key ] ) && is_array( $posted_disc[ $key ] ) ? $posted_disc[ $key ] : array();
			$discount_rows[ $key ] = array(
				'enabled' => ( ! empty( $row['enabled'] ) && 'yes' === $row['enabled'] ) ? 'yes' : 'no',
				'type'    => ( isset( $row['type'] ) && 'pct' === $row['type'] ) ? 'pct' : 'fixed',
				'amount'  => goldmate_positive_float( isset( $row['amount'] ) ? $row['amount'] : 0 ),
			);
		}
		$product->update_meta_data( '_goldmate_discounts', $discount_rows );

		$product->update_meta_data(
			'_goldmate_discounts_from',
			isset( $_POST['_goldmate_discounts_from'] ) ? sanitize_text_field( wp_unslash( $_POST['_goldmate_discounts_from'] ) ) : ''
		);
		$product->update_meta_data(
			'_goldmate_discounts_to',
			isset( $_POST['_goldmate_discounts_to'] ) ? sanitize_text_field( wp_unslash( $_POST['_goldmate_discounts_to'] ) ) : ''
		);

		$comp_vals = array();
		if ( isset( $_POST['_goldmate_component_values'] ) && is_array( $_POST['_goldmate_component_values'] ) ) {
			foreach ( wp_unslash( $_POST['_goldmate_component_values'] ) as $cid => $cval ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$cid = sanitize_key( $cid );
				if ( '' === $cid ) {
					continue;
				}
				$comp_vals[ $cid ] = goldmate_positive_float( $cval );
			}
		}
		$product->update_meta_data( '_goldmate_component_values', $comp_vals );
	}

	/**
	 * Renders the per-variation overrides.
	 *
	 * @param int     $loop           Variation index in the form.
	 * @param array   $variation_data Legacy variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function render_variation_fields( $loop, $variation_data, $variation ) {

		$parent_id = $variation->post_parent;
		$enabled   = 'yes' === get_post_meta( $parent_id, '_goldmate_enabled', true );

		echo '<div class="goldmate-variation-fields show_if_goldmate" style="clear:both;padding-top:6px;">';

		printf(
			'<p style="margin:0 0 6px;font-weight:600;">نرخ خودکار%s</p>',
			$enabled ? '' : ' <span style="font-weight:400;color:#b32d2e;">(در محصول اصلی غیرفعال است — تیک «نرخ خودکار» را بزنید)</span>'
		);

		woocommerce_wp_checkbox(
			array(
				'id'            => "_goldmate_excluded{$loop}",
				'name'          => "_goldmate_excluded[{$loop}]",
				'value'         => get_post_meta( $variation->ID, '_goldmate_excluded', true ),
				'label'         => 'این متغیر محاسبه نشود',
				'description'   => 'قیمت این متغیر دستی می‌ماند.',
				'wrapper_class' => 'form-row form-row-full',
			)
		);

		$parent_mode = get_post_meta( $parent_id, '_goldmate_wage_mode', true );
		if ( '' === trim( (string) $parent_mode ) ) {
			$parent_mode = goldmate_option( 'goldmate_default_wage_mode' );
		}
		$own_mode = get_post_meta( $variation->ID, '_goldmate_wage_mode', true );

		woocommerce_wp_select(
			array(
				'id'            => "_goldmate_wage_mode{$loop}",
				'name'          => "_goldmate_wage_mode[{$loop}]",
				'value'         => $own_mode,
				'label'         => 'نوع اجرت',
				'options'       => array(
					''         => 'ارثی از محصول اصلی',
					'pct'      => 'درصدی از مبلغ طلا',
					'fixed'    => 'رقم ثابت به ازای هر گرم',
					'combined' => 'ترکیبی (ثابت + درصد)',
				),
				'wrapper_class' => 'form-row form-row-full',
				'description'   => 'خالی بگذارید تا از محصول اصلی به ارث برسد.',
				'desc_tip'      => true,
			)
		);

		$fields = array(
			'_goldmate_weight'     => array( 'وزن (گرم)', '0.001', 'form-row form-row-first' ),
			'_goldmate_karat'      => array( 'عیار', '0.1', 'form-row form-row-last' ),
			'_goldmate_wage_pct'   => array( 'اجرت (٪)', '0.01', 'form-row form-row-first' ),
			'_goldmate_wage_fixed' => array( 'اجرت ثابت (تومان/گرم)', '1', 'form-row form-row-last' ),
			'_goldmate_stone'      => array( 'قیمت سنگ', '1', 'form-row form-row-first' ),
			'_goldmate_leather'    => array( 'قیمت چرم', '1', 'form-row form-row-last' ),
		);

		foreach ( $fields as $key => $field ) {

			list( $label, $step, $wrapper ) = $field;

			$inherited = get_post_meta( $parent_id, $key, true );

			woocommerce_wp_text_input(
				array(
					'id'                => "{$key}{$loop}",
					'name'              => "{$key}[{$loop}]",
					'value'             => get_post_meta( $variation->ID, $key, true ),
					'label'             => $label,
					'type'              => 'number',
					'placeholder'       => '' !== trim( (string) $inherited ) ? $inherited : 'ارثی از محصول اصلی',
					'custom_attributes' => array(
						'step' => $step,
						'min'  => '0',
					),
					'wrapper_class'     => $wrapper,
					'description'       => 'خالی بگذارید تا از محصول اصلی به ارث برسد.',
					'desc_tip'          => true,
				)
			);
		}

		echo '</div>';
	}

	/**
	 * Saves the per-variation overrides.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Variation index in the form.
	 */
	public static function save_variation_fields( $variation_id, $loop ) {

		$excluded = isset( $_POST['_goldmate_excluded'][ $loop ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $variation_id, '_goldmate_excluded', $excluded );

		if ( isset( $_POST['_goldmate_wage_mode'][ $loop ] ) ) {
			$raw_mode = sanitize_text_field( wp_unslash( $_POST['_goldmate_wage_mode'][ $loop ] ) );
			if ( '' === $raw_mode ) {
				delete_post_meta( $variation_id, '_goldmate_wage_mode' );
			} else {
				update_post_meta( $variation_id, '_goldmate_wage_mode', Goldmate_Calculator::normalize_wage_mode( $raw_mode ) );
			}
		}

		$keys = array( '_goldmate_weight', '_goldmate_karat', '_goldmate_wage_pct', '_goldmate_wage_fixed', '_goldmate_stone', '_goldmate_leather' );

		foreach ( $keys as $key ) {

			if ( ! isset( $_POST[ $key ][ $loop ] ) ) {
				continue;
			}

			$raw = wc_clean( wp_unslash( $_POST[ $key ][ $loop ] ) );

			if ( '' === trim( (string) $raw ) ) {
				delete_post_meta( $variation_id, $key );
				continue;
			}

			update_post_meta( $variation_id, $key, goldmate_positive_float( $raw ) );
		}

		$stone   = goldmate_meta_float( $variation_id, '_goldmate_stone' );
		$leather = goldmate_meta_float( $variation_id, '_goldmate_leather' );
		if ( $stone > 0 || $leather > 0 || metadata_exists( 'post', $variation_id, '_goldmate_stone' ) || metadata_exists( 'post', $variation_id, '_goldmate_leather' ) ) {
			update_post_meta( $variation_id, '_goldmate_accessories', $stone + $leather );
		}
	}

	/**
	 * Adds GoldMate weight to variation JSON for the storefront.
	 *
	 * @param array                $data      Variation data.
	 * @param WC_Product           $product   Parent product.
	 * @param WC_Product_Variation $variation Variation.
	 * @return array
	 */
	public static function add_goldmate_variation_data( $data, $product, $variation ) {

		$weight = get_post_meta( $variation->get_id(), '_goldmate_weight', true );

		if ( '' === $weight ) {
			$weight = get_post_meta( $product->get_id(), '_goldmate_weight', true );
		}

		$data['goldmate_weight'] = '' !== $weight ? (float) $weight : 0;

		return $data;
	}
}
