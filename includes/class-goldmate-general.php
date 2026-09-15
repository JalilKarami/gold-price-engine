<?php
/**
 * Runtime behaviour for the General (عمومی) settings tab:
 * cart/checkout recalculation, out-of-service display, order rules, visibility.
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

		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_add_to_cart' ), 20, 6 );
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'on_cart_page' ), 5 );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'on_checkout_page' ), 5 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'maybe_recalc_order_items' ), 5 );

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
	 * Reprices every gold line currently in the cart.
	 */
	public static function reprice_cart() {

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			$id = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : (int) $item['product_id'];
			if ( $id > 0 ) {
				Goldmate_Pricing::apply( $id );
			}
		}

		WC()->cart->calculate_totals();
	}

	/**
	 * After add-to-cart: optional product and/or full-cart reprice.
	 *
	 * @param string $cart_item_key Cart key.
	 * @param int    $product_id    Product ID.
	 * @param int    $quantity      Qty.
	 * @param int    $variation_id  Variation ID.
	 */
	public static function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0 ) {

		$id = $variation_id ? (int) $variation_id : (int) $product_id;

		if ( 'yes' === goldmate_option( 'goldmate_reprice_on_add_to_cart' ) && $id > 0 ) {
			Goldmate_Pricing::apply( $id );
		}

		if ( 'yes' === goldmate_option( 'goldmate_reprice_cart_on_add' ) ) {
			self::reprice_cart();
		}
	}

	/**
	 * Cart page open.
	 */
	public static function on_cart_page() {

		if ( 'yes' === goldmate_option( 'goldmate_reprice_cart_on_cart' ) ) {
			self::reprice_cart();
		}
	}

	/**
	 * Checkout page open.
	 */
	public static function on_checkout_page() {

		if ( 'yes' === goldmate_option( 'goldmate_reprice_cart_on_checkout' ) ) {
			self::reprice_cart();
		}
	}

	/**
	 * Recalc cart gold lines when their priced_at is older than the order-item window.
	 */
	public static function maybe_recalc_order_items() {

		if ( 'yes' !== goldmate_option( 'goldmate_order_recalc' ) ) {
			return;
		}

		$window = (float) goldmate_option( 'goldmate_order_item_validity' );

		if ( $window <= 0 || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$limit = $window * HOUR_IN_SECONDS;
		$now   = time();

		foreach ( WC()->cart->get_cart() as $item ) {
			$id = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : (int) $item['product_id'];
			if ( $id <= 0 ) {
				continue;
			}
			$priced_at = (int) get_post_meta( $id, '_goldmate_priced_at', true );
			if ( $priced_at <= 0 || ( $now - $priced_at ) >= $limit ) {
				Goldmate_Pricing::apply( $id );
			}
		}
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
