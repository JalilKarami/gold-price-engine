<?php
/**
 * Shared helpers: option defaults, numeric sanitisation and meta access.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default value for every option the plugin owns.
 *
 * @return array
 */
function goldmate_defaults() {
	return array(
		// Pricing.
		'goldmate_rate_per_gram'     => 0,
		'goldmate_profit_pct'        => 7,
		'goldmate_tax_pct'           => 9,
		'goldmate_tax_accessories'   => 'no',
		'goldmate_default_wage_mode' => 'pct',
		'goldmate_round_to'          => 1000,
		'goldmate_round_mode'        => 'round',
		'goldmate_wc_tax_status'     => 'none',
		'goldmate_show_breakdown'    => 'yes',
		'goldmate_show_formula'      => 'yes',

		// Cart, order and invoice detail.
		'goldmate_cart_details'  => 'yes',
		'goldmate_order_details' => 'full',

		// Uninstall behaviour.
		'goldmate_delete_data' => 'no',

		// General (عمومی) — Ratesbox-style operational controls.
		'goldmate_calc_mode'                  => 'store',
		'goldmate_check_price_validity'       => 'yes',
		'goldmate_reprice_on_cron'            => 'yes',
		'goldmate_reprice_on_manual_fetch'    => 'yes',
		'goldmate_reprice_on_item_save'       => 'yes',
		'goldmate_reprice_on_all_items_save'  => 'yes',
		'goldmate_reprice_on_product_save'    => 'yes',
		'goldmate_reprice_on_add_to_cart'     => 'yes',
		'goldmate_reprice_cart_on_add'        => 'no',
		'goldmate_reprice_cart_on_cart'       => 'no',
		'goldmate_reprice_cart_on_checkout'   => 'yes',
		'goldmate_store_calc_time'            => 'yes',
		'goldmate_recalc_only_on_change'      => 'no',
		'goldmate_price_history_hours'        => 24,
		'goldmate_round_prices'               => 'yes',
		'goldmate_strip_below'                => 1,
		'goldmate_details_admin'              => 'yes',
		'goldmate_details_customer'           => 'yes',
		'goldmate_details_email'              => 'no',
		'goldmate_hide_price_change'          => 'no',
		'goldmate_details_colleague'          => 'no',
		'goldmate_colleague_role'             => '',
		'goldmate_max_price_validity'         => 240,
		'goldmate_oos_shortcodes'             => 'no',
		'goldmate_oos_manual_shortcodes'      => 'no',
		'goldmate_oos_shortcode_text'         => 'خارج از سرویس',
		'goldmate_oos_products'               => 'no',
		'goldmate_oos_contact_url'            => '',
		'goldmate_oos_product_text'           => 'تماس بگیرید',
		'goldmate_ajax_shortcodes'            => 'yes',
		'goldmate_ajax_shortcodes_enable'     => 'yes',
		'goldmate_ajax_shortcodes_interval'   => 30,
		'goldmate_ajax_products'              => 'yes',
		'goldmate_ajax_products_interval'     => 60,
		'goldmate_tax_method'                 => 'all',
		'goldmate_tax_on_wage'                => 'yes',
		'goldmate_tax_on_profit'              => 'yes',
		'goldmate_tax_selective_karats'       => array( '18', '24' ),
		'goldmate_order_recalc'               => 'yes',
		'goldmate_order_item_validity'        => 3,
		'goldmate_order_auto_cancel'          => 'no',
		'goldmate_order_auto_cancel_minutes'  => 30,
		'goldmate_hide_shortcode_outofstock'  => 'yes',

		// Automatic rate fetching (per rate-item; global API settings retired).
		'goldmate_fetch_interval'    => 60,
		'goldmate_max_deviation'     => 20,
		'goldmate_min_change_pct'    => 0.4,
		'goldmate_min_change_amount' => 0,

		// Live storefront refresh (seconds; 0 disables). Synced from AJAX product interval.
		'goldmate_live_interval' => 60,

		// Staleness handling.
		'goldmate_stale_hours'  => 24,
		'goldmate_stale_action' => 'notice',

		// Fetch log retention.
		'goldmate_log_days' => 14,
	);
}

/**
 * Reads a plugin option, falling back to the registered default.
 *
 * @param string $key     Option name.
 * @param mixed  $default Optional override for the registered default.
 * @return mixed
 */
