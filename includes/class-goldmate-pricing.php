<?php
/**
 * Writes calculated prices onto products and keeps them in sync with every save path.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Pricing {

	/**
	 * Product IDs currently being repriced, keyed by ID.
	 *
	 * Saving a product fires `woocommerce_update_product`, which is the very hook
	 * used to trigger repricing. Without this guard the two would recurse.
	 *
	 * @var array<int,bool>
	 */
	protected static $in_progress = array();

	/**
	 * Successful calculation signatures, keyed by product or variation ID.
	 *
	 * One request can reach apply() several times for the same product: the
	 * admin form saves the product (CRUD hook) and then fires
	 * `woocommerce_process_product_meta`, and parent syncs save the children
	 * again. Skip a repeated calculation only while its stored prices still
	 * match. Unlike a once-per-product flag, this permits new children and
	 * changed inputs/rates later in the same request without a save loop.
	 * Failed writes are never recorded, so they remain retryable.
	 *
	 * @var array<int,string>
	 */
	protected static $repriced = array();

	/**
	 * Registers every save path that should trigger a recalculation.
	 */
	public static function init() {

		// Admin product screen.
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'on_product_meta_saved' ), 20 );

		// CRUD saves: REST API, WP-CLI, third-party plugins, programmatic updates.
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_saved' ), 20, 2 );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_product_saved' ), 20, 2 );

		// Variation saves.
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'on_variation_saved' ), 20, 2 );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'on_variation_saved' ), 20, 2 );

		// The classic admin "Save changes" button in the Variations panel fires
		// this after woocommerce_update_product_variation. Gold values are no
		// longer edited there (see Goldmate_Variation_Table), but other plugins
		// may still write them on this hook, so reprice once more afterwards.
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'on_variation_saved' ), 20, 2 );

		// CSV importer.
		add_action( 'woocommerce_product_import_inserted_product_object', array( __CLASS__, 'on_product_imported' ), 20, 2 );
	}

	/**
	 * Prices a product, including every variation of a variable product.
	 *
	 * @param int $product_id Product ID.
	 * @return int|false Number of priced entities, or false when the product is not a gold product.
	 */
	public static function apply( $product_id ) {

		$product_id = (int) $product_id;

		if ( isset( self::$in_progress[ $product_id ] ) ) {
			return false;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return false;
		}

		if ( $product->is_type( 'variable' ) ) {
			return self::apply_variable( $product );
		}

		if ( ! $product->is_type( array( 'simple', 'variation' ) ) ) {
			// Grouped and external products carry no price of their own.
			return false;
		}

		$breakdown = Goldmate_Calculator::calculate( $product_id );

		if ( false === $breakdown ) {
			return false;
		}

		if ( self::can_skip( $product, $breakdown ) ) {
			return 0;
		}

		return self::write_price( $product, $breakdown ) ? 1 : false;
	}

	/**
	 * Prices each variation of a variable product, then resyncs the parent.
	 *
	 * @param WC_Product_Variable $parent Parent product.
	 * @return int|false
	 */
	protected static function apply_variable( $parent ) {

		$parent_id = $parent->get_id();

		if ( 'yes' !== get_post_meta( $parent_id, '_goldmate_enabled', true ) ) {
			return false;
		}

		$children = $parent->get_children();

		if ( empty( $children ) ) {
			// Not marked yet: a product saved before its variations exist must
			// be priceable later in the same request, once the children exist.
			return false;
		}

		$count = 0;

		// Syncing the parent saves it, which fires the very hook that calls us.
		self::$in_progress[ $parent_id ] = true;

		try {

			foreach ( $children as $variation_id ) {

				$breakdown = Goldmate_Calculator::calculate( $variation_id );

				if ( false === $breakdown ) {
					continue;
				}

				$variation = wc_get_product( $variation_id );

				if ( ! $variation || self::can_skip( $variation, $breakdown ) ) {
					continue;
				}

				if ( self::write_price( $variation, $breakdown ) ) {
					$count++;
				}
			}

			if ( $count > 0 ) {
				WC_Product_Variable::sync( $parent_id );
				wc_delete_product_transients( $parent_id );
			}
		} finally {
			unset( self::$in_progress[ $parent_id ] );
		}

		return $count > 0 ? $count : false;
	}

	/**
	 * Stores the calculated total on a product.
	 *
	 * The price is written rather than filtered at runtime so that price sorting,
	 * price filters, the cart and invoices all agree with the displayed figure.
	 *
	 * @param WC_Product $product   Product or variation object.
	 * @param array      $breakdown Breakdown from the calculator.
	 * @return bool
	 */
	protected static function write_price( $product, $breakdown ) {

		$id = $product->get_id();

		if ( isset( self::$in_progress[ $id ] ) ) {
			return false;
		}

		$prices = self::wc_prices( $breakdown );

		$product->set_regular_price( $prices['regular'] );
		$product->set_sale_price( $prices['sale'] );
		$product->set_price( $prices['price'] );

		// GoldMate discounts carry their own dates; a WooCommerce sale schedule
		// left on the product would switch the sale price on or off behind them.
		$product->set_date_on_sale_from( null );
		$product->set_date_on_sale_to( null );

		if ( 'none' === goldmate_option( 'goldmate_wc_tax_status' ) ) {
			// VAT is already inside the price; WooCommerce must not add it again.
			$product->set_tax_status( 'none' );
		}

		self::$in_progress[ $id ] = true;

		try {
			$product->save();
		} finally {
			unset( self::$in_progress[ $id ] );
		}

		if ( 'yes' === goldmate_option( 'goldmate_store_calc_time' ) ) {
			update_post_meta( $id, '_goldmate_priced_at', time() );
		}

		wc_delete_product_transients( $id );

		self::$repriced[ $id ] = self::signature( $breakdown );

		return true;
	}

	/**
	 * True when writing `$breakdown` onto `$product` would change nothing worth saving.
	 *
	 * With "only reprice on change" on, any product whose stored price already
	 * matches is skipped. Otherwise only a repeat of a calculation this request
	 * already wrote is skipped (see $repriced), so changed weights, rates or
	 * breakdown data are still written again within the same request.
	 *
	 * @param WC_Product $product   Product or variation.
	 * @param array      $breakdown Breakdown from the calculator.
	 * @return bool
	 */
	protected static function can_skip( $product, $breakdown ) {

		$prices = self::wc_prices( $breakdown );
		$sale   = $product->get_sale_price( 'edit' );

		$current = abs( (float) $product->get_regular_price( 'edit' ) - (float) $prices['regular'] ) < 0.5
			&& abs( (float) $product->get_price( 'edit' ) - (float) $prices['price'] ) < 0.5
			&& ( '' === $prices['sale'] ? '' === $sale : ( '' !== $sale && abs( (float) $sale - (float) $prices['sale'] ) < 0.5 ) )
			&& null === $product->get_date_on_sale_from( 'edit' )
			&& null === $product->get_date_on_sale_to( 'edit' )
			&& ( 'none' !== goldmate_option( 'goldmate_wc_tax_status' ) || 'none' === $product->get_tax_status( 'edit' ) );

		if ( ! $current ) {
			return false;
		}

		if ( 'yes' === goldmate_option( 'goldmate_recalc_only_on_change' ) ) {
			return true;
		}

		$id = $product->get_id();

		return isset( self::$repriced[ $id ] ) && self::$repriced[ $id ] === self::signature( $breakdown );
	}

	/**
	 * The WooCommerce regular/sale/active prices for a breakdown.
	 *
	 * With an active GoldMate discount (and the setting on), the undiscounted
	 * total becomes the regular price and the discounted total the sale price,
	 * so WooCommerce shows it crossed out, badges the product as on sale and
	 * lists it in sale filters. Otherwise the total is the regular price and any
	 * sale price is cleared.
	 *
	 * @param array $breakdown Breakdown from the calculator.
	 * @return array{regular:string,sale:string,price:string}
	 */
	public static function wc_prices( $breakdown ) {

		$total = (string) $breakdown['total'];
		$list  = self::list_price( $breakdown );

		if ( $list > (float) $total ) {
			return array(
				'regular' => (string) $list,
				'sale'    => $total,
				'price'   => $total,
			);
		}

		return array(
			'regular' => $total,
			'sale'    => '',
			'price'   => $total,
		);
	}

	/**
	 * Price to show crossed out: the undiscounted total, or the total itself.
	 *
	 * @param array $breakdown Breakdown from the calculator.
	 * @return float
	 */
	public static function list_price( $breakdown ) {

		$total = (float) $breakdown['total'];

		if ( 'yes' !== goldmate_option( 'goldmate_discount_as_sale' ) || empty( $breakdown['total_before_discount'] ) ) {
			return $total;
		}

		return max( $total, (float) $breakdown['total_before_discount'] );
	}

	/**
	 * Price HTML for a live-recalculated breakdown, matching what WooCommerce
	 * renders for the stored prices (crossed-out regular price when discounted).
	 *
	 * @param array $breakdown Breakdown from the calculator.
	 * @return string
	 */
	public static function price_html( $breakdown ) {

		$list = self::list_price( $breakdown );

		return $list > (float) $breakdown['total']
			? wc_format_sale_price( $list, $breakdown['total'] )
			: wc_price( $breakdown['total'] );
	}

	/**
	 * Fingerprint of a calculation and the settings write_price() applies with it.
	 *
	 * @param array $breakdown Breakdown from the calculator.
	 * @return string
	 */
	protected static function signature( $breakdown ) {
		return md5( serialize( array( $breakdown, goldmate_option( 'goldmate_wc_tax_status' ) ) ) );
	}

	/* ---------------------------------------------------------------------
	 *  Save hooks
	 * ------------------------------------------------------------------ */

	/**
	 * Handles the classic admin product form.
	 *
	 * @param int $post_id Product ID.
	 */
	public static function on_product_meta_saved( $post_id ) {

		if ( 'yes' !== goldmate_option( 'goldmate_reprice_on_item_save' )
			&& 'yes' !== goldmate_option( 'goldmate_reprice_on_product_save' ) ) {
			return;
		}

		self::apply( $post_id );
	}

	/**
	 * Handles every CRUD save, which is how the REST API and imports reach us.
	 *
	 * @param int             $product_id Product ID.
	 * @param WC_Product|null $product    Product object.
	 */
	public static function on_product_saved( $product_id, $product = null ) {

		if ( isset( self::$in_progress[ $product_id ] ) ) {
			return;
		}

		if ( 'yes' !== goldmate_option( 'goldmate_reprice_on_product_save' ) ) {
			return;
		}

		self::apply( $product_id );
	}

	/**
	 * Handles variation saves, repricing just the variation that changed.
	 *
	 * Shared by `woocommerce_update_product_variation`/`woocommerce_new_product_variation`
	 * (second arg: variation object or none) and `woocommerce_save_product_variation`
	 * (second arg: form loop index). The second argument is unused either way.
	 *
	 * @param int $variation_id Variation ID.
	 */
	public static function on_variation_saved( $variation_id ) {

		if ( isset( self::$in_progress[ $variation_id ] ) ) {
			return;
		}

		if ( 'yes' !== goldmate_option( 'goldmate_reprice_on_item_save' )
			&& 'yes' !== goldmate_option( 'goldmate_reprice_on_product_save' ) ) {
			return;
		}

		$breakdown = Goldmate_Calculator::calculate( $variation_id );

		if ( false === $breakdown ) {
			return;
		}

		$product = wc_get_product( $variation_id );

		if ( ! $product ) {
			return;
		}

		if ( self::can_skip( $product, $breakdown ) ) {
			return;
		}

		self::write_price( $product, $breakdown );

		$parent_id = $product->get_parent_id();

		if ( ! $parent_id || isset( self::$in_progress[ $parent_id ] ) ) {
			return;
		}

		self::$in_progress[ $parent_id ] = true;

		try {
			WC_Product_Variable::sync( $parent_id );
			wc_delete_product_transients( $parent_id );
		} finally {
			unset( self::$in_progress[ $parent_id ] );
		}
	}

	/**
	 * Handles rows coming from the WooCommerce CSV importer.
	 *
	 * @param WC_Product $object Imported product.
	 * @param array      $data   Raw row.
	 */
	public static function on_product_imported( $object, $data ) {

		if ( $object instanceof WC_Product ) {
			self::apply( $object->get_id() );
		}
	}

	/* ---------------------------------------------------------------------
	 *  Bulk queries
	 * ------------------------------------------------------------------ */

	/**
	 * Counts the products that automatic pricing applies to.
	 *
	 * @return int
	 */
	public static function count_enabled() {
		global $wpdb;

		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			   FROM {$wpdb->posts} p
			   INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			  WHERE p.post_type = 'product'
			    AND p.post_status != 'trash'
			    AND m.meta_key = '_goldmate_enabled'
			    AND m.meta_value = 'yes'"
		);
	}

	/**
	 * Fetches the next page of gold-product IDs above `$after_id`, ordered by ID.
	 *
	 * Paging by ID rather than OFFSET keeps a background run from skipping or
	 * repeating products when one is enabled, disabled or trashed mid-run.
	 *
	 * @param int $after_id Last ID already handled; 0 to start.
	 * @param int $limit    Rows to return.
	 * @return int[]
	 */
	public static function get_enabled_ids( $after_id = 0, $limit = 50 ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				   FROM {$wpdb->posts} p
				   INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				  WHERE p.post_type = 'product'
				    AND p.post_status != 'trash'
				    AND m.meta_key = '_goldmate_enabled'
				    AND m.meta_value = 'yes'
				    AND p.ID > %d
				  ORDER BY p.ID ASC
				  LIMIT %d",
				(int) $after_id,
				(int) $limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}
}
