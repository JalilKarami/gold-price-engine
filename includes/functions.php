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
		'goldmate_rate_per_gram'   => 0,
		'goldmate_profit_pct'      => 7,
		'goldmate_tax_pct'         => 9,
		'goldmate_tax_accessories' => 'no',
		'goldmate_round_to'        => 1000,
		'goldmate_round_mode'      => 'round',
		'goldmate_wc_tax_status'   => 'none',
		'goldmate_show_breakdown'  => 'yes',
		'goldmate_show_formula'    => 'yes',

		// Cart, order and invoice detail.
		'goldmate_cart_details'    => 'yes',
		'goldmate_order_details'   => 'full',

		// Uninstall behaviour.
		'goldmate_delete_data'     => 'no',

		// Automatic rate fetching.
		'goldmate_rate_source'     => 'manual',
		'goldmate_api_url'         => '',
		'goldmate_api_key'         => '',
		'goldmate_api_key_header'  => '',
		'goldmate_api_path'        => '',
		'goldmate_api_multiplier'  => 1,
		'goldmate_api_time_path'   => '',
		'goldmate_api_max_age'     => 0,
		'goldmate_fetch_interval'  => 60,
		'goldmate_max_deviation'   => 20,

		// Staleness handling.
		'goldmate_stale_hours'     => 24,
		'goldmate_stale_action'    => 'notice',

		// Fetch log retention.
		'goldmate_log_days'        => 14,
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
	return array( '_goldmate_weight', '_goldmate_karat', '_goldmate_wage_pct', '_goldmate_accessories' );
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

	return wp_date( 'Y/m/d H:i', $timestamp );
}
