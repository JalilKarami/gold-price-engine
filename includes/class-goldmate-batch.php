<?php
/**
 * Reprices the catalogue in background chunks instead of one blocking request.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Batch {

	const HOOK          = 'goldmate_batch_step';
	const GROUP         = 'goldmate';
	const PROGRESS_KEY  = 'goldmate_batch_progress';
	const DEFAULT_CHUNK = 40;

	/**
	 * Registers the background step handler.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_step' ), 10, 1 );
	}

	/**
	 * True when Action Scheduler is available to run the job.
	 *
	 * @return bool
	 */
	public static function has_scheduler() {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * Number of products handled per step.
	 *
	 * @return int
	 */
	public static function chunk_size() {
		return max( 1, (int) apply_filters( 'goldmate_batch_chunk_size', self::DEFAULT_CHUNK ) );
	}

	/**
	 * Queues a full catalogue reprice.
	 *
	 * @param string $reason Short label shown in the admin, e.g. "قیمت روز".
	 * @return array Progress state.
	 */
	public static function start( $reason = '' ) {

		self::cancel();

		$total = Goldmate_Pricing::count_enabled();

		$progress = array(
			'total'    => $total,
			'done'     => 0,
			'priced'   => 0,
			'offset'   => 0,
			'reason'   => (string) $reason,
			'started'  => time(),
			'finished' => 0,
		);

		update_option( self::PROGRESS_KEY, $progress, false );

		if ( 0 === $total ) {
			$progress['finished'] = time();
			update_option( self::PROGRESS_KEY, $progress, false );
			return $progress;
		}

		self::schedule_step( 0 );

		return $progress;
	}

	/**
	 * Schedules one step, preferring Action Scheduler and falling back to WP-Cron.
	 *
	 * @param int $offset Rows already processed.
	 */
	protected static function schedule_step( $offset ) {

		$args = array( 'offset' => (int) $offset );

		if ( self::has_scheduler() ) {
			as_schedule_single_action( time(), self::HOOK, $args, self::GROUP );
			return;
		}

		wp_schedule_single_event( time() + 1, self::HOOK, array( $args ) );
		spawn_cron();
	}

	/**
	 * Processes one chunk and queues the next.
	 *
	 * @param array|int $args Step arguments; an int offset is accepted for WP-Cron.
	 */
	public static function run_step( $args = array() ) {

		$offset = is_array( $args ) ? (int) ( $args['offset'] ?? 0 ) : (int) $args;

		$progress = self::get_progress();

		if ( empty( $progress ) || ! empty( $progress['finished'] ) ) {
			return;
		}

		$ids = Goldmate_Pricing::get_enabled_ids( $offset, self::chunk_size() );

		if ( empty( $ids ) ) {
			self::finish( $progress );
			return;
		}

		foreach ( $ids as $id ) {
			if ( false !== Goldmate_Pricing::apply( $id ) ) {
				$progress['priced']++;
			}
			$progress['done']++;
		}

		$progress['offset'] = $offset + count( $ids );

		update_option( self::PROGRESS_KEY, $progress, false );

		if ( count( $ids ) < self::chunk_size() ) {
			self::finish( $progress );
			return;
		}

		self::schedule_step( $progress['offset'] );
	}

	/**
	 * Marks the run complete and flushes product caches.
	 *
	 * @param array $progress Current progress state.
	 */
	protected static function finish( $progress ) {

		$progress['finished'] = time();

		update_option( self::PROGRESS_KEY, $progress, false );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		do_action( 'goldmate_batch_finished', $progress );
	}

	/**
	 * Cancels any queued steps.
	 */
	public static function cancel() {

		if ( self::has_scheduler() ) {
			as_unschedule_all_actions( self::HOOK, null, self::GROUP );
		}

		// Steps are queued with an offset argument, which wp_next_scheduled() and
		// wp_clear_scheduled_hook() would not match without repeating that argument.
		wp_unschedule_hook( self::HOOK );
	}

	/**
	 * Reads the current progress state.
	 *
	 * @return array
	 */
	public static function get_progress() {

		$progress = get_option( self::PROGRESS_KEY, array() );

		return is_array( $progress ) ? $progress : array();
	}

	/**
	 * True while a run is queued or mid-flight.
	 *
	 * @return bool
	 */
	public static function is_running() {

		$progress = self::get_progress();

		return ! empty( $progress ) && empty( $progress['finished'] );
	}
}