function goldmate_option( $key, $default = null ) {

	$defaults = goldmate_defaults();

	if ( null === $default ) {
		$default = isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}

	return get_option( $key, $default );
}

/**
 * Casts a value to a float and clamps it at zero.
 *
 * User-supplied numbers reach us from POST bodies, the REST API and CSV
 * imports, where the `min` attribute on the admin input offers no protection.
 * A negative weight or wage would otherwise produce a negative product price.
 *
 * @param mixed $value Raw value.
 * @return float
 */
function goldmate_positive_float( $value ) {

	if ( is_string( $value ) ) {
		// Persian and Arabic digits arrive from RTL keyboards and pasted text.
		$value = goldmate_normalize_digits( $value );
		$value = str_replace( array( ',', '،', ' ', ' ' ), '', $value );
	}

	$value = (float) $value;

	if ( ! is_finite( $value ) || $value < 0 ) {
		return 0.0;
	}

	return $value;
}

/**
 * Converts Persian and Arabic-Indic digits to their ASCII equivalents.
 *
 * @param string $value Raw string.
 * @return string
 */
function goldmate_normalize_digits( $value ) {

	$persian = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٫' );
	$arabic  = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '٬' );
	$ascii   = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.' );

	$value = str_replace( $persian, $ascii, $value );
	$value = str_replace( $arabic, $ascii, $value );

	return $value;
}

/**
 * Formats an amount as plain text, with no markup and no HTML entities.
 *
 * wc_price() returns a styled span, and many currency symbols come back as
 * numeric entities. Both are wrong wherever the result is later escaped or
 * printed outside HTML: inside a table label, in order item meta, in an e-mail,
 * or in a PDF invoice. Decoding to real characters is what those callers need.
 *
 * @param float       $amount   Amount.
 * @param string|null $currency Currency code, or null for the shop default.
 * @return string
 */
function goldmate_plain_price( $amount, $currency = null ) {

	$args = $currency ? array( 'currency' => $currency ) : array();

	return html_entity_decode( wp_strip_all_tags( wc_price( $amount, $args ) ), ENT_QUOTES, 'UTF-8' );
}

/**
 * Meta keys that carry per-product calculation inputs.
 *
 * @return string[]
 */
function goldmate_numeric_meta_keys() {
	return array(
		'_goldmate_weight',
		'_goldmate_karat',
		'_goldmate_wage_pct',
		'_goldmate_wage_fixed',
		'_goldmate_stone',
		'_goldmate_leather',
		'_goldmate_accessories',
		'_goldmate_profit_pct',
	);
}

/**
 * Casts a value to a float without clamping at zero (for rate markup/markdown).
 *
 * @param mixed $value Raw value.
 * @return float
 */
function goldmate_signed_float( $value ) {

	if ( is_string( $value ) ) {
		$value = goldmate_normalize_digits( $value );
		$value = str_replace( array( ',', '،', ' ', ' ' ), '', $value );
	}

	$value = (float) $value;

	if ( ! is_finite( $value ) ) {
		return 0.0;
	}

	return $value;
}

/**
 * Reads a numeric product meta value, clamped at zero.
 *
 * @param int    $product_id Product or variation ID.
 * @param string $key        Meta key.
 * @return float
 */
function goldmate_meta_float( $product_id, $key ) {
	return goldmate_positive_float( get_post_meta( $product_id, $key, true ) );
}

/**
 * Walks a decoded JSON structure using a dot-separated path.
 *
 * Numeric segments index into lists, so `data.0.price` is valid.
 *
 * A segment may also select a list entry by one of its own fields, written as
 * `gold[symbol=IR_GOLD_18K]`. Position in a list is the provider's to change —
 * BrsApi returns 18-karat gold, 24-karat gold, the ounce (priced in dollars)
 * and five coins in one array — so pinning to an index means a reordered feed
 * can silently reprice the whole catalogue against the wrong item.
 *
 * @param mixed  $data Decoded JSON.
 * @param string $path Dot-separated path. An empty path returns $data itself.
 * @return mixed|null Null when the path does not resolve.
 */
