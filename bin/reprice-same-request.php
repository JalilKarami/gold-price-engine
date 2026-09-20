<?php
/**
 * Regression test: a variable product must be priced in the same request that
 * creates it, even when the repricing pass runs before its variations exist.
 *
 * Reproduces the stale-price failure: the parent is saved once with no children
 * (Goldmate_Pricing::apply() returns false) and again after the variations are
 * added. The second pass must price the children instead of silently bailing.
 *
 * From the WordPress root (Local site shell / WP-CLI):
 *   wp eval-file wp-content/plugins/goldmate-gold-price/bin/reprice-same-request.php
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce is required.' );
}

if ( ! class_exists( 'Goldmate_Pricing' ) ) {
	WP_CLI::error( 'GoldMate plugin is not active.' );
}

$ref  = Goldmate_Rate_Items::get_by_slug( Goldmate_Rate_Items::DEFAULT_SLUG );
$rate = (float) $ref['rate'];

if ( $rate <= 0 ) {
	WP_CLI::error( 'No gold rate configured; cannot compute expected prices.' );
}

$hist_start = time();

/*
 * Pass 1: the parent exists with no children yet. This is the admin reality
 * (product saved before variations are generated) and fires the same hooks.
 */
$product = new WC_Product_Variable();
$product->set_name( 'GM regression - same-request reprice' );
$product->update_meta_data( '_goldmate_enabled', 'yes' );
$product->update_meta_data( '_goldmate_karat', '18' );
$product->update_meta_data( '_goldmate_wage_pct', '7' );
$pid = $product->save();

$first = Goldmate_Pricing::apply( $pid );
WP_CLI::log( "pass1 apply=" . var_export( $first, true ) . " (false expected: no children yet)" );

/*
 * Pass 2: variations now exist with weights (as the variation table or the
 * attribute fill would have written them). The same request must price them.
 */
$v1 = new WC_Product_Variation();
$v1->set_parent_id( $pid );
$v1->set_status( 'publish' );
$v1id = $v1->save();
update_post_meta( $v1id, '_goldmate_weight', 3.049 );

$v2 = new WC_Product_Variation();
$v2->set_parent_id( $pid );
$v2->set_status( 'publish' );
$v2id = $v2->save();
update_post_meta( $v2id, '_goldmate_weight', 2.111 );

$second = Goldmate_Pricing::apply( $pid );

$v1p = wc_get_product( $v1id );
$v2p = wc_get_product( $v2id );
$reg1 = (float) $v1p->get_regular_price( 'edit' );
$reg2 = (float) $v2p->get_regular_price( 'edit' );

$gold = 3.049 * $rate;
$wage = $gold * 0.07;
$prof = ( $gold + $wage ) * 0.07;
$tax  = ( $wage + $prof ) * 0.09;
$raw  = $gold + $wage + $prof + $tax;
$expected = round( round( $raw / 1000 ) * 1000, 2 );

$ok = ( 2 === (int) $second ) && $reg1 > 0 && $reg2 > 0;

WP_CLI::log( "pass2 apply=" . var_export( $second, true ) . " reg1={$reg1} reg2={$reg2} expected={$expected}" );

if ( ! $ok ) {
	WP_CLI::error( 'FAIL: same-request repricing did not price the variations.' );
}

if ( abs( $reg1 - $expected ) > 1 ) {
	WP_CLI::error( "FAIL: v1 price {$reg1} != expected {$expected}." );
}

WP_CLI::success( 'PASS: variations priced in the same request that created them.' );

// Cleanup.
wp_delete_post( $v2id, true );
wp_delete_post( $v1id, true );
wp_delete_post( $pid, true );

if ( class_exists( 'Goldmate_Rate_Items' ) && method_exists( 'Goldmate_Rate_Items', 'history_table' ) ) {
	global $wpdb;
	$table = Goldmate_Rate_Items::history_table();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE recorded_at > %d", $hist_start ) );
}
