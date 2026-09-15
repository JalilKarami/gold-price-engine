<?php
/**
 * Enable نرخ خودکار on all publish products and seed missing weight defaults.
 *
 *   wp eval-file wp-content/plugins/goldmate-gold-price/bin/enable-all-goldmate.php
 */

// Dev tooling: WP-CLI only, never reachable over HTTP.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

if ( ! class_exists( 'WooCommerce' ) ) {
	fwrite( STDERR, "WooCommerce required.\n" );
	exit( 1 );
}
if ( ! class_exists( 'Goldmate_Calculator' ) ) {
	fwrite( STDERR, "GoldMate plugin not active.\n" );
	exit( 1 );
}

$rate = 0.0;
if ( class_exists( 'Goldmate_Rate_Items' ) ) {
	$item = Goldmate_Rate_Items::get_by_slug( 'gold18' );
	if ( $item ) {
		$rate = (float) Goldmate_Rate_Items::rate_18_for_calculator( $item );
	}
}
if ( $rate <= 0 ) {
	$rate = goldmate_positive_float( goldmate_option( 'goldmate_rate_per_gram' ) );
}
if ( $rate <= 0 ) {
	$rate = 23441756;
}

$ids = get_posts(
	array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
	)
);

$enabled = 0;
$seeded  = 0;
$priced  = 0;
$failed  = array();
$ok      = array();

foreach ( $ids as $id ) {
	$id      = (int) $id;
	$product = wc_get_product( $id );
	if ( ! $product ) {
		continue;
	}

	// Skip non-physical / wallet-style products with no price and no title hint of gold.
	$title = $product->get_name();
	if ( false !== mb_stripos( $title, 'کیف پول' ) ) {
		continue;
	}

	update_post_meta( $id, '_goldmate_enabled', 'yes' );
	$enabled++;

	$weight = goldmate_meta_float( $id, '_goldmate_weight' );
	$karat  = goldmate_meta_float( $id, '_goldmate_karat' );
	$wage   = goldmate_meta_float( $id, '_goldmate_wage_pct' );

	if ( $weight <= 0 ) {
		$fixed = goldmate_positive_float( $product->get_regular_price() );
		// Rough demo weight from existing fixed price (~gold + ~20% extras).
		$approx = $fixed > 0 ? round( $fixed / ( $rate * 1.25 ), 2 ) : 1.0;
		if ( $approx < 0.3 ) {
			$approx = 0.5;
		}
		if ( $approx > 25 ) {
			$approx = 10.0;
		}
		update_post_meta( $id, '_goldmate_weight', $approx );
		$weight = $approx;
		$seeded++;
	}

	if ( $karat <= 0 || $karat < 8 ) {
		update_post_meta( $id, '_goldmate_karat', 18 );
	}
	if ( $wage <= 0 ) {
		update_post_meta( $id, '_goldmate_wage_pct', 12 );
	}
	if ( '' === trim( (string) get_post_meta( $id, '_goldmate_wage_mode', true ) ) ) {
		update_post_meta( $id, '_goldmate_wage_mode', 'pct' );
	}
	// Leave formula empty → calculator uses global default (gold18).

	if ( class_exists( 'Goldmate_Pricing' ) ) {
		Goldmate_Pricing::apply( $id );
	}

	$breakdown = Goldmate_Calculator::calculate( $id );
	if ( false === $breakdown || empty( $breakdown['total'] ) ) {
		$failed[] = array(
			'id'    => $id,
			'title' => $title,
			'weight'=> $weight,
		);
	} else {
		$priced++;
		$ok[] = array(
			'id'     => $id,
			'title'  => $title,
			'weight' => isset( $breakdown['weight'] ) ? $breakdown['weight'] : $weight,
			'total'  => (float) $breakdown['total'],
			'formula'=> isset( $breakdown['formula_slug'] ) ? $breakdown['formula_slug'] : '',
			'rate'   => isset( $breakdown['rate_item'] ) ? $breakdown['rate_item'] : '',
		);
	}
}

$out = function ( $msg ) {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::log( $msg );
	} else {
		echo $msg . "\n";
	}
};

$out( sprintf( '[OK] Enabled نرخ خودکار on %d publish products (seeded weight on %d).', $enabled, $seeded ) );
$out( sprintf( '[OK] Calculator priced %d / %d products (rate_18=%s).', $priced, $enabled, number_format( $rate ) ) );

foreach ( $ok as $row ) {
	$out(
		sprintf(
			'  #%d %s | w=%s | formula=%s | rate=%s | total=%s',
			$row['id'],
			mb_substr( $row['title'], 0, 40 ),
			$row['weight'],
			$row['formula'] ?: '(default)',
			$row['rate'] ?: '-',
			number_format( $row['total'] )
		)
	);
}

if ( $failed ) {
	$out( '[FAIL] Could not price:' );
	foreach ( $failed as $row ) {
		$out( sprintf( '  #%d %s (weight=%s)', $row['id'], $row['title'], $row['weight'] ) );
	}
	exit( 1 );
}

$out( '[OK] All enabled products calculate successfully.' );
