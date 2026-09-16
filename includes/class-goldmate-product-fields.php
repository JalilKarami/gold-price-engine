<?php
/**
 * Per-product and per-variation calculation inputs.
 *
 * Everything a gold product needs lives in one "نرخ خودکار" product-data tab:
 * the on/off switch, a live price preview, the main inputs, and collapsible
 * extras / discount / advanced / accessories sections. It is a product option,
 * not a separate WooCommerce product type.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Product_Fields {

	/**
	 * Registers the product and variation field UI and their save handlers.
	 */
	public static function init() {

		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_data_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_product_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_object' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );

		add_filter(
			'woocommerce_available_variation',
			array( __CLASS__, 'add_goldmate_variation_data' ),
			10,
			3
		);
	}

	/**
	 * Adds the "نرخ خودکار" product data tab.
	 *
	 * The tab is always there for simple and variable products, so the switch
	 * that turns gold pricing on is never hidden behind itself.
	 *
	 * @param array $tabs Existing tabs.
	 * @return array
	 */
	public static function product_data_tab( $tabs ) {

		$tabs['goldmate'] = array(
			'label'    => 'نرخ خودکار',
			'target'   => 'goldmate_product_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 65,
		);

		return $tabs;
	}

	/**
	 * Enqueues the panel's styles and behaviour on the product edit screen.
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

		foreach ( array( 'css/product-panel.css', 'js/product-panel.js' ) as $asset ) {
			$mtime   = @filemtime( GOLDMATE_PATH . 'assets/' . $asset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$version = $mtime ? GOLDMATE_VERSION . '.' . $mtime : GOLDMATE_VERSION;

			if ( 'css' === substr( $asset, 0, 3 ) ) {
				wp_enqueue_style( 'goldmate-product-panel', GOLDMATE_URL . 'assets/' . $asset, array(), $version );
			} else {
				wp_enqueue_script( 'goldmate-product-panel', GOLDMATE_URL . 'assets/' . $asset, array( 'jquery' ), $version, true );
			}
		}
	}

	/**
	 * Works out the wage mode from which wage fields are filled in.
	 *
	 * Combined wage is (weight × fixed) + (gold × %), so it equals the percent
	 * mode when fixed is zero and the fixed mode when the percent is zero. That
	 * lets the form show both fields with no mode dropdown.
	 *
	 * @param mixed  $pct     Raw wage percent.
	 * @param mixed  $fixed   Raw fixed wage per gram.
	 * @param string $current Mode to keep when both are empty.
	 * @return string `pct`, `fixed`, `combined`, or $current.
	 */
	public static function derive_wage_mode( $pct, $fixed, $current = '' ) {

		$pct   = goldmate_positive_float( $pct );
		$fixed = goldmate_positive_float( $fixed );

		if ( $pct > 0 && $fixed > 0 ) {
			return 'combined';
		}

		if ( $fixed > 0 ) {
			return 'fixed';
		}

		if ( $pct > 0 ) {
			return 'pct';
		}

		return (string) $current;
	}

	/**
	 * Turns the posted discount table into stored rows.
	 *
	 * A row is on exactly when its amount is above zero, and the product layer
	 * is on when any row is, so the form needs no checkboxes.
	 *
	 * @param mixed $posted Raw `_goldmate_discounts` array.
	 * @return array{enabled:bool,items:array}
	 */
	public static function discount_rows_from_post( $posted ) {

		$posted  = is_array( $posted ) ? $posted : array();
		$items   = array();
		$enabled = false;

		foreach ( Goldmate_Discounts::targets() as $key => $def ) {
			$row    = isset( $posted[ $key ] ) && is_array( $posted[ $key ] ) ? $posted[ $key ] : array();
			$amount = goldmate_positive_float( isset( $row['amount'] ) ? $row['amount'] : 0 );

			$items[ $key ] = array(
				'enabled' => $amount > 0 ? 'yes' : 'no',
				'type'    => ( isset( $row['type'] ) && 'pct' === $row['type'] ) ? 'pct' : 'fixed',
				'amount'  => $amount,
			);

			$enabled = $enabled || $amount > 0;
		}

		return array(
			'enabled' => $enabled,
			'items'   => $items,
		);
	}

	/**
	 * Opens a collapsible panel section.
	 *
	 * @param string $key   Section key, used by the script for its summary.
	 * @param string $title Section title.
	 * @param bool   $open  Start expanded.
	 */
	protected static function open_section( $key, $title, $open ) {

		printf(
			'<details class="goldmate-section" data-section="%s"%s><summary><span class="goldmate-section-title">%s</span><span class="goldmate-section-summary"></span></summary><div class="goldmate-section-body">',
			esc_attr( $key ),
			$open ? ' open' : '',
			esc_html( $title )
		);
	}

	/**
	 * Closes a section opened by open_section().
	 */
	protected static function close_section() {
		echo '</div></details>';
	}

	/**
	 * Renders the "نرخ خودکار" product data panel.
	 */
	public static function render_product_panel() {

		global $post;

		$post_id = $post ? (int) $post->ID : 0;
		$enabled = 'yes' === get_post_meta( $post_id, '_goldmate_enabled', true );

		$karat = get_post_meta( $post_id, '_goldmate_karat', true );
		if ( '' === trim( (string) $karat ) ) {
			$karat = '18';
		}

		// Show only the wage fields the saved mode uses: a value left in the other
		// field would otherwise turn the product into a combined wage on save.
		$wage_mode = get_post_meta( $post_id, '_goldmate_wage_mode', true );
		if ( '' === trim( (string) $wage_mode ) ) {
			$wage_mode = goldmate_option( 'goldmate_default_wage_mode' );
		}
		$wage_mode  = Goldmate_Calculator::normalize_wage_mode( $wage_mode );
		$wage_pct   = 'fixed' === $wage_mode ? '' : get_post_meta( $post_id, '_goldmate_wage_pct', true );
		$wage_fixed = 'pct' === $wage_mode ? '' : get_post_meta( $post_id, '_goldmate_wage_fixed', true );

		$stone   = get_post_meta( $post_id, '_goldmate_stone', true );
		$leather = get_post_meta( $post_id, '_goldmate_leather', true );
		// Legacy single accessories field still shown if stone/leather empty.
		$legacy_acc = get_post_meta( $post_id, '_goldmate_accessories', true );
		if ( '' === trim( (string) $stone ) && '' === trim( (string) $leather ) && '' !== trim( (string) $legacy_acc ) ) {
			$stone = $legacy_acc;
		}

		$profit      = get_post_meta( $post_id, '_goldmate_profit_pct', true );
		$shop_profit = goldmate_shop_percentages( $post_id )['profit_pct'];
		$tax_exempt  = 'yes' === get_post_meta( $post_id, '_goldmate_tax_exempt', true );

		$formula         = (string) get_post_meta( $post_id, '_goldmate_formula', true );
		$formula_choices = class_exists( 'Goldmate_Formulas' ) ? Goldmate_Formulas::choices( true ) : array();

		// Only discounts that actually apply are shown; a switched-off row's
		// leftover amount would otherwise switch on when the product is saved.
		$discounts = Goldmate_Discounts::get( $post_id );
		$disc_rows = array();
		$disc_open = false;
		foreach ( Goldmate_Discounts::targets() as $key => $def ) {
			$row    = $discounts['items'][ $key ];
			$active = $discounts['enabled'] && ! empty( $row['enabled'] ) && $row['amount'] > 0;

			$disc_rows[ $key ] = array(
				'label'  => $def['label'],
				'type'   => $row['type'],
				'amount' => $active ? $row['amount'] : '',
			);
			$disc_open = $disc_open || $active;
		}

		$components = class_exists( 'Goldmate_Components' ) ? Goldmate_Components::all() : array();
		$comp_vals  = class_exists( 'Goldmate_Components' ) ? Goldmate_Components::product_values( $post_id ) : array();

		$custom_components = false;
		foreach ( $components as $comp ) {
			if ( array_key_exists( $comp['id'], $comp_vals ) && (float) $comp_vals[ $comp['id'] ] !== (float) $comp['default'] ) {
				$custom_components = true;
			}
		}

		$advanced_open = '' !== trim( (string) $profit )
			|| $tax_exempt
			|| $custom_components
			|| ( '' !== $formula && count( $formula_choices ) > 1 );

		$has_accessories = class_exists( 'Goldmate_Accessories' ) && Goldmate_Accessories::get_groups( $post_id );
		?>
		<div id="goldmate_product_data" class="panel woocommerce_options_panel hidden">

			<div class="options_group goldmate-switch">
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => '_goldmate_enabled',
						'label'       => 'قیمت از نرخ روز طلا',
						'value'       => $enabled ? 'yes' : 'no',
						'cbvalue'     => 'yes',
						'description' => 'قیمت این محصول از روی وزن، عیار، اجرت و قیمت روز طلا محاسبه شود.',
					)
				);
				?>
			</div>

			<div class="goldmate-when-on">

				<?php Goldmate_Product_Preview::render_box(); ?>

				<div class="options_group goldmate-panel-grid">
					<?php
					woocommerce_wp_text_input(
						array(
							'id'                => '_goldmate_weight',
							'label'             => 'وزن (گرم)',
							'type'              => 'number',
							// Variable products take a weight per variation.
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
							'label'       => 'عیار',
							'options'     => self::karat_options(),
							'value'       => (string) $karat,
							'desc_tip'    => true,
							'description' => 'برای آیتم مرجع ۱۸ عیار، نرخ به‌صورت خطی با عیار مقیاس می‌شود.',
						)
					);

					woocommerce_wp_text_input(
						array(
							'id'                => '_goldmate_wage_pct',
							'label'             => 'اجرت (٪)',
							'type'              => 'number',
							'value'             => $wage_pct,
							'custom_attributes' => array(
								'step' => '0.01',
								'min'  => '0',
							),
							'desc_tip'          => true,
							'description'       => 'درصد از مبلغ طلا. اگر اجرت ثابت هم وارد شود، هر دو جمع می‌شوند.',
						)
					);

					woocommerce_wp_text_input(
						array(
							'id'                => '_goldmate_wage_fixed',
							'label'             => 'اجرت ثابت (تومان/گرم)',
							'type'              => 'number',
							'value'             => $wage_fixed,
							'custom_attributes' => array(
								'step' => '1',
								'min'  => '0',
							),
							'desc_tip'          => true,
							'description'       => 'تومان به ازای هر گرم. اگر درصد اجرت هم وارد شود، هر دو جمع می‌شوند.',
						)
					);
					?>
				</div>

				<div class="show_if_variable goldmate-variations">
					<p class="goldmate-hint description">مقادیر بالا برای همه‌ی متغیرها است. وزن هر متغیر و هر مقدار متفاوت را در جدول زیر وارد کنید.</p>
					<?php Goldmate_Variation_Table::render( $post_id ); ?>
				</div>

				<?php
				self::open_section( 'extras', 'سنگ و چرم', goldmate_positive_float( $stone ) > 0 || goldmate_positive_float( $leather ) > 0 );
				echo '<div class="options_group goldmate-panel-grid">';

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

				echo '</div>';
				self::close_section();

				self::open_section( 'discount', 'تخفیف', $disc_open );
				echo '<div class="options_group goldmate-panel-grid">';

				woocommerce_wp_text_input(
					array(
						'id'          => '_goldmate_discounts_from',
						'label'       => 'از تاریخ',
						'type'        => 'date',
						'value'       => isset( $discounts['date_from'] ) ? $discounts['date_from'] : '',
						'desc_tip'    => true,
						'description' => 'خالی = بدون محدودیت شروع',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'          => '_goldmate_discounts_to',
						'label'       => 'تا تاریخ',
						'type'        => 'date',
						'value'       => isset( $discounts['date_to'] ) ? $discounts['date_to'] : '',
						'desc_tip'    => true,
						'description' => 'خالی = بدون محدودیت پایان',
					)
				);

				echo '</div>';
				?>
				<table class="goldmate-discount-table">
					<thead>
						<tr>
							<th>تخفیف روی</th>
							<th>نوع</th>
							<th>مقدار</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $disc_rows as $key => $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['label'] ); ?></td>
								<td>
									<select name="_goldmate_discounts[<?php echo esc_attr( $key ); ?>][type]">
										<option value="pct" <?php selected( $row['type'], 'pct' ); ?>>درصدی</option>
										<option value="fixed" <?php selected( $row['type'], 'fixed' ); ?>>ثابت (تومان)</option>
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
				<p class="goldmate-hint description">هر ردیفی که مقدار داشته باشد فعال است؛ برای خاموش کردن، مقدار را خالی کنید. تخفیف محصول بر تخفیف دسته و سراسری اولویت دارد.</p>
				<?php
				self::close_section();

				self::open_section( 'advanced', 'پیشرفته', $advanced_open );
				echo '<div class="options_group goldmate-panel-grid">';

				// With a single active formula there is nothing to choose.
				if ( count( $formula_choices ) > 1 ) {
					woocommerce_wp_select(
						array(
							'id'          => '_goldmate_formula',
							'label'       => 'فرمول قیمت',
							'options'     => array_merge( array( '' => '— پیش‌فرض فروشگاه —' ), $formula_choices ),
							'value'       => $formula,
							'desc_tip'    => true,
							'description' => 'فرمول مشخص می‌کند کدام آیتم نرخ استفاده شود.',
						)
					);
				}

				woocommerce_wp_text_input(
					array(
						'id'                => '_goldmate_profit_pct',
						'label'             => 'سود (٪)',
						'type'              => 'number',
						'value'             => $profit,
						'placeholder'       => 'پیش‌فرض فروشگاه: ' . wc_format_localized_decimal( $shop_profit ),
						'custom_attributes' => array(
							'step' => '0.01',
							'min'  => '0',
						),
						'desc_tip'          => true,
						'description'       => 'خالی = درصد سود پیش‌فرض فروشگاه.',
					)
				);

				woocommerce_wp_checkbox(
					array(
						'id'          => '_goldmate_tax_exempt',
						'label'       => 'معاف از مالیات',
						'value'       => $tax_exempt ? 'yes' : 'no',
						'cbvalue'     => 'yes',
						'description' => 'مالیات و عوارض به قیمت این محصول اضافه نشود.',
					)
				);

				foreach ( $components as $comp ) {
					if ( 'yes' !== $comp['enabled'] ) {
						continue;
					}

					$modes = Goldmate_Components::calc_modes();
					$hint  = isset( $modes[ $comp['calc'] ] ) ? $modes[ $comp['calc'] ] : $comp['calc'];

					woocommerce_wp_text_input(
						array(
							'id'                => '_goldmate_comp_' . $comp['id'],
							'name'              => '_goldmate_component_values[' . $comp['id'] . ']',
							'label'             => $comp['label'],
							'type'              => 'number',
							'value'             => array_key_exists( $comp['id'], $comp_vals ) ? $comp_vals[ $comp['id'] ] : $comp['default'],
							'custom_attributes' => array(
								'step' => '0.01',
								'min'  => '0',
							),
							'desc_tip'          => true,
							'description'       => $hint . ( $comp['default'] > 0 ? ' — پیش‌فرض: ' . $comp['default'] : '' ),
						)
					);
				}

				echo '</div>';
				self::close_section();
				?>
			</div>

			<?php
			// Accessory groups work whether or not the price is automatic.
			if ( class_exists( 'Goldmate_Accessories' ) ) {
				self::open_section( 'accessories', 'متعلقات قابل انتخاب (زنجیر، جعبه، …)', (bool) $has_accessories );
				Goldmate_Accessories::render_admin_panel();
				self::close_section();
			}
			?>
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
	 * Saves product-level gold fields from the "نرخ خودکار" tab.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public static function save_product_object( $product ) {

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC verifies before this hook.

		$enabled = isset( $_POST['_goldmate_enabled'] ) ? 'yes' : 'no';
		$product->update_meta_data( '_goldmate_enabled', $enabled );

		if ( isset( $_POST['_goldmate_wage_pct'] ) || isset( $_POST['_goldmate_wage_fixed'] ) ) {
			$mode = self::derive_wage_mode(
				isset( $_POST['_goldmate_wage_pct'] ) ? wc_clean( wp_unslash( $_POST['_goldmate_wage_pct'] ) ) : '',
				isset( $_POST['_goldmate_wage_fixed'] ) ? wc_clean( wp_unslash( $_POST['_goldmate_wage_fixed'] ) ) : '',
				(string) $product->get_meta( '_goldmate_wage_mode', true )
			);
			if ( '' !== $mode ) {
				$product->update_meta_data( '_goldmate_wage_mode', Goldmate_Calculator::normalize_wage_mode( $mode ) );
			}
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

		// Empty profit means the shop default; "0" is a real 0% override.
		if ( isset( $_POST['_goldmate_profit_pct'] ) ) {
			$profit = trim( (string) wc_clean( wp_unslash( $_POST['_goldmate_profit_pct'] ) ) );
			$product->update_meta_data( '_goldmate_profit_pct', '' === $profit ? '' : goldmate_positive_float( $profit ) );
		}

		$product->update_meta_data(
			'_goldmate_tax_exempt',
			isset( $_POST['_goldmate_tax_exempt'] ) ? 'yes' : 'no'
		);

		if ( isset( $_POST['_goldmate_discounts'] ) ) {
			$discounts = self::discount_rows_from_post( wp_unslash( $_POST['_goldmate_discounts'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per row.
			$product->update_meta_data( '_goldmate_discounts_enabled', $discounts['enabled'] ? 'yes' : 'no' );
			$product->update_meta_data( '_goldmate_discounts', $discounts['items'] );
		}

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

		// After the parent's own values, which variations inherit from.
		Goldmate_Variation_Table::save( $product );

		// phpcs:enable WordPress.Security.NonceVerification.Missing
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
