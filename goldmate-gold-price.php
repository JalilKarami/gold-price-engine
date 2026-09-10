<?php
/**
 * Plugin Name: گلدمیت — محاسبه قیمت طلا
 * Description: محاسبه خودکار قیمت محصولات طلا بر اساس فرمول: وزن × (قیمت روز + اجرت) + سود + متعلقات + مالیات بر اجرت و سود. شامل پشتیبانی از محصولات متغیر، دریافت خودکار قیمت روز و به‌روزرسانی دسته‌ای.
 * Version: 2.6.0
 * Author: gold-mate.ir
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * WC tested up to: 11.0
 * Requires PHP: 7.4
 * Text Domain: goldmate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GOLDMATE_VERSION', '2.6.0' );
define( 'GOLDMATE_FILE', __FILE__ );
define( 'GOLDMATE_PATH', plugin_dir_path( __FILE__ ) );
define( 'GOLDMATE_URL', plugin_dir_url( __FILE__ ) );

require_once GOLDMATE_PATH . 'includes/functions.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-calculator.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-pricing.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-batch.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-rates.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-product-fields.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-display.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-order.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-settings.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-admin.php';

/**
 * Declares compatibility with WooCommerce's High-Performance Order Storage.
 *
 * The order side of this plugin only ever touches line items, through the CRUD
 * API (`$item->add_meta_data()` / `$item->get_meta()`), and never reads orders
 * through post meta — so it behaves identically whether orders live in posts or
 * in the custom tables. Without this declaration WooCommerce lists the plugin as
 * incompatible and refuses to let the shop turn HPOS on.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Boots the plugin once WooCommerce is known to be available.
 */
function goldmate_init() {

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>افزونه‌ی «گلدمیت — محاسبه قیمت طلا» به ووکامرس نیاز دارد.</p></div>';
			}
		);
		return;
	}

	Goldmate_Pricing::init();
	Goldmate_Batch::init();
	Goldmate_Rates::init();
	Goldmate_Product_Fields::init();
	Goldmate_Display::init();
	Goldmate_Order::init();
	Goldmate_Admin::init();
}
add_action( 'plugins_loaded', 'goldmate_init' );

/**
 * Makes sure the recurring rate fetch is scheduled as soon as the plugin is switched on.
 */
function goldmate_activate() {
	require_once GOLDMATE_PATH . 'includes/functions.php';
	require_once GOLDMATE_PATH . 'includes/class-goldmate-rates.php';
	Goldmate_Rates::reschedule();
}
register_activation_hook( __FILE__, 'goldmate_activate' );

/**
 * Clears every background job the plugin owns so a deactivated plugin stays idle.
 */
function goldmate_deactivate() {
	require_once GOLDMATE_PATH . 'includes/functions.php';
	require_once GOLDMATE_PATH . 'includes/class-goldmate-rates.php';
	require_once GOLDMATE_PATH . 'includes/class-goldmate-batch.php';
	Goldmate_Rates::unschedule();
	Goldmate_Batch::cancel();
}
register_deactivation_hook( __FILE__, 'goldmate_deactivate' );

/* -------------------------------------------------------------------------
 *  Backwards-compatible wrappers for the 1.x procedural API.
 * ---------------------------------------------------------------------- */

/**
 * @deprecated 2.0.0 Use Goldmate_Calculator::calculate().
 */
function goldmate_calculate( $product_id ) {
	return Goldmate_Calculator::calculate( $product_id );
}

/**
 * @deprecated 2.0.0 Use Goldmate_Pricing::apply().
 */
function goldmate_apply_price( $product_id ) {
	return Goldmate_Pricing::apply( $product_id );
}

/**
 * @deprecated 2.0.0 Use Goldmate_Batch::start() for a non-blocking rebuild.
 */
function goldmate_apply_all() {
	return Goldmate_Pricing::apply_all_now();
}
