<?php
/**
 * GoldMate Variation Selector
 *
 * Frontend-only data provider for:
 * - GoldMate variation weights
 * - Simple product GoldMate weight
 *
 * IMPORTANT:
 * This class does NOT calculate or modify prices.
 * It does NOT modify Goldmate_Calculator.
 * It does NOT modify Goldmate_Pricing.
 */

if (!defined('ABSPATH')) {
	exit;
}

class Goldmate_Variation_Selector {

	/**
	 * Initialize hooks.
	 */
	public static function init() {

		/*
		 * Add GoldMate weight to WooCommerce variation JSON.
		 *
		 * High priority is intentional so another filter is less
		 * likely to overwrite/remove our custom field.
		 */
		add_filter(
			'woocommerce_available_variation',
			array(__CLASS__, 'add_variation_weight'),
			999,
			3
		);

		/*
		 * Provide simple-product GoldMate weight to JavaScript.
		 */
		add_action(
			'wp_footer',
			array(__CLASS__, 'output_frontend_data'),
			99
		);
	}

	/**
	 * Add GoldMate weight to WooCommerce variation data.
	 *
	 * @param array                $data
	 * @param WC_Product_Variable  $product
	 * @param WC_Product_Variation $variation
	 *
	 * @return array
	 */
	public static function add_variation_weight(
		$data,
		$product,
		$variation
	) {
		if (!$variation || !is_a($variation, 'WC_Product_Variation')) {
			return $data;
		}

		$weight = $variation->get_meta(
			'_goldmate_weight',
			true
		);

		/*
		 * If variation has no weight, allow inheritance
		 * from the parent product.
		 */
		if ($weight === '' || $weight === null) {
			$weight = $product->get_meta(
				'_goldmate_weight',
				true
			);
		}

		$data['goldmate_weight'] = (
			$weight !== '' &&
			$weight !== null
		)
			? (float) $weight
			: 0;

		$breakdown = Goldmate_Calculator::calculate($variation->get_id());

		if (is_array($breakdown)) {
			$data['goldmate_gold']          = (float) $breakdown['gold'];
			$data['goldmate_gold_html']     = wc_price($breakdown['gold']);
			$data['goldmate_rate_18']       = (float) $breakdown['rate_18'];
			$data['goldmate_rate_18_html']  = wc_price($breakdown['rate_18']);
		}

		return $data;
	}

	/**
	 * Shop gold rate plus simple-product weight, for the product-page JS.
	 *
	 * This does NOT change the WooCommerce price.
	 */
	public static function output_frontend_data() {

		global $product;

		if (!is_product()) {
			return;
		}

		$rate = function_exists( 'goldmate_reference_rate' )
			? goldmate_reference_rate()
			: goldmate_positive_float( goldmate_option( 'goldmate_rate_per_gram' ) );

		// Prefer the rate item behind this product's formula when available.
		if ( $product && is_a( $product, 'WC_Product' ) && class_exists( 'Goldmate_Rate_Items' ) ) {
			$item = Goldmate_Rate_Items::for_product( (int) $product->get_id() );
			if ( $item && goldmate_positive_float( $item['rate'] ) > 0 ) {
				$rate = goldmate_positive_float( $item['rate'] );
			}
		}

		$payload = array(
			'rate'      => $rate,
			'rate_html' => $rate > 0 ? wc_price( $rate ) : '',
		);

		if ($product && is_a($product, 'WC_Product') && $product->is_type('simple')) {
			$weight = $product->get_meta('_goldmate_weight', true);

			if ($weight !== '' && $weight !== null) {
				$payload['weight'] = (float) $weight;
			}
		}

		?>
		<script>
			window.goldmatePrice = <?php echo wp_json_encode($payload); ?>;
			window.goldmateSimpleProduct = window.goldmatePrice;
		</script>
		<?php
	}
}

