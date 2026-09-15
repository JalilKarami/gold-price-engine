<?php
/**
 * Per-item rate fetchers: JSON API and HTML/CSS selector scraping.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Fetcher {

	const ITEM_CRON = 'goldmate_fetch_rate_items';

	/**
	 * Endpoint presets offered in the admin (source type dropdowns).
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
				'note'       => 'کلید در آدرس جایگزین {KEY} می‌شود. پاسخ به تومان است؛ ضریب ۱.',
			),
			'atn'    => array(
				'label'      => 'ATN (وب‌سرویس اختصاصی)',
				'url'        => 'http://77.104.95.33/api/v1/prices/atn',
				'path'       => 'atn.products[code=melted_gold_tomorrow].gram_sell',
				'multiplier' => 1,
				'key_header' => 'X-API-Key',
				'time_path'  => 'atn.updated_at',
				'max_age'    => 1440,
				'note'       => 'کلید در هدر X-API-Key. مقدار gram_sell قیمت گرم ۱۸ عیار به تومان است.',
			),
			'tgju'   => array(
				'label'      => 'TGJU',
				'url'        => 'https://call1.tgju.org/ajax.json',
				'path'       => 'current.geram18.p',
				'multiplier' => 0.1,
				'key_header' => '',
				'note'       => 'پاسخ به ریال است؛ ضریب ۰٫۱ آن را به تومان تبدیل می‌کند.',
			),
		);
	}

	/**
	 * Human-readable label for a provider/source key.
	 *
	 * @param string $provider Raw key, e.g. `atn`.
	 * @return string
	 */
	public static function provider_label( $provider ) {

		if ( '' === (string) $provider ) {
			return '';
		}

		$presets = self::presets();

		return isset( $presets[ $provider ] ) ? $presets[ $provider ]['label'] : (string) $provider;
	}

	/**
	 * Boots the multi-item cron walker.
	 */
	public static function init() {
		add_action( self::ITEM_CRON, array( __CLASS__, 'run_due_items' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
	}

	/**
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		$schedules['goldmate_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'هر ۵ دقیقه (گلدمیت آیتم‌ها)',
		);
		return $schedules;
	}

	/**
	 * Keep a frequent walker registered (Action Scheduler preferred).
	 */
	public static function ensure_scheduled() {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::ITEM_CRON, array(), 'goldmate' ) ) {
				as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, self::ITEM_CRON, array(), 'goldmate' );
			}
			// Clear legacy WP-Cron duplicate if AS is available.
			$ts = wp_next_scheduled( self::ITEM_CRON );
			if ( $ts ) {
				wp_unschedule_event( $ts, self::ITEM_CRON );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::ITEM_CRON ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'goldmate_five_minutes', self::ITEM_CRON );
		}
	}

	/**
	 * Unschedule on deactivate.
	 */
	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ITEM_CRON, null, 'goldmate' );
		}
		$ts = wp_next_scheduled( self::ITEM_CRON );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::ITEM_CRON );
		}
	}

	/**
	 * Fetch every due auto-fetch item.
	 */
	public static function run_due_items() {
		foreach ( Goldmate_Rate_Items::due_for_fetch() as $item ) {
			self::fetch_and_apply( $item );
		}
	}

	/**
	 * Fetch one item and apply when guards pass.
	 *
	 * @param array|int $item Item row or ID.
	 * @param bool      $force Skip min-change.
	 * @return array{ok:bool,rate:float,error:string,raw:string}
	 */
	public static function fetch_and_apply( $item, $force = false ) {
		if ( is_numeric( $item ) ) {
			$item = Goldmate_Rate_Items::get( (int) $item );
		}
		if ( ! $item ) {
			return array( 'ok' => false, 'rate' => 0.0, 'error' => 'آیتم یافت نشد.', 'raw' => '' );
		}

		$result = self::fetch_item( $item );
		Goldmate_Rate_Items::update(
			(int) $item['id'],
			array(
				'checked_at' => time(),
				'last_error' => $result['error'],
			)
		);

		if ( '' !== $result['error'] || $result['rate'] <= 0 ) {
			return $result;
		}

		$rate = self::apply_adjust( $result['rate'], $item );

		// Shop currency is Toman; convert Rial sources unless a custom multiplier already did.
		if ( isset( $item['unit'] ) && 'rial' === $item['unit'] && abs( (float) $item['multiplier'] - 1.0 ) < 0.0000001 ) {
			$rate = $rate / 10;
		}

		if ( ! $force && ! self::passes_min_change( $rate, (float) $item['rate'] ) ) {
			$result['error'] = 'تغییر کمتر از آستانه بود.';
			$result['ok']    = false;
			return $result;
		}

		$dev = self::deviation_pct( $rate, (float) $item['rate'] );
		$max = goldmate_positive_float( goldmate_option( 'goldmate_max_deviation' ) );
		if ( $max > 0 && (float) $item['rate'] > 0 && $dev > $max ) {
			$result['error'] = sprintf( 'اختلاف %.1f٪ بیش از سقف مجاز است.', $dev );
			$result['ok']    = false;
			$result['rate']  = $rate;
			return $result;
		}

		Goldmate_Rate_Items::set_rate(
			(int) $item['id'],
			$rate,
			'auto',
			array(
				'provider' => ! empty( $result['provider'] )
					? (string) $result['provider']
					: (string) $item['source_type'],
			)
		);

		$result['ok']   = true;
		$result['rate'] = $rate;
		$result['error'] = '';
		return $result;
	}

	/**
	 * Fetch without applying.
	 *
	 * @param array $item Item.
	 * @return array{ok:bool,rate:float,error:string,raw:string}
	 */
	public static function fetch_item( $item ) {
		$type = isset( $item['source_type'] ) ? $item['source_type'] : 'manual';
		$cfg  = isset( $item['source_config'] ) && is_array( $item['source_config'] ) ? $item['source_config'] : array();

		if ( 'manual' === $type || '' === $type ) {
			return array( 'ok' => false, 'rate' => 0.0, 'error' => 'منبع دستی است.', 'raw' => '' );
		}

		if ( 'html' === $type ) {
			return self::fetch_html( $cfg, (float) $item['multiplier'] );
		}

		$resolved = self::resolve_source( $type, $cfg, (float) $item['multiplier'] );
		$result   = self::fetch_json( $resolved['cfg'], $resolved['multiplier'], $resolved['type'] );

		if ( ! empty( $result['ok'] ) ) {
			$result['provider'] = $resolved['type'];
			return $result;
		}

		// Ordered fallbacks (e.g. brsapi, tgju) when the primary preset fails.
		$fallbacks = isset( $cfg['fallbacks'] ) ? $cfg['fallbacks'] : array();
		if ( is_string( $fallbacks ) ) {
			$fallbacks = array_filter( array_map( 'trim', explode( ',', $fallbacks ) ) );
		}
		if ( ! is_array( $fallbacks ) ) {
			$fallbacks = array();
		}

		$errors = array( $resolved['type'] . ': ' . $result['error'] );
		foreach ( $fallbacks as $fb ) {
			$fb = sanitize_key( $fb );
			if ( '' === $fb || $fb === $type || 'manual' === $fb || 'html' === $fb ) {
				continue;
			}
			$fb_cfg = $cfg;
			// BrsApi uses its own key when provided; otherwise reuse the item key.
			if ( 'brsapi' === $fb && ! empty( $cfg['brsapi_key'] ) ) {
				$fb_cfg['api_key'] = $cfg['brsapi_key'];
			}
			$try = self::resolve_source( $fb, $fb_cfg, (float) $item['multiplier'] );
			$sec = self::fetch_json( $try['cfg'], $try['multiplier'], $try['type'] );
			if ( ! empty( $sec['ok'] ) ) {
				$sec['provider']      = $try['type'];
				$sec['fallback_used'] = true;
				return $sec;
			}
			$errors[] = $try['type'] . ': ' . $sec['error'];
		}

		if ( ! empty( $fallbacks ) ) {
			$result['error'] = implode( ' | ', $errors );
		}

		return $result;
	}

	/**
	 * Expands a named preset (atn / brsapi / tgju) into concrete URL/path settings.
	 *
	 * @param string $type       Source type.
	 * @param array  $cfg        Item source_config.
	 * @param float  $multiplier Item multiplier.
	 * @return array{type:string,cfg:array,multiplier:float}
	 */
	public static function resolve_source( $type, $cfg, $multiplier = 1.0 ) {
		$type = sanitize_key( $type );
		$cfg  = is_array( $cfg ) ? $cfg : array();

		$presets = self::presets();

		if ( isset( $presets[ $type ] ) && ! empty( $presets[ $type ]['url'] ) ) {
			$p = $presets[ $type ];

			$cfg['url'] = (string) $p['url'];
			if ( '' === trim( (string) ( isset( $cfg['path'] ) ? $cfg['path'] : '' ) ) && isset( $p['path'] ) ) {
				$cfg['path'] = (string) $p['path'];
			}
			if ( ! empty( $p['key_header'] ) ) {
				$cfg['api_key_header'] = (string) $p['key_header'];
			}
			if ( '' === trim( (string) ( isset( $cfg['time_path'] ) ? $cfg['time_path'] : '' ) ) && ! empty( $p['time_path'] ) ) {
				$cfg['time_path'] = (string) $p['time_path'];
			}
			if ( empty( $cfg['max_age'] ) && ! empty( $p['max_age'] ) ) {
				$cfg['max_age'] = (int) $p['max_age'];
			}
			if ( isset( $p['multiplier'] ) && (float) $p['multiplier'] > 0 ) {
				$multiplier = (float) $p['multiplier'];
			}
		}

		return array(
			'type'       => $type,
			'cfg'        => $cfg,
			'multiplier' => $multiplier > 0 ? $multiplier : 1.0,
		);
	}

	/**
	 * JSON endpoint fetch.
	 *
	 * @param array  $cfg        Config.
	 * @param float  $multiplier Multiplier.
	 * @param string $type       Source type label.
	 * @return array
	 */
	public static function fetch_json( $cfg, $multiplier = 1.0, $type = 'json' ) {
		$url    = isset( $cfg['url'] ) ? (string) $cfg['url'] : '';
		$key    = isset( $cfg['api_key'] ) ? trim( (string) $cfg['api_key'] ) : '';
		$header = isset( $cfg['api_key_header'] ) ? trim( (string) $cfg['api_key_header'] ) : '';
		$path   = isset( $cfg['path'] ) ? (string) $cfg['path'] : '';

		if ( '' === $url ) {
			return array( 'ok' => false, 'rate' => 0.0, 'error' => 'آدرس سرویس خالی است.', 'raw' => '' );
		}

		$request_url = str_replace( array( '{KEY}', '%KEY%' ), rawurlencode( $key ), $url );
		$args        = array(
			'timeout' => 20,
			'headers' => array( 'Accept' => 'application/json' ),
		);
		if ( '' !== $key && '' !== $header ) {
			$args['headers'][ $header ] = $key;
		}

		$response = wp_remote_get( $request_url, $args );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'rate' => 0.0, 'error' => $response->get_error_message(), 'raw' => '' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$raw  = mb_substr( $body, 0, 400 );

		if ( $code < 200 || $code >= 300 ) {
			return array( 'ok' => false, 'rate' => 0.0, 'error' => 'HTTP ' . $code, 'raw' => $raw );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return array( 'ok' => false, 'rate' => 0.0, 'error' => 'پاسخ JSON معتبر نیست.', 'raw' => $raw );
		}

		$freshness = self::freshness_error( $data, $cfg );
		if ( '' !== $freshness ) {
			return array( 'ok' => false, 'rate' => 0.0, 'error' => $freshness, 'raw' => $raw );
		}

		$value = '' !== $path && function_exists( 'goldmate_json_path' )
			? goldmate_json_path( $data, $path )
			: null;

		if ( null === $value ) {
			// Common fallbacks.
			foreach ( array( 'price', 'rate', 'value', 'p', 'geram18' ) as $k ) {
				if ( isset( $data[ $k ] ) ) {
					$value = $data[ $k ];
					break;
				}
			}
		}

		$rate = goldmate_positive_float( $value );
		if ( $multiplier > 0 && $multiplier != 1.0 ) {
			$rate = $rate * $multiplier;
		}

		if ( $rate <= 0 ) {
			return array( 'ok' => false, 'rate' => 0.0, 'error' => 'مقدار قیمت در پاسخ پیدا نشد.', 'raw' => $raw );
		}

		return array( 'ok' => true, 'rate' => $rate, 'error' => '', 'raw' => $raw );
	}

	/**
	 * Rejects a JSON feed when its published timestamp is older than max_age minutes.
	 *
	 * @param mixed $data Decoded JSON.
	 * @param array $cfg  Item source_config (time_path, max_age).
	 * @return string Empty when OK / not checked.
	 */
	protected static function freshness_error( $data, $cfg ) {
		$path    = isset( $cfg['time_path'] ) ? trim( (string) $cfg['time_path'] ) : '';
		$max_age = isset( $cfg['max_age'] ) ? (int) $cfg['max_age'] : 0;

		if ( '' === $path || $max_age <= 0 || ! function_exists( 'goldmate_json_path' ) ) {
			return '';
		}

		$stamp = goldmate_json_path( $data, $path );
		if ( null === $stamp || is_array( $stamp ) || is_object( $stamp ) ) {
			return 'زمان به‌روزرسانی در پاسخ سرویس پیدا نشد.';
		}

		$time = is_numeric( $stamp ) ? (int) $stamp : (int) strtotime( (string) $stamp );
		if ( $time <= 0 ) {
			return 'زمان به‌روزرسانی پاسخ سرویس خوانده نشد.';
		}

		$age = time() - $time;
		if ( $age < -HOUR_IN_SECONDS ) {
			return sprintf(
				'زمان اعلام‌شده‌ی سرویس (%s) در آینده است؛ احتمالاً منطقه‌ی زمانی ندارد.',
				function_exists( 'goldmate_format_time' ) ? goldmate_format_time( $time ) : date( 'Y-m-d H:i', $time )
			);
		}

		if ( $age <= $max_age * MINUTE_IN_SECONDS ) {
			return '';
		}

		return sprintf(
			'قیمت سرویس مربوط به %s است و بیش از حد مجاز (%s دقیقه) قدمت دارد.',
			function_exists( 'goldmate_format_time' ) ? goldmate_format_time( $time ) : date( 'Y-m-d H:i', $time ),
			number_format_i18n( $max_age )
		);
	}

	/**
	 * HTML page scrape via CSS-like selector (supports :eq(n) and nested spaces).
	 *
	 * @param array $cfg        Config: url, selector, decimal_sep, thousands_sep.
	 * @param float $multiplier Multiplier.
	 * @return array
	 */
	public static function fetch_html( $cfg, $multiplier = 1.0 ) {
		$url      = isset( $cfg['url'] ) ? (string) $cfg['url'] : '';
		$selector = isset( $cfg['selector'] ) ? trim( (string) $cfg['selector'] ) : '';
		$dec      = isset( $cfg['decimal_sep'] ) ? (string) $cfg['decimal_sep'] : '.';
		$thou     = isset( $cfg['thousands_sep'] ) ? (string) $cfg['thousands_sep'] : ',';

		if ( '' === $url || '' === $selector ) {
			return array( 'ok' => false, 'rate' => 0.0, 'error' => 'آدرس یا سلکتور خالی است.', 'raw' => '' );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 25,
				'headers' => array(
					'User-Agent' => 'GoldMate/' . ( defined( 'GOLDMATE_VERSION' ) ? GOLDMATE_VERSION : '3' ) . '; WordPress',
					'Accept'     => 'text/html',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'rate' => 0.0, 'error' => $response->get_error_message(), 'raw' => '' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$html = (string) wp_remote_retrieve_body( $response );
		$raw  = mb_substr( wp_strip_all_tags( $html ), 0, 200 );

		if ( $code < 200 || $code >= 300 || '' === $html ) {
			return array( 'ok' => false, 'rate' => 0.0, 'error' => 'HTTP ' . $code, 'raw' => $raw );
		}

		$text = self::extract_by_selector( $html, $selector );
		if ( null === $text || '' === trim( $text ) ) {
			return array( 'ok' => false, 'rate' => 0.0, 'error' => 'سلکتور مقداری برنگرداند.', 'raw' => $raw );
		}

		$rate = self::parse_number( $text, $dec, $thou );
		if ( $multiplier > 0 && abs( $multiplier - 1.0 ) > 0.0000001 ) {
			$rate = $rate * $multiplier;
		}

		if ( $rate <= 0 ) {
			return array( 'ok' => false, 'rate' => 0.0, 'error' => 'عدد معتبری از HTML خوانده نشد: ' . mb_substr( $text, 0, 40 ), 'raw' => $raw );
		}

		return array( 'ok' => true, 'rate' => $rate, 'error' => '', 'raw' => mb_substr( $text, 0, 80 ) );
	}

	/**
	 * Very small CSS subset: `.class`, `#id`, `tag`, spaces, `:eq(n)`.
	 *
	 * @param string $html     HTML.
	 * @param string $selector Selector.
	 * @return string|null
	 */
	public static function extract_by_selector( $html, $selector ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return null;
		}

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();

		$xpath = new DOMXPath( $dom );
		$query = self::css_to_xpath( $selector );
		if ( '' === $query ) {
			return null;
		}

		$nodes = @$xpath->query( $query );
		if ( ! $nodes || 0 === $nodes->length ) {
			return null;
		}

		$node = $nodes->item( 0 );
		return $node ? trim( preg_replace( '/\s+/u', ' ', $node->textContent ) ) : null;
	}

	/**
	 * Convert a limited CSS selector (with :eq) to XPath.
	 *
	 * @param string $selector CSS.
	 * @return string
	 */
	public static function css_to_xpath( $selector ) {
		$selector = trim( $selector );
		$selector = preg_replace( '/\s+/', ' ', $selector );

		// Strip wrapping parentheses used in some Ratesbox examples.
		if ( strlen( $selector ) > 1 && '(' === $selector[0] && ')' === substr( $selector, -1 ) ) {
			$selector = substr( $selector, 1, -1 );
		}

		$parts = preg_split( '/\s+/', $selector );
		$xpath = '//';
		$first = true;

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			$eq = null;
			if ( preg_match( '/^(.*):eq\((\d+)\)$/', $part, $m ) ) {
				$part = $m[1];
				$eq   = (int) $m[2];
			}

			$tag   = '*';
			$class = '';
			$id    = '';

			if ( preg_match( '/^([a-zA-Z0-9]+)/', $part, $m ) ) {
				$tag  = $m[1];
				$part = substr( $part, strlen( $tag ) );
			}

			if ( preg_match( '/^\.([a-zA-Z0-9_-]+)/', $part, $m ) ) {
				$class = $m[1];
				$part  = substr( $part, strlen( $m[0] ) );
			} elseif ( preg_match( '/^#([a-zA-Z0-9_-]+)/', $part, $m ) ) {
				$id   = $m[1];
				$part = substr( $part, strlen( $m[0] ) );
			}

			// Extra .classes
			$extra_classes = array();
			while ( preg_match( '/^\.([a-zA-Z0-9_-]+)/', $part, $m ) ) {
				$extra_classes[] = $m[1];
				$part            = substr( $part, strlen( $m[0] ) );
			}

			$node = $tag;
			$preds = array();
			if ( $id ) {
				$preds[] = '@id="' . $id . '"';
			}
			if ( $class ) {
				$preds[] = 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';
			}
			foreach ( $extra_classes as $c ) {
				$preds[] = 'contains(concat(" ", normalize-space(@class), " "), " ' . $c . ' ")';
			}

			$segment = $node;
			if ( $preds ) {
				$segment .= '[' . implode( ' and ', $preds ) . ']';
			}

			if ( null !== $eq ) {
				// XPath is 1-based; :eq is 0-based.
				$segment = '(' . ( $first ? '//' . $segment : $segment ) . ')[' . ( $eq + 1 ) . ']';
				$xpath   = $first ? $segment : $xpath . '//' . ltrim( $segment, '(' );
				// Rebuild carefully:
				if ( $first ) {
					$xpath = '(//' . $node . ( $preds ? '[' . implode( ' and ', $preds ) . ']' : '' ) . ')[' . ( $eq + 1 ) . ']';
				} else {
					$xpath = '(' . $xpath . '//' . $node . ( $preds ? '[' . implode( ' and ', $preds ) . ']' : '' ) . ')[' . ( $eq + 1 ) . ']';
				}
			} else {
				$xpath = $first ? '//' . $segment : $xpath . '//' . $segment;
			}

			$first = false;
		}

		return $xpath;
	}

	/**
	 * @param string $text Number text.
	 * @param string $dec  Decimal sep.
	 * @param string $thou Thousands sep.
	 * @return float
	 */
	public static function parse_number( $text, $dec = '.', $thou = ',' ) {
		$text = goldmate_normalize_digits( $text );
		$text = preg_replace( '/[^\d\.,\-/]/', '', $text );
		if ( $thou !== '' ) {
			$text = str_replace( $thou, '', $text );
		}
		if ( $dec !== '.' && $dec !== '' ) {
			$text = str_replace( $dec, '.', $text );
		}
		return goldmate_positive_float( $text );
	}

	/**
	 * @param float $rate Rate.
	 * @param array $item Item.
	 * @return float
	 */
	public static function apply_adjust( $rate, $item ) {
		$mode = isset( $item['adjust_mode'] ) ? $item['adjust_mode'] : 'none';
		$adj  = isset( $item['adjust_value'] ) ? (float) $item['adjust_value'] : 0.0;
		if ( 'none' === $mode || 0.0 === $adj ) {
			return $rate;
		}
		if ( 'pct' === $mode ) {
			return goldmate_positive_float( $rate * ( 1 + ( $adj / 100 ) ) );
		}
		if ( 'fixed' === $mode ) {
			return goldmate_positive_float( $rate + $adj );
		}
		return $rate;
	}

	/**
	 * @param float $new_rate New.
	 * @param float $old_rate Old.
	 * @return bool
	 */
	protected static function passes_min_change( $new_rate, $old_rate ) {
		if ( $old_rate <= 0 ) {
			return true;
		}
		$min_pct = goldmate_positive_float( goldmate_option( 'goldmate_min_change_pct' ) );
		$min_amt = goldmate_positive_float( goldmate_option( 'goldmate_min_change_amount' ) );
		$diff    = abs( $new_rate - $old_rate );
		$pct     = ( $diff / $old_rate ) * 100;

		if ( $min_pct <= 0 && $min_amt <= 0 ) {
			return true;
		}
		if ( $min_pct > 0 && $pct >= $min_pct ) {
			return true;
		}
		if ( $min_amt > 0 && $diff >= $min_amt ) {
			return true;
		}
		return false;
	}

	/**
	 * @param float $new_rate New.
	 * @param float $old_rate Old.
	 * @return float
	 */
	protected static function deviation_pct( $new_rate, $old_rate ) {
		if ( $old_rate <= 0 ) {
			return 0.0;
		}
		return abs( ( $new_rate - $old_rate ) / $old_rate ) * 100;
	}
}
