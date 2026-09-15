<?php
/**
 * Seeds a Mio-style demo: pendant with two weights + linked chain options.
 *
 * From the WordPress root (Local site shell / WP-CLI):
 *   wp eval-file wp-content/plugins/goldmate-gold-price/bin/create-demo-products.php
 */

// Dev tooling: WP-CLI only, never reachable over HTTP.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce is required.' );
}

if ( ! class_exists( 'Goldmate_Pricing' ) ) {
	WP_CLI::error( 'GoldMate plugin is not active.' );
}

$rate = (float) goldmate_option( 'goldmate_rate_per_gram' );
if ( $rate <= 0 ) {
	update_option( 'goldmate_rate_per_gram', 23295000 );
	$rate = 23295000;
	WP_CLI::log( "Set demo gold rate to {$rate} Toman/g." );
}

/**
 * Creates or updates a GoldMate-enabled simple product.
 *
 * @param string $sku    SKU.
 * @param string $name   Name.
 * @param float  $weight Grams.
 * @param float  $wage   Wage %.
 * @return WC_Product_Simple
 */
function goldmate_demo_simple( $sku, $name, $weight, $wage = 12.0 ) {

	$existing = wc_get_product_id_by_sku( $sku );
	$product  = $existing ? wc_get_product( $existing ) : new WC_Product_Simple();

	if ( ! $product ) {
		$product = new WC_Product_Simple();
	}

	$product->set_name( $name );
	$product->set_sku( $sku );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_regular_price( '0' );
	$product->set_manage_stock( false );
	$product->set_stock_status( 'instock' );
	$product->set_description( 'محصول نمونه گلدمیت برای تست متعلقات قابل انتخاب.' );
	$product->set_short_description( sprintf( 'وزن %s گرم — طلای ۱۸ عیار', wc_format_localized_decimal( $weight ) ) );

	$id = $product->save();

	update_post_meta( $id, '_goldmate_enabled', 'yes' );
	update_post_meta( $id, '_goldmate_weight', $weight );
	update_post_meta( $id, '_goldmate_karat', 18 );
	update_post_meta( $id, '_goldmate_wage_pct', $wage );
	update_post_meta( $id, '_goldmate_accessories', 0 );

	Goldmate_Pricing::apply( $id );

	return wc_get_product( $id );
}

$chain_light = goldmate_demo_simple(
	'GM-DEMO-Z240',
	'زنجیر طلا ۴۰ سانتی‌متر سبک کد Z240 (نمونه)',
	1.10,
	10.0
);

$chain_mid = goldmate_demo_simple(
	'GM-DEMO-Z145',
	'زنجیر طلا ۴۵ سانتی‌متر خیلی سبک کد Z145 (نمونه)',
	0.87,
	10.0
);

$chain_med = goldmate_demo_simple(
	'GM-DEMO-Z340',
	'زنجیر طلا ۴۰ سانتی‌متر متوسط کد Z340 (نمونه)',
	1.57,
	10.0
);

WP_CLI::log( sprintf( 'Chains: #%d, #%d, #%d', $chain_light->get_id(), $chain_mid->get_id(), $chain_med->get_id() ) );

$pendant_sku = 'GM-DEMO-N1722';
$pendant_id  = wc_get_product_id_by_sku( $pendant_sku );

if ( $pendant_id ) {
	$pendant = wc_get_product( $pendant_id );
	foreach ( $pendant->get_children() as $child_id ) {
		wp_delete_post( $child_id, true );
	}
} else {
	$pendant = new WC_Product_Variable();
}

$pendant->set_name( 'پلاک طلا قلب سایز کوچک کد N1722 (نمونه گلدمیت)' );
$pendant->set_sku( $pendant_sku );
$pendant->set_status( 'publish' );
$pendant->set_catalog_visibility( 'visible' );
$pendant->set_description(
	"پلاک طلا قلب سایز کوچک — محصول نمونه برای تست سیستم متعلقات گلدمیت.\n\n"
	. 'وزن‌های مختلف به‌عنوان متغیر ووکامرس؛ زنجیر به‌عنوان متعلقات لینک‌شده (نه ویژگی متغیر).'
);
$pendant->set_short_description( 'نمونه Mio-style: انتخاب وزن + زنجیر اختیاری' );
$pendant->set_manage_stock( false );
$pendant->set_stock_status( 'instock' );

$attr_name = 'وزن';
$attr_slug = sanitize_title( $attr_name );

$attr = new WC_Product_Attribute();
$attr->set_name( $attr_name );
$attr->set_options( array( '0.41 گرم', '0.42 گرم' ) );
$attr->set_visible( true );
$attr->set_variation( true );
$pendant->set_attributes( array( $attr ) );

$pendant_id = $pendant->save();

update_post_meta( $pendant_id, '_goldmate_enabled', 'yes' );
update_post_meta( $pendant_id, '_goldmate_karat', 18 );
update_post_meta( $pendant_id, '_goldmate_wage_pct', 15 );
update_post_meta( $pendant_id, '_goldmate_accessories', 0 );
update_post_meta( $pendant_id, '_goldmate_weight', 0.41 );

update_post_meta(
	$pendant_id,
	'_goldmate_accessory_groups',
	array(
		array(
			'label'       => 'زنجیر طلا',
			'none_label'  => 'بدون زنجیر',
			'product_ids' => array(
				$chain_light->get_id(),
				$chain_mid->get_id(),
				$chain_med->get_id(),
			),
		),
	)
);

$weights = array(
	'0.41 گرم' => 0.41,
	'0.42 گرم' => 0.42,
);

foreach ( $weights as $label => $grams ) {

	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $pendant_id );
	// Keys must be sanitize_title() of the attribute name (URL-encoded for Persian).
	$variation->set_attributes( array( $attr_slug => $label ) );
	$variation->set_status( 'publish' );
	$variation->set_regular_price( '0' );
	$variation->set_manage_stock( false );
	$variation->set_stock_status( 'instock' );
	$variation->set_sku( 'GM-DEMO-N1722-' . str_replace( '.', '', (string) $grams ) );

	$vid = $variation->save();

	update_post_meta( $vid, '_goldmate_weight', $grams );
	Goldmate_Pricing::apply( $vid );

	WP_CLI::log( sprintf( 'Variation %s → #%d @ %s', $label, $vid, get_post_meta( $vid, '_price', true ) ) );
}

WC_Product_Variable::sync( $pendant_id );
wc_delete_product_transients( $pendant_id );

WP_CLI::success( 'Demo product ready.' );
WP_CLI::log( 'Pendant ID: ' . $pendant_id );
WP_CLI::log( 'URL: ' . get_permalink( $pendant_id ) );
WP_CLI::log( 'Edit: ' . admin_url( 'post.php?post=' . $pendant_id . '&action=edit' ) );
