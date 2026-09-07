<?php
/**
 * Turns a product's stored inputs plus the current gold rate into a price breakdown.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Calculator {

	/**
	 * Calculates the itemised price of a gold product or variation.
	 *
	 * Formula:
	 *   gold   = weight × per-gram rate adjusted for karat
	 *   wage   = gold × wage percentage
	 *   profit = (gold + wage) × profit percentage
	 *   tax    = (wage + profit [+ accessories]) × tax percentage
	 *   total  = gold + wage + profit + accessories + tax
	 *
	 * @param int $product_id Product or variation ID.
	 * @return array|false Breakdown, or false when the product is not priced by weight.
	 */
	public static function calculate( $product_id ) {

		$inputs = self::resolve_inputs( $product_id );

		if ( false === $inputs ) {
			return false;
		}

		$rate_18 = goldmate_positive_float( goldmate_option( 'goldmate_rate_per_gram' ) );

		if ( $rate_18 <= 0 ) {
			return false;
		}

		$profit_pct      = goldmate_positive_float( goldmate_option( 'goldmate_profit_pct' ) );
		$tax_pct         = goldmate_positive_float( goldmate_option( 'goldmate_tax_pct' ) );
		$tax_accessories = 'yes' === goldmate_option( 'goldmate_tax_accessories' );
		$round_to        = goldmate_positive_float( goldmate_option( 'goldmate_round_to' ) );
		$round_mode      = goldmate_option( 'goldmate_round_mode' );

		// Karat scales linearly against the 18-karat reference (750/1000 purity).
		$rate = $rate_18 * ( $inputs['karat'] / 18 );

		$gold   = $inputs['weight'] * $rate;
		$wage   = $gold * ( $inputs['wage_pct'] / 100 );
		$profit = ( $gold + $wage ) * ( $profit_pct / 100 );

		$taxable = $wage + $profit;
		if ( $tax_accessories ) {
			$taxable += $inputs['accessories'];
		}

		$tax = $taxable * ( $tax_pct / 100 );

		$total = $gold + $wage + $profit + $inputs['accessories'] + $tax;
		$total = self::round_total( $total, $round_to, $round_mode );

		$breakdown = array(
			'product_id'      => (int) $product_id,
			'weight'          => $inputs['weight'],
			'karat'           => $inputs['karat'],
			'rate_18'         => $rate_18,
			'rate'            => $rate,
			'gold'            => $gold,
			'wage'            => $wage,
			'wage_pct'        => $inputs['wage_pct'],
			'profit'          => $profit,
			'profit_pct'      => $profit_pct,
			'accessories'     => $inputs['accessories'],
			'tax'             => $tax,
			'tax_pct'         => $tax_pct,
			'tax_accessories' => $tax_accessories,
			'total'           => $total,
		);

		/**
		 * Filters the finished breakdown before it is priced or displayed.
		 *
		 * @param array $breakdown  Itemised price.
		 * @param int   $product_id Product or variation ID.
		 */
		return apply_filters( 'goldmate_breakdown', $breakdown, $product_id );
	}

	/**
	 * Collects the calculation inputs for a product, resolving variation inheritance.
	 *
	 * A variation inherits every field left blank from its parent, so a shop only
	 * needs to override the weight per ring size.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return array|false
	 */
	public static function resolve_inputs( $product_id ) {

		$product_id = (int) $product_id;
		$parent_id  = wp_get_post_parent_id( $product_id );
		$is_variant = 'product_variation' === get_post_type( $product_id );
		$owner_id   = ( $is_variant && $parent_id ) ? $parent_id : $product_id;

		// The parent product holds the master switch for the whole family.
		if ( 'yes' !== get_post_meta( $owner_id, '_goldmate_enabled', true ) ) {
			return false;
		}

		if ( $is_variant && 'yes' === get_post_meta( $product_id, '_goldmate_excluded', true ) ) {
			return false;
		}

		$read = function ( $key ) use ( $product_id, $owner_id, $is_variant ) {

			if ( $is_variant ) {
				$own = get_post_meta( $product_id, $key, true );
				if ( '' !== trim( (string) $own ) ) {
					return goldmate_positive_float( $own );
				}
			}

			return goldmate_meta_float( $owner_id, $key );
		};

		$weight = $read( '_goldmate_weight' );

		if ( $weight <= 0 ) {
			return false;
		}

		$karat = $read( '_goldmate_karat' );

		if ( $karat <= 0 ) {
			$karat = 18;
		}

		return array(
			'weight'      => $weight,
			'karat'       => $karat,
			'wage_pct'    => $read( '_goldmate_wage_pct' ),
			'accessories' => $read( '_goldmate_accessories' ),
		);
	}

	/**
	 * Applies the configured rounding step and direction.
	 *
	 * @param float  $total    Raw total.
	 * @param float  $round_to Step size; zero disables rounding.
	 * @param string $mode     `round`, `ceil` or `floor`.
	 * @return float
	 */
	protected static function round_total( $total, $round_to, $mode ) {

		if ( $round_to <= 0 ) {
			return round( $total, 2 );
		}

		$steps = $total / $round_to;

		switch ( $mode ) {
			case 'ceil':
				$steps = ceil( $steps );
				break;
			case 'floor':
				$steps = floor( $steps );
				break;
			default:
				$steps = round( $steps );
		}

		return $steps * $round_to;
	}

	/**
	 * Builds the label/amount rows used by the customer-facing breakdown table.
	 *
	 * @param array $b Breakdown from calculate().
	 * @return array[] List of [label, amount] pairs.
	 */
	public static function breakdown_rows( $b ) {

		$rows = array(
			array(
				sprintf(
					'مبلغ طلا (%s گرم عیار %s × %s)',
					wc_format_localized_decimal( $b['weight'] ),
					wc_format_localized_decimal( $b['karat'] ),
					goldmate_plain_price( $b['rate'] )
				),
				$b['gold'],
			),
			array( sprintf( 'اجرت (%s٪)', wc_format_localized_decimal( $b['wage_pct'] ) ), $b['wage'] ),
			array( sprintf( 'سود (%s٪)', wc_format_localized_decimal( $b['profit_pct'] ) ), $b['profit'] ),
		);

		if ( $b['accessories'] > 0 ) {
			$rows[] = array( 'متعلقات', $b['accessories'] );
		}

		$tax_base = $b['tax_accessories'] ? 'اجرت، سود و متعلقات' : 'اجرت و سود';

		$rows[] = array(
			sprintf( 'مالیات بر ارزش افزوده (%s٪ %s)', wc_format_localized_decimal( $b['tax_pct'] ), $tax_base ),
			$b['tax'],
		);

		$rounding = self::rounding_difference( $b );

		// Without this row the listed amounts would not add up to the total.
		if ( 0.0 !== $rounding ) {
			$rows[] = array( 'گرد کردن', $rounding );
		}

		return apply_filters( 'goldmate_breakdown_rows', $rows, $b );
	}

	/**
	 * The amount rounding added to, or took off, the sum of the parts.
	 *
	 * @param array $b Breakdown from calculate().
	 * @return float Signed difference, or 0.0 when the total is exact.
	 */
	public static function rounding_difference( $b ) {

		$parts = $b['gold'] + $b['wage'] + $b['profit'] + $b['accessories'] + $b['tax'];

		$difference = round( $b['total'] - $parts, 2 );

		return ( abs( $difference ) < 0.01 ) ? 0.0 : (float) $difference;
	}

	/**
	 * The pricing formula written out in words.
	 *
	 * @param array $b Breakdown from calculate().
	 * @return string
	 */
	public static function formula_symbolic( $b ) {

		$parts = array( 'وزن × قیمت هر گرم', 'اجرت', 'سود' );

		if ( $b['accessories'] > 0 ) {
			$parts[] = 'متعلقات';
		}

		$parts[] = 'مالیات';

		return 'قیمت نهایی = ' . implode( ' + ', $parts );
	}

	/**
	 * The same formula with this product's own numbers filled in.
	 *
	 * @param array $b Breakdown from calculate().
	 * @return string
	 */
	public static function formula_numeric( $b ) {

		$parts = array(
			sprintf(
				'(%s گرم × %s)',
				wc_format_localized_decimal( $b['weight'] ),
				goldmate_plain_price( $b['rate'] )
			),
			goldmate_plain_price( $b['wage'] ),
			goldmate_plain_price( $b['profit'] ),
		);

		if ( $b['accessories'] > 0 ) {
			$parts[] = goldmate_plain_price( $b['accessories'] );
		}

		$parts[] = goldmate_plain_price( $b['tax'] );

		$expression = implode( ' + ', $parts );

		$rounding = self::rounding_difference( $b );

		// Rounding can go either way, so it carries its own sign.
		if ( 0.0 !== $rounding ) {
			$expression .= ( $rounding < 0 ? ' − ' : ' + ' ) . goldmate_plain_price( abs( $rounding ) );
		}

		return $expression . ' = ' . goldmate_plain_price( $b['total'] );
	}
}
