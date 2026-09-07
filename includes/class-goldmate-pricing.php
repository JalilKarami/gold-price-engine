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
	 * Parent IDs already repriced during this request, keyed by ID.
	 *
	 * `$in_progress` only covers re-entry on the same stack. Pricing the children
	 * saves each one, and WooCommerce queues the parent for a deferred sync on
	 * every child save. That queue is walked on `shutdown`, long after the guard
	 * has been released, and each sync saves the parent, which brings us back
	 * here to save the children again and queue the parent again. The queue grows
	 * faster than it drains and the request dies on the execution-time limit.
	 * Pricing a parent at most once per request breaks that cycle.
	 *
	 * @var array<int,bool>
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

		if ( isset( self::$repriced[ $parent_id ] ) ) {
			return false;
		}

		self::$repriced[ $parent_id ] = true;

		$children = $parent->get_children();

		if ( empty( $children ) ) {
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

				if ( $variation && self::write_price( $variation, $breakdown ) ) {
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

		$total = (string) $breakdown['total'];

		$product->set_regular_price( $total );
		$product->set_sale_price( '' );
		$product->set_price( $total );

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

		// Remember what the price was built from, for auditing and invoices.
		update_post_meta( $id, '_goldmate_priced_at', time() );
		update_post_meta( $id, '_goldmate_priced_rate', $breakdown['rate_18'] );
		update_post_meta( $id, '_goldmate_tax_amount', $breakdown['tax'] );

		wc_delete_product_transients( $id );

		return true;
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

		self::apply( $product_id );
	}

	/**
	 * Handles variation saves, repricing just the variation that changed.
	 *
	 * @param int             $variation_id Variation ID.
	 * @param WC_Product|null $variation    Variation object.
	 */
	public static function on_variation_saved( $variation_id, $variation = null ) {

		if ( isset( self::$in_progress[ $variation_id ] ) ) {
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
	 * Fetches one page of gold-product IDs, ordered stably by ID.
	 *
	 * @param int $offset Rows to skip.
	 * @param int $limit  Rows to return.
	 * @return int[]
	 */
	public static function get_enabled_ids( $offset = 0, $limit = 50 ) {
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
				  ORDER BY p.ID ASC
				  LIMIT %d OFFSET %d",
				(int) $limit,
				(int) $offset
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Reprices every gold product in one request.
	 *
	 * Kept for WP-CLI and small catalogues. The admin UI uses Goldmate_Batch so a
	 * large catalogue cannot time out mid-way and leave prices half-updated.
	 *
	 * @return int Number of parent products touched.
	 */
	public static function apply_all_now() {

		$count  = 0;
		$offset = 0;

		while ( true ) {

			$ids = self::get_enabled_ids( $offset, 50 );

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				if ( false !== self::apply( $id ) ) {
					$count++;
				}
			}

			$offset += 50;
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		return $count;
	}
}
