<?php
/**
 * Live storefront rate refresh (AJAX) without waiting for a full catalogue reprice.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Live {

	/**
	 * Registers AJAX endpoints and front-end assets.
	 */
	public static function init() {

		add_action( 'wp_ajax_goldmate_live_rate', array( __CLASS__, 'ajax_live_rate' ) );
		add_action( 'wp_ajax_nopriv_goldmate_live_rate', array( __CLASS__, 'ajax_live_rate' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'woocommerce_post_class', array( __CLASS__, 'product_post_class' ), 10, 2 );
	}

	/**
	 * Marks GoldMate-priced products so live.js can refresh loop cards.
	 *
	 * @param string[]   $classes Classes.
	 * @param WC_Product $product Product.
	 * @return string[]
	 */
	public static function product_post_class( $classes, $product ) {

		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return $classes;
		}

		$enabled = $product->get_meta( '_goldmate_enabled', true );
		if ( 'yes' !== $enabled && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$enabled = $parent ? $parent->get_meta( '_goldmate_enabled', true ) : $enabled;
		}

		if ( 'yes' === $enabled ) {
			$classes[] = 'goldmate-priced';
		}

		return $classes;
	}

	/**
	 * Enqueues the lightweight poller when live refresh is enabled.
	 */
	public static function enqueue() {

		$product_on  = 'yes' === goldmate_option( 'goldmate_ajax_products' );
		$short_on    = 'yes' === goldmate_option( 'goldmate_ajax_shortcodes' )
			&& 'yes' === goldmate_option( 'goldmate_ajax_shortcodes_enable' );
		$prod_int    = (int) goldmate_option( 'goldmate_ajax_products_interval' );
		$short_int   = (int) goldmate_option( 'goldmate_ajax_shortcodes_interval' );

		$has_shortcode = self::page_may_have_shortcode();
		$interval      = 0;

		if ( is_product() && $product_on ) {
			$interval = $prod_int > 0 ? $prod_int : 60;
		} elseif ( $has_shortcode && $short_on ) {
			$interval = $short_int > 0 ? $short_int : ( $prod_int > 0 ? $prod_int : 30 );
		} elseif ( $product_on && ( is_shop() || is_product_category() || is_front_page() || is_home() ) ) {
			$interval = $prod_int > 0 ? $prod_int : 60;
		} elseif ( $has_shortcode ) {
			// Boards on a page should still refresh even if shortcode AJAX toggle was left off.
			$interval = $short_int > 0 ? $short_int : 30;
		}

		if ( $interval <= 0 ) {
			return;
		}

		wp_enqueue_script(
			'goldmate-live',
			GOLDMATE_URL . 'assets/js/live.js',
			array(),
			GOLDMATE_VERSION,
			true
		);

		$product_id  = 0;
		$is_variable = false;
		if ( function_exists( 'is_product' ) && is_product() ) {
			$product_id = (int) get_queried_object_id();
			$product    = $product_id ? wc_get_product( $product_id ) : null;
			if ( $product && is_a( $product, 'WC_Product' ) ) {
				$is_variable = $product->is_type( 'variable' );
			} else {
				$product_id = 0;
			}
		}

		$items = array( 'gold18' );
		if ( class_exists( 'Goldmate_Rate_Items' ) ) {
			foreach ( Goldmate_Rate_Items::all() as $row ) {
				if ( ! empty( $row['slug'] ) ) {
					$items[] = $row['slug'];
				}
			}
			$items = array_values( array_unique( $items ) );
		}

		wp_localize_script(
			'goldmate-live',
			'goldmateLive',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'interval'   => max( 15, $interval ) * 1000,
				'productId'  => $product_id,
				'isVariable' => $is_variable,
				'items'      => $items,
			)
		);
	}

	/**
	 * Best-effort check for GoldMate shortcodes on the current singular page.
	 *
	 * @return bool
	 */
	protected static function page_may_have_shortcode() {

		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();

		if ( ! $post ) {
			return false;
		}

		$content = (string) $post->post_content;

		return false !== strpos( $content, 'goldmate_' )
			|| false !== strpos( $content, 'goldmate-price' )
			|| has_shortcode( $content, 'goldmate_price' )
			|| has_shortcode( $content, 'goldmate_rate_board' )
			|| has_shortcode( $content, 'goldmate_details' )
			|| has_shortcode( $content, 'goldmate_price_field' )
			|| has_shortcode( $content, 'goldmate_calculator' )
			|| has_shortcode( $content, 'goldmate_price_banner' );
	}

	/**
	 * AJAX: current rate, change %, optional live product total(s).
	 */
	public static function ajax_live_rate() {

		$product_id    = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$variation_id  = isset( $_GET['variation_id'] ) ? absint( $_GET['variation_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$item_slug     = isset( $_GET['item'] ) ? sanitize_title( wp_unslash( $_GET['item'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$product_ids   = array();

		if ( ! empty( $_GET['product_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = sanitize_text_field( wp_unslash( $_GET['product_ids'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			foreach ( explode( ',', $raw ) as $id ) {
				$id = absint( $id );
				if ( $id > 0 ) {
					$product_ids[] = $id;
				}
			}
			$product_ids = array_values( array_unique( array_slice( $product_ids, 0, 24 ) ) );
		}

		if ( $item_slug && class_exists( 'Goldmate_Rate_Items' ) && ! $product_id && empty( $product_ids ) ) {
			$item = Goldmate_Rate_Items::get_by_slug( $item_slug );
			if ( $item ) {
				wp_send_json_success(
					array(
						'rate'            => (float) $item['rate'],
						'rate_html'       => $item['rate'] > 0 ? wc_price( $item['rate'] ) : '',
						'updated_at'      => (int) $item['updated_at'],
						'updated_human'   => goldmate_format_time( (int) $item['updated_at'] ),
						'change_pct'      => self::change_pct( (int) $item['id'] ),
						'change_pct_html' => self::format_change_pct( self::change_pct( (int) $item['id'] ) ),
						'item'            => $item['slug'],
						'item_label'      => $item['label'],
					)
				);
			}
		}

		$calc_id = $variation_id > 0 ? $variation_id : $product_id;
		$data    = self::payload( $calc_id );

		if ( $variation_id > 0 ) {
			$data['variation_id'] = $variation_id;
			$data['product_id']   = $product_id > 0 ? $product_id : (int) wp_get_post_parent_id( $variation_id );
		}

		if ( $product_ids ) {
			$data['products'] = self::payloads_for_products( $product_ids );
		}

		wp_send_json_success( $data );
	}

	/**
	 * Live totals for a list of product IDs (shop/archive cards).
	 *
	 * @param int[] $product_ids IDs.
	 * @return array<int,array{live_total:float,live_total_html:string}>
	 */
	protected static function payloads_for_products( $product_ids ) {

		$out = array();
		foreach ( $product_ids as $id ) {
			$id = (int) $id;
			$b  = Goldmate_Calculator::calculate( $id );
			if ( false === $b ) {
				continue;
			}
			$out[ $id ] = array(
				'live_total'      => $b['total'],
				'live_total_html' => wc_price( $b['total'] ),
			);
		}
		return $out;
	}

	/**
	 * Builds the live rate payload used by AJAX and shortcodes.
	 *
	 * @param int $product_id Optional product for a live total.
	 * @return array
	 */
	public static function payload( $product_id = 0 ) {

		$item = null;
		if ( class_exists( 'Goldmate_Rate_Items' ) ) {
			$item = $product_id > 0
				? Goldmate_Rate_Items::for_product( $product_id )
				: Goldmate_Rate_Items::get_default();
		}

		$rate = $item
			? goldmate_positive_float( $item['rate'] )
			: goldmate_positive_float( goldmate_option( 'goldmate_rate_per_gram' ) );
		$applied  = self::applied_rate();
		$updated  = $item ? (int) $item['updated_at'] : (int) get_option( 'goldmate_rate_updated_at', 0 );
		$running  = Goldmate_Batch::is_running();
		$provider = $item && ! empty( $item['last_provider'] )
			? (string) $item['last_provider']
			: (string) get_option( 'goldmate_rate_last_provider', '' );
		$change   = self::change_pct( $item ? (int) $item['id'] : 0 );

		if ( class_exists( 'Goldmate_General' ) && Goldmate_General::hide_price_change() ) {
			$change = 0.0;
		}

		$data = array(
			'rate'              => $rate,
			'rate_html'         => $rate > 0 ? wc_price( $rate ) : '',
			'rate_plain'        => $rate > 0 ? goldmate_plain_price( $rate ) : '',
			'applied_rate'      => $applied,
			'applied_rate_html' => $applied > 0 ? wc_price( $applied ) : '',
			'updated_at'        => $updated,
			'updated_human'     => goldmate_format_time( $updated ),
			'batch_running'     => $running,
			'stale'             => ! $running && ( Goldmate_Rate_Items::is_reference_stale() || ( class_exists( 'Goldmate_General' ) && Goldmate_General::validity_expired() ) ),
			'change_pct'        => $change,
			'change_pct_html'   => ( class_exists( 'Goldmate_General' ) && Goldmate_General::hide_price_change() ) ? '' : self::format_change_pct( $change ),
			'provider'          => $provider,
			'provider_label'    => Goldmate_Fetcher::provider_label( $provider ),
			'oos'               => class_exists( 'Goldmate_General' ) && Goldmate_General::products_oos(),
			'item'              => $item ? $item['slug'] : 'gold18',
			'item_label'        => $item ? $item['label'] : '',
		);

		if ( $product_id > 0 ) {
			$b = Goldmate_Calculator::calculate( $product_id );

			if ( false !== $b ) {
				$data['product_id']      = $product_id;
				$data['live_total']      = $b['total'];
				$data['live_total_html'] = wc_price( $b['total'] );
				$data['breakdown_html']  = Goldmate_Display::render_block( $b );
			}
		}

		return $data;
	}

	/**
	 * Catalogue-applied rate (same semantics as the price banner).
	 *
	 * @return float
	 */
	public static function applied_rate() {

		if ( ! Goldmate_Batch::is_running() ) {
			return goldmate_reference_rate();
		}

		$history = Goldmate_Rate_Items::reference_history( 5 );

		return isset( $history[1]['rate'] ) ? goldmate_positive_float( $history[1]['rate'] ) : 0.0;
	}

	/**
	 * Percentage change vs previous history entry.
	 *
	 * @param int $item_id Optional rate item ID.
	 * @return float|null Null when unknown.
	 */
	public static function change_pct( $item_id = 0 ) {

		if ( $item_id > 0 && class_exists( 'Goldmate_Rate_Items' ) ) {
			$history = Goldmate_Rate_Items::history( $item_id, 2 );
		} else {
			$history = Goldmate_Rate_Items::reference_history( 2 );
		}

		if ( count( $history ) < 2 ) {
			return null;
		}

		$current  = goldmate_positive_float( $history[0]['rate'] );
		$previous = goldmate_positive_float( $history[1]['rate'] );

		if ( $previous <= 0 ) {
			return null;
		}

		return round( ( ( $current - $previous ) / $previous ) * 100, 2 );
	}

	/**
	 * Formats a change percentage for display.
	 *
	 * @param float|null $change Change percent.
	 * @return string
	 */
	public static function format_change_pct( $change ) {

		if ( null === $change ) {
			return '';
		}

		$sign = $change > 0 ? '+' : '';

		return $sign . wc_format_localized_decimal( $change ) . '٪';
	}
}
