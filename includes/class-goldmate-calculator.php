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
	 *   wage   = gold × wage%  OR  weight × fixed Toman/gram
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

		// Named formula → rate item. Empty product meta uses the default formula.
		$formula = class_exists( 'Goldmate_Formulas' ) ? Goldmate_Formulas::for_product( (int) $product_id ) : null;

		return self::calculate_with_formula( $inputs, $formula, (int) $product_id );
	}

	/**
	 * Prices already-resolved product inputs against a formula's rate item.
	 *
	 * Split out of calculate() so the product edit screen can preview a price
	 * from unsaved form values while going through the exact same rate, profit
	 * and tax resolution as a saved product.
	 *
	 * @param array      $inputs     Inputs shaped like resolve_inputs() returns.
	 * @param array|null $formula    Formula row, or null for the default rate item.
	 * @param int        $product_id Product or variation ID.
	 * @return array|false
	 */
	public static function calculate_with_formula( $inputs, $formula, $product_id ) {

		$item = null;

		if ( $formula ) {
			$inputs['formula_slug'] = $formula['slug'];
			$inputs['formula_id']   = (int) $formula['id'];
			$item = class_exists( 'Goldmate_Rate_Items' )
				? Goldmate_Rate_Items::get_by_slug( $formula['rate_slug'] )
				: null;
		}

		if ( ! $item && class_exists( 'Goldmate_Rate_Items' ) ) {
			$item = Goldmate_Rate_Items::get_by_slug( Goldmate_Rate_Items::DEFAULT_SLUG );
		}

		if ( $item ) {
			$rate_18 = Goldmate_Rate_Items::rate_18_for_calculator( $item );
			$inputs['rate_item']        = $item['slug'];
			$inputs['rate_item_id']     = (int) $item['id'];
			$inputs['skip_karat_scale'] = ! Goldmate_Rate_Items::uses_karat_scale( $item );

			// The rate item owns the shop profit/tax; a product override still wins.
			// A 0% item profit is a real setting, not a cue to fall back elsewhere.
			if ( '' === (string) $inputs['profit_pct'] ) {
				$inputs['profit_pct'] = $item['profit_pct'];
			}
			if ( empty( $inputs['tax_exempt'] ) && isset( $item['tax_pct'] ) && $item['tax_pct'] >= 0 ) {
				$inputs['item_tax_pct'] = $item['tax_pct'];
			}
		} else {
			$rate_18 = goldmate_positive_float( goldmate_option( 'goldmate_rate_per_gram' ) );
		}

		if ( $rate_18 <= 0 ) {
			return false;
		}

		return self::calculate_from_inputs( $inputs, $rate_18, (int) $product_id );
	}

	/**
	 * Runs the formula from already-resolved inputs (products or visitor calculator).
	 *
	 * @param array $inputs     weight, karat, wage_mode, wage_pct, wage_fixed, accessories.
	 *                          Ad-hoc callers may also pass `tax_pct`, `tax_accessories`
	 *                          and `profit_accessories` to override the shop settings.
	 * @param float $rate_18    Toman per gram of 18-karat gold.
	 * @param int   $product_id Optional product ID for filters; 0 for ad-hoc.
	 * @return array|false
	 */
	public static function calculate_from_inputs( $inputs, $rate_18, $product_id = 0 ) {

		$rate_18 = goldmate_positive_float( $rate_18 );
		$weight  = goldmate_positive_float( isset( $inputs['weight'] ) ? $inputs['weight'] : 0 );
		$karat   = goldmate_positive_float( isset( $inputs['karat'] ) ? $inputs['karat'] : 18 );

		if ( $rate_18 <= 0 || $weight <= 0 ) {
			return false;
		}

		if ( $karat <= 0 ) {
			$karat = 18;
		}

		$wage_mode  = self::normalize_wage_mode( isset( $inputs['wage_mode'] ) ? $inputs['wage_mode'] : 'pct' );
		$wage_pct   = goldmate_positive_float( isset( $inputs['wage_pct'] ) ? $inputs['wage_pct'] : 0 );
		$wage_fixed = goldmate_positive_float( isset( $inputs['wage_fixed'] ) ? $inputs['wage_fixed'] : 0 );
		$stone      = goldmate_positive_float( isset( $inputs['stone'] ) ? $inputs['stone'] : 0 );
		$leather    = goldmate_positive_float( isset( $inputs['leather'] ) ? $inputs['leather'] : 0 );
		$accessories = goldmate_positive_float( isset( $inputs['accessories'] ) ? $inputs['accessories'] : 0 );

		if ( $accessories <= 0 && ( $stone > 0 || $leather > 0 ) ) {
			$accessories = $stone + $leather;
		}

		$profit_pct = goldmate_positive_float(
			isset( $inputs['profit_pct'] ) && '' !== (string) $inputs['profit_pct']
				? $inputs['profit_pct']
				: goldmate_option( 'goldmate_profit_pct' )
		);
		$tax_exempt      = ! empty( $inputs['tax_exempt'] );
		$tax_pct         = $tax_exempt ? 0.0 : goldmate_positive_float( goldmate_option( 'goldmate_tax_pct' ) );
		if ( ! $tax_exempt && isset( $inputs['item_tax_pct'] ) && '' !== (string) $inputs['item_tax_pct'] ) {
			$tax_pct = goldmate_positive_float( $inputs['item_tax_pct'] );
		}
		$tax_accessories = 'yes' === goldmate_option( 'goldmate_tax_accessories' );
		$tax_method      = (string) goldmate_option( 'goldmate_tax_method' );
		$tax_on_wage     = 'yes' === goldmate_option( 'goldmate_tax_on_wage' );
		$tax_on_profit   = 'yes' === goldmate_option( 'goldmate_tax_on_profit' );
		$round_to        = goldmate_positive_float( goldmate_option( 'goldmate_round_to' ) );
		$round_mode      = goldmate_option( 'goldmate_round_mode' );

		if ( 'yes' !== goldmate_option( 'goldmate_round_prices' ) ) {
			$round_to = 0;
		} elseif ( $round_to <= 0 ) {
			$round_to = goldmate_positive_float( goldmate_option( 'goldmate_strip_below' ) );
		}

		// Ad-hoc callers — the admin what-if calculator — drive these from their own
		// form instead of the shop settings. resolve_inputs() never sets them, so
		// product pricing keeps reading the options.
		$profit_accessories = ! empty( $inputs['profit_accessories'] );

		if ( ! $tax_exempt && isset( $inputs['tax_pct'] ) && '' !== (string) $inputs['tax_pct'] ) {
			$tax_pct = goldmate_positive_float( $inputs['tax_pct'] );
			// An explicit rate outranks the selective/none shop-wide tax method.
			$tax_method = 'all';
		}

		if ( array_key_exists( 'tax_accessories', $inputs ) ) {
			$tax_accessories = ! empty( $inputs['tax_accessories'] );
		}

		if ( 'none' === $tax_method ) {
			$tax_pct = 0.0;
		} elseif ( 'selective' === $tax_method && ! $tax_exempt ) {
			$allowed = goldmate_option( 'goldmate_tax_selective_karats' );
			if ( ! is_array( $allowed ) ) {
				$allowed = array();
			}
			$karat_key = (string) (int) round( $karat );
			if ( ! in_array( $karat_key, array_map( 'strval', $allowed ), true ) ) {
				$tax_pct = 0.0;
			}
		}

		// Explicit discounts (the edit-screen preview) outrank the stored ones.
		$discounts = array( 'enabled' => false, 'items' => array() );
		if ( ! empty( $inputs['discounts'] ) && is_array( $inputs['discounts'] ) ) {
			$discounts = $inputs['discounts'];
		} elseif ( $product_id > 0 && class_exists( 'Goldmate_Discounts' ) ) {
			$discounts = Goldmate_Discounts::resolve( $product_id );
		}

		// Karat scales linearly against the 18-karat reference (750/1000 purity).
		if ( ! empty( $inputs['skip_karat_scale'] ) ) {
			$rate = $rate_18;
		} else {
			$rate = $rate_18 * ( $karat / 18 );
		}
		$rate_before_discount = $rate;
		$discount_rate = 0.0;

		if ( class_exists( 'Goldmate_Discounts' ) && Goldmate_Discounts::is_active( $discounts, 'rate' ) ) {
			$d = Goldmate_Discounts::apply_one(
				$rate,
				$discounts['items']['rate']['type'],
				$discounts['items']['rate']['amount']
			);
			$rate          = $d['amount'];
			$discount_rate = $d['discount'];
		}

		$gold = $weight * $rate;
		$gold_before_discount = $gold;

		if ( 'fixed' === $wage_mode ) {
			$wage = $weight * $wage_fixed;
		} elseif ( 'combined' === $wage_mode ) {
			$wage = ( $weight * $wage_fixed ) + ( $gold * ( $wage_pct / 100 ) );
		} else {
			$wage = $gold * ( $wage_pct / 100 );
		}
		$wage_before_discount = $wage;

		// Custom formula components (before profit so they can feed into it).
		$components = array(
			'lines'       => array(),
			'total'       => 0.0,
			'taxable'     => 0.0,
			'profit_base' => 0.0,
		);
		if ( class_exists( 'Goldmate_Components' ) ) {
			$components = Goldmate_Components::compute( $inputs, $gold, $wage, $product_id );
		}

		$profit_base = $gold + $wage + $components['profit_base'];
		if ( $profit_accessories ) {
			$profit_base += $accessories;
		}

		$profit = $profit_base * ( $profit_pct / 100 );
		$profit_before_discount = $profit;

		$accessories_before = $accessories;
		if ( class_exists( 'Goldmate_Discounts' ) && Goldmate_Discounts::is_active( $discounts, 'accessories_per_gram' ) ) {
			$per = $discounts['items']['accessories_per_gram'];
			$cut = Goldmate_Discounts::apply_one(
				$accessories,
				'fixed' === $per['type'] ? 'fixed' : 'pct',
				'fixed' === $per['type'] ? ( $per['amount'] * $weight ) : $per['amount']
			);
			$accessories = $cut['amount'];
		}

		$discount_map = array(
			'rate'                 => $discount_rate * $weight,
			'wage'                 => 0.0,
			'profit'               => 0.0,
			'gold'                 => 0.0,
			'accessories'          => 0.0,
			'gold_and_accessories' => 0.0,
			'accessories_per_gram' => max( 0, $accessories_before - $accessories ),
			'total'                => 0.0,
		);

		if ( class_exists( 'Goldmate_Discounts' ) ) {
			if ( Goldmate_Discounts::is_active( $discounts, 'wage' ) ) {
				$d = Goldmate_Discounts::apply_one( $wage, $discounts['items']['wage']['type'], $discounts['items']['wage']['amount'] );
				$wage                 = $d['amount'];
				$discount_map['wage'] = $d['discount'];
			}
			if ( Goldmate_Discounts::is_active( $discounts, 'profit' ) ) {
				$d = Goldmate_Discounts::apply_one( $profit, $discounts['items']['profit']['type'], $discounts['items']['profit']['amount'] );
				$profit                 = $d['amount'];
				$discount_map['profit'] = $d['discount'];
			}
			if ( Goldmate_Discounts::is_active( $discounts, 'gold' ) ) {
				$d = Goldmate_Discounts::apply_one( $gold, $discounts['items']['gold']['type'], $discounts['items']['gold']['amount'] );
				$gold                 = $d['amount'];
				$discount_map['gold'] = $d['discount'];
			}
			if ( Goldmate_Discounts::is_active( $discounts, 'accessories' ) ) {
				$d = Goldmate_Discounts::apply_one( $accessories, $discounts['items']['accessories']['type'], $discounts['items']['accessories']['amount'] );
				$discount_map['accessories'] = $d['discount'];
				$accessories                 = $d['amount'];
			}
			if ( Goldmate_Discounts::is_active( $discounts, 'gold_and_accessories' ) ) {
				$base = $gold + $accessories;
				$d    = Goldmate_Discounts::apply_one( $base, $discounts['items']['gold_and_accessories']['type'], $discounts['items']['gold_and_accessories']['amount'] );
				$discount_map['gold_and_accessories'] = $d['discount'];
				if ( $base > 0 ) {
					$ratio       = $d['amount'] / $base;
					$gold        = $gold * $ratio;
					$accessories = $accessories * $ratio;
				}
			}
		}

		$taxable = $components['taxable'];
		if ( $tax_on_wage ) {
			$taxable += $wage;
		}
		if ( $tax_on_profit ) {
			$taxable += $profit;
		}
		if ( $tax_accessories ) {
			$taxable += $accessories;
		}

		$tax = $taxable * ( $tax_pct / 100 );

		$total = $gold + $wage + $profit + $accessories + $components['total'] + $tax;

		if ( class_exists( 'Goldmate_Discounts' ) && Goldmate_Discounts::is_active( $discounts, 'total' ) ) {
			$d = Goldmate_Discounts::apply_one( $total, $discounts['items']['total']['type'], $discounts['items']['total']['amount'] );
			$discount_map['total'] = $d['discount'];
			$total                 = $d['amount'];
		}

		$total = self::round_total( $total, $round_to, $round_mode );

		$discount_total = 0.0;
		foreach ( $discount_map as $amt ) {
			$discount_total += (float) $amt;
		}

		$breakdown = array(
			'product_id'            => (int) $product_id,
			'weight'                => $weight,
			'karat'                 => $karat,
			'formula_slug'          => isset( $inputs['formula_slug'] ) ? (string) $inputs['formula_slug'] : '',
			'formula_id'            => isset( $inputs['formula_id'] ) ? (int) $inputs['formula_id'] : 0,
			'rate_18'               => $rate_18,
			'rate'                  => $rate,
			'rate_before_discount'  => $rate_before_discount,
			'gold'                  => $gold,
			'gold_before_discount'  => $gold_before_discount,
			'wage'                  => $wage,
			'wage_before_discount'  => $wage_before_discount,
			'wage_mode'             => $wage_mode,
			'wage_pct'              => $wage_pct,
			'wage_fixed'            => $wage_fixed,
			'profit'                => $profit,
			'profit_before_discount'=> $profit_before_discount,
			'profit_pct'            => $profit_pct,
			'stone'                 => $stone,
			'leather'               => $leather,
			'accessories'           => $accessories,
			'accessories_before_discount' => $accessories_before,
			'components'            => $components['lines'],
			'components_total'      => $components['total'],
			'tax'                   => $tax,
			'tax_pct'               => $tax_pct,
			'tax_exempt'            => $tax_exempt,
			'tax_accessories'       => $tax_accessories,
			'tax_on_wage'           => $tax_on_wage,
			'tax_on_profit'         => $tax_on_profit,
			'profit_accessories'    => $profit_accessories,
			'rate_item'             => isset( $inputs['rate_item'] ) ? (string) $inputs['rate_item'] : '',
			'skip_karat_scale'      => ! empty( $inputs['skip_karat_scale'] ),
			'discounts_enabled'     => ! empty( $discounts['enabled'] ),
			'discount_map'          => $discount_map,
			'discount_total'        => $discount_total,
			'total'                 => $total,
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
	 * Normalises a wage mode string to `pct`, `fixed`, or `combined`.
	 *
	 * @param mixed $mode Raw mode.
	 * @return string
	 */
	public static function normalize_wage_mode( $mode ) {

		$mode = (string) $mode;

		if ( 'fixed' === $mode || 'combined' === $mode ) {
			return $mode;
		}

		return 'pct';
	}

	/**
	 * Human-readable wage row label for a breakdown.
	 *
	 * @param array $b Breakdown from calculate().
	 * @return string
	 */
	public static function wage_label( $b ) {

		$mode = self::normalize_wage_mode( isset( $b['wage_mode'] ) ? $b['wage_mode'] : 'pct' );

		if ( 'fixed' === $mode ) {
			return sprintf(
				'اجرت (%s تومان/گرم)',
				wc_format_localized_decimal( isset( $b['wage_fixed'] ) ? $b['wage_fixed'] : 0 )
			);
		}

		if ( 'combined' === $mode ) {
			return sprintf(
				'اجرت ترکیبی (%s تومان/گرم + %s٪)',
				wc_format_localized_decimal( isset( $b['wage_fixed'] ) ? $b['wage_fixed'] : 0 ),
				wc_format_localized_decimal( isset( $b['wage_pct'] ) ? $b['wage_pct'] : 0 )
			);
		}

		return sprintf( 'اجرت (%s٪)', wc_format_localized_decimal( isset( $b['wage_pct'] ) ? $b['wage_pct'] : 0 ) );
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

		$read_mode = function () use ( $product_id, $owner_id, $is_variant ) {

			if ( $is_variant ) {
				$own = get_post_meta( $product_id, '_goldmate_wage_mode', true );
				if ( '' !== trim( (string) $own ) ) {
					return self::normalize_wage_mode( $own );
				}
			}

			$parent_mode = get_post_meta( $owner_id, '_goldmate_wage_mode', true );

			if ( '' !== trim( (string) $parent_mode ) ) {
				return self::normalize_wage_mode( $parent_mode );
			}

			return self::normalize_wage_mode( goldmate_option( 'goldmate_default_wage_mode' ) );
		};

		$weight = $read( '_goldmate_weight' );

		if ( $weight <= 0 ) {
			return false;
		}

		$karat = $read( '_goldmate_karat' );

		if ( $karat <= 0 ) {
			$karat = 18;
		}

		$stone   = $read( '_goldmate_stone' );
		$leather = $read( '_goldmate_leather' );
		$accessories = $read( '_goldmate_accessories' );

		if ( $accessories <= 0 && ( $stone > 0 || $leather > 0 ) ) {
			$accessories = $stone + $leather;
		} elseif ( $stone <= 0 && $leather <= 0 && $accessories > 0 ) {
			// Legacy products stored a single accessories total as "stone" for display.
			$stone = $accessories;
		}

		$profit_meta = '';
		if ( $is_variant ) {
			$own_profit = get_post_meta( $product_id, '_goldmate_profit_pct', true );
			if ( '' !== trim( (string) $own_profit ) ) {
				$profit_meta = $own_profit;
			}
		}
		if ( '' === $profit_meta ) {
			$profit_meta = get_post_meta( $owner_id, '_goldmate_profit_pct', true );
		}

		$tax_exempt = 'yes' === get_post_meta( $owner_id, '_goldmate_tax_exempt', true );

		return array(
			'weight'      => $weight,
			'karat'       => $karat,
			'wage_mode'   => $read_mode(),
			'wage_pct'    => $read( '_goldmate_wage_pct' ),
			'wage_fixed'  => $read( '_goldmate_wage_fixed' ),
			'stone'       => $stone,
			'leather'     => $leather,
			'accessories' => $accessories,
			'profit_pct'  => '' !== trim( (string) $profit_meta ) ? goldmate_positive_float( $profit_meta ) : '',
			'tax_exempt'  => $tax_exempt,
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

		$map = ( ! empty( $b['discount_map'] ) && is_array( $b['discount_map'] ) ) ? $b['discount_map'] : array();

		$gold_label = sprintf(
			'مبلغ طلا (%s گرم عیار %s × %s)',
			wc_format_localized_decimal( $b['weight'] ),
			wc_format_localized_decimal( $b['karat'] ),
			goldmate_plain_price( $b['rate'] )
		);
		if ( ! empty( $map['rate'] ) || ! empty( $map['gold'] ) ) {
			$gold_label .= ' — با تخفیف';
		}

		$wage_label = self::wage_label( $b );
		if ( ! empty( $map['wage'] ) ) {
			$wage_label .= ' — با تخفیف';
		}

		$profit_label = sprintf( 'سود (%s٪)', wc_format_localized_decimal( $b['profit_pct'] ) );
		if ( ! empty( $map['profit'] ) ) {
			$profit_label .= ' — با تخفیف';
		}

		$rows = array(
			array( $gold_label, $b['gold'] ),
			array( $wage_label, $b['wage'] ),
			array( $profit_label, $b['profit'] ),
		);

		if ( $b['accessories'] > 0 ) {
			$has_split = ( ! empty( $b['stone'] ) && $b['stone'] > 0 )
				|| ( ! empty( $b['leather'] ) && $b['leather'] > 0 );
			$acc_discounted = ! empty( $map['accessories'] )
				|| ! empty( $map['accessories_per_gram'] )
				|| ! empty( $map['gold_and_accessories'] );
			$disc_note = $acc_discounted ? ' — با تخفیف' : '';

			// Prefer stone/leather lines when both (or either) are set so the storefront shows every item.
			if ( $has_split ) {
				$stone   = ! empty( $b['stone'] ) ? (float) $b['stone'] : 0.0;
				$leather = ! empty( $b['leather'] ) ? (float) $b['leather'] : 0.0;
				$raw_sum = $stone + $leather;
				$acc     = (float) $b['accessories'];

				// When an accessories discount reduced the combined total, scale lines proportionally.
				$scale = ( $raw_sum > 0 && abs( $raw_sum - $acc ) > 0.01 ) ? ( $acc / $raw_sum ) : 1.0;

				if ( $stone > 0 ) {
					$rows[] = array( 'سنگ' . $disc_note, round( $stone * $scale, 2 ) );
				}
				if ( $leather > 0 ) {
					$rows[] = array( 'چرم و یراق' . $disc_note, round( $leather * $scale, 2 ) );
				}
			} else {
				$rows[] = array( 'ملحقات' . $disc_note, $b['accessories'] );
			}
		}

		if ( ! empty( $b['components'] ) && is_array( $b['components'] ) ) {
			foreach ( $b['components'] as $line ) {
				$rows[] = array( $line['label'], $line['amount'] );
			}
		}

		if ( empty( $b['tax_exempt'] ) || $b['tax'] > 0 ) {
			$tax_base = $b['tax_accessories'] ? 'اجرت، سود و متعلقات' : 'اجرت و سود';

			$rows[] = array(
				sprintf( 'مالیات بر ارزش افزوده (%s٪ %s)', wc_format_localized_decimal( $b['tax_pct'] ), $tax_base ),
				$b['tax'],
			);
		}

		if ( ! empty( $b['discount_total'] ) && (float) $b['discount_total'] > 0 ) {
			$rows[] = array(
				sprintf( 'جمع تخفیف اعمال‌شده (%s)', goldmate_plain_price( $b['discount_total'] ) ),
				0,
			);
		}

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
		if ( ! empty( $b['components_total'] ) ) {
			$parts += (float) $b['components_total'];
		}
		// Discount total is already reflected inside reduced component amounts,
		// except a pure "total" discount which sits outside the parts.
		if ( ! empty( $b['discount_map']['total'] ) ) {
			$parts -= (float) $b['discount_map']['total'];
		}

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
