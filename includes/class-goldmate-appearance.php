<?php
/**
 * Product-page appearance: owns the storefront CSS/JS for the variation
 * choosers and lets the admin restyle buttons from the "ظاهر" tab.
 *
 * The static look lives in assets/css/product-ui.css and is driven by custom
 * properties; this class prints the admin's overrides after it. Nothing here
 * touches prices.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goldmate_Appearance {

	const OPTION = 'goldmate_appearance';

	const CUSTOM_CSS_KEY = 'custom_css';

	const PRICE_INFO_DEFAULT = 'وزن طلا × (قیمت روز طلا + اجرت) + 7٪ سود + متعلقات + 9٪ مالیات سود و اجرت';

	/**
	 * Registers front-end assets and the admin colour picker.
	 */
	public static function init() {

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_front' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
	}

	/* ---------------------------------------------------------------------
	 *  Schema
	 * ------------------------------------------------------------------ */

	/**
	 * Every editable style, grouped for the admin screen.
	 *
	 * Field keys:
	 *  - type:  color | px | select | checkbox | overlay
	 *  - var:   custom property set on :root (used by the static stylesheet)
	 *  - rules: list of [ selector, property ] pairs printed with !important
	 *           (checkbox: [ selector, property, value ])
	 * An empty value means "leave the theme / stylesheet default alone".
	 *
	 * @return array<string,array>
	 */
	public static function schema() {

		$atc  = '.single-product .summary form.cart .single_add_to_cart_button';
		$cart_row = '.single-product .summary form.cart';
		$act_text = '.single-product .summary .wd-wishlist-btn a .wd-action-text, .single-product .summary .wd-compare-btn a .wd-action-text';
		$act_icon = '.single-product .summary .wd-wishlist-btn a .wd-action-icon, .single-product .summary .wd-compare-btn a .wd-action-icon';
		$row      = '.single-product .summary .goldmate-action-row';
		$hide     = '{position:absolute !important;width:1px !important;height:1px !important;overflow:hidden !important;clip:rect(0,0,0,0) !important;white-space:nowrap !important;}';
		$wl       = $row . ' > .wd-wishlist-btn';
		$cp       = $row . ' > .wd-compare-btn';
		$grow     = '{flex:1 1 0 !important;min-width:0;}';
		$wide_a   = ' > a{display:flex !important;justify-content:center;width:100%;box-sizing:border-box;}';
		$icon_a   = ' > a{position:relative;padding:.7em !important;}';
		$buy  = '.single-product .summary form.cart .wd-buy-now-btn';
		$qty  = '.single-product .summary form.cart .quantity';
		$act  = '.single-product .summary .wd-wishlist-btn a, .single-product .summary .wd-compare-btn a';
		$acth = '.single-product .summary .wd-wishlist-btn a:hover, .single-product .summary .wd-compare-btn a:hover';

		$weights = array(
			''    => 'پیش‌فرض قالب',
			'400' => 'معمولی',
			'500' => 'متوسط',
			'600' => 'نیمه‌ضخیم',
			'700' => 'ضخیم',
			'800' => 'خیلی ضخیم',
		);

		return array(

			'colors'  => array(
				'title'  => 'رنگ‌ها و شکل کلی',
				'desc'   => 'رنگ‌های اصلی دکمه‌های انتخاب سایز/وزن و پنجره‌ی انتخاب در صفحه محصول.',
				'fields' => array(
					'accent'         => array( 'label' => 'رنگ اصلی (تاکید)', 'type' => 'color', 'var' => '--goldmate-vd-accent' ),
					'ink'            => array( 'label' => 'رنگ متن', 'type' => 'color', 'var' => '--goldmate-vd-ink' ),
					'muted'          => array( 'label' => 'رنگ متن کم‌رنگ', 'type' => 'color', 'var' => '--goldmate-vd-muted' ),
					'border'         => array( 'label' => 'رنگ حاشیه', 'type' => 'color', 'var' => '--goldmate-vd-border' ),
					'surface'        => array( 'label' => 'پس‌زمینه دکمه و پنجره', 'type' => 'color', 'var' => '--goldmate-vd-surface' ),
					'row_bg'         => array( 'label' => 'پس‌زمینه گزینه‌ها در پنجره', 'type' => 'color', 'var' => '--goldmate-vd-row-bg' ),
					'price'          => array( 'label' => 'رنگ قیمت کنار گزینه‌ها', 'type' => 'color', 'var' => '--goldmate-vd-price' ),
					'overlay'        => array( 'label' => 'رنگ پس‌زمینه پشت پنجره', 'type' => 'overlay', 'var' => '--goldmate-vd-overlay' ),
					'radius'         => array( 'label' => 'گردی گوشه‌های پنجره (px)', 'type' => 'px', 'var' => '--goldmate-vd-radius', 'max' => 60 ),
					'trigger_radius' => array( 'label' => 'گردی گوشه‌های دکمه انتخاب (px)', 'type' => 'px', 'var' => '--goldmate-vd-trigger-radius', 'max' => 60 ),
				),
			),

			'trigger' => array(
				'title'  => 'دکمه انتخاب (سایز / وزن / ...)',
				'desc'   => 'دکمه‌ای که پنجره‌ی انتخاب را باز می‌کند.',
				'fields' => array(
					'trigger_size'   => array( 'label' => 'اندازه متن (px)', 'type' => 'px', 'rules' => array( array( 'button.goldmate-vd-trigger', 'font-size' ) ), 'max' => 40 ),
					'trigger_weight' => array( 'label' => 'ضخامت متن', 'type' => 'select', 'options' => $weights, 'rules' => array( array( '.goldmate-vd-trigger-value, .goldmate-vd-trigger-label', 'font-weight' ) ) ),
					'trigger_height' => array( 'label' => 'حداقل ارتفاع (px)', 'type' => 'px', 'rules' => array( array( 'button.goldmate-vd-trigger', 'min-height' ) ), 'max' => 120 ),
					'trigger_hover'  => array( 'label' => 'رنگ حاشیه هنگام قرارگیری ماوس', 'type' => 'color', 'rules' => array( array( 'button.goldmate-vd-trigger:hover', 'border-color' ) ) ),
				),
			),

			'price_info' => array(
				'title'  => 'راهنمای قیمت نهایی',
				'desc'   => 'آیکون کوچک کنار عنوان «قیمت نهایی» بالای دکمه خرید؛ با قرار گرفتن ماوس یا کلیک روی آن، این متن در یک پنجره کوچک نمایش داده می‌شود. خالی گذاشتن متن یعنی همان متن پیش‌فرض.',
				'fields' => array(
					'price_info_text'    => array( 'label' => 'متن راهنما', 'type' => 'textarea', 'default' => self::PRICE_INFO_DEFAULT ),
					'price_info_hide'    => array( 'label' => 'مخفی کردن آیکون راهنما', 'type' => 'checkbox' ),
					'price_info_icon'    => array(
						'label'   => 'شکل آیکون',
						'type'    => 'select',
						'options' => array(
							''          => 'حرف i در دایره (پیش‌فرض)',
							'info'      => 'آیکون اطلاعات',
							'info_fill' => 'آیکون اطلاعات توپر',
							'question'  => 'علامت سوال',
						),
					),
					'price_info_trigger' => array(
						'label'   => 'نحوه باز شدن',
						'type'    => 'select',
						'options' => array(
							''      => 'ماوس و کلیک (پیش‌فرض)',
							'click' => 'فقط با کلیک',
						),
					),
					'price_info_color'   => array( 'label' => 'رنگ آیکون', 'type' => 'color', 'var' => '--goldmate-info-icon' ),
					'price_info_color_h' => array( 'label' => 'رنگ آیکون هنگام قرارگیری ماوس', 'type' => 'color', 'var' => '--goldmate-info-icon-h' ),
					'price_info_size'    => array( 'label' => 'اندازه آیکون (px)', 'type' => 'px', 'var' => '--goldmate-info-size', 'max' => 40 ),
				),
			),

			'price_info_pop' => array(
				'title'  => 'پنجره راهنمای قیمت',
				'desc'   => 'ظاهر پنجره‌ای که با آیکون راهنما باز می‌شود. خالی گذاشتن هر فیلد یعنی همان ظاهر پیش‌فرض (رنگ‌های بخش «رنگ‌ها و شکل کلی»).',
				'fields' => array(
					'pop_shadow' => array(
						'label'   => 'سایه',
						'type'    => 'select',
						'options' => array(
							''       => 'ملایم (پیش‌فرض)',
							'strong' => 'پررنگ',
							'none'   => 'بدون سایه',
						),
						'map'     => array(
							'strong' => ':root{--goldmate-info-shadow:0 10px 30px rgba(28,26,23,.25)}',
							'none'   => ':root{--goldmate-info-shadow:none}',
						),
					),
					'pop_bg'     => array( 'label' => 'رنگ پس‌زمینه', 'type' => 'color', 'var' => '--goldmate-info-bg' ),
					'pop_color'  => array( 'label' => 'رنگ متن', 'type' => 'color', 'var' => '--goldmate-info-ink' ),
					'pop_border' => array( 'label' => 'رنگ حاشیه', 'type' => 'color', 'var' => '--goldmate-info-border' ),
					'pop_radius' => array( 'label' => 'گردی گوشه‌ها (px)', 'type' => 'px', 'var' => '--goldmate-info-radius', 'max' => 30 ),
					'pop_size'   => array( 'label' => 'اندازه متن (px)', 'type' => 'px', 'var' => '--goldmate-info-font', 'max' => 24 ),
					'pop_width'  => array( 'label' => 'حداکثر عرض (px)', 'type' => 'px', 'var' => '--goldmate-info-width', 'max' => 640 ),
				),
			),

			'cart'    => array(
				'title'  => 'دکمه افزودن به سبد خرید',
				'desc'   => 'خالی گذاشتن هر فیلد یعنی همان ظاهر پیش‌فرض قالب.',
				'fields' => array(
					'atc_bg'        => array( 'label' => 'رنگ پس‌زمینه', 'type' => 'color', 'rules' => array( array( $atc, 'background-color' ) ) ),
					'atc_color'     => array( 'label' => 'رنگ متن', 'type' => 'color', 'rules' => array( array( $atc, 'color' ) ) ),
					'atc_border'    => array( 'label' => 'رنگ حاشیه', 'type' => 'color', 'rules' => array( array( $atc, 'border-color' ) ) ),
					'atc_bg_h'      => array( 'label' => 'پس‌زمینه هنگام قرارگیری ماوس', 'type' => 'color', 'rules' => array( array( $atc . ':hover', 'background-color' ) ) ),
					'atc_color_h'   => array( 'label' => 'رنگ متن هنگام قرارگیری ماوس', 'type' => 'color', 'rules' => array( array( $atc . ':hover', 'color' ) ) ),
					'atc_border_h'  => array( 'label' => 'رنگ حاشیه هنگام قرارگیری ماوس', 'type' => 'color', 'rules' => array( array( $atc . ':hover', 'border-color' ) ) ),
					'atc_radius'    => array( 'label' => 'گردی گوشه‌ها (px)', 'type' => 'px', 'rules' => array( array( $atc, 'border-radius' ) ), 'max' => 60 ),
					'atc_size'      => array( 'label' => 'اندازه متن (px)', 'type' => 'px', 'rules' => array( array( $atc, 'font-size' ) ), 'max' => 40 ),
					'atc_weight'    => array( 'label' => 'ضخامت متن', 'type' => 'select', 'options' => $weights, 'rules' => array( array( $atc, 'font-weight' ) ) ),
					'atc_height'    => array( 'label' => 'ارتفاع دکمه (px)', 'type' => 'px', 'rules' => array( array( $atc, 'min-height' ) ), 'max' => 120 ),
					'atc_icon'      => array(
						'label'   => 'آیکن دکمه',
						'type'    => 'select',
						'options' => array(
							''     => 'سبد خرید (پیش‌فرض)',
							'bag'  => 'کیسه خرید',
							'none' => 'بدون آیکن',
						),
						'map'     => array(
							'bag'  => ':root{--goldmate-atc-icon:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%23000\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3E%3Cpath d=\'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z\'/%3E%3Cpath d=\'M3 6h18\'/%3E%3Cpath d=\'M16 10a4 4 0 0 1-8 0\'/%3E%3C/svg%3E")}',
							'none' => $atc . ' > .goldmate-atc-icon{display:none !important}',
						),
					),
					'atc_layout'    => array(
						'label'   => 'چیدمان ردیف خرید',
						'type'    => 'select',
						'options' => array(
							''       => 'دکمه تمام‌عرض در ردیف جدا (پیش‌فرض)',
							'inline' => 'تعداد کنار دکمه',
						),
						'map'     => array(
							'inline' => $cart_row . ' .goldmate-final-price{flex-basis:100% !important;}' . $cart_row . ' .quantity{margin-inline-start:0 !important;}' . $atc . '{flex:1 1 0 !important;width:auto !important;min-width:160px !important;}',
						),
					),
				),
			),

			'buynow'  => array(
				'title'  => 'دکمه خرید فوری',
				'desc'   => 'فقط اگر قالب یا افزونه‌ای دکمه «خرید فوری» به صفحه اضافه کرده باشد اثر دارد.',
				'fields' => array(
					'buy_bg'      => array( 'label' => 'رنگ پس‌زمینه', 'type' => 'color', 'rules' => array( array( $buy, 'background-color' ) ) ),
					'buy_color'   => array( 'label' => 'رنگ متن', 'type' => 'color', 'rules' => array( array( $buy, 'color' ) ) ),
					'buy_bg_h'    => array( 'label' => 'پس‌زمینه هنگام قرارگیری ماوس', 'type' => 'color', 'rules' => array( array( $buy . ':hover', 'background-color' ) ) ),
					'buy_color_h' => array( 'label' => 'رنگ متن هنگام قرارگیری ماوس', 'type' => 'color', 'rules' => array( array( $buy . ':hover', 'color' ) ) ),
					'buy_radius'  => array( 'label' => 'گردی گوشه‌ها (px)', 'type' => 'px', 'rules' => array( array( $buy, 'border-radius' ) ), 'max' => 60 ),
				),
			),

			'qty'     => array(
				'title'  => 'انتخاب تعداد',
				'desc'   => '',
				'fields' => array(
					'qty_radius' => array( 'label' => 'گردی گوشه‌ها (px)', 'type' => 'px', 'rules' => array( array( $qty, 'border-radius' ), array( $qty . ' .minus', 'border-radius' ), array( $qty . ' .plus', 'border-radius' ) ), 'max' => 60 ),
					'qty_border' => array( 'label' => 'رنگ حاشیه', 'type' => 'color', 'rules' => array( array( $qty . ', ' . $qty . ' .minus, ' . $qty . ' .plus, ' . $qty . ' input.qty', 'border-color' ) ) ),
					'qty_btn'    => array( 'label' => 'رنگ دکمه‌های + و −', 'type' => 'color', 'rules' => array( array( $qty . ' .minus, ' . $qty . ' .plus', 'color' ) ) ),
					'qty_input'  => array( 'label' => 'رنگ عدد', 'type' => 'color', 'rules' => array( array( $qty . ' input.qty', 'color' ) ) ),
				),
			),

			'actions' => array(
				'title'  => 'دکمه‌های علاقه‌مندی و مقایسه',
				'desc'   => 'این دو به‌صورت دکمه نمایش داده می‌شوند؛ ظاهر پیش‌فرض از رنگ‌های بخش «رنگ‌ها و شکل کلی» می‌آید.',
				'fields' => array(
					'act_display' => array(
						'label'   => 'نمایش',
						'type'    => 'select',
						'options' => array(
							''     => 'آیکن و متن (پیش‌فرض)',
							'icon' => 'فقط آیکن',
							'text' => 'فقط متن',
						),
						'map'     => array(
							'icon' => $act_text . '{position:absolute !important;width:1px !important;height:1px !important;overflow:hidden !important;clip:rect(0,0,0,0) !important;white-space:nowrap !important;}' . $act . '{position:relative;padding:.7em !important;}',
							'text' => $act_icon . '{display:none !important;}',
						),
					),
					'act_layout'  => array(
						'label'   => 'چیدمان دو دکمه',
						'type'    => 'select',
						'options' => array(
							''              => 'کنار هم، با اندازه‌ی محتوا (پیش‌فرض)',
							'half'          => 'هر کدام ۵۰٪ عرض، هم‌اندازه',
							'wide_wishlist' => 'علاقه‌مندی عرض کامل، مقایسه فقط آیکن',
							'wide_compare'  => 'مقایسه عرض کامل، علاقه‌مندی فقط آیکن',
						),
						'map'     => array(
							'half'          => $wl . $grow . $cp . $grow . $wl . $wide_a . $cp . $wide_a,
							'wide_wishlist' => $wl . $grow . $wl . $wide_a . $cp . ' > a .wd-action-text' . $hide . $cp . $icon_a,
							'wide_compare'  => $cp . $grow . $cp . $wide_a . $wl . ' > a .wd-action-text' . $hide . $wl . $icon_a,
						),
					),
					'act_color'   => array( 'label' => 'رنگ', 'type' => 'color', 'rules' => array( array( $act, 'color' ) ) ),
					'act_color_h' => array( 'label' => 'رنگ هنگام قرارگیری ماوس', 'type' => 'color', 'rules' => array( array( $acth, 'color' ) ) ),
					'act_bg'      => array( 'label' => 'رنگ پس‌زمینه دکمه', 'type' => 'color', 'rules' => array( array( $act, 'background-color' ) ) ),
					'act_bg_h'    => array( 'label' => 'پس‌زمینه هنگام قرارگیری ماوس', 'type' => 'color', 'rules' => array( array( $acth, 'background-color' ) ) ),
					'act_border'  => array( 'label' => 'رنگ حاشیه', 'type' => 'color', 'rules' => array( array( $act, 'border-color' ) ) ),
					'act_border_h' => array( 'label' => 'رنگ حاشیه هنگام قرارگیری ماوس', 'type' => 'color', 'rules' => array( array( $acth, 'border-color' ) ) ),
					'act_radius'  => array( 'label' => 'گردی گوشه‌ها (px)', 'type' => 'px', 'rules' => array( array( $act, 'border-radius' ) ), 'max' => 60 ),
					'act_size'    => array( 'label' => 'اندازه متن (px)', 'type' => 'px', 'rules' => array( array( $act, 'font-size' ) ), 'max' => 40 ),
				),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 *  Stored values
	 * ------------------------------------------------------------------ */

	/**
	 * Saved settings, keyed by field id, plus the custom CSS under CUSTOM_CSS_KEY.
	 *
	 * @return array<string,string>
	 */
	public static function values() {

		$saved = get_option( self::OPTION, array() );

		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Sanitises one field's raw value; '' means "unset".
	 *
	 * @param array $field Field definition.
	 * @param mixed $raw   Submitted value.
	 * @return string
	 */
	protected static function clean( $field, $raw ) {

		$raw = is_string( $raw ) ? trim( wp_unslash( $raw ) ) : '';

		switch ( $field['type'] ) {

			case 'color':
			case 'overlay':
				$hex = sanitize_hex_color( $raw );
				return $hex ? $hex : '';

			case 'px':
				if ( '' === $raw || ! is_numeric( $raw ) ) {
					return '';
				}
				$max = isset( $field['max'] ) ? (int) $field['max'] : 200;
				return (string) max( 0, min( $max, (int) round( (float) $raw ) ) );

			case 'select':
				return isset( $field['options'][ $raw ] ) ? $raw : '';

			case 'checkbox':
				return 'yes' === $raw ? 'yes' : '';

			case 'textarea':
				return sanitize_textarea_field( $raw );
		}

		return '';
	}

	/**
	 * Text behind the final-price info icon; '' when the icon is hidden.
	 *
	 * @return string
	 */
	public static function price_info_text() {

		$values = self::values();

		if ( ! empty( $values['price_info_hide'] ) ) {
			return '';
		}

		return ! empty( $values['price_info_text'] ) ? $values['price_info_text'] : self::PRICE_INFO_DEFAULT;
	}

	/**
	 * Stores the submitted appearance form.
	 *
	 * @param array $post Raw $_POST.
	 */
	public static function save_from_post( $post ) {

		$out = array();

		foreach ( self::schema() as $group ) {
			foreach ( $group['fields'] as $id => $field ) {
				$raw = isset( $post[ 'goldmate_ap_' . $id ] ) ? $post[ 'goldmate_ap_' . $id ] : '';
				if ( 'checkbox' === $field['type'] ) {
					$raw = isset( $post[ 'goldmate_ap_' . $id ] ) ? 'yes' : '';
				}
				$value = self::clean( $field, $raw );
				if ( '' !== $value ) {
					$out[ $id ] = $value;
				}
			}
		}

		$css = isset( $post['goldmate_ap_custom_css'] ) ? (string) wp_unslash( $post['goldmate_ap_custom_css'] ) : '';
		$css = trim( wp_strip_all_tags( $css ) );
		if ( '' !== $css ) {
			$out[ self::CUSTOM_CSS_KEY ] = $css;
		}

		update_option( self::OPTION, $out );
	}

	/**
	 * Clears every override, restoring the stylesheet defaults.
	 */
	public static function reset() {
		delete_option( self::OPTION );
	}

	/* ---------------------------------------------------------------------
	 *  CSS output
	 * ------------------------------------------------------------------ */

	/**
	 * CSS produced from the saved settings.
	 *
	 * @return string
	 */
	public static function build_css() {

		$values = self::values();
		$vars   = '';
		$rules  = '';

		foreach ( self::schema() as $group ) {
			foreach ( $group['fields'] as $id => $field ) {

				if ( empty( $values[ $id ] ) ) {
					continue;
				}

				$value = $values[ $id ];

				if ( ! empty( $field['var'] ) ) {
					if ( 'overlay' === $field['type'] ) {
						$value = self::hex_to_rgba( $value, 0.5 );
					} elseif ( 'px' === $field['type'] ) {
						$value .= 'px';
					}
					$vars .= $field['var'] . ':' . $value . ';';
				}

				if ( ! empty( $field['map'][ $value ] ) ) {
					$rules .= $field['map'][ $value ];
				}

				if ( empty( $field['rules'] ) ) {
					continue;
				}

				foreach ( $field['rules'] as $rule ) {
					if ( 'checkbox' === $field['type'] ) {
						$decl = $rule[1] . ':' . $rule[2];
					} elseif ( 'px' === $field['type'] ) {
						$decl = $rule[1] . ':' . $value . 'px';
					} else {
						$decl = $rule[1] . ':' . $value;
					}
					$rules .= $rule[0] . '{' . $decl . ' !important;}';
				}
			}
		}

		$css = '';
		if ( '' !== $vars ) {
			$css .= ':root{' . $vars . '}';
		}
		$css .= $rules;

		if ( ! empty( $values[ self::CUSTOM_CSS_KEY ] ) ) {
			$css .= "\n" . $values[ self::CUSTOM_CSS_KEY ];
		}

		return $css;
	}

	/**
	 * #rrggbb (or #rgb) to rgba().
	 *
	 * @param string $hex   Hex colour.
	 * @param float  $alpha Alpha.
	 * @return string
	 */
	protected static function hex_to_rgba( $hex, $alpha ) {

		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return sprintf( 'rgba(%d,%d,%d,%s)', hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ), $alpha );
	}

	/* ---------------------------------------------------------------------
	 *  Front end
	 * ------------------------------------------------------------------ */

	/**
	 * Loads the chooser CSS/JS wherever WooCommerce products can be shown
	 * (single page, plus quick view / quick shop on archives).
	 */
	public static function enqueue_front() {

		if ( ! function_exists( 'is_woocommerce' ) || apply_filters( 'goldmate_disable_product_ui', false ) ) {
			return;
		}

		wp_enqueue_style(
			'goldmate-product-ui',
			GOLDMATE_URL . 'assets/css/product-ui.css',
			array(),
			self::asset_version( 'assets/css/product-ui.css' )
		);

		$css = self::build_css();
		if ( '' !== $css ) {
			wp_add_inline_style( 'goldmate-product-ui', $css );
		}

		wp_enqueue_script(
			'goldmate-product-ui',
			GOLDMATE_URL . 'assets/js/product-ui.js',
			array( 'jquery' ),
			self::asset_version( 'assets/js/product-ui.js' ),
			true
		);

		$values = self::values();

		wp_localize_script(
			'goldmate-product-ui',
			'goldmateProductUiConfig',
			array(
				'priceInfo'        => self::price_info_text(),
				'priceInfoLabel'   => 'نحوه محاسبه قیمت',
				'priceInfoIcon'    => isset( $values['price_info_icon'] ) ? $values['price_info_icon'] : '',
				'priceInfoTrigger' => isset( $values['price_info_trigger'] ) ? $values['price_info_trigger'] : '',
			)
		);
	}

	/**
	 * Cache-busting version: plugin version plus the file's mtime.
	 *
	 * @param string $relative Path inside the plugin.
	 * @return string
	 */
	protected static function asset_version( $relative ) {

		$mtime = @filemtime( GOLDMATE_PATH . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return $mtime ? GOLDMATE_VERSION . '.' . $mtime : GOLDMATE_VERSION;
	}

	/* ---------------------------------------------------------------------
	 *  Admin
	 * ------------------------------------------------------------------ */

	/**
	 * Colour picker on the appearance tab only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue_admin( $hook ) {

		if ( 'toplevel_page_' . Goldmate_Admin::SLUG !== $hook || 'appearance' !== Goldmate_Admin::current_tab() ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".goldmate-ap-color").wpColorPicker();});' );
	}

	/**
	 * Sub-heading a field is listed under inside its group card.
	 *
	 * @param array $field Field definition.
	 * @return string
	 */
	protected static function field_section( $field ) {

		$prop = isset( $field['rules'][0][1] ) ? $field['rules'][0][1] : '';

		if ( 'textarea' === $field['type'] || 'checkbox' === $field['type'] || ( 'select' === $field['type'] && 'font-weight' !== $prop ) ) {
			return 'نمایش و چیدمان';
		}
		if ( false !== strpos( $field['label'], 'ماوس' ) ) {
			return 'هنگام قرارگیری ماوس';
		}
		if ( 'px' === $field['type'] || 'select' === $field['type'] ) {
			return 'اندازه و شکل';
		}

		return 'رنگ‌ها';
	}

	/**
	 * Prints one field's input.
	 *
	 * @param string $id    Field id.
	 * @param array  $field Field definition.
	 * @param string $value Saved value.
	 */
	protected static function render_field( $id, $field, $value ) {

		$name = 'goldmate_ap_' . $id;

		if ( 'color' === $field['type'] || 'overlay' === $field['type'] ) : ?>
			<input type="text" class="goldmate-ap-color" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
		<?php elseif ( 'px' === $field['type'] ) : ?>
			<input type="number" min="0" max="<?php echo esc_attr( isset( $field['max'] ) ? $field['max'] : 200 ); ?>" step="1" class="small-text" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="پیش‌فرض">
		<?php elseif ( 'select' === $field['type'] ) : ?>
			<select id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>">
				<?php foreach ( $field['options'] as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( (string) $value, (string) $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php elseif ( 'checkbox' === $field['type'] ) : ?>
			<input type="checkbox" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="yes" <?php checked( $value, 'yes' ); ?>>
		<?php elseif ( 'textarea' === $field['type'] ) : ?>
			<textarea id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="3" class="large-text"><?php echo esc_textarea( '' !== $value ? $value : ( isset( $field['default'] ) ? $field['default'] : '' ) ); ?></textarea>
		<?php endif;
	}

	/**
	 * Appearance tab UI: a jump bar, then one collapsible card per group with
	 * its fields split into colour / hover / size / layout sections.
	 */
	public static function render_admin_tab() {

		$values = self::values();
		$schema = self::schema();
		$css    = isset( $values[ self::CUSTOM_CSS_KEY ] ) ? $values[ self::CUSTOM_CSS_KEY ] : '';
		?>
		<style>
			.goldmate-ap-nav{display:flex;flex-wrap:wrap;gap:6px;margin:16px 0 12px;}
			.goldmate-ap-nav a{padding:4px 12px;border:1px solid #c3c4c7;border-radius:999px;background:#fff;text-decoration:none;}
			.goldmate-ap-nav a:hover{border-color:#2271b1;}
			.goldmate-ap-card{max-width:960px;margin:0 0 10px;background:#fff;border:1px solid #c3c4c7;border-radius:6px;}
			.goldmate-ap-card>summary{display:flex;align-items:center;gap:10px;padding:12px 16px;cursor:pointer;font-size:14px;font-weight:600;}
			.goldmate-ap-card[open]>summary{border-bottom:1px solid #f0f0f1;}
			.goldmate-ap-badge{padding:1px 8px;border-radius:999px;background:#f0f6fc;color:#2271b1;font-size:12px;font-weight:400;}
			.goldmate-ap-body{padding:4px 16px 16px;}
			.goldmate-ap-body>.description{margin:8px 0 0;}
			.goldmate-ap-body h4{margin:16px 0 8px;color:#646970;font-size:12px;font-weight:600;}
			.goldmate-ap-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px 24px;}
			.goldmate-ap-field{display:flex;flex-direction:column;align-items:flex-start;gap:4px;}
			.goldmate-ap-field.is-wide{grid-column:1/-1;align-items:stretch;}
			.goldmate-ap-field.is-inline{flex-direction:row;align-items:center;}
			.goldmate-ap-field>label{font-weight:500;}
			.goldmate-ap-save{position:sticky;bottom:0;z-index:10;max-width:960px;margin:0;padding:12px 16px;background:#f0f0f1;border-top:1px solid #c3c4c7;}
		</style>

		<form method="post" action="<?php echo esc_url( Goldmate_Admin::url( 'appearance' ) ); ?>">
			<?php wp_nonce_field( 'goldmate_admin' ); ?>

			<nav class="goldmate-ap-nav">
				<?php foreach ( $schema as $key => $group ) : ?>
					<a href="#goldmate-ap-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $group['title'] ); ?></a>
				<?php endforeach; ?>
				<a href="#goldmate-ap-custom_css">CSS سفارشی</a>
			</nav>

			<?php foreach ( $schema as $key => $group ) : ?>
				<?php
				$sections = array_fill_keys( array( 'نمایش و چیدمان', 'رنگ‌ها', 'هنگام قرارگیری ماوس', 'اندازه و شکل' ), array() );
				$changed  = 0;
				foreach ( $group['fields'] as $id => $field ) {
					$sections[ self::field_section( $field ) ][ $id ] = $field;
					if ( ! empty( $values[ $id ] ) ) {
						++$changed;
					}
				}
				$sections = array_filter( $sections );
				?>
				<details class="goldmate-ap-card" id="goldmate-ap-<?php echo esc_attr( $key ); ?>">
					<summary>
						<?php echo esc_html( $group['title'] ); ?>
						<?php if ( $changed ) : ?>
							<span class="goldmate-ap-badge"><?php echo esc_html( sprintf( '%s مورد سفارشی', number_format_i18n( $changed ) ) ); ?></span>
						<?php endif; ?>
					</summary>
					<div class="goldmate-ap-body">
						<?php if ( ! empty( $group['desc'] ) ) : ?>
							<p class="description"><?php echo esc_html( $group['desc'] ); ?></p>
						<?php endif; ?>

						<?php foreach ( $sections as $title => $fields ) : ?>
							<?php if ( count( $sections ) > 1 ) : ?>
								<h4><?php echo esc_html( $title ); ?></h4>
							<?php endif; ?>
							<div class="goldmate-ap-grid">
								<?php foreach ( $fields as $id => $field ) : ?>
									<?php
									$value = isset( $values[ $id ] ) ? $values[ $id ] : '';
									$class = 'goldmate-ap-field';
									if ( 'textarea' === $field['type'] ) {
										$class .= ' is-wide';
									} elseif ( 'checkbox' === $field['type'] ) {
										$class .= ' is-inline';
									}
									?>
									<div class="<?php echo esc_attr( $class ); ?>">
										<?php if ( 'checkbox' === $field['type'] ) : ?>
											<?php self::render_field( $id, $field, $value ); ?>
										<?php endif; ?>
										<label for="<?php echo esc_attr( 'goldmate_ap_' . $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
										<?php if ( 'checkbox' !== $field['type'] ) : ?>
											<?php self::render_field( $id, $field, $value ); ?>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</details>
			<?php endforeach; ?>

			<details class="goldmate-ap-card" id="goldmate-ap-custom_css">
				<summary>
					CSS سفارشی
					<?php if ( '' !== $css ) : ?>
						<span class="goldmate-ap-badge">فعال</span>
					<?php endif; ?>
				</summary>
				<div class="goldmate-ap-body">
					<p class="description">استایل‌های دلخواه که بعد از همه‌ی موارد بالا روی سایت بارگذاری می‌شوند. برچسب‌های HTML حذف می‌شوند.</p>
					<textarea name="goldmate_ap_custom_css" rows="10" class="large-text code" dir="ltr" style="margin-top:8px;"><?php echo esc_textarea( $css ); ?></textarea>
				</div>
			</details>

			<p class="submit goldmate-ap-save">
				<button type="submit" class="button button-primary" name="goldmate_action" value="save">ذخیره تغییرات</button>
				<button type="submit" class="button" name="goldmate_action" value="appearance_reset" onclick="return confirm('همه‌ی تنظیمات ظاهر به حالت پیش‌فرض برگردد؟');">بازگشت به پیش‌فرض</button>
			</p>
		</form>

		<script>
		( function () {
			function openHash() {
				var el = location.hash && document.getElementById( location.hash.slice( 1 ) );
				if ( el && 'DETAILS' === el.tagName ) {
					el.open = true;
				}
			}
			window.addEventListener( 'hashchange', openHash );
			openHash();
		} )();
		</script>
		<?php
	}
}
