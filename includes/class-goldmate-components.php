<?php
/**
 * Shop-defined custom price components added into the gold formula.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Components {

	const OPTION = 'goldmate_price_components';

	/**
	 * Allowed calculation modes for a component.
	 *
	 * @return array<string,string>
	 */
	public static function calc_modes() {
		return array(
			'fixed'         => 'مبلغ ثابت (تومان)',
			'per_gram'      => 'به ازای هر گرم (تومان)',
			'pct_gold'      => 'درصدی از مبلغ طلا',
			'pct_wage'      => 'درصدی از اجرت',
			'pct_subtotal'  => 'درصدی از (طلا + اجرت)',
		);
	}

	/**
	 * All saved components, newest last.
	 *
	 * @return array[]
	 */
	public static function all() {

		$items = get_option( self::OPTION, array() );

		if ( ! is_array( $items ) ) {
			return array();
		}

		$out = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) || empty( $item['label'] ) ) {
				continue;
			}
			$out[] = self::normalize( $item );
		}

		return $out;
	}

	/**
	 * Normalises one component row.
	 *
	 * @param array $item Raw item.
	 * @return array
	 */
	public static function normalize( $item ) {

		$modes = self::calc_modes();
		$calc  = isset( $item['calc'] ) ? (string) $item['calc'] : 'fixed';
		if ( ! isset( $modes[ $calc ] ) ) {
			$calc = 'fixed';
		}

		return array(
			'id'        => sanitize_key( $item['id'] ),
			'label'     => sanitize_text_field( $item['label'] ),
			'calc'      => $calc,
			'default'   => goldmate_positive_float( isset( $item['default'] ) ? $item['default'] : 0 ),
			'taxable'   => ( isset( $item['taxable'] ) && 'yes' === $item['taxable'] ) ? 'yes' : 'no',
			'in_profit' => ( isset( $item['in_profit'] ) && 'yes' === $item['in_profit'] ) ? 'yes' : 'no',
			'enabled'   => ( ! isset( $item['enabled'] ) || 'yes' === $item['enabled'] ) ? 'yes' : 'no',
		);
	}

	/**
	 * Saves the full component list from the admin form.
	 *
	 * @param array $post Raw $_POST.
	 */
	public static function save_from_post( $post ) {

		$labels   = isset( $post['goldmate_comp_label'] ) && is_array( $post['goldmate_comp_label'] ) ? $post['goldmate_comp_label'] : array();
		$calcs    = isset( $post['goldmate_comp_calc'] ) && is_array( $post['goldmate_comp_calc'] ) ? $post['goldmate_comp_calc'] : array();
		$defaults = isset( $post['goldmate_comp_default'] ) && is_array( $post['goldmate_comp_default'] ) ? $post['goldmate_comp_default'] : array();
		$ids      = isset( $post['goldmate_comp_id'] ) && is_array( $post['goldmate_comp_id'] ) ? $post['goldmate_comp_id'] : array();
		$taxable  = isset( $post['goldmate_comp_taxable'] ) && is_array( $post['goldmate_comp_taxable'] ) ? $post['goldmate_comp_taxable'] : array();
		$profit   = isset( $post['goldmate_comp_in_profit'] ) && is_array( $post['goldmate_comp_in_profit'] ) ? $post['goldmate_comp_in_profit'] : array();
		$enabled  = isset( $post['goldmate_comp_enabled'] ) && is_array( $post['goldmate_comp_enabled'] ) ? $post['goldmate_comp_enabled'] : array();

		$items = array();

		foreach ( $labels as $i => $label ) {
			$label = sanitize_text_field( wp_unslash( $label ) );
			if ( '' === trim( $label ) ) {
				continue;
			}

			$id = isset( $ids[ $i ] ) ? sanitize_key( wp_unslash( $ids[ $i ] ) ) : '';
			if ( '' === $id ) {
				$id = 'comp_' . substr( md5( $label . microtime( true ) . $i ), 0, 10 );
			}

			$items[] = self::normalize(
				array(
					'id'        => $id,
					'label'     => $label,
					'calc'      => isset( $calcs[ $i ] ) ? sanitize_text_field( wp_unslash( $calcs[ $i ] ) ) : 'fixed',
					'default'   => isset( $defaults[ $i ] ) ? wp_unslash( $defaults[ $i ] ) : 0,
					'taxable'   => ! empty( $taxable[ $i ] ) ? 'yes' : 'no',
					'in_profit' => ! empty( $profit[ $i ] ) ? 'yes' : 'no',
					'enabled'   => ! empty( $enabled[ $i ] ) ? 'yes' : 'no',
				)
			);
		}

		update_option( self::OPTION, $items, false );
	}

	/**
	 * Product overrides for component input values (the "amount" field meaning depends on calc mode).
	 *
	 * @param int $product_id Product or variation ID.
	 * @return array<string,float>
	 */
	public static function product_values( $product_id ) {

		$product_id = (int) $product_id;
		$parent_id  = wp_get_post_parent_id( $product_id );
		$owner_id   = $parent_id ? $parent_id : $product_id;

		$values = array();

		if ( $parent_id && 'product_variation' === get_post_type( $product_id ) ) {
			$own = get_post_meta( $product_id, '_goldmate_component_values', true );
			if ( is_array( $own ) ) {
				foreach ( $own as $id => $val ) {
					if ( '' !== trim( (string) $val ) ) {
						$values[ sanitize_key( $id ) ] = goldmate_positive_float( $val );
					}
				}
			}
		}

		$parent_vals = get_post_meta( $owner_id, '_goldmate_component_values', true );
		if ( is_array( $parent_vals ) ) {
			foreach ( $parent_vals as $id => $val ) {
				$id = sanitize_key( $id );
				if ( ! array_key_exists( $id, $values ) ) {
					$values[ $id ] = goldmate_positive_float( $val );
				}
			}
		}

		return $values;
	}

	/**
	 * Computes component line amounts for a pricing pass.
	 *
	 * @param array $inputs     Calculator inputs (weight, etc.).
	 * @param float $gold       Gold amount.
	 * @param float $wage       Wage amount (pre-discount).
	 * @param int   $product_id Product ID.
	 * @return array{lines:array[],total:float,taxable:float,profit_base:float}
	 */
	public static function compute( $inputs, $gold, $wage, $product_id = 0 ) {

		$weight = goldmate_positive_float( isset( $inputs['weight'] ) ? $inputs['weight'] : 0 );
		$values = $product_id ? self::product_values( $product_id ) : array();
		if ( isset( $inputs['component_values'] ) && is_array( $inputs['component_values'] ) ) {
			$values = array_merge( $values, $inputs['component_values'] );
		}

		$lines       = array();
		$total       = 0.0;
		$taxable     = 0.0;
		$profit_base = 0.0;

		foreach ( self::all() as $comp ) {
			if ( 'yes' !== $comp['enabled'] ) {
				continue;
			}

			$input = array_key_exists( $comp['id'], $values )
				? goldmate_positive_float( $values[ $comp['id'] ] )
				: $comp['default'];

			if ( $input <= 0 && 'fixed' !== $comp['calc'] ) {
				// Zero input means skip for percentage / per-gram modes.
				if ( $input <= 0 ) {
					continue;
				}
			}

			switch ( $comp['calc'] ) {
				case 'per_gram':
					$amount = $weight * $input;
					break;
				case 'pct_gold':
					$amount = $gold * ( $input / 100 );
					break;
				case 'pct_wage':
					$amount = $wage * ( $input / 100 );
					break;
				case 'pct_subtotal':
					$amount = ( $gold + $wage ) * ( $input / 100 );
					break;
				case 'fixed':
				default:
					$amount = $input;
					break;
			}

			$amount = goldmate_positive_float( $amount );
			if ( $amount <= 0 ) {
				continue;
			}

			$line = array(
				'id'     => $comp['id'],
				'label'  => $comp['label'],
				'calc'   => $comp['calc'],
				'input'  => $input,
				'amount' => $amount,
			);
			$lines[] = $line;
			$total  += $amount;

			if ( 'yes' === $comp['taxable'] ) {
				$taxable += $amount;
			}
			if ( 'yes' === $comp['in_profit'] ) {
				$profit_base += $amount;
			}
		}

		return array(
			'lines'       => $lines,
			'total'       => $total,
			'taxable'     => $taxable,
			'profit_base' => $profit_base,
		);
	}
}
