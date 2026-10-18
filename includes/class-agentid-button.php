<?php
/**
 * "Sign in with AgentID" button surfaces: wp-login, WooCommerce
 * login, shortcode, and a Gutenberg block.
 *
 * @package AgentKeySignIn
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sign-in button surfaces (login forms, shortcode, block).
 */
class AgentID_Button {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'login_form', array( __CLASS__, 'on_wp_login' ) );
		add_action( 'woocommerce_login_form_end', array( __CLASS__, 'on_woo_login' ) );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'enqueue_css' ) );
		add_shortcode( 'agentid_login', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ), 20 );
	}

	/**
	 * Enqueue the button stylesheet.
	 */
	public static function enqueue_css() {
		wp_enqueue_style(
			'agentid-sso-login',
			AGENTID_SSO_URL . 'assets/css/agentid-login.css',
			array(),
			AGENTID_SSO_VERSION
		);
	}

	/**
	 * Render the button HTML.
	 *
	 * @param string $redirect_to Local redirect target after sign-in.
	 * @param string $label       Button label.
	 * @return string
	 */
	public static function html( $redirect_to = '', $label = '' ) {
		if ( ! agentid_sso_is_configured() ) {
			return '';
		}
		if ( '' === $label ) {
			$label = __( 'Sign in with AgentID', 'agentkey-sign-in-agentid' );
		}
		$url = agentid_sso_start_url( $redirect_to );
		return sprintf(
			'<p class="agentid-login-wrap"><a class="agentid-login-button" href="%s">%s</a></p>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Button under the wp-login form.
	 */
	public static function on_wp_login() {
		if ( ! agentid_sso_get_option( 'show_login_button', 1 ) ) {
			return;
		}
		$redirect_to = '';
		if ( isset( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only passthrough; validated below.
			$redirect_to = wp_validate_redirect( esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ), '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		echo self::html( (string) $redirect_to ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside html().
	}

	/**
	 * Button at the end of the WooCommerce login form.
	 */
	public static function on_woo_login() {
		if ( ! agentid_sso_get_option( 'show_login_button', 1 ) ) {
			return;
		}
		self::enqueue_css();
		// Send the customer back where they were headed: checkout
		// when the form is embedded there, My Account otherwise.
		// The homepage default broke the WooCommerce journey.
		$redirect = '';
		if ( function_exists( 'is_checkout' ) && is_checkout() && function_exists( 'wc_get_checkout_url' ) ) {
			$redirect = (string) wc_get_checkout_url();
		} elseif ( function_exists( 'wc_get_page_permalink' ) ) {
			$redirect = (string) wc_get_page_permalink( 'myaccount' );
		}
		echo self::html( $redirect ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside html().
	}

	/**
	 * Shortcode: [agentid_login label="..." redirect="/my-account/"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'label'    => '',
				'redirect' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'agentid_login'
		);
		self::enqueue_css();
		$redirect = wp_validate_redirect( esc_url_raw( (string) $atts['redirect'] ), '' );
		return self::html( (string) $redirect, sanitize_text_field( (string) $atts['label'] ) );
	}

	/**
	 * Register the login button block (server-rendered).
	 */
	public static function register_block() {
		$dir = AGENTID_SSO_DIR . 'blocks/login-button';
		if ( file_exists( $dir . '/block.json' ) ) {
			register_block_type(
				$dir,
				array(
					'render_callback' => array( __CLASS__, 'render_block' ),
				)
			);
		}
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_block( $attributes ) {
		$label = isset( $attributes['label'] ) ? sanitize_text_field( (string) $attributes['label'] ) : '';
		return self::html( '', $label );
	}
}
