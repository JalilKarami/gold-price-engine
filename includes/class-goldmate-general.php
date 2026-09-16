<?php
/**
 * Runtime behaviour for the General (عمومی) settings tab:
 * live cart pricing, out-of-service display, order rules, visibility.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_General {

	const VALIDITY_CRON = 'goldmate_check_price_validity';

	/**
	 * Boots hooks driven by general settings.
	 */
	public static function init() {

		// Cart lines always carry the live price, set in memory; products are never saved here.
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'on_cart_loaded' ), 20 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'on_before_calculate_totals' ), 20 );

		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'maybe_oos_price_html' ), 40, 2 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'maybe_oos_purchasable' ), 40, 2 );

		add_action( self::VALIDITY_CRON, array( __CLASS__, 'run_validity_check' ) );
		add_action( 'init', array( __CLASS__, 'ensure_validity_cron' ) );

		add_action( 'woocommerce_order_status_pending', array( __CLASS__, 'schedule_order_auto_cancel' ), 10, 1 );
		add_action( 'woocommerce_order_status_on-hold', array( __CLASS__, 'schedule_order_auto_cancel' ), 10, 1 );
		add_action( 'goldmate_auto_cancel_order', array( __CLASS__, 'auto_cancel_order' ), 10, 1 );
	}

	/**
	 * Whether catalogue prices are currently out of service.
	 *
	 * @return bool
	 */
	public static function products_oos() {
		return 'yes' === goldmate_option( 'goldmate_oos_products' ) && self::validity_expired();
	}

	/**
	 * Whether shortcode prices are out of service.
	 *
	 * @param bool $manual True for “manual” shortcodes (no product context).
	 * @return bool
	 */
	public static function shortcodes_oos( $manual = false ) {

		if ( $manual ) {
			if ( 'yes' !== goldmate_option( 'goldmate_oos_manual_shortcodes' ) ) {
				return false;
			}
		} elseif ( 'yes' !== goldmate_option( 'goldmate_oos_shortcodes' ) ) {
			return false;
		}

		return self::validity_expired();
	}

	/**
	 * True when the live rate has exceeded the max validity window.
	 *
	 * @return bool
	 */
	public static function validity_expired() {

		$max = (int) goldmate_option( 'goldmate_max_price_validity' );

		if ( $max <= 0 ) {
			return Goldmate_Rate_Items::is_reference_stale();
		}

		$item = class_exists( 'Goldmate_Rate_Items' ) ? Goldmate_Rate_Items::reference_item() : null;
		$updated = $item ? (int) $item['updated_at'] : (int) get_option( 'goldmate_rate_updated_at', 0 );

		if ( $updated <= 0 ) {
			return true;
		}

		return ( time() - $updated ) > ( $max * MINUTE_IN_SECONDS );
	}

	/**
	 * Replacement markup when product prices are offline.
	 *
	 * @return string
	 */
	public static function product_oos_html() {

		$text = trim( (string) goldmate_option( 'goldmate_oos_product_text' ) );
		$url  = trim( (string) goldmate_option( 'goldmate_oos_contact_url' ) );

		if ( '' === $text ) {
			$text = 'تماس بگیرید';
		}

		if ( '' !== $url ) {
			return sprintf(
				'<a class="goldmate-oos goldmate-oos--product" href="%s">%s</a>',
				esc_url( $url ),
				esc_html( $text )
			);
		}

		return '<span class="goldmate-oos goldmate-oos--product">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Replacement text for shortcodes when offline.
	 *
	 * @return string
	 */
	public static function shortcode_oos_html() {

		$text = trim( (string) goldmate_option( 'goldmate_oos_shortcode_text' ) );

		if ( '' === $text ) {
			$text = 'خارج از سرویس';
		}

		return '<span class="goldmate-oos goldmate-oos--shortcode">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Whether the current viewer may see supplementary price details.
	 *
	 * @return bool
	 */
	public static function can_see_details() {

		if ( is_admin() && ! wp_doing_ajax() ) {
			return 'yes' === goldmate_option( 'goldmate_details_admin' );
		}

		if ( ! is_user_logged_in() ) {
			return 'yes' === goldmate_option( 'goldmate_details_customer' );
		}

		$user = wp_get_current_user();
		$role = (string) goldmate_option( 'goldmate_colleague_role' );

		if ( $role && in_array( $role, (array) $user->roles, true ) ) {
			return 'yes' === goldmate_option( 'goldmate_details_colleague' );
		}

		if ( user_can( $user, 'manage_woocommerce' ) || user_can( $user, 'manage_options' ) ) {
			return 'yes' === goldmate_option( 'goldmate_details_admin' );
		}

		return 'yes' === goldmate_option( 'goldmate_details_customer' );
	}

	/**
	 * Whether price up/down chrome should stay hidden.
	 *
	 * @return bool
	 */
	public static function hide_price_change() {
		return 'yes' === goldmate_option( 'goldmate_hide_price_change' );
	}

	/**
	 * Live totals already worked out during this request, keyed by product ID.
	 *
	 * WooCommerce recalculates totals several times per request (and once per
	 * shipping package), so each line is only run through the calculator once.
	 *
	 * @var array<int,float|false>
	 */
	protected static $cart_prices = array();

	/**
	 * Sets every gold line's price from the current rate, in memory only.
	 *
	 * The cart works on its own copy of each product, loaded fresh from the
	 * database on every request, so setting the price on that copy is enough for
	 * the line price, the totals and the order WooCommerce creates from them.
	 * Nothing is saved: the stored catalogue price belongs to the background
	 * reprice, and saving products from shopper requests would make concurrent
	 * carts overwrite the same rows, flush caches and race admin edits.
	 *
	 * The order snapshot (Goldmate_Order) runs the same calculation at checkout,
	 * so the invoice breakdown and the charged line price agree.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return bool Whether any line's price differs from what the session last totalled.
	 */
	public static function price_cart_items( $cart ) {

		if ( ! $cart instanceof WC_Cart ) {
			return false;
		}

		$changed = false;

		foreach ( $cart->get_cart() as $item ) {

			if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}

			$id = (int) $item['data']->get_id();

			if ( ! array_key_exists( $id, self::$cart_prices ) ) {
				$b = Goldmate_Calculator::calculate( $id );
				self::$cart_prices[ $id ] = false === $b ? false : (float) $b['total'];
			}

			if ( false === self::$cart_prices[ $id ] ) {
				continue;
			}

			$price = self::$cart_prices[ $id ];

			$item['data']->set_price( $price );

			if ( isset( $item['line_subtotal'], $item['quantity'] )
				&& abs( ( $price * (float) $item['quantity'] ) - (float) $item['line_subtotal'] ) > 0.5 ) {
				$changed = true;
			}
		}

		return $changed;
	}

	/**
	 * Prices the cart as it comes out of the session.
	 *
	 * WooCommerce only recalculates totals here when the cart changed, so the
	 * mini-cart would otherwise show the totals stored at the last change. When
	 * the gold rate moved since, the totals are refreshed; that updates the
	 * shopper's session, never the product.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function on_cart_loaded( $cart ) {

		if ( self::price_cart_items( $cart ) && ! is_null( WC()->session->get( 'cart_totals', null ) ) ) {
			$cart->calculate_totals();
		}
	}

	/**
	 * Prices the cart right before WooCommerce totals it.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function on_before_calculate_totals( $cart ) {
		self::price_cart_items( $cart );
	}

	/**
	 * Swap product price HTML when OOS.
	 *
	 * @param string     $html    Price HTML.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function maybe_oos_price_html( $html, $product ) {

		if ( ! self::products_oos() || ! $product instanceof WC_Product ) {
			return $html;
		}

		if ( 'yes' !== get_post_meta( $product->get_id(), '_goldmate_enabled', true )
			&& ( ! $product->is_type( 'variation' ) || 'yes' !== get_post_meta( $product->get_parent_id(), '_goldmate_enabled', true ) ) ) {
			return $html;
		}

		return self::product_oos_html();
	}

	/**
	 * Block purchase when product prices are OOS.
	 *
	 * @param bool       $purchasable Purchasable.
	 * @param WC_Product $product     Product.
	 * @return bool
	 */
	public static function maybe_oos_purchasable( $purchasable, $product ) {

		if ( ! $purchasable || ! self::products_oos() || ! $product instanceof WC_Product ) {
			return $purchasable;
		}

		$id = $product->get_id();
		if ( $product->is_type( 'variation' ) ) {
			$id = $product->get_parent_id();
		}

		if ( 'yes' === get_post_meta( $id, '_goldmate_enabled', true ) ) {
			return false;
		}

		return $purchasable;
	}

	/**
	 * Keeps the optional validity cron registered.
	 */
	public static function ensure_validity_cron() {

		if ( 'yes' !== goldmate_option( 'goldmate_check_price_validity' ) ) {
			$ts = wp_next_scheduled( self::VALIDITY_CRON );
			if ( $ts ) {
				wp_unschedule_event( $ts, self::VALIDITY_CRON );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::VALIDITY_CRON ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::VALIDITY_CRON );
		}
	}

	/**
	 * Hourly: if validity expired and store mode is on, queue a catalogue reprice.
	 */
	public static function run_validity_check() {

		if ( 'yes' !== goldmate_option( 'goldmate_check_price_validity' ) ) {
			return;
		}

		update_option( 'goldmate_rate_checked_at', time(), false );

		if ( self::validity_expired() && 'store' === goldmate_option( 'goldmate_calc_mode' ) ) {
			// Do not force a rate change — just refresh stored product prices from the last known rate.
			Goldmate_Batch::start( 'بررسی اعتبار قیمت' );
		}
	}

	/**
	 * Queue auto-cancel for unpaid gold orders.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function schedule_order_auto_cancel( $order_id ) {

		if ( 'yes' !== goldmate_option( 'goldmate_order_auto_cancel' ) ) {
			return;
		}

		$minutes = (int) goldmate_option( 'goldmate_order_auto_cancel_minutes' );

		if ( $minutes <= 0 ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order || ! self::order_has_goldmate( $order ) ) {
			return;
		}

		$hook = 'goldmate_auto_cancel_order';
		$args = array( (int) $order_id );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, $args, 'goldmate' );
			as_schedule_single_action( time() + ( $minutes * MINUTE_IN_SECONDS ), $hook, $args, 'goldmate' );
			return;
		}

		wp_clear_scheduled_hook( $hook, $args );
		wp_schedule_single_event( time() + ( $minutes * MINUTE_IN_SECONDS ), $hook, $args );
	}

	/**
	 * Cancels a still-unpaid order after the validity window.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function auto_cancel_order( $order_id ) {

		if ( 'yes' !== goldmate_option( 'goldmate_order_auto_cancel' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$status = $order->get_status();

		if ( ! in_array( $status, array( 'pending', 'on-hold' ), true ) ) {
			return;
		}

		$order->update_status( 'cancelled', 'لغو خودکار گلدمیت به‌خاطر انقضای اعتبار سفارش.' );
	}

	/**
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	protected static function order_has_goldmate( $order ) {

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$pid = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$parent = $item->get_product_id();
			if ( 'yes' === get_post_meta( $pid, '_goldmate_enabled', true )
				|| 'yes' === get_post_meta( $parent, '_goldmate_enabled', true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Clears rate history (admin action).
	 */
	public static function clear_rate_history() {
		if ( class_exists( 'Goldmate_Rate_Items' ) ) {
			Goldmate_Rate_Items::clear_history( 0 );
		}
		delete_option( 'goldmate_rate_history' );
	}

	/**
	 * Prunes history older than the configured retention hours.
	 */
	public static function prune_rate_history() {

		$hours = (float) goldmate_option( 'goldmate_price_history_hours' );
		if ( $hours <= 0 || ! class_exists( 'Goldmate_Rate_Items' ) ) {
			return;
		}

		Goldmate_Rate_Items::prune_history_older_than( $hours );
	}

	/**
	 * WP roles for the colleague dropdown.
	 *
	 * @return array<string,string>
	 */
	public static function role_options() {

		$options = array( '' => '— انتخاب نقش —' );

		if ( ! function_exists( 'wp_roles' ) ) {
			return $options;
		}

		foreach ( wp_roles()->get_names() as $key => $label ) {
			$options[ $key ] = translate_user_role( $label );
		}

		return $options;
	}
}
