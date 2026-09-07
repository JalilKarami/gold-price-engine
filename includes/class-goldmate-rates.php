<?php
/**
 * Fetches the daily gold rate, guards against bad data, and records its history.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Rates {

	const CRON_HOOK   = 'goldmate_fetch_rate';
	const GROUP       = 'goldmate';
	const HISTORY_KEY = 'goldmate_rate_history';
	const HISTORY_MAX = 100;
	const LOG_KEY     = 'goldmate_fetch_log';
	const LOG_MAX     = 500;

	/**
	 * Registers the fetch job and its schedule.
	 */
	public static function init() {

		add_action( self::CRON_HOOK, array( __CLASS__, 'run_fetch' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedule' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * Endpoint presets offered in the admin.
	 *
	 * These prefill the custom-endpoint fields; the exact URL, response shape and
	 * authentication of each provider must still be confirmed against their own
	 * documentation before going live.
	 *
	 * @return array
	 */
	public static function presets() {
		return array(
			'manual' => array(
				'label' => 'دستی (بدون دریافت خودکار)',
			),
			'custom' => array(
				'label'      => 'آدرس دلخواه (JSON)',
				'url'        => '',
				'path'       => '',
				'multiplier' => 1,
				'key_header' => '',
			),
			'brsapi' => array(
				'label'      => 'BrsApi.ir',
				'url'        => 'https://Api.BrsApi.ir/Market/Gold_Currency.php?key={KEY}',
				'path'       => 'gold[symbol=IR_GOLD_18K].price',
				'multiplier' => 1,
				'key_header' => '',
				'time_path'  => 'gold[symbol=IR_GOLD_18K].time_unix',
				'max_age'    => 1440,
				'note'       => 'کلید در آدرس جایگزین {KEY} می‌شود. پاسخ به تومان است، پس ضریب ۱ می‌ماند. قلم قیمت با نماد IR_GOLD_18K انتخاب می‌شود، نه با جای آن در فهرست.',
			),
			'atn'    => array(
				'label'      => 'ATN (وب‌سرویس اختصاصی)',
				'url'        => 'http://77.104.95.33/api/v1/prices/atn',
				'path'       => 'atn.products[code=melted_gold_tomorrow].gram_sell',
				'multiplier' => 1,
				'key_header' => 'X-API-Key',
				'time_path'  => 'atn.updated_at',
				'max_age'    => 1440,
				'note'       => 'کلید در هدر X-API-Key فرستاده می‌شود، نه در آدرس. مقدار gram_sell همان قیمت گرم ۱۸ عیار است (مظنه تقسیم بر ۴٫۳۳۱۸) و به تومان است. این سرویس روی HTTP بدون رمزنگاری کار می‌کند.',
			),
			'tgju'   => array(
				'label'      => 'TGJU',
				'url'        => 'https://call1.tgju.org/ajax.json',
				'path'       => 'current.geram18.p',
				'multiplier' => 0.1,
				'key_header' => '',
				'note'       => 'پاسخ به ریال است؛ ضریب ۰٫۱ آن را به تومان تبدیل می‌کند. مسیر را با پاسخ واقعی تطبیق دهید.',
			),
		);
	}

	/* ---------------------------------------------------------------------
	 *  Scheduling
	 * ------------------------------------------------------------------ */

	/**
	 * Registers a WP-Cron interval matching the configured fetch period.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_cron_schedule( $schedules ) {

		$minutes = max( 5, (int) goldmate_option( 'goldmate_fetch_interval' ) );

		$schedules['goldmate_interval'] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			'display'  => sprintf( 'هر %d دقیقه (گلدمیت)', $minutes ),
		);

		return $schedules;
	}

	/**
	 * Schedules the fetch job when automatic fetching is on and nothing is queued.
	 */
	public static function ensure_scheduled() {

		if ( 'manual' === goldmate_option( 'goldmate_rate_source' ) ) {
			return;
		}

		if ( Goldmate_Batch::has_scheduler() && function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( self::CRON_HOOK, null, self::GROUP ) ) {
				self::reschedule();
			}
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::reschedule();
		}
	}

	/**
	 * Rebuilds the schedule from the current interval setting.
	 */
	public static function reschedule() {

		self::unschedule();

		if ( 'manual' === goldmate_option( 'goldmate_rate_source' ) ) {
			return;
		}

		$minutes  = max( 5, (int) goldmate_option( 'goldmate_fetch_interval' ) );
		$interval = $minutes * MINUTE_IN_SECONDS;

		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			as_schedule_recurring_action( time() + 60, $interval, self::CRON_HOOK, array(), self::GROUP );
			return;
		}

		wp_schedule_event( time() + 60, 'goldmate_interval', self::CRON_HOOK );
	}

	/**
	 * Removes the fetch job from every scheduler.
	 */
	public static function unschedule() {

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CRON_HOOK, null, self::GROUP );
		}

		// Clears every queued event for the hook, whatever arguments it carries.
		wp_unschedule_hook( self::CRON_HOOK );
	}

	/* ---------------------------------------------------------------------
	 *  Fetching
	 * ------------------------------------------------------------------ */

	/**
	 * Reads the configured endpoint's settings.
	 *
	 * @return array
	 */
	protected static function config() {

		return array(
			'url'        => trim( (string) goldmate_option( 'goldmate_api_url' ) ),
			'key'        => trim( (string) goldmate_option( 'goldmate_api_key' ) ),
			'key_header' => trim( (string) goldmate_option( 'goldmate_api_key_header' ) ),
			'path'       => (string) goldmate_option( 'goldmate_api_path' ),
			'multiplier' => (float) goldmate_option( 'goldmate_api_multiplier' ),
			'time_path'  => trim( (string) goldmate_option( 'goldmate_api_time_path' ) ),
			'max_age'    => (int) goldmate_option( 'goldmate_api_max_age' ),
		);
	}

	/**
	 * Requests the current rate from the configured endpoint.
	 *
	 * There is deliberately no automatic failover: when a source misbehaves the
	 * shop chooses the replacement, so nobody is ever priced against a provider
	 * they did not pick. Switching source in the admin refills the fields from
	 * that provider's preset.
	 *
	 * @return array{rate: float, error: string, raw: string}
	 */
	public static function fetch() {

		if ( 'manual' === goldmate_option( 'goldmate_rate_source' ) ) {
			return array(
				'rate'  => 0.0,
				'error' => 'دریافت خودکار غیرفعال است.',
				'raw'   => '',
			);
		}

		return self::request( self::config() );
	}

	/**
	 * Performs one endpoint's request and turns its response into a rate.
	 *
	 * @param array $config One source's settings, as built by config().
	 * @return array{rate: float, error: string, raw: string, outcome: string, attempts: int}
	 */
	protected static function request( $config ) {

		$result = array(
			'rate'     => 0.0,
			'error'    => '',
			'raw'      => '',
			'outcome'  => 'ok',
			'attempts' => 0,
		);

		$url = $config['url'];
		$key = $config['key'];

		if ( '' === $url ) {
			$result['error']   = 'آدرس سرویس تنظیم نشده است.';
			$result['outcome'] = 'config';
			return $result;
		}

		if ( '' !== $key ) {
			$url = str_replace( array( '{KEY}', '%KEY%' ), rawurlencode( $key ), $url );
		}

		$args = array(
			'timeout'     => 15,
			'redirection' => 3,
			'headers'     => array( 'Accept' => 'application/json' ),
			'user-agent'  => 'Goldmate/' . GOLDMATE_VERSION . '; ' . home_url( '/' ),
		);

		if ( '' !== $config['key_header'] && '' !== $key ) {
			$args['headers'][ $config['key_header'] ] = $key;
		}

		$attempts = 0;
		$response = self::request_with_retry( $url, $args, $attempts );

		$result['attempts'] = $attempts;

		if ( is_wp_error( $response ) ) {
			$result['error']   = $response->get_error_message();
			$result['outcome'] = 'transport';
			return $result;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		$result['raw'] = mb_substr( $body, 0, 500 );

		if ( $code < 200 || $code >= 300 ) {
			$result['error']   = sprintf( 'پاسخ سرویس: HTTP %d', $code );
			$result['outcome'] = 'http';
			return $result;
		}

		$data = json_decode( $body, true );

		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			$result['error']   = 'پاسخ سرویس JSON معتبر نیست.';
			$result['outcome'] = 'shape';
			return $result;
		}

		$value = goldmate_json_path( $data, $config['path'] );

		if ( null === $value || is_array( $value ) || is_object( $value ) ) {
			$result['error']   = 'مسیر JSON در پاسخ سرویس پیدا نشد.';
			$result['outcome'] = 'shape';
			return $result;
		}

		$freshness = self::freshness_error( $data, $config );

		if ( '' !== $freshness ) {
			$result['error']   = $freshness;
			$result['outcome'] = 'stale';
			return $result;
		}

		$multiplier = $config['multiplier'] > 0 ? $config['multiplier'] : 1;

		$rate = goldmate_positive_float( $value ) * $multiplier;

		if ( $rate <= 0 ) {
			$result['error']   = 'مقدار دریافتی معتبر نیست.';
			$result['outcome'] = 'shape';
			return $result;
		}

		$result['rate'] = $rate;

		return $result;
	}

	/**
	 * Performs the HTTP request, retrying transport failures.
	 *
	 * Shared hosts resolve DNS slowly and intermittently: on this shop three of
	 * four hourly fetches succeed and the fourth dies on
	 * `cURL error 28: Resolving timed out`. One extra attempt absorbs that
	 * without waiting a whole hour for the next scheduled run.
	 *
	 * Only transport errors are retried. An HTTP 401 or a malformed body is a
	 * settled answer — repeating the request just wastes the daily quota.
	 *
	 * @param string $url      Request URL.
	 * @param array  $args     Request arguments.
	 * @param int    $attempts Filled with how many attempts were made, so the log
	 *                         can show when a retry is what saved the fetch.
	 * @return array|WP_Error
	 */
	protected static function request_with_retry( $url, $args, &$attempts = 0 ) {

		/**
		 * Filters how many extra attempts a failed request gets.
		 *
		 * @param int $retries Extra attempts after the first. Default 1.
		 */
		$retries = (int) apply_filters( 'goldmate_fetch_retries', 1 );
		$retries = max( 0, min( 3, $retries ) );

		for ( $attempt = 0; ; $attempt++ ) {

			$response = wp_remote_get( $url, $args );
			$attempts = $attempt + 1;

			if ( ! is_wp_error( $response ) || $attempt >= $retries ) {
				return $response;
			}

			// Long enough for a flaky resolver to settle, short enough that an
			// admin waiting on the test button does not think the page hung.
			sleep( 2 );
		}
	}

	/**
	 * Rejects a response whose own timestamp shows the price is old.
	 *
	 * HTTP status and JSON shape both stay perfectly healthy while a provider
	 * serves a frozen number, so the only signal is the timestamp it publishes
	 * beside the price. Providers that publish none are simply not checked.
	 *
	 * @param mixed $data   Decoded response.
	 * @param array $config The source's settings.
	 * @return string Empty when fresh, or not checked.
	 */
	protected static function freshness_error( $data, $config ) {

		$path    = $config['time_path'];
		$max_age = $config['max_age'];

		if ( '' === $path || $max_age <= 0 ) {
			return '';
		}

		$stamp = goldmate_json_path( $data, $path );

		if ( null === $stamp || is_array( $stamp ) || is_object( $stamp ) ) {
			return 'زمان به‌روزرسانی در پاسخ سرویس پیدا نشد.';
		}

		// Providers publish either a Unix timestamp or a written date.
		$time = is_numeric( $stamp ) ? (int) $stamp : (int) strtotime( (string) $stamp );

		if ( $time <= 0 ) {
			return 'زمان به‌روزرسانی پاسخ سرویس خوانده نشد.';
		}

		$age = time() - $time;

		// Some feeds publish local time with no UTC offset — TGJU writes Tehran
		// time as "2026-09-07 18:26:30" — which parses hours into the future and
		// would then read as permanently fresh, silently disabling this guard.
		// An hour of tolerance covers ordinary clock skew.
		if ( $age < -HOUR_IN_SECONDS ) {
			return sprintf(
				'زمان اعلام‌شده‌ی سرویس (%s) در آینده است؛ احتمالاً منطقه‌ی زمانی ندارد، پس این محافظ قابل اتکا نیست.',
				goldmate_format_time( $time )
			);
		}

		if ( $age <= $max_age * MINUTE_IN_SECONDS ) {
			return '';
		}

		return sprintf(
			'قیمت سرویس مربوط به %s است و بیش از حد مجاز (%s دقیقه) قدمت دارد، پس اعمال نشد.',
			goldmate_format_time( $time ),
			number_format_i18n( $max_age )
		);
	}

	/**
	 * Cron callback: fetches, validates and stores the rate.
	 *
	 * @param bool $force Skip the deviation guard.
	 * @return array The fetch result, annotated with what was done.
	 */
	public static function run_fetch( $force = false ) {

		$result = self::fetch();

		if ( '' !== $result['error'] ) {
			update_option( 'goldmate_rate_last_error', $result['error'], false );
			update_option( 'goldmate_rate_checked_at', time(), false );
			self::record_log( $result, $result['outcome'] );
			return $result;
		}

		$current   = goldmate_positive_float( goldmate_option( 'goldmate_rate_per_gram' ) );
		$deviation = self::deviation_pct( $current, $result['rate'] );
		$max       = goldmate_positive_float( goldmate_option( 'goldmate_max_deviation' ) );

		if ( ! $force && $current > 0 && $max > 0 && $deviation > $max ) {

			// A provider glitch could otherwise reprice the whole catalogue.
			update_option( 'goldmate_pending_rate', $result['rate'], false );
			update_option(
				'goldmate_rate_last_error',
				sprintf(
					'قیمت دریافتی %s با قیمت فعلی %s٪ اختلاف دارد و اعمال نشد. از بخش «وضعیت» می‌توانید دستی تأیید کنید.',
					// Plain text, not wc_price(): this message is escaped wherever
					// it is shown, so a numeric currency entity would be printed
					// literally in the status table and the fetch log.
					goldmate_plain_price( $result['rate'] ),
					wc_format_localized_decimal( round( $deviation, 1 ) )
				),
				false
			);
			update_option( 'goldmate_rate_checked_at', time(), false );

			$result['rejected'] = true;
			$result['error']    = get_option( 'goldmate_rate_last_error', '' );

			self::record_log( $result, 'deviation' );

			return $result;
		}

		self::set_rate( $result['rate'], 'auto' );

		$result['applied'] = true;

		self::record_log( $result, 'applied' );

		return $result;
	}

	/**
	 * Percentage difference between two rates.
	 *
	 * @param float $from Baseline.
	 * @param float $to   New value.
	 * @return float
	 */
	public static function deviation_pct( $from, $to ) {

		if ( $from <= 0 ) {
			return 0.0;
		}

		return abs( $to - $from ) / $from * 100;
	}

	/**
	 * Stores a new rate, records it in history and queues a catalogue reprice.
	 *
	 * @param float  $rate   Rate per gram of 18-karat gold.
	 * @param string $source `auto` or `manual`.
	 */
	public static function set_rate( $rate, $source = 'manual' ) {

		$rate = goldmate_positive_float( $rate );

		if ( $rate <= 0 ) {
			return;
		}

		$previous = goldmate_positive_float( goldmate_option( 'goldmate_rate_per_gram' ) );

		update_option( 'goldmate_rate_per_gram', $rate );
		update_option( 'goldmate_rate_updated_at', time(), false );
		update_option( 'goldmate_rate_checked_at', time(), false );
		update_option( 'goldmate_rate_last_error', '', false );
		delete_option( 'goldmate_pending_rate' );

		// History answers "what rate did this invoice use", so only real changes
		// belong in it. An hourly fetch that returns the same number all evening
		// would otherwise bury the actual moves under identical rows; every
		// attempt is recorded in the fetch log instead.
		if ( abs( $rate - $previous ) > 0.0001 ) {

			self::record_history( $rate, $source );

			Goldmate_Batch::start( 'auto' === $source ? 'دریافت خودکار قیمت روز' : 'تغییر دستی قیمت روز' );
		}
	}

	/* ---------------------------------------------------------------------
	 *  History and staleness
	 * ------------------------------------------------------------------ */

	/**
	 * Appends an entry to the rolling rate history.
	 *
	 * @param float  $rate   Applied rate.
	 * @param string $source `auto` or `manual`.
	 */
	protected static function record_history( $rate, $source ) {

		$history = self::history();

		array_unshift(
			$history,
			array(
				'rate'   => $rate,
				'at'     => time(),
				'source' => $source,
				'user'   => get_current_user_id(),
			)
		);

		update_option( self::HISTORY_KEY, array_slice( $history, 0, self::HISTORY_MAX ), false );
	}

	/**
	 * Reads the stored rate history, newest first.
	 *
	 * @return array[]
	 */
	public static function history() {

		$history = get_option( self::HISTORY_KEY, array() );

		return is_array( $history ) ? $history : array();
	}

	/* ---------------------------------------------------------------------
	 *  Fetch log
	 * ------------------------------------------------------------------ */

	/**
	 * Records the outcome of one fetch attempt.
	 *
	 * `goldmate_rate_last_error` only ever holds the most recent message, so a
	 * failure at 3am is erased by the next success and nobody can tell a one-off
	 * blip from a pattern. This keeps every attempt long enough to see the shape
	 * of the problem.
	 *
	 * @param array  $result  Fetch result.
	 * @param string $outcome One of: applied, deviation, stale, transport, http,
	 *                        shape, config.
	 */
	protected static function record_log( $result, $outcome ) {

		$log = self::log();

		array_unshift(
			$log,
			array(
				'at'       => time(),
				'outcome'  => $outcome,
				'rate'     => isset( $result['rate'] ) ? (float) $result['rate'] : 0.0,
				'error'    => isset( $result['error'] ) ? mb_substr( (string) $result['error'], 0, 300 ) : '',
				'attempts' => isset( $result['attempts'] ) ? (int) $result['attempts'] : 0,
			)
		);

		update_option( self::LOG_KEY, self::prune_log( $log ), false );
	}

	/**
	 * Trims the log by both age and count.
	 *
	 * @param array[] $log Newest-first entries.
	 * @return array[]
	 */
	protected static function prune_log( $log ) {

		$days = goldmate_positive_float( goldmate_option( 'goldmate_log_days' ) );

		if ( $days > 0 ) {

			$cutoff = time() - (int) ( $days * DAY_IN_SECONDS );

			$log = array_values(
				array_filter(
					$log,
					function ( $entry ) use ( $cutoff ) {
						return isset( $entry['at'] ) && (int) $entry['at'] >= $cutoff;
					}
				)
			);
		}

		// A hard cap as well: a site fetching every five minutes would otherwise
		// build a very large option before the age limit ever bites.
		return array_slice( $log, 0, self::LOG_MAX );
	}

	/**
	 * Reads the fetch log, newest first.
	 *
	 * @return array[]
	 */
	public static function log() {

		$log = get_option( self::LOG_KEY, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Counts the last day's outcomes, for the one-line health summary.
	 *
	 * @return array{total: int, ok: int, failed: int, retried: int}
	 */
	public static function log_summary() {

		$since   = time() - DAY_IN_SECONDS;
		$summary = array(
			'total'   => 0,
			'ok'      => 0,
			'failed'  => 0,
			'retried' => 0,
		);

		foreach ( self::log() as $entry ) {

			if ( ! isset( $entry['at'] ) || (int) $entry['at'] < $since ) {
				continue;
			}

			$summary['total']++;

			if ( 'applied' === $entry['outcome'] ) {
				$summary['ok']++;
			} else {
				$summary['failed']++;
			}

			if ( isset( $entry['attempts'] ) && (int) $entry['attempts'] > 1 ) {
				$summary['retried']++;
			}
		}

		return $summary;
	}

	/**
	 * Human-readable label for a log outcome.
	 *
	 * @param string $outcome Outcome key.
	 * @return string
	 */
	public static function outcome_label( $outcome ) {

		$labels = array(
			'applied'   => 'اعمال شد',
			'deviation' => 'رد شد — اختلاف زیاد',
			'stale'     => 'رد شد — قیمت کهنه',
			'transport' => 'خطای اتصال',
			'http'      => 'خطای پاسخ سرویس',
			'shape'     => 'پاسخ نامفهوم',
			'config'    => 'تنظیمات ناقص',
		);

		return isset( $labels[ $outcome ] ) ? $labels[ $outcome ] : $outcome;
	}

	/**
	 * Empties the fetch log.
	 */
	public static function clear_log() {
		delete_option( self::LOG_KEY );
	}

	/**
	 * Seconds since the rate was last changed.
	 *
	 * @return int Zero when no rate has ever been stored.
	 */
	public static function age_seconds() {

		$updated = (int) get_option( 'goldmate_rate_updated_at', 0 );

		if ( $updated <= 0 ) {
			return 0;
		}

		return max( 0, time() - $updated );
	}

	/**
	 * True when the stored rate is older than the configured threshold.
	 *
	 * @return bool
	 */
	public static function is_stale() {

		$hours = goldmate_positive_float( goldmate_option( 'goldmate_stale_hours' ) );

		if ( $hours <= 0 ) {
			return false;
		}

		$updated = (int) get_option( 'goldmate_rate_updated_at', 0 );

		if ( $updated <= 0 ) {
			// A rate that was never recorded counts as stale only once one exists.
			return goldmate_positive_float( goldmate_option( 'goldmate_rate_per_gram' ) ) > 0;
		}

		return self::age_seconds() > $hours * HOUR_IN_SECONDS;
	}
}
