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
		add_action( self::HOOK, array( __CLASS__, 'run_step' ), 10, 3 );
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
			'run'      => wp_generate_password( 12, false ),
			'total'    => $total,
			'done'     => 0,
			'priced'   => 0,
			'offset'   => 0,
			'after'    => 0,
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

		self::schedule_step( $progress['run'], 0, 0 );

		return $progress;
	}

	/**
	 * Schedules one step, preferring Action Scheduler and falling back to WP-Cron.
	 *
	 * @param string $run    ID of the run the step belongs to.
	 * @param int    $offset Rows already processed.
	 * @param int    $after  Last product ID already processed.
	 */
	protected static function schedule_step( $run, $offset, $after ) {

		$args = array(
			'run'    => (string) $run,
			'offset' => (int) $offset,
			'after'  => (int) $after,
		);

		// Action Scheduler calls the hook with array_values( $args ), so wrap the
		// step arguments in a list to hand run_step() the array, as WP-Cron does.
		if ( self::has_scheduler() ) {
			as_schedule_single_action( time(), self::HOOK, array( $args ), self::GROUP );
			return;
		}

		wp_schedule_single_event( time() + 1, self::HOOK, array( $args ) );
		spawn_cron();
	}

	/**
	 * Processes one chunk and queues the next.
	 *
	 * A step belongs to the run that queued it. When a rate change restarts the
	 * batch while a step is mid-flight, cancel() cannot stop that step, so it
	 * must notice the new run and bow out rather than overwrite the new run's
	 * progress and queue a second, competing chain of steps.
	 *
	 * Steps queued in Action Scheduler before 3.6.1 arrive as positional
	 * values (run, offset, after) because Action Scheduler unwraps the keyed
	 * array; those are still accepted. Older WP-Cron events pass an int offset.
	 *
	 * @param array|int|string $args       Step arguments, or the run ID of an unwrapped step.
	 * @param int|null         $pos_offset Offset of an unwrapped step.
	 * @param int|null         $pos_after  Last product ID of an unwrapped step.
	 */
	public static function run_step( $args = array(), $pos_offset = null, $pos_after = null ) {

		if ( is_array( $args ) ) {
			$offset = (int) ( $args['offset'] ?? 0 );
			$run    = (string) ( $args['run'] ?? '' );
			$after  = (int) ( $args['after'] ?? 0 );
		} elseif ( null !== $pos_offset ) {
			$run    = (string) $args;
			$offset = (int) $pos_offset;
			$after  = (int) $pos_after;
		} else {
			$offset = (int) $args;
			$run    = '';
			$after  = 0;
		}

		$progress = self::get_progress();

		if ( ! self::owns( $progress, $run ) ) {
			return;
		}

		$ids = Goldmate_Pricing::get_enabled_ids( $after, self::chunk_size() );

		if ( empty( $ids ) ) {
			self::finish( $progress );
			return;
		}

		$priced = 0;

		foreach ( $ids as $id ) {
			if ( false !== Goldmate_Pricing::apply( $id ) ) {
				$priced++;
			}
		}

		// Pricing the chunk takes a while; a newer run may have started meanwhile.
		$progress = self::get_progress();

		if ( ! self::owns( $progress, $run ) ) {
			return;
		}

		$progress['priced'] += $priced;
		$progress['done']   += count( $ids );
		$progress['offset']  = $offset + count( $ids );
		$progress['after']   = (int) end( $ids );

		update_option( self::PROGRESS_KEY, $progress, false );

		if ( count( $ids ) < self::chunk_size() ) {
			self::finish( $progress );
			return;
		}

		self::schedule_step( $run, $progress['offset'], $progress['after'] );
	}

	/**
	 * True when a step of run `$run` may still act on the stored progress.
	 *
	 * Progress saved before run IDs existed has none, and neither do the steps it
	 * queued, so those still match each other across the upgrade.
	 *
	 * @param array  $progress Stored progress.
	 * @param string $run      Run ID carried by the step.
	 * @return bool
	 */
	protected static function owns( $progress, $run ) {

		if ( empty( $progress ) || ! empty( $progress['finished'] ) ) {
			return false;
		}

		return (string) ( $progress['run'] ?? '' ) === $run;
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