function goldmate_json_path( $data, $path ) {

	$path = trim( (string) $path );

	if ( '' === $path ) {
		return $data;
	}

	foreach ( explode( '.', $path ) as $segment ) {

		$segment = trim( $segment );
		$filter  = null;

		if ( preg_match( '/^(.*?)\[\s*([^\]=]+?)\s*=\s*(.*?)\s*\]$/', $segment, $matches ) ) {
			$segment = trim( $matches[1] );
			$filter  = array( trim( $matches[2] ), $matches[3] );
		}

		if ( '' !== $segment ) {

			$data = goldmate_json_child( $data, $segment );

			if ( null === $data ) {
				return null;
			}
		}

		if ( null !== $filter ) {

			$data = goldmate_json_find( $data, $filter[0], $filter[1] );

			if ( null === $data ) {
				return null;
			}
		}
	}

	return $data;
}

/**
 * Reads one key from an array or object.
 *
 * @param mixed  $data Array or object.
 * @param string $key  Key to read.
 * @return mixed|null Null when the key is absent.
 */
function goldmate_json_child( $data, $key ) {

	if ( is_array( $data ) && array_key_exists( $key, $data ) ) {
		return $data[ $key ];
	}

	if ( is_object( $data ) && isset( $data->$key ) ) {
		return $data->$key;
	}

	return null;
}

/**
 * Finds the first entry of a list whose $field equals $value.
 *
 * Works over both JSON arrays and objects keyed by id, since providers use
 * either shape for the same kind of list.
 *
 * @param mixed  $list  Decoded list.
 * @param string $field Field to match on.
 * @param string $value Value to match, compared as text.
 * @return mixed|null Null when nothing matches.
 */
function goldmate_json_find( $list, $field, $value ) {

	if ( ! is_array( $list ) && ! is_object( $list ) ) {
		return null;
	}

	foreach ( (array) $list as $entry ) {

		$found = goldmate_json_child( $entry, $field );

		if ( null === $found || is_array( $found ) || is_object( $found ) ) {
			continue;
		}

		// Compared as text, so a JSON number and its written form both match.
		if ( 0 === strcasecmp( (string) $found, (string) $value ) ) {
			return $entry;
		}
	}

	return null;
}

/**
 * Correction for a date filter that formats from UTC and drops the site timezone.
 *
 * woodmart-plus converts every wp_date() result to the Jalali calendar through
 * its `wcplus_date_to_jalali` filter, but rebuilds the date from the raw UTC
 * timestamp and ignores the timezone wp_date() hands it. Every date on the site
 * therefore reads `gmt_offset` hours early — 3:30 behind Tehran. That plugin is
 * ionCube-encoded, so the only place this can be corrected is at the call site:
 * shifting the timestamp by the same offset lands its arithmetic back on local
 * time, and the Jalali date it prints stays correct too.
 *
 * The shift applies only while that filter is actually installed, so removing or
 * fixing woodmart-plus restores plain wp_date() behaviour with no change here.
 *
 * @return int Seconds to add before formatting; zero on a healthy site.
 */
function goldmate_date_offset_fix() {

	if ( ! class_exists( 'wcplus_date_to_jalali' ) || ! has_filter( 'wp_date' ) ) {
		return 0;
	}

	return (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
}

/**
 * Formats a timestamp in the site's timezone, or a dash when unset.
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function goldmate_format_time( $timestamp ) {

	$timestamp = (int) $timestamp;

	if ( $timestamp <= 0 ) {
		return '—';
	}

	return wp_date( 'Y/m/d H:i', $timestamp + goldmate_date_offset_fix() );
}

/**
 * Sanitises an endpoint URL without destroying the {KEY} placeholder.
 *
 * `esc_url_raw()` strips braces, which turns `?key={KEY}` into `?key=KEY`.
 *
 * @param string $url Submitted URL.
 * @return string
 */
function goldmate_sanitize_endpoint_url( $url ) {

	$url   = (string) $url;
	$token = 'goldmateKeyPlaceholder';

	$url = str_replace( array( '{KEY}', '%7BKEY%7D', '%7bkey%7d' ), $token, $url );
	$url = esc_url_raw( $url, array( 'http', 'https' ) );

	return str_replace( $token, '{KEY}', $url );
}

/**
 * Current 18k reference rate from the gold18 rate item (option is a mirror only).
 *
 * @return float
 */
function goldmate_reference_rate() {

	if ( class_exists( 'Goldmate_Rate_Items' ) ) {
		$item = Goldmate_Rate_Items::get_by_slug( Goldmate_Rate_Items::DEFAULT_SLUG );
		if ( $item && goldmate_positive_float( $item['rate'] ) > 0 ) {
			return goldmate_positive_float( $item['rate'] );
		}
	}

	return goldmate_positive_float( goldmate_option( 'goldmate_rate_per_gram' ) );
}
