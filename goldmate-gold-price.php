<?php
/**
 * Plugin Name: گلدمیت — محاسبه قیمت طلا
 * Description: محاسبه خودکار قیمت محصولات طلا بر اساس وزن، عیار، اجرت (درصدی یا ثابت)، سود، متعلقات و مالیات. شامل دریافت خودکار قیمت روز با منبع جایگزین، بروزرسانی دسته‌ای و نمایش آنی نرخ.
 * Version: 3.4.7
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

define( 'GOLDMATE_VERSION', '3.4.7' );
define( 'GOLDMATE_FILE', __FILE__ );
define( 'GOLDMATE_PATH', plugin_dir_path( __FILE__ ) );
define( 'GOLDMATE_URL', plugin_dir_url( __FILE__ ) );

require_once GOLDMATE_PATH . 'includes/functions.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-install.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-rate-items.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-formulas.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-fetcher.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-admin-items.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-admin-formulas.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-calculator.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-pricing.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-batch.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-product-fields.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-display.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-order.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-accessories.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-live.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-widgets.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-shortcodes.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-discounts.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-components.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-calculator-admin.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-status.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-tools.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-general.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-settings.php';
require_once GOLDMATE_PATH . 'includes/class-goldmate-admin.php';
require_once GOLDMATE_PATH . 'includes/goldmate-variation-selector.php';

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

	Goldmate_Install::maybe_upgrade();

	Goldmate_Pricing::init();
	Goldmate_Batch::init();
	Goldmate_Fetcher::init();
	Goldmate_Product_Fields::init();
	Goldmate_Display::init();
	Goldmate_Order::init();
	Goldmate_Accessories::init();
	Goldmate_Live::init();
	Goldmate_Widgets::init();
	Goldmate_Shortcodes::init();
	Goldmate_Discounts::init();
	Goldmate_General::init();
	Goldmate_Calculator_Admin::init();
	Goldmate_Admin::init();
	Goldmate_Variation_Selector::init();
}
add_action( 'plugins_loaded', 'goldmate_init' );

/**
 * Makes sure the recurring rate fetch is scheduled as soon as the plugin is switched on.
 */
function goldmate_activate() {
	require_once GOLDMATE_PATH . 'includes/functions.php';
	require_once GOLDMATE_PATH . 'includes/class-goldmate-install.php';
	require_once GOLDMATE_PATH . 'includes/class-goldmate-rate-items.php';
	require_once GOLDMATE_PATH . 'includes/class-goldmate-formulas.php';
	require_once GOLDMATE_PATH . 'includes/class-goldmate-fetcher.php';
	Goldmate_Install::install();
	Goldmate_Fetcher::ensure_scheduled();
}
register_activation_hook( __FILE__, 'goldmate_activate' );

/**
 * Clears every background job the plugin owns so a deactivated plugin stays idle.
 */
function goldmate_deactivate() {
	require_once GOLDMATE_PATH . 'includes/functions.php';
	require_once GOLDMATE_PATH . 'includes/class-goldmate-fetcher.php';
	require_once GOLDMATE_PATH . 'includes/class-goldmate-batch.php';
	Goldmate_Fetcher::unschedule();
	Goldmate_Batch::cancel();
}
register_deactivation_hook( __FILE__, 'goldmate_deactivate' );
