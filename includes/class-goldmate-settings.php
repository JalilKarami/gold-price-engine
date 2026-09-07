<?php
/**
 * Declarative definition of every setting, used for both rendering and saving.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Settings {

	/**
	 * Admin page tabs.
	 *
	 * @return array<string,string>
	 */
	public static function tabs() {
		return array(
			'pricing' => 'قیمت‌گذاری',
			'fetch'   => 'دریافت خودکار قیمت',
			'status'  => 'وضعیت و ابزارها',
		);
	}

	/**
	 * Field definitions for one tab.
	 *
	 * @param string $tab Tab key.
	 * @return array[]
	 */
	public static function fields( $tab ) {

		$fields = array(

			'pricing' => array(
				array(
					'type'  => 'section',
					'title' => 'فرمول قیمت',
					'desc'  => 'وزن × قیمت هر گرم (متناسب با عیار) = مبلغ طلا؛ سپس اجرت، سود، متعلقات و مالیات به آن افزوده می‌شود.',
				),
				array(
					'id'    => 'goldmate_rate_per_gram',
					'title' => 'قیمت روز هر گرم طلای ۱۸ عیار',
					'type'  => 'number',
					'step'  => '1',
					'desc'  => 'به تومان. مثال: 7000000. با تغییر این مقدار، قیمت همه‌ی محصولات طلا در پس‌زمینه به‌روزرسانی می‌شود.',
				),
				array(
					'id'    => 'goldmate_profit_pct',
					'title' => 'درصد سود',
					'type'  => 'number',
					'step'  => '0.01',
					'desc'  => 'روی «مبلغ طلا + اجرت» اعمال می‌شود.',
				),
				array(
					'id'    => 'goldmate_tax_pct',
					'title' => 'درصد مالیات بر ارزش افزوده',
					'type'  => 'number',
					'step'  => '0.01',
					'desc'  => 'روی مبلغ طلا اعمال نمی‌شود.',
				),
				array(
					'id'    => 'goldmate_tax_accessories',
					'title' => 'مالیات روی متعلقات',
					'type'  => 'checkbox',
					'label' => 'ارزش سنگ و نگین هم مشمول مالیات بر ارزش افزوده شود',
					'desc'  => 'پیش‌فرض خاموش است. پیش از تغییر، با حسابدار خود هماهنگ کنید.',
				),
				array(
					'type'  => 'section',
					'title' => 'گرد کردن و مالیات ووکامرس',
				),
				array(
					'id'    => 'goldmate_round_to',
					'title' => 'گرد کردن قیمت نهایی به',
					'type'  => 'number',
					'step'  => '1',
					'desc'  => 'به تومان. مثال: 1000 یعنی گرد شدن به نزدیک‌ترین هزار تومان. عدد 0 یعنی بدون گرد کردن.',
				),
				array(
					'id'      => 'goldmate_round_mode',
					'title'   => 'جهت گرد کردن',
					'type'    => 'select',
					'options' => array(
						'round' => 'نزدیک‌ترین (پیش‌فرض)',
						'ceil'  => 'به بالا — هیچ‌گاه زیر قیمت تمام‌شده',
						'floor' => 'به پایین',
					),
				),
				array(
					'id'      => 'goldmate_wc_tax_status',
					'title'   => 'وضعیت مالیات محصول در ووکامرس',
					'type'    => 'select',
					'options' => array(
						'none'  => 'مالیات ندارد — مالیات داخل قیمت محاسبه شده است',
						'leave' => 'دست نزن — تنظیم مالیات محصول را خودم مدیریت می‌کنم',
					),
					'desc'    => 'حالت «مالیات ندارد» مالیات را داخل قیمت نگه می‌دارد؛ در نتیجه گزارش‌های مالیاتی ووکامرس صفر نشان می‌دهند. اگر فاکتور رسمی صادر می‌کنید، حالت «دست نزن» را انتخاب کرده و نرخ مالیات ووکامرس را خودتان تنظیم کنید.',
				),
				array(
					'type'  => 'section',
					'title' => 'نمایش',
				),
				array(
					'id'    => 'goldmate_show_breakdown',
					'title' => 'نمایش ریز محاسبات',
					'type'  => 'checkbox',
					'label' => 'جدول ریز قیمت در صفحه‌ی محصول نمایش داده شود',
					'desc'  => 'برای محصولات متغیر، جدول با انتخاب هر متغیر به‌روز می‌شود.',
				),
				array(
					'id'    => 'goldmate_show_formula',
					'title' => 'نمایش فرمول قیمت',
					'type'  => 'checkbox',
					'label' => 'فرمول محاسبه، همراه با اعداد همان محصول، زیر جدول نمایش داده شود',
					'desc'  => 'مثال: (۵ گرم × ۳٬۵۰۰٬۰۰۰ تومان) + اجرت + سود + مالیات = قیمت نهایی. مستقل از جدول کار می‌کند؛ می‌توانید فقط فرمول را نشان دهید.',
				),
				array(
					'type'  => 'section',
					'title' => 'سبد خرید و فاکتور',
					'desc'  => 'ریز قیمت در لحظه‌ی ثبت سفارش روی همان سفارش ذخیره می‌شود. با تغییر قیمت روز طلا، فاکتورهای قدیمی دست‌نخورده می‌مانند و همان اعدادی را نشان می‌دهند که مشتری پرداخت کرده است.',
				),
				array(
					'id'      => 'goldmate_order_details',
					'title'   => 'ریز قیمت در سفارش و فاکتور',
					'type'    => 'select',
					'options' => array(
						'full'    => 'کامل — وزن، عیار، قیمت روز، اجرت، سود، متعلقات و مالیات',
						'summary' => 'خلاصه — وزن، عیار، قیمت روز و مالیات (بدون درصد اجرت و سود)',
						'none'    => 'نمایش داده نشود',
					),
					'desc'    => 'این اقلام در صفحه‌ی سفارش، ایمیل‌ها، پنل مدیریت و افزونه‌های فاکتور PDF دیده می‌شوند. حتی در حالت «نمایش داده نشود» ریز محاسبات به‌صورت پنهان روی سفارش ذخیره می‌شود تا بعداً قابل بررسی باشد.',
				),
				array(
					'id'    => 'goldmate_cart_details',
					'title' => 'ریز قیمت در سبد خرید',
					'type'  => 'checkbox',
					'label' => 'همین اقلام زیر هر کالا در سبد خرید و صفحه‌ی پرداخت هم نمایش داده شود',
					'desc'  => 'در سبد خرید، قیمت روزِ همان لحظه نمایش داده می‌شود.',
				),
				array(
					'type'  => 'section',
					'title' => 'حذف افزونه',
				),
				array(
					'id'    => 'goldmate_delete_data',
					'title' => 'پاک کردن داده‌ها هنگام حذف افزونه',
					'type'  => 'checkbox',
					'label' => 'با حذف افزونه، تنظیمات و اطلاعات طلای محصولات هم پاک شود',
					'desc'  => 'پیش‌فرض خاموش است. اگر روشن کنید، هنگام حذف افزونه وزن، عیار، اجرت و متعلقات همه‌ی محصولات برای همیشه پاک می‌شود و بازگشتی ندارد. غیرفعال کردن افزونه هیچ‌چیز را پاک نمی‌کند.',
				),
			),

			'fetch' => array(
				array(
					'type'  => 'section',
					'title' => 'منبع قیمت',
					'desc'  => 'در حالت دستی، قیمت روز را خودتان در تب «قیمت‌گذاری» وارد می‌کنید.',
				),
				array(
					'id'      => 'goldmate_rate_source',
					'title'   => 'منبع',
					'type'    => 'select',
					'options' => wp_list_pluck( Goldmate_Rates::presets(), 'label' ),
					'desc'    => 'پس از تغییر منبع، ذخیره کنید و سپس با دکمه‌ی «دریافت آزمایشی» در تب وضعیت، پاسخ سرویس را بررسی کنید.',
				),
				array(
					'id'      => 'goldmate_api_url',
					'title'   => 'آدرس سرویس',
					'type'    => 'text',
					'depends' => array( 'goldmate_rate_source' => array( 'custom', 'brsapi', 'atn', 'tgju' ) ),
					'desc'    => 'آدرس کامل JSON. اگر کلید باید داخل آدرس بیاید، عبارت {KEY} را در جای آن قرار دهید.',
				),
				array(
					'id'      => 'goldmate_api_key',
					'title'   => 'کلید API',
					'type'    => 'password',
					'depends' => array( 'goldmate_rate_source' => array( 'custom', 'brsapi', 'atn', 'tgju' ) ),
					'desc'    => 'اگر سرویس کلید نمی‌خواهد، خالی بگذارید.',
				),
				array(
					'id'      => 'goldmate_api_key_header',
					'title'   => 'نام هدر کلید',
					'type'    => 'text',
					'depends' => array( 'goldmate_rate_source' => array( 'custom', 'brsapi', 'atn', 'tgju' ) ),
					'desc'    => 'اگر کلید باید در هدر ارسال شود، نام هدر را وارد کنید. مثال: X-API-KEY. خالی یعنی کلید فقط در آدرس استفاده می‌شود.',
				),
				array(
					'id'      => 'goldmate_api_path',
					'title'   => 'مسیر مقدار در پاسخ',
					'type'    => 'text',
					'depends' => array( 'goldmate_rate_source' => array( 'custom', 'brsapi', 'atn', 'tgju' ) ),
					'desc'    => 'مسیر نقطه‌ای تا عدد قیمت. مثال: current.geram18.p — برای انتخاب یک قلم از فهرست، به‌جای شماره‌ی آن از نماد استفاده کنید: gold[symbol=IR_GOLD_18K].price',
				),
				array(
					'id'      => 'goldmate_api_multiplier',
					'title'   => 'ضریب تبدیل',
					'type'    => 'number',
					'step'    => '0.0001',
					'depends' => array( 'goldmate_rate_source' => array( 'custom', 'brsapi', 'atn', 'tgju' ) ),
					'desc'    => 'عدد دریافتی در این ضریب ضرب می‌شود تا به «تومان بر گرم» برسد. برای پاسخ ریالی: 0.1',
				),
				array(
					'id'      => 'goldmate_api_time_path',
					'title'   => 'مسیر زمان به‌روزرسانی',
					'type'    => 'text',
					'depends' => array( 'goldmate_rate_source' => array( 'custom', 'brsapi', 'atn', 'tgju' ) ),
					'desc'    => 'مسیر نقطه‌ای تا زمان به‌روزرسانی در پاسخ سرویس؛ هم عدد unix و هم تاریخ متنی پذیرفته می‌شود. مثال: gold[symbol=IR_GOLD_18K].time_unix — خالی یعنی تازگی پاسخ بررسی نشود.',
				),
				array(
					'id'      => 'goldmate_api_max_age',
					'title'   => 'بیشینه‌ی قدمت پاسخ (دقیقه)',
					'type'    => 'number',
					'step'    => '1',
					'depends' => array( 'goldmate_rate_source' => array( 'custom', 'brsapi', 'atn', 'tgju' ) ),
					'desc'    => 'اگر زمان به‌روزرسانی پاسخ از این مقدار قدیمی‌تر باشد، قیمت اعمال نمی‌شود. سرویس می‌تواند بدون هیچ خطایی عدد دیروز را تحویل دهد؛ این محافظ همان حالت را می‌گیرد. عدد 0 یعنی بدون بررسی.',
				),
				array(
					'id'      => 'goldmate_fetch_interval',
					'title'   => 'فاصله‌ی دریافت (دقیقه)',
					'type'    => 'number',
					'step'    => '1',
					'depends' => array( 'goldmate_rate_source' => array( 'custom', 'brsapi', 'atn', 'tgju' ) ),
					'desc'    => 'کمینه ۵ دقیقه. هر دریافت که قیمت را تغییر دهد، یک به‌روزرسانی دسته‌ای در پس‌زمینه راه می‌اندازد.',
				),
				array(
					'type'  => 'section',
					'title' => 'محافظ‌ها',
					'desc'  => 'این تنظیمات جلوی خراب شدن قیمت کل فروشگاه بر اثر یک پاسخ نادرست سرویس را می‌گیرند.',
				),
				array(
					'id'    => 'goldmate_max_deviation',
					'title' => 'بیشینه‌ی اختلاف مجاز (٪)',
					'type'  => 'number',
					'step'  => '0.1',
					'desc'  => 'اگر قیمت دریافتی بیش از این مقدار با قیمت فعلی اختلاف داشته باشد، اعمال نمی‌شود و برای تأیید دستی نگه داشته می‌شود. عدد 0 یعنی بدون محدودیت.',
				),
				array(
					'id'    => 'goldmate_stale_hours',
					'title' => 'قیمت پس از چند ساعت کهنه است؟',
					'type'  => 'number',
					'step'  => '0.5',
					'desc'  => 'عدد 0 یعنی هیچ‌گاه کهنه در نظر گرفته نشود.',
				),
				array(
					'id'      => 'goldmate_stale_action',
					'title'   => 'رفتار در حالت کهنه',
					'type'    => 'select',
					'options' => array(
						'none'   => 'کاری انجام نشود',
						'notice' => 'فقط به مدیر هشدار داده شود',
						'block'  => 'خرید محصولات طلا موقتاً غیرفعال شود',
					),
				),
			),
		);

		return isset( $fields[ $tab ] ) ? $fields[ $tab ] : array();
	}

	/**
	 * Sanitises an endpoint URL without destroying the {KEY} placeholder.
	 *
	 * `esc_url_raw()` strips braces, which silently turns `?key={KEY}` into
	 * `?key=KEY` — the provider then rejects the literal word as an invalid key,
	 * and nothing in the settings screen shows what happened. Swapping the
	 * placeholder for a brace-free token across the call keeps the URL validation
	 * and the placeholder both intact.
	 *
	 * @param string $url Submitted URL.
	 * @return string
	 */
	protected static function sanitize_endpoint_url( $url ) {

		$token = 'goldmateKeyPlaceholder';

		$url = str_replace( '{KEY}', $token, $url );
		$url = esc_url_raw( $url, array( 'http', 'https' ) );

		return str_replace( $token, '{KEY}', $url );
	}

	/**
	 * Sanitises and stores one tab's submitted values.
	 *
	 * @param string $tab  Tab key.
	 * @param array  $post Raw $_POST.
	 * @return string[] Human-readable warnings.
	 */
	public static function save( $tab, $post ) {

		$warnings = array();

		foreach ( self::fields( $tab ) as $field ) {

			if ( empty( $field['id'] ) ) {
				continue;
			}

			$id = $field['id'];

			switch ( $field['type'] ) {

				case 'checkbox':
					update_option( $id, isset( $post[ $id ] ) ? 'yes' : 'no' );
					break;

				case 'number':
					if ( ! isset( $post[ $id ] ) ) {
						break;
					}
					$value = goldmate_positive_float( wp_unslash( $post[ $id ] ) );

					if ( 'goldmate_fetch_interval' === $id && $value > 0 && $value < 5 ) {
						$value      = 5;
						$warnings[] = 'فاصله‌ی دریافت به کمینه‌ی مجاز، یعنی ۵ دقیقه، تنظیم شد.';
					}

					// The rate has its own writer so history and repricing stay in sync.
					if ( 'goldmate_rate_per_gram' === $id ) {
						break;
					}

					update_option( $id, $value );
					break;

				case 'select':
					if ( ! isset( $post[ $id ] ) ) {
						break;
					}
					$value = sanitize_text_field( wp_unslash( $post[ $id ] ) );
					if ( isset( $field['options'] ) && ! array_key_exists( $value, $field['options'] ) ) {
						break;
					}
					update_option( $id, $value );
					break;

				case 'password':
				case 'text':
				default:
					if ( ! isset( $post[ $id ] ) ) {
						break;
					}
					$value = sanitize_text_field( wp_unslash( $post[ $id ] ) );
					if ( 'goldmate_api_url' === $id && '' !== $value ) {
						$value = self::sanitize_endpoint_url( $value );
					}
					update_option( $id, $value );
					break;
			}
		}

		if ( 'fetch' === $tab ) {
			$warnings = array_merge( $warnings, self::fetch_warnings() );
		}

		return $warnings;
	}

	/**
	 * Warns when a saved key would never actually reach the provider.
	 *
	 * A key that is set but sent nowhere produces a plain `401` from the service,
	 * which reads like a wrong key rather than a key that was never delivered.
	 *
	 * @return string[]
	 */
	protected static function fetch_warnings() {

		$warnings = array();

		if ( 'manual' === goldmate_option( 'goldmate_rate_source' ) ) {
			return $warnings;
		}

		$url    = (string) goldmate_option( 'goldmate_api_url' );
		$key    = trim( (string) goldmate_option( 'goldmate_api_key' ) );
		$header = trim( (string) goldmate_option( 'goldmate_api_key_header' ) );

		$in_url = ( false !== strpos( $url, '{KEY}' ) || false !== strpos( $url, '%KEY%' ) );

		if ( '' !== $key && ! $in_url && '' === $header ) {
			$warnings[] = 'کلید API ذخیره شد ولی هیچ جایی برای ارسالش تعیین نشده است. یا عبارت {KEY} را در آدرس سرویس بگذارید، یا نام هدر کلید را وارد کنید — وگرنه سرویس پاسخ ۴۰۱ می‌دهد.';
		}

		if ( $in_url && '' === $key ) {
			$warnings[] = 'آدرس سرویس جای {KEY} دارد ولی کلید API خالی است؛ عبارت {KEY} همان‌طور برای سرویس فرستاده می‌شود.';
		}

		return $warnings;
	}
}
