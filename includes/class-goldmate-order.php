<?php
/**
 * Carries the price breakdown from the cart into the order, so invoices can
 * itemise what the customer actually paid for.
 *
 * The breakdown is snapshotted onto the order line at purchase time rather than
 * recalculated on demand. The gold rate moves during the day; an invoice
 * reprinted next week must still show the figures the customer agreed to.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Order {

	/**
	 * Hidden order-item meta holding the full breakdown as JSON.
	 *
	 * The leading underscore keeps WooCommerce from printing it as a plain row.
	 */
	const SNAPSHOT = '_goldmate_breakdown';

	/**
	 * Registers cart display and order snapshotting.
	 */
	public static function init() {

		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_data' ), 10, 2 );

		// Size, color and weight go on rows of their own ("سایز: 56"), not after the name.
		add_filter( 'woocommerce_product_variation_title_include_attributes', array( __CLASS__, 'variation_title_attributes' ), 10, 2 );

		// Classic checkout and the block checkout both run through this hook,
		// because the Store API delegates line items to WC_Checkout.
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'on_checkout_line_item' ), 10, 4 );

		// Orders keyed in by hand in wp-admin, or created over the REST API.
		add_action( 'woocommerce_new_order_item', array( __CLASS__, 'on_new_order_item' ), 10, 3 );

		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( __CLASS__, 'maybe_hide_email_meta' ), 10, 2 );
	}

	/**
	 * How much detail the shop wants on the invoice.
	 *
	 * @return string `full`, `summary` or `none`.
	 */
	protected static function detail_level() {

		$level = goldmate_option( 'goldmate_order_details' );

		return in_array( $level, array( 'full', 'summary', 'none' ), true ) ? $level : 'full';
	}

	/**
	 * Formats an amount as plain text.
	 *
	 * Order item meta travels into e-mails and PDF invoices that do not always
	 * render HTML, so the markup wc_price() produces is flattened.
	 *
	 * @param float       $amount   Amount.
	 * @param string|null $currency Order currency, or null for the shop default.
	 * @return string
	 */
	public static function plain_price( $amount, $currency = null ) {

		return goldmate_plain_price( $amount, $currency );
	}

	/**
	 * Builds the label/value pairs recorded against an order line.
	 *
	 * @param array       $b        Breakdown from the calculator.
	 * @param string      $level    Detail level.
	 * @param string|null $currency Order currency.
	 * @return array<string,string> Label => formatted value.
	 */
	public static function invoice_rows( $b, $level = 'full', $currency = null ) {

		if ( 'none' === $level ) {
			return array();
		}

		$rows = array(
			'وزن'                      => wc_format_localized_decimal( $b['weight'] ) . ' گرم',
			'عیار'                     => wc_format_localized_decimal( $b['karat'] ),
			'قیمت هر گرم در زمان خرید' => self::plain_price( $b['rate'], $currency ),
		);

		if ( 'full' === $level ) {

			$rows['مبلغ طلا'] = self::plain_price( $b['gold'], $currency );

			$rows[ Goldmate_Calculator::wage_label( $b ) ] = self::plain_price( $b['wage'], $currency );

			$rows[ sprintf( 'سود (%s٪)', wc_format_localized_decimal( $b['profit_pct'] ) ) ] = self::plain_price( $b['profit'], $currency );

			if ( $b['accessories'] > 0 ) {
				$rows['متعلقات'] = self::plain_price( $b['accessories'], $currency );
			}
		}

		$rows[ sprintf( 'مالیات بر ارزش افزوده (%s٪)', wc_format_localized_decimal( $b['tax_pct'] ) ) ] = self::plain_price( $b['tax'], $currency );

		$rounding = Goldmate_Calculator::rounding_difference( $b );

		if ( 'full' === $level && 0.0 !== $rounding ) {
			$rows['گرد کردن'] = self::plain_price( $rounding, $currency );
		}

		$rows['قیمت واحد'] = self::plain_price( $b['total'], $currency );

		/**
		 * Filters the rows written onto an order line.
		 *
		 * @param array  $rows  Label => formatted value.
		 * @param array  $b     Breakdown.
		 * @param string $level Detail level.
		 */
		return apply_filters( 'goldmate_invoice_rows', $rows, $b, $level );
	}

	/* ---------------------------------------------------------------------
	 *  Cart and checkout
	 * ------------------------------------------------------------------ */

	/**
	 * Whether a product (or a variation's parent) is priced by GoldMate.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	protected static function is_gold( $product ) {

		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();

		return 'yes' === get_post_meta( $id, '_goldmate_enabled', true );
	}

	/**
	 * Keeps attributes out of gold variation names.
	 *
	 * WooCommerce otherwise names a variation "Ring - 56, Rose gold" and then
	 * hides those attributes from the rows under the line. Without them in the
	 * name, the cart, checkout, e-mails and invoices list each attribute on its
	 * own row. WooCommerce rewrites a stored name that no longer matches the
	 * next time the variation is read, so existing variations follow too.
	 *
	 * @param bool                 $include   Whether to include attributes.
	 * @param WC_Product_Variation $variation Variation.
	 * @return bool
	 */
	public static function variation_title_attributes( $include, $variation ) {
		return self::is_gold( $variation ) ? false : $include;
	}

	/**
	 * The "وزن" row for a gold line whose weight is not already shown.
	 *
	 * A weight attribute already has its own row, so only lines whose weight
	 * lives in GoldMate's weight field (e.g. size + color rings) get one.
	 *
	 * @param array $cart_item Cart item.
	 * @return array|null
	 */
	protected static function weight_row( $cart_item ) {

		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;

		if ( ! self::is_gold( $product ) ) {
			return null;
		}

		if ( $product instanceof WC_Product_Variation && null !== Goldmate_Variation_Table::attribute_weight( $product ) ) {
			return null;
		}

		$weight = goldmate_meta_float( $product->get_id(), '_goldmate_weight' );

		if ( $weight <= 0 && $product->get_parent_id() ) {
			$weight = goldmate_meta_float( $product->get_parent_id(), '_goldmate_weight' );
		}

		if ( $weight <= 0 ) {
			return null;
		}

		// Written like the weight attribute values ("3.049 گرم") so every
		// weight in the cart reads the same.
		return array(
			'key'   => 'وزن',
			'value' => rtrim( rtrim( number_format( $weight, 3, '.', '' ), '0' ), '.' ) . ' گرم',
		);
	}

	/**
	 * Shows the breakdown under each gold line in the cart and at checkout.
	 *
	 * With the breakdown turned off, the line still shows its gold weight.
	 *
	 * @param array $item_data Existing rows.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function cart_item_data( $item_data, $cart_item ) {

		if ( 'yes' !== goldmate_option( 'goldmate_cart_details' ) ) {

			$row = self::weight_row( $cart_item );

			if ( $row ) {
				$item_data[] = $row;
			}

			return $item_data;
		}

		if ( ! empty( $cart_item['variation_id'] ) ) {
			$product_id = $cart_item['variation_id'];
		} elseif ( ! empty( $cart_item['product_id'] ) ) {
			$product_id = $cart_item['product_id'];
		} else {
			return $item_data;
		}

		$b = Goldmate_Calculator::calculate( $product_id );

		if ( false === $b ) {
			return $item_data;
		}

		foreach ( self::invoice_rows( $b, self::detail_level() ) as $label => $value ) {
			$item_data[] = array(
				'key'   => $label,
				'value' => $value,
			);
		}

		return $item_data;
	}

	/* ---------------------------------------------------------------------
	 *  Order lines
	 * ------------------------------------------------------------------ */

	/**
	 * Snapshots the breakdown onto a checkout line item before the order saves.
	 *
	 * @param WC_Order_Item_Product $item          Line item.
	 * @param string                $cart_item_key Cart key.
	 * @param array                 $values        Cart item.
	 * @param WC_Order              $order         Order.
	 */
	public static function on_checkout_line_item( $item, $cart_item_key, $values, $order ) {

		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		self::attach( $item, $order ? $order->get_currency() : null );
	}

	/**
	 * Catches line items created outside the checkout, such as manual orders.
	 *
	 * @param int           $item_id  Item ID.
	 * @param WC_Order_Item $item     Item.
	 * @param int           $order_id Order ID.
	 */
	public static function on_new_order_item( $item_id, $item, $order_id ) {

		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		// Checkout already attached the snapshot before the item was saved.
		if ( $item->meta_exists( self::SNAPSHOT ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		// Refund lines mirror an existing order line; they carry no breakdown.
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return;
		}

		if ( self::attach( $item, $order->get_currency() ) ) {
			$item->save_meta_data();
		}
	}

	/**
	 * Writes the hidden snapshot and the visible invoice rows onto one line.
	 *
	 * @param WC_Order_Item_Product $item     Line item.
	 * @param string|null           $currency Order currency.
	 * @return bool Whether anything was written.
	 */
	protected static function attach( $item, $currency = null ) {

		$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();

		if ( ! $product_id ) {
			return false;
		}

		$b = Goldmate_Calculator::calculate( $product_id );

		if ( false === $b ) {
			return false;
		}

		// Recorded whatever the shop chooses to show the customer, so the
		// figures behind an old invoice can always be audited.
		$item->add_meta_data( self::SNAPSHOT, wp_json_encode( $b, JSON_UNESCAPED_UNICODE ), true );

		$rows = self::invoice_rows( $b, self::detail_level(), $currency );

		// A weight attribute already puts "وزن" on the line; don't repeat it.
		if ( $item->get_variation_id() && null !== Goldmate_Variation_Table::attribute_weight( $item->get_variation_id() ) ) {
			unset( $rows['وزن'] );
		}

		foreach ( $rows as $label => $value ) {
			$item->add_meta_data( $label, $value, true );
		}

		return true;
	}

	/**
	 * Reads back the snapshot stored on an order line.
	 *
	 * @param WC_Order_Item_Product $item Line item.
	 * @return array|false
	 */
	public static function get_snapshot( $item ) {

		if ( ! $item instanceof WC_Order_Item ) {
			return false;
		}

		$raw = $item->get_meta( self::SNAPSHOT, true );

		if ( ! $raw ) {
			return false;
		}

		$data = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );

		return is_array( $data ) ? $data : false;
	}

	/**
	 * Hides GoldMate breakdown rows inside customer emails when disabled.
	 *
	 * @param array         $formatted_meta Formatted meta.
	 * @param WC_Order_Item $item           Item.
	 * @return array
	 */
	public static function maybe_hide_email_meta( $formatted_meta, $item ) {

		if ( 'yes' === goldmate_option( 'goldmate_details_email' ) ) {
			return $formatted_meta;
		}

		// Only strip during email rendering, not on the thank-you / account pages.
		if ( ! did_action( 'woocommerce_email_header' ) && ! doing_action( 'woocommerce_email_order_details' ) ) {
			return $formatted_meta;
		}

		if ( ! self::get_snapshot( $item ) ) {
			return $formatted_meta;
		}

		$keep = array();
		foreach ( $formatted_meta as $key => $meta ) {
			if ( isset( $meta->key ) && 0 === strpos( (string) $meta->key, '_' ) ) {
				$keep[ $key ] = $meta;
				continue;
			}
			// Drop visible GoldMate invoice labels (Persian keys without leading underscore).
			if ( isset( $meta->key ) && in_array( $meta->key, array( 'وزن', 'عیار', 'متعلقات', 'گرد کردن', 'قیمت واحد' ), true ) ) {
				continue;
			}
			if ( isset( $meta->key ) && (
				false !== strpos( (string) $meta->key, 'قیمت هر گرم' )
				|| false !== strpos( (string) $meta->key, 'مبلغ طلا' )
				|| false !== strpos( (string) $meta->key, 'اجرت' )
				|| false !== strpos( (string) $meta->key, 'سود' )
				|| false !== strpos( (string) $meta->key, 'مالیات' )
			) ) {
				continue;
			}
			$keep[ $key ] = $meta;
		}

		return $keep;
	}
}
