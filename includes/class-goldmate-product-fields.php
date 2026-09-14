<?php
/**
 * Per-product and per-variation calculation inputs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Product_Fields {

	/**
	 * Registers the product and variation field UI and their save handlers.
	 */
	public static function init() {
		
		add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'render_product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_fields' ), 10 );

		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_fields' ), 10, 2 );
		add_filter(
			'woocommerce_available_variation',
			array(__CLASS__, 'add_goldmate_variation_data'),
			10,
			3
		);
	}

	/**
	 * Renders the gold fields on the product data pricing panel.
	 */
	public static function render_product_fields() {

		echo '<div class="options_group goldmate-fields">';
		echo '<p style="margin:0 12px 6px;font-weight:600;">محاسبه قیمت طلا</p>';

		woocommerce_wp_checkbox(
			array(
				'id'          => '_goldmate_enabled',
				'label'       => 'محاسبه خودکار',
				'description' => 'قیمت این محصول از روی وزن و قیمت روز طلا محاسبه شود. برای محصولات متغیر، همه‌ی متغیرها را پوشش می‌دهد.',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_goldmate_weight',
				'label'             => 'وزن (گرم)',
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '0.001',
					'min'  => '0',
				),
				'description'       => 'وزن خالص طلا بدون متعلقات. برای محصولات متغیر، مقدار پیش‌فرض همه‌ی متغیرها.',
				'desc_tip'          => true,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_goldmate_karat',
				'label'             => 'عیار',
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '0.1',
					'min'  => '0',
				),
				'placeholder'       => '18',
				'description'       => 'خالی بگذارید تا ۱۸ در نظر گرفته شود.',
				'desc_tip'          => true,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_goldmate_wage_pct',
				'label'             => 'اجرت (٪)',
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
				'description'       => 'درصد اجرت نسبت به مبلغ طلا. مثال: 15',
				'desc_tip'          => true,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_goldmate_accessories',
				'label'             => 'متعلقات (تومان)',
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '1',
					'min'  => '0',
				),
				'description'       => 'ارزش سنگ، نگین و سایر متعلقات. مشمول سود نمی‌شود.',
				'desc_tip'          => true,
			)
		);

		echo '</div>';
	}

	/**
	 * Saves the product-level fields, clamping every number at zero.
	 *
	 * The `min` attribute on the inputs is advisory only, and REST or import
	 * payloads never see it at all, so the clamp has to happen here.
	 *
	 * @param int $post_id Product ID.
	 */
	public static function save_product_fields( $post_id ) {

		// WooCommerce verifies the nonce for this hook before it fires.
		$enabled = isset( $_POST['_goldmate_enabled'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, '_goldmate_enabled', $enabled );

		foreach ( goldmate_numeric_meta_keys() as $key ) {

			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			$raw = wc_clean( wp_unslash( $_POST[ $key ] ) );

			if ( '' === trim( (string) $raw ) ) {
				update_post_meta( $post_id, $key, '' );
				continue;
			}

			update_post_meta( $post_id, $key, goldmate_positive_float( $raw ) );
		}
	}

	/**
	 * Renders the per-variation overrides.
	 *
	 * @param int     $loop           Variation index in the form.
	 * @param array   $variation_data Legacy variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function render_variation_fields( $loop, $variation_data, $variation ) {

		$parent_id = $variation->post_parent;
		$enabled   = 'yes' === get_post_meta( $parent_id, '_goldmate_enabled', true );

		echo '<div class="goldmate-variation-fields" style="clear:both;padding-top:6px;">';

		printf(
			'<p style="margin:0 0 6px;font-weight:600;">محاسبه قیمت طلا%s</p>',
			$enabled ? '' : ' <span style="font-weight:400;color:#b32d2e;">(در محصول اصلی غیرفعال است)</span>'
		);

		woocommerce_wp_checkbox(
			array(
				'id'            => "_goldmate_excluded{$loop}",
				'name'          => "_goldmate_excluded[{$loop}]",
				'value'         => get_post_meta( $variation->ID, '_goldmate_excluded', true ),
				'label'         => 'این متغیر محاسبه نشود',
				'description'   => 'قیمت این متغیر دستی می‌ماند.',
				'wrapper_class' => 'form-row form-row-full',
			)
		);

		$fields = array(
			'_goldmate_weight'      => array( 'وزن (گرم)', '0.001', 'form-row form-row-first' ),
			'_goldmate_karat'       => array( 'عیار', '0.1', 'form-row form-row-last' ),
			'_goldmate_wage_pct'    => array( 'اجرت (٪)', '0.01', 'form-row form-row-first' ),
			'_goldmate_accessories' => array( 'متعلقات (تومان)', '1', 'form-row form-row-last' ),
		);

		foreach ( $fields as $key => $field ) {

			list( $label, $step, $wrapper ) = $field;

			$inherited = get_post_meta( $parent_id, $key, true );

			woocommerce_wp_text_input(
				array(
					'id'                => "{$key}{$loop}",
					'name'              => "{$key}[{$loop}]",
					'value'             => get_post_meta( $variation->ID, $key, true ),
					'label'             => $label,
					'type'              => 'number',
					'placeholder'       => '' !== trim( (string) $inherited ) ? $inherited : 'ارثی از محصول اصلی',
					'custom_attributes' => array(
						'step' => $step,
						'min'  => '0',
					),
					'wrapper_class'     => $wrapper,
					'description'       => 'خالی بگذارید تا از محصول اصلی به ارث برسد.',
					'desc_tip'          => true,
				)
			);
		}

		echo '</div>';
	}

	/**
	 * Saves the per-variation overrides.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Variation index in the form.
	 */
	public static function save_variation_fields( $variation_id, $loop ) {

		// WooCommerce verifies the nonce for this hook before it fires.
		$excluded = isset( $_POST['_goldmate_excluded'][ $loop ] ) ? 'yes' : 'no';
		update_post_meta( $variation_id, '_goldmate_excluded', $excluded );

		foreach ( goldmate_numeric_meta_keys() as $key ) {

			if ( ! isset( $_POST[ $key ][ $loop ] ) ) {
				continue;
			}

			$raw = wc_clean( wp_unslash( $_POST[ $key ][ $loop ] ) );

			// An empty override means "inherit from the parent".
			if ( '' === trim( (string) $raw ) ) {
				delete_post_meta( $variation_id, $key );
				continue;
			}

			update_post_meta( $variation_id, $key, goldmate_positive_float( $raw ) );
		}
	}
	public static function add_goldmate_variation_data(
		$data,
		$product,
		$variation
	) {

		$weight = get_post_meta(
			$variation->get_id(),
			'_goldmate_weight',
			true
		);

		/*
		* If variation doesn't have its own weight,
		* use the parent product weight.
		*/
		if ($weight === '') {

			$weight = get_post_meta(
				$product->get_id(),
				'_goldmate_weight',
				true
			);

		}

		$data['goldmate_weight'] = $weight !== ''
			? (float) $weight
			: 0;

		return $data;
	}
}
