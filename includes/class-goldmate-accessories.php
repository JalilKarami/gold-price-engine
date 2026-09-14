<?php
/**
 * Linked accessory products (زنجیر، جعبه، …) as independent options —
 * not WooCommerce variation attributes.
 *
 * Each group on a product lists optional SKUs. The shopper picks at most one
 * per group; chosen accessories are added as separate cart lines so GoldMate
 * pricing, stock and order snapshots stay per-SKU.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Accessories {

	const META_GROUPS = '_goldmate_accessory_groups';

	/**
	 * Cart-item flag: this line was added as an accessory of another line.
	 */
	const CART_IS_ACCESSORY = 'goldmate_is_accessory';

	/**
	 * Cart-item key of the parent jewellery line.
	 */
	const CART_OF = 'goldmate_accessory_of';

	/**
	 * Selected accessory product IDs keyed by group index (parent line only).
	 */
	const CART_SELECTIONS = 'goldmate_accessories';

	/**
	 * Guards against recursive add_to_cart while attaching accessories.
	 *
	 * @var bool
	 */
	protected static $adding = false;

	/**
	 * Registers admin, product page, and cart hooks.
	 */
	public static function init() {

		add_action( 'woocommerce_product_options_related', array( __CLASS__, 'render_admin_panel' ), 5 );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_admin_panel' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );

		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render_picker' ), 15 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_front' ) );

		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'capture_selections' ), 10, 2 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'attach_accessories' ), 20, 6 );

		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'on_parent_removed' ), 10, 2 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( __CLASS__, 'on_parent_qty' ), 10, 4 );

		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'cart_item_name' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_data' ), 5, 2 );

		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_line_meta' ), 5, 4 );
	}

	/* ---------------------------------------------------------------------
	 *  Data
	 * ------------------------------------------------------------------ */

	/**
	 * Normalises stored groups into a clean list.
	 *
	 * @param mixed $raw Raw meta.
	 * @return array<int,array{label:string,none_label:string,product_ids:int[]}>
	 */
	public static function sanitize_groups( $raw ) {

		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$groups = array();

		foreach ( $raw as $group ) {

			if ( ! is_array( $group ) ) {
				continue;
			}

			$ids = array();

			if ( ! empty( $group['product_ids'] ) && is_array( $group['product_ids'] ) ) {
				foreach ( $group['product_ids'] as $id ) {
					$id = absint( $id );
					if ( $id > 0 ) {
						$ids[] = $id;
					}
				}
			}

			$ids = array_values( array_unique( $ids ) );

			if ( empty( $ids ) ) {
				continue;
			}

			$label = isset( $group['label'] ) ? sanitize_text_field( $group['label'] ) : '';
			$none  = isset( $group['none_label'] ) ? sanitize_text_field( $group['none_label'] ) : '';

			$groups[] = array(
				'label'       => '' !== $label ? $label : 'متعلقات',
				'none_label'  => '' !== $none ? $none : 'بدون متعلقات',
				'product_ids' => $ids,
			);
		}

		return $groups;
	}

	/**
	 * Groups configured on a product (parent ID for variables).
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function get_groups( $product_id ) {

		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return array();
		}

		return self::sanitize_groups( get_post_meta( $product_id, self::META_GROUPS, true ) );
	}

	/**
	 * Whether this product has at least one accessory group.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function has_groups( $product_id ) {
		return ! empty( self::get_groups( $product_id ) );
	}

	/**
	 * Expands a linked ID into purchasable option rows.
	 *
	 * Variable parents expand to every purchasable variation; simples and
	 * variations become a single row.
	 *
	 * @param int $linked_id Product or variation ID.
	 * @return array<int,array{id:int,parent_id:int,name:string,weight:float,price:float,price_html:string,purchasable:bool}>
	 */
	public static function expand_option( $linked_id ) {

		$product = wc_get_product( $linked_id );

		if ( ! $product ) {
			return array();
		}

		if ( $product->is_type( 'variable' ) ) {

			$rows = array();

			foreach ( $product->get_children() as $child_id ) {
				$rows = array_merge( $rows, self::expand_option( $child_id ) );
			}

			return $rows;
		}

		$name = $product->get_name();

		if ( $product->is_type( 'variation' ) ) {
			$attrs = wc_get_formatted_variation( $product, true, false, true );
			if ( $attrs ) {
				$name .= ' — ' . $attrs;
			}
		}

		$weight = (float) get_post_meta( $product->get_id(), '_goldmate_weight', true );

		if ( $weight <= 0 && $product->is_type( 'variation' ) ) {
			$weight = (float) get_post_meta( $product->get_parent_id(), '_goldmate_weight', true );
		}

		$price = (float) $product->get_price();

		return array(
			array(
				'id'          => $product->get_id(),
				'parent_id'   => $product->get_parent_id() ? $product->get_parent_id() : $product->get_id(),
				'name'        => $name,
				'weight'      => $weight,
				'price'       => $price,
				'price_html'  => $product->get_price_html(),
				'purchasable' => $product->is_purchasable() && $product->is_in_stock(),
			),
		);
	}

	/**
	 * All selectable options for one group, keyed by option product ID.
	 *
	 * @param array $group Sanitised group.
	 * @return array<int,array>
	 */
	public static function group_options( $group ) {

		$options = array();

		foreach ( $group['product_ids'] as $linked_id ) {
			foreach ( self::expand_option( $linked_id ) as $row ) {
				$options[ $row['id'] ] = $row;
			}
		}

		return $options;
	}

	/**
	 * Flat map of every allowed accessory option ID for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array<int,true>
	 */
	public static function allowed_ids( $product_id ) {

		$allowed = array();

		foreach ( self::get_groups( $product_id ) as $group ) {
			foreach ( self::group_options( $group ) as $id => $_row ) {
				$allowed[ (int) $id ] = true;
			}
		}

		return $allowed;
	}

	/* ---------------------------------------------------------------------
	 *  Admin
	 * ------------------------------------------------------------------ */

	/**
	 * Product-edit panel under Linked Products.
	 */
	public static function render_admin_panel() {

		global $post;

		if ( ! $post ) {
			return;
		}

		$groups = self::get_groups( $post->ID );

		echo '<div class="options_group show_if_simple show_if_variable goldmate-accessory-admin">';
		echo '<p class="form-field" style="margin-bottom:4px;"><strong>متعلقات قابل انتخاب (زنجیر، جعبه، …)</strong></p>';
		echo '<p class="form-field" style="margin-top:0;color:#646970;">هر گروه یک انتخاب اختیاری است (مثلاً زنجیر). محصولات لینک‌شده به‌عنوان خط جدا در سبد اضافه می‌شوند و با گلدمیت قیمت‌گذاری می‌مانند — نه به‌عنوان ویژگی متغیر.</p>';

		$next = empty( $groups ) ? 1 : count( $groups );

		echo '<div id="goldmate-accessory-groups" data-next-index="' . esc_attr( (string) $next ) . '">';

		if ( empty( $groups ) ) {
			self::render_admin_group( 0, array(
				'label'       => 'زنجیر طلا',
				'none_label'  => 'بدون زنجیر',
				'product_ids' => array(),
			) );
		} else {
			foreach ( $groups as $i => $group ) {
				self::render_admin_group( $i, $group );
			}
		}

		echo '</div>';

		echo '<p class="form-field"><button type="button" class="button" id="goldmate-add-accessory-group">افزودن گروه متعلقات</button></p>';

		// Prototype cloned by accessories-admin.js (Select2 is re-inited after clone).
		// disabled fields are not submitted with the product form.
		echo '<div id="goldmate-accessory-group-prototype" hidden>';
		self::render_admin_group( '__INDEX__', array(
			'label'       => '',
			'none_label'  => '',
			'product_ids' => array(),
		), true );
		echo '</div>';

		echo '</div>';
	}

	/**
	 * One admin group row.
	 *
	 * @param int|string $index    Group index (or `__INDEX__` placeholder for the prototype).
	 * @param array      $group    Group data.
	 * @param bool       $disabled Disable inputs (prototype row).
	 */
	protected static function render_admin_group( $index, $group, $disabled = false ) {

		$label      = isset( $group['label'] ) ? $group['label'] : '';
		$none_label = isset( $group['none_label'] ) ? $group['none_label'] : '';
		$ids        = isset( $group['product_ids'] ) ? (array) $group['product_ids'] : array();
		$name_base  = 'goldmate_accessory_groups[' . $index . ']';
		$disabled_attr = $disabled ? ' disabled="disabled"' : '';

		echo '<div class="goldmate-accessory-group" style="border:1px solid #c3c4c7;padding:12px;margin:0 12px 12px;background:#fff;">';

		printf(
			'<p class="form-field"><label>%s</label><input type="text" class="short" name="%s[label]" value="%s" placeholder="زنجیر طلا"%s /></p>',
			esc_html( 'عنوان گروه' ),
			esc_attr( $name_base ),
			esc_attr( $label ),
			$disabled_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		printf(
			'<p class="form-field"><label>%s</label><input type="text" class="short" name="%s[none_label]" value="%s" placeholder="بدون زنجیر"%s /></p>',
			esc_html( 'برچسب گزینهٔ خالی' ),
			esc_attr( $name_base ),
			esc_attr( $none_label ),
			$disabled_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		printf(
			'<p class="form-field"><label>%s</label><select class="wc-product-search" multiple="multiple" style="width:50%%;" name="%s[product_ids][]" data-placeholder="%s" data-action="woocommerce_json_search_products_and_variations" data-exclude="%s"%s>',
			esc_html( 'محصولات / متغیرها' ),
			esc_attr( $name_base ),
			esc_attr( 'جستجوی محصول یا متغیر…' ),
			esc_attr( isset( $GLOBALS['post']->ID ) ? (string) (int) $GLOBALS['post']->ID : '' ),
			$disabled_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			printf(
				'<option value="%d" selected="selected">%s</option>',
				(int) $id,
				esc_html( rawurldecode( wp_strip_all_tags( $product->get_formatted_name() ) ) )
			);
		}

		echo '</select></p>';

		echo '<p class="form-field" style="margin-bottom:0;"><button type="button" class="button-link-delete goldmate-remove-accessory-group">حذف این گروه</button></p>';

		echo '</div>';
	}

	/**
	 * Persists accessory groups from the product form.
	 *
	 * @param int $post_id Product ID.
	 */
	public static function save_admin_panel( $post_id ) {

		if ( ! isset( $_POST['goldmate_accessory_groups'] ) || ! is_array( $_POST['goldmate_accessory_groups'] ) ) {
			delete_post_meta( $post_id, self::META_GROUPS );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC verifies before this hook.
		$raw    = wp_unslash( $_POST['goldmate_accessory_groups'] );
		$groups = self::sanitize_groups( $raw );

		if ( empty( $groups ) ) {
			delete_post_meta( $post_id, self::META_GROUPS );
			return;
		}

		update_post_meta( $post_id, self::META_GROUPS, $groups );
	}

	/**
	 * Admin script for add/remove group rows and Select2 init.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue_admin( $hook ) {

		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'goldmate-accessories-admin',
			GOLDMATE_URL . 'assets/js/accessories-admin.js',
			array( 'jquery', 'wc-enhanced-select' ),
			GOLDMATE_VERSION,
			true
		);
	}

	/* ---------------------------------------------------------------------
	 *  Product page
	 * ------------------------------------------------------------------ */

	/**
	 * Front assets on single product when groups exist.
	 */
	public static function enqueue_front() {

		if ( ! is_product() ) {
			return;
		}

		$product_id = get_queried_object_id();

		if ( ! self::has_groups( $product_id ) ) {
			return;
		}

		wp_enqueue_style(
			'goldmate-accessories',
			GOLDMATE_URL . 'assets/css/accessories.css',
			array(),
			GOLDMATE_VERSION
		);

		wp_enqueue_script(
			'goldmate-accessories',
			GOLDMATE_URL . 'assets/js/accessories.js',
			array( 'jquery' ),
			GOLDMATE_VERSION,
			true
		);

		wp_localize_script(
			'goldmate-accessories',
			'goldmateAccessories',
			array(
				'currencyFormat' => array(
					'decimal'     => wc_get_price_decimals(),
					'decimalSep'  => wc_get_price_decimal_separator(),
					'thousand'    => wc_get_price_thousand_separator(),
					// Symbols often contain &nbsp; / &#x...; — decode so JS .text() shows تومان, not entities.
					'priceFormat' => html_entity_decode( get_woocommerce_price_format(), ENT_QUOTES, 'UTF-8' ),
					'currency'    => html_entity_decode( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ), ENT_QUOTES, 'UTF-8' ),
				),
				'i18n'           => array(
					'total' => 'جمع با متعلقات:',
				),
			)
		);
	}

	/**
	 * Renders the group pickers above the add-to-cart button.
	 */
	public static function render_picker() {

		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product_id = $product->get_id();
		$groups     = self::get_groups( $product_id );

		if ( empty( $groups ) ) {
			return;
		}

		echo '<div class="goldmate-accessories" data-product-id="' . esc_attr( (string) $product_id ) . '" data-base-price="' . esc_attr( wc_format_decimal( (float) $product->get_price(), wc_get_price_decimals() ) ) . '">';

		foreach ( $groups as $index => $group ) {

			$options = self::group_options( $group );

			if ( empty( $options ) ) {
				continue;
			}

			echo '<div class="goldmate-accessory-group" data-group="' . esc_attr( (string) $index ) . '">';
			echo '<label class="goldmate-accessory-label">' . esc_html( $group['label'] ) . '</label>';

			printf(
				'<select name="goldmate_accessory[%d]" class="goldmate-accessory-select" data-group="%d">',
				(int) $index,
				(int) $index
			);

			printf(
				'<option value="0" data-price="0">%s</option>',
				esc_html( $group['none_label'] )
			);

			foreach ( $options as $opt ) {

				if ( empty( $opt['purchasable'] ) ) {
					continue;
				}

				$weight_bit = $opt['weight'] > 0
					? sprintf( ' — %s گرم', wc_format_localized_decimal( $opt['weight'] ) )
					: '';

				$price_bit = goldmate_plain_price( $opt['price'] );

				printf(
					'<option value="%d" data-price="%s" data-weight="%s">%s%s — %s</option>',
					(int) $opt['id'],
					esc_attr( wc_format_decimal( $opt['price'], wc_get_price_decimals() ) ),
					esc_attr( wc_format_decimal( $opt['weight'], 3 ) ),
					esc_html( $opt['name'] ),
					esc_html( $weight_bit ),
					esc_html( $price_bit )
				);
			}

			echo '</select>';
			echo '</div>';
		}

		echo '<div class="goldmate-accessory-total" hidden><span class="label"></span> <span class="amount"></span></div>';
		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 *  Cart
	 * ------------------------------------------------------------------ */

	/**
	 * Validates POSTed accessory IDs against the product's allow-list.
	 *
	 * @param bool $passed     Validation so far.
	 * @param int  $product_id Product ID.
	 * @param int  $quantity   Quantity.
	 * @return bool
	 */
	public static function validate_add( $passed, $product_id, $quantity ) {

		unset( $quantity );

		if ( ! $passed || empty( $_REQUEST['goldmate_accessory'] ) || ! is_array( $_REQUEST['goldmate_accessory'] ) ) {
			return $passed;
		}

		$groups  = self::get_groups( $product_id );
		$allowed = self::allowed_ids( $product_id );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- add-to-cart request.
		$posted = wp_unslash( $_REQUEST['goldmate_accessory'] );

		foreach ( $posted as $group_index => $option_id ) {

			$group_index = (int) $group_index;
			$option_id   = absint( $option_id );

			if ( ! isset( $groups[ $group_index ] ) ) {
				wc_add_notice( 'گروه متعلقات نامعتبر است.', 'error' );
				return false;
			}

			if ( 0 === $option_id ) {
				continue;
			}

			if ( empty( $allowed[ $option_id ] ) ) {
				wc_add_notice( 'متعلقات انتخاب‌شده برای این محصول مجاز نیست.', 'error' );
				return false;
			}

			$acc = wc_get_product( $option_id );

			if ( ! $acc || ! $acc->is_purchasable() || ! $acc->is_in_stock() ) {
				wc_add_notice( 'یکی از متعلقات انتخاب‌شده موجود نیست.', 'error' );
				return false;
			}
		}

		return $passed;
	}

	/**
	 * Stores validated selections on the parent cart line.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product ID.
	 * @return array
	 */
	public static function capture_selections( $cart_item_data, $product_id ) {

		if ( ! empty( $cart_item_data[ self::CART_IS_ACCESSORY ] ) ) {
			return $cart_item_data;
		}

		if ( empty( $_REQUEST['goldmate_accessory'] ) || ! is_array( $_REQUEST['goldmate_accessory'] ) ) {
			return $cart_item_data;
		}

		$groups  = self::get_groups( $product_id );
		$allowed = self::allowed_ids( $product_id );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$posted = wp_unslash( $_REQUEST['goldmate_accessory'] );
		$picked = array();

		foreach ( $posted as $group_index => $option_id ) {
			$group_index = (int) $group_index;
			$option_id   = absint( $option_id );

			if ( ! isset( $groups[ $group_index ] ) || 0 === $option_id ) {
				continue;
			}

			if ( empty( $allowed[ $option_id ] ) ) {
				continue;
			}

			$picked[ $group_index ] = $option_id;
		}

		if ( ! empty( $picked ) ) {
			$cart_item_data[ self::CART_SELECTIONS ] = $picked;
			// Unique key so two identical jewellery+chain combos don't merge wrongly
			// before accessories are attached.
			$cart_item_data['unique_key'] = md5( microtime() . wp_json_encode( $picked ) );
		}

		return $cart_item_data;
	}

	/**
	 * Adds each selected accessory as its own cart line, linked to the parent.
	 *
	 * @param string $cart_item_key  Parent cart key.
	 * @param int    $product_id     Product ID.
	 * @param int    $quantity       Quantity.
	 * @param int    $variation_id   Variation ID.
	 * @param array  $variation      Variation attributes.
	 * @param array  $cart_item_data Cart item data.
	 */
	public static function attach_accessories( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {

		unset( $product_id, $variation_id, $variation );

		if ( self::$adding ) {
			return;
		}

		if ( empty( $cart_item_data[ self::CART_SELECTIONS ] ) || ! is_array( $cart_item_data[ self::CART_SELECTIONS ] ) ) {
			return;
		}

		if ( ! empty( $cart_item_data[ self::CART_IS_ACCESSORY ] ) ) {
			return;
		}

		if ( ! WC()->cart ) {
			return;
		}

		self::$adding = true;

		foreach ( $cart_item_data[ self::CART_SELECTIONS ] as $group_index => $option_id ) {

			$option_id = absint( $option_id );

			if ( ! $option_id ) {
				continue;
			}

			$acc = wc_get_product( $option_id );

			if ( ! $acc ) {
				continue;
			}

			$vid = 0;
			$pid = $option_id;
			$attrs = array();

			if ( $acc->is_type( 'variation' ) ) {
				$vid   = $option_id;
				$pid   = $acc->get_parent_id();
				$attrs = $acc->get_variation_attributes();
			}

			WC()->cart->add_to_cart(
				$pid,
				$quantity,
				$vid,
				$attrs,
				array(
					self::CART_IS_ACCESSORY => true,
					self::CART_OF           => $cart_item_key,
					'goldmate_accessory_group' => (int) $group_index,
					'unique_key'            => md5( $cart_item_key . ':' . $group_index . ':' . $option_id ),
				)
			);
		}

		self::$adding = false;
	}

	/**
	 * Removes linked accessories when the parent jewellery line is removed.
	 *
	 * @param string   $cart_item_key Removed key.
	 * @param WC_Cart  $cart          Cart.
	 */
	public static function on_parent_removed( $cart_item_key, $cart ) {

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! empty( $item[ self::CART_OF ] ) && $item[ self::CART_OF ] === $cart_item_key ) {
				$cart->remove_cart_item( $key );
			}
		}
	}

	/**
	 * Keeps accessory quantities in sync with the parent line.
	 *
	 * @param string  $cart_item_key Cart key.
	 * @param int     $quantity      New quantity.
	 * @param int     $old_quantity  Previous quantity.
	 * @param WC_Cart $cart          Cart.
	 */
	public static function on_parent_qty( $cart_item_key, $quantity, $old_quantity, $cart ) {

		unset( $old_quantity );

		$item = isset( $cart->cart_contents[ $cart_item_key ] ) ? $cart->cart_contents[ $cart_item_key ] : null;

		if ( ! $item || ! empty( $item[ self::CART_IS_ACCESSORY ] ) ) {
			return;
		}

		if ( empty( $item[ self::CART_SELECTIONS ] ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $key => $child ) {
			if ( ! empty( $child[ self::CART_OF ] ) && $child[ self::CART_OF ] === $cart_item_key ) {
				$cart->set_quantity( $key, $quantity, false );
			}
		}
	}

	/**
	 * Marks accessory lines in the cart name.
	 *
	 * @param string $name         Product name HTML.
	 * @param array  $cart_item    Cart item.
	 * @param string $cart_item_key Key.
	 * @return string
	 */
	public static function cart_item_name( $name, $cart_item, $cart_item_key ) {

		unset( $cart_item_key );

		if ( ! empty( $cart_item[ self::CART_IS_ACCESSORY ] ) ) {
			$name = '<span class="goldmate-cart-accessory-tag">متعلقات:</span> ' . $name;
		}

		return $name;
	}

	/**
	 * Shows which accessory was chosen under the parent cart line.
	 *
	 * @param array $item_data Existing rows.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function cart_item_data( $item_data, $cart_item ) {

		if ( empty( $cart_item[ self::CART_SELECTIONS ] ) || ! is_array( $cart_item[ self::CART_SELECTIONS ] ) ) {
			return $item_data;
		}

		$product_id = ! empty( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
		$groups     = self::get_groups( $product_id );

		foreach ( $cart_item[ self::CART_SELECTIONS ] as $group_index => $option_id ) {

			$option_id = absint( $option_id );
			$label     = isset( $groups[ $group_index ]['label'] ) ? $groups[ $group_index ]['label'] : 'متعلقات';
			$product   = wc_get_product( $option_id );

			if ( ! $product ) {
				continue;
			}

			$item_data[] = array(
				'key'   => $label,
				'value' => $product->get_name(),
			);
		}

		return $item_data;
	}

	/**
	 * Records the parent/accessory link on the order line for support.
	 *
	 * @param WC_Order_Item_Product $item          Line item.
	 * @param string                $cart_item_key Cart key.
	 * @param array                 $values        Cart item.
	 * @param WC_Order              $order         Order.
	 */
	public static function order_line_meta( $item, $cart_item_key, $values, $order ) {

		unset( $cart_item_key, $order );

		if ( ! empty( $values[ self::CART_IS_ACCESSORY ] ) ) {
			$item->add_meta_data( 'نوع', 'متعلقات محصول', true );
		}

		if ( ! empty( $values[ self::CART_SELECTIONS ] ) && is_array( $values[ self::CART_SELECTIONS ] ) ) {
			$names = array();
			foreach ( $values[ self::CART_SELECTIONS ] as $option_id ) {
				$p = wc_get_product( absint( $option_id ) );
				if ( $p ) {
					$names[] = $p->get_name();
				}
			}
			if ( $names ) {
				$item->add_meta_data( 'متعلقات انتخاب‌شده', implode( '، ', $names ), true );
			}
		}
	}
}
