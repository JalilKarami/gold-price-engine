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
			'general'    => 'عمومی',
			'pricing'    => 'قیمت‌گذاری',
			'fetch'      => 'تنظیمات فراخوانی قیمت',
			'discounts'  => 'تخفیف',
			'calculator' => 'ماشین‌حساب',
			'components' => 'اجزای قیمت',
			'shortcodes' => 'شورتکدها',
			'tools'      => 'ابزارها',
			'status'     => 'وضعیت',
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

			'general' => self::general_fields(),

			'pricing' => array(
				array(
					'type'  => 'section',
					'title' => 'فرمول قیمت',
					'desc'  => 'وزن × قیمت هر گرم (متناسب با عیار) = مبلغ طلا؛ سپس اجرت (درصدی یا ثابت تومان/گرم)، سود، متعلقات و مالیات به آن افزوده می‌شود.',
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
					'id'      => 'goldmate_default_wage_mode',
					'title'   => 'نوع اجرت پیش‌فرض',
					'type'    => 'select',
					'options' => array(
						'pct'      => 'درصدی از مبلغ طلا',
						'fixed'    => 'رقم ثابت به ازای هر گرم (تومان)',
						'combined' => 'ترکیبی (ثابت + درصد)',
					),
					'desc'    => 'برای محصولاتی که نوع اجرت را مشخص نکرده‌اند. هر محصول می‌تواند جداگانه درصدی یا ثابت باشد.',
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
					'id'    => 'goldmate_live_interval',
					'title' => 'بروزرسانی آنی نرخ در فرانت (ثانیه)',
					'type'  => 'number',
					'step'  => '1',
					'desc'  => 'بنر نرخ، تابلو و قیمت محصول بدون رفرش صفحه به‌روز می‌شود. کمینه عملی ۱۵ ثانیه است. عدد 0 یعنی غیرفعال. سبد خرید همچنان پس از اتمام به‌روزرسانی دسته‌ای قیمت ووکامرس را می‌گیرد.',
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
					'title' => 'منبع جایگزین',
					'desc'  => 'اگر منبع اصلی پاسخ ندهد یا قیمت کهنه باشد، همین دریافت از منبع جایگزین تلاش می‌کند. فقط طلای ۱۸ عیار.',
				),
				array(
					'id'      => 'goldmate_fallback_source',
					'title'   => 'منبع جایگزین',
					'type'    => 'select',
					'options' => array(
						'none'   => 'بدون جایگزین',
						'brsapi' => 'BrsApi.ir',
						'atn'    => 'ATN',
						'tgju'   => 'TGJU',
						'custom' => 'آدرس دلخواه (JSON)',
					),
					'depends' => array( 'goldmate_rate_source' => array( 'custom', 'brsapi', 'atn', 'tgju' ) ),
					'desc'    => 'باید با منبع اصلی فرق داشته باشد. برای پیش‌فرض‌های BrsApi/ATN/TGJU فیلدهای زیر فقط در حالت «دلخواه» لازم‌اند؛ کلید جداگانه برای جایگزین اختیاری است.',
				),
				array(
					'id'      => 'goldmate_fallback_api_url',
					'title'   => 'آدرس سرویس جایگزین',
					'type'    => 'text',
					'depends' => array( 'goldmate_fallback_source' => array( 'custom' ) ),
				),
				array(
					'id'      => 'goldmate_fallback_api_key',
					'title'   => 'کلید API جایگزین',
					'type'    => 'password',
					'depends' => array( 'goldmate_fallback_source' => array( 'custom', 'brsapi', 'atn' ) ),
					'desc'    => 'خالی یعنی همان کلید منبع اصلی استفاده شود (اگر پر باشد).',
				),
				array(
					'id'      => 'goldmate_fallback_api_key_header',
					'title'   => 'نام هدر کلید جایگزین',
					'type'    => 'text',
					'depends' => array( 'goldmate_fallback_source' => array( 'custom' ) ),
				),
				array(
					'id'      => 'goldmate_fallback_api_path',
					'title'   => 'مسیر مقدار جایگزین',
					'type'    => 'text',
					'depends' => array( 'goldmate_fallback_source' => array( 'custom' ) ),
				),
				array(
					'id'      => 'goldmate_fallback_api_multiplier',
					'title'   => 'ضریب تبدیل جایگزین',
					'type'    => 'number',
					'step'    => '0.0001',
					'depends' => array( 'goldmate_fallback_source' => array( 'custom' ) ),
				),
				array(
					'id'      => 'goldmate_fallback_api_time_path',
					'title'   => 'مسیر زمان جایگزین',
					'type'    => 'text',
					'depends' => array( 'goldmate_fallback_source' => array( 'custom' ) ),
				),
				array(
					'id'      => 'goldmate_fallback_api_max_age',
					'title'   => 'بیشینه‌ی قدمت جایگزین (دقیقه)',
					'type'    => 'number',
					'step'    => '1',
					'depends' => array( 'goldmate_fallback_source' => array( 'custom' ) ),
				),
				array(
					'type'  => 'section',
					'title' => 'تنظیم روی نرخ دریافتی',
					'desc'  => 'پس از خواندن نرخ از منبع، می‌توانید درصد یا مبلغ ثابت اضافه/کم کنید و بعد محافظ‌ها اعمال شوند.',
				),
				array(
					'id'      => 'goldmate_rate_adjust_mode',
					'title'   => 'نوع تنظیم نرخ',
					'type'    => 'select',
					'options' => array(
						'none'  => 'بدون تغییر',
						'pct'   => 'درصدی (+/−)',
						'fixed' => 'مبلغ ثابت تومان (+/−)',
					),
					'depends' => array( 'goldmate_rate_source' => array( 'custom', 'brsapi', 'atn', 'tgju' ) ),
				),
				array(
					'id'      => 'goldmate_rate_adjust_value',
					'title'   => 'مقدار تنظیم',
					'type'    => 'signed_number',
					'step'    => '0.01',
					'depends' => array( 'goldmate_rate_adjust_mode' => array( 'pct', 'fixed' ) ),
					'desc'    => 'مثبت = افزایش، منفی = کاهش. برای درصد مثلاً 1 یعنی یک درصد بالاتر از نرخ منبع.',
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
					'id'    => 'goldmate_min_change_pct',
					'title' => 'کمینه‌ی تغییر برای اعمال قیمت (٪)',
					'type'  => 'number',
					'step'  => '0.01',
					'desc'  => 'اگر تغییر قیمت دریافتی نسبت به آخرین قیمتِ اعمال‌شده کمتر از این درصد باشد، قیمت محصولات به‌روز نمی‌شود — نوسان‌های ریز باعث تغییر مداوم قیمت جلوی چشم مشتری نمی‌شوند. تغییرهای کوچک پیاپی روی هم جمع می‌شوند تا این آستانه رد شود، نه این‌که هر بار از نو شمرده شوند. عدد 0 یعنی بدون آستانه. برای قیمت طلا معمولاً ۰٫۳ تا ۰٫۵ درصد مناسب است. این مستقل از «گرد کردن قیمت نهایی» (تب قیمت‌گذاری) است؛ آن مقدار نوسان‌های ریزتر از یک پله‌ی گرد کردن را در قیمت هر محصول می‌بلعد، این یکی تصمیم می‌گیرد که اصلاً محاسبه‌ی دوباره لازم است یا نه.',
				),
				array(
					'id'    => 'goldmate_min_change_amount',
					'title' => 'کمینه‌ی تغییر برای اعمال قیمت (تومان)',
					'type'  => 'number',
					'step'  => '1000',
					'desc'  => 'مثل بالا، اما مبلغ ثابت تومانی به‌جای درصد. اگر هر دو مقدار پر شوند، رد شدن از هر کدام کافی است. چون قیمت طلا در طول زمان بالا می‌رود، این عدد نسبت به قیمت روز کوچک و کوچک‌تر می‌شود — درصد را معیار اصلی در نظر بگیرید و این را فقط به‌عنوان یک سقف تکمیلی پر کنید. عدد 0 یعنی بدون آستانه‌ی مبلغی.',
				),
				array(
					'id'    => 'goldmate_stale_hours',
					'title' => 'قیمت پس از چند ساعت کهنه است؟',
					'type'  => 'number',
					'step'  => '0.5',
					'desc'  => 'عدد 0 یعنی هیچ‌گاه کهنه در نظر گرفته نشود.',
				),
				array(
					'id'    => 'goldmate_log_days',
					'title' => 'نگهداری گزارش دریافت‌ها (روز)',
					'type'  => 'number',
					'step'  => '1',
					'desc'  => 'هر تلاش دریافت — موفق یا ناموفق — در تب «وضعیت» ثبت می‌شود. موارد قدیمی‌تر از این مقدار خودکار پاک می‌شوند. عدد 0 یعنی فقط سقف تعدادی اعمال شود.',
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
	 * Ratesbox-style General tab fields.
	 *
	 * @return array[]
	 */
	public static function general_fields() {

		$roles = class_exists( 'Goldmate_General' ) ? Goldmate_General::role_options() : array( '' => '—' );

		return array(
			array(
				'type'  => 'section',
				'title' => 'بخش ابتدایی',
				'desc'  => 'زمان و شرایط محاسبه و ذخیره قیمت محصولات طلا.',
			),
			array(
				'id'      => 'goldmate_calc_mode',
				'title'   => 'نوع محاسبه قیمت',
				'type'    => 'select',
				'options' => array(
					'store' => 'محاسبه و ذخیره در دیتابیس',
				),
			),
			array(
				'id'    => 'goldmate_check_price_validity',
				'title' => 'بررسی زمانبندی شده اعتبار قیمت ها',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_reprice_on_cron',
				'title' => 'محاسبه و ذخیره قیمت در زمان فراخوانی (cron job) ها؟',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_reprice_on_manual_fetch',
				'title' => 'محاسبه و ذخیره قیمت در زمان فراخوانی (قیمت دستی)؟',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_reprice_on_item_save',
				'title' => 'محاسبه و ذخیره قیمت در زمان ذخیره اطلاعات یک آیتم؟',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_reprice_on_all_items_save',
				'title' => 'محاسبه و ذخیره قیمت در زمان ذخیره اطلاعات همه آیتم ها؟',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_reprice_on_product_save',
				'title' => 'محاسبه و ذخیره قیمت در زمان ایجاد یا به‌روزرسانی محصول؟',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_reprice_on_add_to_cart',
				'title' => 'محاسبه و ذخیره قیمت محصول در زمان افزودن به سبد خرید؟',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_reprice_cart_on_add',
				'title' => 'محاسبه و ذخیره قیمت تمام سبد خرید در زمان افزودن محصول؟',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_reprice_cart_on_cart',
				'title' => 'محاسبه و ذخیره قیمت تمام سبد خرید در زمان باز شدن سبد خرید؟',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_reprice_cart_on_checkout',
				'title' => 'محاسبه و ذخیره قیمت تمام سبد خرید در زمان باز شدن صفحه تسویه حساب؟',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_store_calc_time',
				'title' => 'ذخیره زمان محاسبه قیمت محصول؟',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),

			array(
				'type'  => 'section',
				'title' => 'بخش سابقه تغییرات قیمت',
			),
			array(
				'id'    => 'goldmate_recalc_only_on_change',
				'title' => 'محاسبه مجدد تنها در زمان تغییر قیمت آیتم',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_price_history_hours',
				'title' => 'مدت نگهداری سوابق تغییرات قیمت (ساعت)',
				'type'  => 'number',
				'step'  => '1',
			),
			array(
				'type'   => 'submit_action',
				'title'  => 'حذف سوابق',
				'action' => 'clear_rate_history',
				'label'  => 'حذف سوابق',
				'class'  => 'button button-secondary',
			),
			array(
				'type'   => 'submit_action',
				'title'  => 'به‌روزرسانی سوابق قیمت',
				'action' => 'prune_rate_history',
				'label'  => 'به‌روزرسانی سوابق',
				'class'  => 'button',
			),
			array(
				'id'    => 'goldmate_history_per_page',
				'title' => 'آیتم در هر صفحه',
				'type'  => 'number',
				'step'  => '1',
			),
			array(
				'id'    => 'goldmate_round_prices',
				'title' => 'روند کردن قیمت',
				'type'  => 'checkbox',
				'label' => 'فعال',
				'desc'  => 'جزئیات گرد کردن (پله و جهت) در تب قیمت‌گذاری است.',
			),
			array(
				'id'    => 'goldmate_strip_below',
				'title' => 'حذف مقادیر کمتر از',
				'type'  => 'number',
				'step'  => '1',
				'desc'  => 'اگر پله‌ی گرد کردن صفر باشد، از این مقدار به‌عنوان واحد گرد کردن استفاده می‌شود.',
			),
			array(
				'id'    => 'goldmate_details_admin',
				'title' => 'اطلاعات تکمیلی برای مدیر نمایش داده شود؟',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_details_customer',
				'title' => 'اطلاعات تکمیلی برای مشتری نمایش داده شود؟',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_details_email',
				'title' => 'اطلاعات تکمیلی در ایمیل های ارسالی نمایش داده شود؟',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_hide_price_change',
				'title' => 'اطلاعات افزایش / کاهش قیمت همیشه مخفی باشد؟',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_details_colleague',
				'title' => 'اطلاعات تکمیلی برای همکاران نمایش داده شود؟',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),

			array(
				'type'  => 'section',
				'title' => 'اطلاعات مدیر و محدودیت اعتبار',
			),
			array(
				'id'      => 'goldmate_colleague_role',
				'title'   => 'نقش همکار',
				'type'    => 'select',
				'options' => $roles,
			),
			array(
				'id'    => 'goldmate_admin_email',
				'title' => 'ایمیل مدیر',
				'type'  => 'text',
			),
			array(
				'id'    => 'goldmate_max_price_validity',
				'title' => 'حداکثر اعتبار قیمت (دقیقه)',
				'type'  => 'number',
				'step'  => '1',
			),
			array(
				'id'    => 'goldmate_oos_shortcodes',
				'title' => 'قیمت شورتکدها از دسترس خارج شوند؟',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_oos_manual_shortcodes',
				'title' => 'قیمت شورت کدهای دستی از دسترس خارج شوند؟',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_oos_shortcode_text',
				'title' => 'متن جایگزین',
				'type'  => 'text_rtl',
			),
			array(
				'id'    => 'goldmate_oos_products',
				'title' => 'قیمت محصولات از دسترس خارج شوند؟',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_oos_contact_url',
				'title' => 'لینک تماس بگیرید',
				'type'  => 'text',
			),
			array(
				'id'    => 'goldmate_oos_product_text',
				'title' => 'متن جایگزین',
				'type'  => 'text_rtl',
			),

			array(
				'type'  => 'section',
				'title' => 'شورتکدها و بروزرسانی',
			),
			array(
				'id'    => 'goldmate_ajax_shortcodes',
				'title' => 'حالت AJAX برای شورتکدها',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_ajax_shortcodes_enable',
				'title' => 'فعال کردن حالت به‌روز رسانی شورتکدها',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_ajax_shortcodes_interval',
				'title' => 'فاصله زمانی به روز رسانی شورتکدها',
				'type'  => 'number',
				'step'  => '1',
				'desc'  => 'ثانیه',
			),
			array(
				'id'    => 'goldmate_ajax_products',
				'title' => 'به‌روزرسانی خودکار قیمت محصول',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_ajax_products_interval',
				'title' => 'فاصله زمانی به روز رسانی قیمت محصولات',
				'type'  => 'number',
				'step'  => '1',
				'desc'  => 'ثانیه',
			),
			array(
				'id'      => 'goldmate_jewelry_rate_ref',
				'title'   => 'مرجع محاسبه قیمت جواهر',
				'type'    => 'select',
				'options' => array(
					'gold_18' => 'طلای ۱۸ عیار (قیمت روز)',
				),
			),
			array(
				'id'      => 'goldmate_wage_ref',
				'title'   => 'مرجع محاسبه اجرت ساخت',
				'type'    => 'select',
				'options' => array(
					'default' => 'پیش‌فرض',
				),
			),
			array(
				'id'      => 'goldmate_tax_method',
				'title'   => 'روش محاسبه مالیات',
				'type'    => 'select',
				'options' => array(
					'all'       => 'همه عیارها',
					'selective' => 'انتخابی',
					'none'      => 'بدون مالیات',
				),
			),

			array(
				'type'  => 'section',
				'title' => 'مالیات',
			),
			array(
				'id'    => 'goldmate_tax_on_wage',
				'title' => 'اعمال مالیات روی اجرت ساخت',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_tax_on_profit',
				'title' => 'اعمال مالیات روی سود',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'      => 'goldmate_tax_selective_karats',
				'title'   => 'اعمال مالیات انتخابی روی',
				'type'    => 'multiselect',
				'options' => array(
					'18' => 'طلای ۱۸ عیار',
					'21' => 'طلای ۲۱ عیار',
					'22' => 'طلای ۲۲ عیار',
					'24' => 'طلای ۲۴ عیار',
				),
				'desc'    => 'فقط وقتی روش مالیات «انتخابی» است اعمال می‌شود.',
			),

			array(
				'type'  => 'section',
				'title' => 'سفارش‌ها',
			),
			array(
				'id'    => 'goldmate_order_recalc',
				'title' => 'محاسبه مجدد قیمت اقلام سفارش',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_order_item_validity',
				'title' => 'زمان اعتبار قیمت اقلام سفارش قبل از محاسبه مجدد',
				'type'  => 'number',
				'step'  => '0.5',
				'desc'  => 'ساعت',
			),
			array(
				'id'    => 'goldmate_order_auto_cancel',
				'title' => 'لغو خودکار سفارش‌ها',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
			array(
				'id'    => 'goldmate_order_auto_cancel_minutes',
				'title' => 'زمان اعتبار سفارش قبل از لغو خودکار',
				'type'  => 'number',
				'step'  => '1',
				'desc'  => 'دقیقه',
			),
			array(
				'id'    => 'goldmate_hide_shortcode_outofstock',
				'title' => 'مخفی کردن شورتکد قیمت برای محصولات ناموجود',
				'type'  => 'checkbox',
				'label' => 'فعال',
			),
		);
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

				case 'submit_action':
					break;

				case 'checkbox':
					update_option( $id, isset( $post[ $id ] ) ? 'yes' : 'no' );
					break;

				case 'multiselect':
					$raw = isset( $post[ $id ] ) && is_array( $post[ $id ] ) ? $post[ $id ] : array();
					$ok  = array();
					foreach ( $raw as $item ) {
						$item = sanitize_text_field( wp_unslash( $item ) );
						if ( isset( $field['options'][ $item ] ) ) {
							$ok[] = $item;
						}
					}
					update_option( $id, $ok );
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

				case 'signed_number':
					if ( ! isset( $post[ $id ] ) ) {
						break;
					}
					update_option( $id, goldmate_signed_float( wp_unslash( $post[ $id ] ) ) );
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
				case 'text_rtl':
				default:
					if ( ! isset( $post[ $id ] ) ) {
						break;
					}
					$value = sanitize_text_field( wp_unslash( $post[ $id ] ) );
					if ( 'goldmate_api_url' === $id && '' !== $value ) {
						$value = self::sanitize_endpoint_url( $value );
					}
					if ( 'goldmate_fallback_api_url' === $id && '' !== $value ) {
						$value = self::sanitize_endpoint_url( $value );
					}
					if ( 'goldmate_admin_email' === $id && '' !== $value ) {
						$value = sanitize_email( $value );
					}
					if ( 'goldmate_oos_contact_url' === $id && '' !== $value ) {
						$value = esc_url_raw( $value );
					}
					update_option( $id, $value );
					break;
			}
		}

		if ( 'fetch' === $tab ) {
			$warnings = array_merge( $warnings, self::fetch_warnings() );
		}

		if ( 'general' === $tab ) {
			// Keep legacy live_interval in sync with product AJAX interval.
			$prod_on  = 'yes' === goldmate_option( 'goldmate_ajax_products' );
			$prod_int = (int) goldmate_option( 'goldmate_ajax_products_interval' );
			update_option( 'goldmate_live_interval', $prod_on ? max( 0, $prod_int ) : 0 );
			if ( class_exists( 'Goldmate_General' ) ) {
				Goldmate_General::ensure_validity_cron();
				Goldmate_General::prune_rate_history();
			}
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
