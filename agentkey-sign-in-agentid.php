<?php
/**
 * Plugin Name:       AgentKey Sign-In for AgentID
 * Plugin URI:        https://github.com/Mike-Demo/agentkey-sign-in-agentid
 * Description:       Adds "Sign in with AgentID" to WP and WooCommerce. Lets verified AI agents sign in as themselves — with a human owner on record — instead of sharing a person's password. Site owners register their own free application at console.agentid.com and connect it here. Independent community integration for AgentID by AgentMail (github.com/agentmail-to); not affiliated with or endorsed by AgentMail.
 * Version:           0.1.6
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Mike Demopoulos
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agentkey-sign-in-agentid
 * Domain Path:       /languages
 *
 * @package AgentKeySignIn
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGENTID_SSO_VERSION', '0.1.6' );
define( 'AGENTID_SSO_FILE', __FILE__ );
define( 'AGENTID_SSO_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGENTID_SSO_URL', plugin_dir_url( __FILE__ ) );
define( 'AGENTID_ISSUER', 'https://auth.agentid.com' );

require_once AGENTID_SSO_DIR . 'includes/class-agentid-oidc.php';
require_once AGENTID_SSO_DIR . 'includes/class-agentid-settings.php';
require_once AGENTID_SSO_DIR . 'includes/class-agentid-auth.php';
require_once AGENTID_SSO_DIR . 'includes/class-agentid-guards.php';
require_once AGENTID_SSO_DIR . 'includes/class-agentid-button.php';

/**
 * Default plugin options.
 *
 * @return array
 */
function agentid_sso_default_options() {
	return array(
		'client_id'           => '',
		'client_secret'       => '',
		'scopes'              => 'openid email profile owner_profile owner_email',
		'default_role'        => 'subscriber',
		'link_by_owner_email' => 1,
		'session_hours'       => 12,
		'show_login_button'   => 1,
	);
}

/**
 * Get a plugin option value.
 *
 * @param string $key           Option key.
 * @param mixed  $fallback_value Fallback when the key is unset.
 * @return mixed
 */
function agentid_sso_get_option( $key, $fallback_value = null ) {
	$options = get_option( 'agentid_sso_options', array() );
	if ( ! is_array( $options ) ) {
		$options = array();
	}
	$defaults = agentid_sso_default_options();
	if ( array_key_exists( $key, $options ) ) {
		return $options[ $key ];
	}
	if ( null !== $fallback_value ) {
		return $fallback_value;
	}
	return $defaults[ $key ] ?? null;
}

/**
 * The redirect URI that must be registered in the AgentID console.
 *
 * @return string
 */
function agentid_sso_redirect_uri() {
	return admin_url( 'admin-post.php?action=agentid_callback' );
}

/**
 * The URL that starts an AgentID sign-in flow.
 *
 * @param string $redirect_to Local URL to land on after sign-in.
 * @param string $intent      Optional flow intent, e.g. "link".
 * @return string
 */
function agentid_sso_start_url( $redirect_to = '', $intent = '' ) {
	$args = array( 'action' => 'agentid_login' );
	if ( '' !== $redirect_to ) {
		$args['redirect_to'] = $redirect_to;
	}
	if ( '' !== $intent ) {
		$args['intent'] = $intent;
	}
	return add_query_arg( $args, admin_url( 'admin-post.php' ) );
}

/**
 * Whether the plugin has the minimum configuration to run a flow.
 *
 * @return bool
 */
function agentid_sso_is_configured() {
	return '' !== (string) agentid_sso_get_option( 'client_id', '' );
}

/**
 * Declare WooCommerce feature compatibility (HPOS) when WooCommerce
 * is present, per the WooCommerce extension guidelines. This plugin
 * only adds a login button to WooCommerce forms and creates standard
 * WP user accounts, so it is fully compatible.
 */
function agentid_sso_declare_woo_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'agentid_sso_declare_woo_compatibility' );

/**
 * Boot the plugin components.
 */
function agentid_sso_init() {
	AgentID_Settings::init();
	AgentID_Auth::init();
	AgentID_Guards::init();
	AgentID_Button::init();
}
add_action( 'init', 'agentid_sso_init' );

/**
 * Activation: seed default options.
 */
function agentid_sso_activate() {
	$existing = get_option( 'agentid_sso_options', false );
	if ( false === $existing ) {
		add_option( 'agentid_sso_options', agentid_sso_default_options() );
	}
}
register_activation_hook( __FILE__, 'agentid_sso_activate' );
