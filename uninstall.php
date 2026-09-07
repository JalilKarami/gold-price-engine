<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Background jobs are always cleared, because a scheduled action that points at a
 * class no longer on disk would keep erroring. Settings and product data are only
 * removed when the shop has explicitly opted in, since the per-product weight,
 * karat, wage and accessories are business records that cannot be recovered.
 *
 * The plugin itself is not loaded here, so nothing below may call its functions.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Cancels every background job the plugin owns on the current site.
 */
function goldmate_uninstall_clear_jobs() {

	$hooks = array( 'goldmate_fetch_rate', 'goldmate_batch_step' );

	foreach ( $hooks as $hook ) {

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, null, 'goldmate' );
		}

		// wp_clear_scheduled_hook() only matches events queued with no arguments,
		// and the batch step carries an offset, so clear the hook wholesale.
		wp_unschedule_hook( $hook );
	}

	delete_transient( 'goldmate_updated_notice' );
}

/**
 * Removes every option and product meta row the plugin created on the current site.
 */
function goldmate_uninstall_delete_data() {
	global $wpdb;

	// Options are named goldmate_*; the escaped LIKE keeps _ literal.
	$option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'goldmate_' ) . '%'
		)
	);

	foreach ( $option_names as $option_name ) {
		delete_option( $option_name );
	}

	// Product and variation meta is named _goldmate_*.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( '_goldmate_' ) . '%'
		)
	);

	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}
}

/**
 * Runs the uninstall routine for whichever site is currently active.
 */
function goldmate_uninstall_site() {

	goldmate_uninstall_clear_jobs();

	if ( 'yes' === get_option( 'goldmate_delete_data', 'no' ) ) {
		goldmate_uninstall_delete_data();
	}
}

if ( is_multisite() ) {

	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		goldmate_uninstall_site();
		restore_current_blog();
	}
} else {
	goldmate_uninstall_site();
}
