<?php
/**
 * AgentID sign-in flow: start, callback, provisioning, linking.
 *
 * Request-integrity model (nonce / capability map):
 *  - The two endpoints in this class are the OAuth 2.0 / OIDC flow
 *    itself. A WordPress nonce cannot be used on them: the login
 *    start must work for signed-out visitors (an anonymous nonce is
 *    not bound to any session, so it would verify nothing), and the
 *    callback request originates as a redirect from the identity
 *    provider, which cannot carry a WordPress nonce. The OAuth
 *    `state` parameter is the CSRF token for this protocol and is
 *    enforced as such below: a 192-bit random value, stored
 *    server-side in a single-use transient, matched against an
 *    HttpOnly browser cookie with hash_equals(), and combined with
 *    PKCE S256 and the OIDC `nonce` claim inside the verified ID
 *    token.
 *  - Starting a flow changes no site state beyond that transient
 *    and cookie. The one state-changing-adjacent variant, starting
 *    a *linking* flow for a signed-in account, additionally
 *    requires is_user_logged_in() and a WordPress nonce
 *    (wp_verify_nonce( ..., 'agentid_link_start' )), and the
 *    callback re-checks that the signed-in user is the account
 *    that started the flow before anything is linked.
 *  - Every other request handler in this plugin follows the usual
 *    WordPress model: settings are saved through the Settings API
 *    (core nonce + manage_options), and the Test Connection AJAX
 *    action runs check_ajax_referer() plus
 *    current_user_can( 'manage_options' ) before doing any work.
 *
 * Security properties:
 *  - state + nonce + PKCE S256 on every flow; the state is also bound
 *    to the browser with an HttpOnly cookie so an authorization code
 *    cannot be injected into someone else's browser session.
 *  - Flow data lives in a 10-minute transient keyed by a hash of the
 *    state and is deleted on first use.
 *  - Users are matched by the immutable AgentID subject (sub), never
 *    by email alone. Email collisions fail closed.
 *  - Provisioned accounts never receive a role that can administer
 *    the site.
 *
 * @package AgentKeySignIn
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sign-in flow, provisioning and account linking.
 */
class AgentID_Auth {

	const FLOW_COOKIE = 'agentid_sso_flow';
	const FLOW_TTL    = 600; // 10 minutes, matching AgentID sign-in link lifetime.

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_post_agentid_login', array( __CLASS__, 'start' ) );
		add_action( 'admin_post_nopriv_agentid_login', array( __CLASS__, 'start' ) );
		add_action( 'admin_post_agentid_callback', array( __CLASS__, 'callback' ) );
		add_action( 'admin_post_nopriv_agentid_callback', array( __CLASS__, 'callback' ) );
		add_filter( 'wp_login_errors', array( __CLASS__, 'login_errors' ), 10, 2 );
	}

	/**
	 * Random base64url token.
	 *
	 * @param int $bytes Entropy in bytes.
	 * @return string
	 */
	private static function random_token( $bytes = 32 ) {
		return AgentID_OIDC::b64url_encode( random_bytes( $bytes ) );
	}

	/**
	 * Transient key for a flow state.
	 *
	 * @param string $state State value.
	 * @return string
	 */
	private static function flow_key( $state ) {
		return 'agentid_sso_flow_' . hash( 'sha256', $state );
	}

	/**
	 * Cookie path for the flow cookie.
	 *
	 * COOKIEPATH is derived from the home URL, but the callback
	 * lives under admin_url() — on installs where the two paths
	 * differ (home in one directory, WordPress core in another)
	 * a COOKIEPATH-scoped cookie never reaches admin-post.php and
	 * every callback fails the state check. The cookie carries
	 * only a hash of the single-use state and lives ten minutes,
	 * so scoping it to the host root is the safe choice.
	 *
	 * @return string
	 */
	private static function flow_cookie_path() {
		return '/';
	}

	/**
	 * Redirect to wp-login with an error code and stop.
	 *
	 * @param string $code Error code slug.
	 */
	private static function fail( $code ) {
		$url = add_query_arg(
			array(
				'action'       => 'agentid_error',
				'agentid_code' => $code,
			),
			wp_login_url()
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Human-readable messages for flow error codes shown on wp-login.
	 *
	 * @return array
	 */
	public static function error_messages() {
		return array(
			'not_configured'  => __( 'AgentID sign-in is not configured on this site yet.', 'agentkey-sign-in-agentid' ),
			'discovery'       => __( 'This site could not reach AgentID. Please try again later.', 'agentkey-sign-in-agentid' ),
			'state'           => __( 'The sign-in link has expired or does not belong to this browser. Please start again.', 'agentkey-sign-in-agentid' ),
			'denied'          => __( 'The sign-in was cancelled at AgentID.', 'agentkey-sign-in-agentid' ),
			'token'           => __( 'AgentID did not complete the sign-in. Please start again.', 'agentkey-sign-in-agentid' ),
			'verify'          => __( 'The AgentID identity could not be verified.', 'agentkey-sign-in-agentid' ),
			'email_taken'     => __( 'The agent\'s email address already belongs to a different account on this site, so sign-in was refused. Ask the site owner to resolve the conflict.', 'agentkey-sign-in-agentid' ),
			'no_role'         => __( 'No role on this site is available for agent accounts right now, so sign-in was refused. Ask the site owner to check the plugin settings.', 'agentkey-sign-in-agentid' ),
			'disabled'        => __( 'This agent account has been disabled by the site owner.', 'agentkey-sign-in-agentid' ),
			'provision'       => __( 'An account could not be created for this agent.', 'agentkey-sign-in-agentid' ),
			'link_no_user'    => __( 'Please sign in to your account first, then start linking again.', 'agentkey-sign-in-agentid' ),
			'link_nonce'      => __( 'That linking link has expired. Please start again from your profile.', 'agentkey-sign-in-agentid' ),
			'link_sub_in_use' => __( 'That agent is already linked to a different account.', 'agentkey-sign-in-agentid' ),
		);
	}

	/**
	 * Add the flow error to the login form's error list.
	 *
	 * Goes through the wp_login_errors filter so core renders the
	 * message inside the form, in the right place — the previous
	 * login_form_agentid_error action printed a raw div before
	 * login_header() had run, i.e. before the page's doctype.
	 *
	 * @param WP_Error $errors      Login errors so far.
	 * @param string   $redirect_to Redirect destination after login.
	 * @return WP_Error
	 */
	public static function login_errors( $errors, $redirect_to ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $redirect_to is part of the wp_login_errors filter signature; the flow error does not depend on it.
		$code     = isset( $_GET['agentid_code'] ) ? sanitize_key( wp_unslash( $_GET['agentid_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Displaying a message for a code we generated; no state changes.
		$messages = self::error_messages();
		if ( $errors instanceof WP_Error && isset( $messages[ $code ] ) ) {
			$errors->add( 'agentid_error', $messages[ $code ] );
		}
		return $errors;
	}

	/**
	 * Start a flow: build the authorize URL and redirect to AgentID.
	 */
	public static function start() {
		if ( ! agentid_sso_is_configured() ) {
			self::fail( 'not_configured' );
		}
		$intent       = isset( $_GET['intent'] ) ? sanitize_key( wp_unslash( $_GET['intent'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Starting a login flow changes no site state; the flow itself is state/PKCE protected.
		$link_user_id = 0;
		if ( 'link' === $intent ) {
			if ( ! is_user_logged_in() ) {
				self::fail( 'link_no_user' );
			}
			// Starting a link flow must be the account holder's own
			// intent: the profile button carries a nonce, verified
			// here, so a cross-site request cannot start a flow in a
			// signed-in user's browser.
			$link_nonce = isset( $_GET['agentid_link_nonce'] ) ? sanitize_key( wp_unslash( $_GET['agentid_link_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The value is a nonce, verified with wp_verify_nonce() immediately below and never output or stored.
			if ( ! wp_verify_nonce( $link_nonce, 'agentid_link_start' ) ) {
				self::fail( 'link_nonce' );
			}
			$link_user_id = get_current_user_id();
		}
		$redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirect_to = wp_validate_redirect( $redirect_to, home_url( '/' ) );

		$discovery = AgentID_OIDC::discovery();
		if ( is_wp_error( $discovery ) ) {
			self::fail( 'discovery' );
		}

		$state     = self::random_token( 24 );
		$nonce     = self::random_token( 24 );
		$verifier  = self::random_token( 32 );
		$challenge = AgentID_OIDC::b64url_encode( hash( 'sha256', $verifier, true ) );

		set_transient(
			self::flow_key( $state ),
			array(
				'nonce'        => $nonce,
				'verifier'     => $verifier,
				'redirect_to'  => $redirect_to,
				'link_user_id' => $link_user_id,
			),
			self::FLOW_TTL
		);

		// Bind the flow to this browser.
		setcookie(
			self::FLOW_COOKIE,
			hash( 'sha256', $state ),
			array(
				'expires'  => time() + self::FLOW_TTL,
				'path'     => self::flow_cookie_path(),
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		$url = add_query_arg(
			array(
				'response_type'         => 'code',
				'client_id'             => (string) agentid_sso_get_option( 'client_id', '' ),
				'redirect_uri'          => agentid_sso_redirect_uri(),
				'scope'                 => (string) agentid_sso_get_option( 'scopes', 'openid' ),
				'state'                 => $state,
				'nonce'                 => $nonce,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			$discovery['authorization_endpoint']
		);
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External redirect to the identity provider is the purpose of this endpoint.
		exit;
	}

	/**
	 * Handle the AgentID redirect back to the site.
	 */
	public static function callback() {
		// The IdP may return an error instead of a code.
		if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback; protected by state + flow cookie below.
			self::fail( 'denied' );
		}
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $state || '' === $code ) {
			self::fail( 'state' );
		}
		$cookie = isset( $_COOKIE[ self::FLOW_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::FLOW_COOKIE ] ) ) : '';
		if ( '' === $cookie || ! hash_equals( hash( 'sha256', $state ), $cookie ) ) {
			self::fail( 'state' );
		}
		$flow = get_transient( self::flow_key( $state ) );
		delete_transient( self::flow_key( $state ) );
		// Clear the flow cookie.
		setcookie(
			self::FLOW_COOKIE,
			'',
			array(
				'expires'  => time() - HOUR_IN_SECONDS,
				'path'     => self::flow_cookie_path(),
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		if ( ! is_array( $flow ) || empty( $flow['verifier'] ) || empty( $flow['nonce'] ) ) {
			self::fail( 'state' );
		}

		$client_id = (string) agentid_sso_get_option( 'client_id', '' );
		$secret    = (string) agentid_sso_get_option( 'client_secret', '' );

		$discovery = AgentID_OIDC::discovery();
		if ( is_wp_error( $discovery ) ) {
			self::fail( 'discovery' );
		}
		$tokens = AgentID_OIDC::exchange_code( $discovery, $code, (string) $flow['verifier'], $client_id, $secret );
		if ( is_wp_error( $tokens ) ) {
			self::fail( 'token' );
		}

		$jwks   = AgentID_OIDC::jwks();
		$claims = is_wp_error( $jwks ) ? $jwks : AgentID_OIDC::verify_id_token( (string) $tokens['id_token'], $jwks, $client_id, (string) $flow['nonce'] );
		if ( is_wp_error( $claims ) && ! is_wp_error( $jwks ) && 'agentid_jwt_kid' === $claims->get_error_code() ) {
			// Key rotation: refetch the JWKS once and retry.
			$jwks_fresh = AgentID_OIDC::jwks( true );
			if ( ! is_wp_error( $jwks_fresh ) ) {
				$claims = AgentID_OIDC::verify_id_token( (string) $tokens['id_token'], $jwks_fresh, $client_id, (string) $flow['nonce'] );
			}
		}
		if ( is_wp_error( $claims ) ) {
			self::fail( 'verify' );
		}

		// Enrich with userinfo when available; subject must agree.
		// Merge through a whitelist with the same type discipline as
		// the ID-token claims: userinfo may add profile fields, but
		// it can never replace verified token claims (subject,
		// actor_type, audience, ...) or smuggle in non-scalar values.
		if ( ! empty( $tokens['access_token'] ) ) {
			$userinfo = AgentID_OIDC::userinfo( $discovery, (string) $tokens['access_token'] );
			if ( ! is_wp_error( $userinfo ) && isset( $userinfo['sub'] ) && $userinfo['sub'] === $claims['sub'] ) {
				foreach ( array( 'email', 'name', 'preferred_username', 'owner_sub', 'owner_email', 'owner_name' ) as $field ) {
					if ( isset( $userinfo[ $field ] ) && is_string( $userinfo[ $field ] ) ) {
						$claims[ $field ] = $userinfo[ $field ];
					}
				}
				foreach ( array( 'email_verified', 'owner_email_verified' ) as $field ) {
					if ( isset( $userinfo[ $field ] ) && ( is_bool( $userinfo[ $field ] ) || is_string( $userinfo[ $field ] ) ) ) {
						$claims[ $field ] = $userinfo[ $field ];
					}
				}
			}
		}

		// Linking flow: attach this agent identity to the signed-in owner.
		if ( ! empty( $flow['link_user_id'] ) ) {
			self::finish_linking( (int) $flow['link_user_id'], $claims );
		}

		$user = self::find_agent_user( (string) $claims['sub'] );
		if ( ! $user ) {
			$user = self::provision_agent_user( $claims );
			if ( is_wp_error( $user ) ) {
				self::fail( $user->get_error_code() );
			}
		} else {
			self::refresh_agent_meta( $user->ID, $claims );
		}

		if ( get_user_meta( $user->ID, 'agentid_disabled', true ) ) {
			self::fail( 'disabled' );
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false );
		/** This action is documented in wp-includes/user.php */
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberately firing core's wp_login action so login auditing and other plugins observe AgentID sign-ins exactly like password sign-ins.

		$target = ! empty( $flow['redirect_to'] ) ? (string) $flow['redirect_to'] : home_url( '/' );
		wp_safe_redirect( wp_validate_redirect( $target, home_url( '/' ) ) );
		exit;
	}

	/**
	 * Complete a linking flow for a signed-in owner.
	 *
	 * Stores the agent subject on the owner's account as a pre-authorised
	 * pairing. Later agent sign-ins still get their own account; the
	 * pairing records who owns it. Never logs the owner in as the agent
	 * or the agent in as the owner.
	 *
	 * @param int   $owner_id Owner user ID.
	 * @param array $claims   Verified claims.
	 */
	private static function finish_linking( $owner_id, array $claims ) {
		if ( get_current_user_id() !== $owner_id ) {
			self::fail( 'link_no_user' );
		}
		$existing = self::find_user_by_meta( 'agentid_linked_sub', (string) $claims['sub'] );
		if ( $existing && (int) $existing->ID !== $owner_id ) {
			self::fail( 'link_sub_in_use' );
		}
		$agent_user = self::find_agent_user( (string) $claims['sub'] );
		if ( $agent_user && (int) get_user_meta( $agent_user->ID, 'agentid_owner_user_id', true ) !== $owner_id
			&& get_user_meta( $agent_user->ID, 'agentid_owner_user_id', true ) ) {
			self::fail( 'link_sub_in_use' );
		}
		update_user_meta( $owner_id, 'agentid_linked_sub', (string) $claims['sub'] );
		if ( $agent_user ) {
			update_user_meta( $agent_user->ID, 'agentid_owner_user_id', $owner_id );
		}
		wp_safe_redirect( add_query_arg( 'agentid_linked', '1', get_edit_profile_url( $owner_id ) ) );
		exit;
	}

	/**
	 * Find a user by a meta key/value pair.
	 *
	 * @param string $key   Meta key.
	 * @param string $value Meta value.
	 * @return WP_User|false
	 */
	private static function find_user_by_meta( $key, $value ) {
		$users = get_users(
			array(
				'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small, bounded lookup at sign-in time.
				'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'all',
			)
		);
		return ! empty( $users ) ? $users[0] : false;
	}

	/**
	 * Find the agent account for a subject.
	 *
	 * @param string $sub AgentID subject.
	 * @return WP_User|false
	 */
	public static function find_agent_user( $sub ) {
		return self::find_user_by_meta( 'agentid_sub', $sub );
	}

	/**
	 * Resolve the provisionable role: the configured role when an
	 * agent may hold it, otherwise the validated fallback from
	 * AgentID_Settings::fallback_role(). Returns null when no safe
	 * role exists at all — callers must refuse rather than assign
	 * an unvalidated or nonexistent role.
	 *
	 * @return string|null
	 */
	public static function safe_default_role() {
		$role  = (string) agentid_sso_get_option( 'default_role', 'subscriber' );
		$roles = AgentID_Settings::provisionable_roles();
		if ( array_key_exists( $role, $roles ) ) {
			return $role;
		}
		return AgentID_Settings::fallback_role();
	}

	/**
	 * The owner user ID for claims.
	 *
	 * An explicit profile link is the strongest signal — a signed-in
	 * human deliberately paired this agent subject with their own
	 * account — so it is honoured first, whether or not owner email
	 * matching is enabled. Without it, an agent whose owner could
	 * not be resolved this way silently received no capability
	 * ceiling beyond the hard denies. Verified owner email matching
	 * is the fallback.
	 *
	 * @param array $claims Verified claims.
	 * @return int
	 */
	private static function resolve_owner_id( array $claims ) {
		$linked = self::find_user_by_meta( 'agentid_linked_sub', (string) $claims['sub'] );
		if ( $linked ) {
			return (int) $linked->ID;
		}
		if ( ! agentid_sso_get_option( 'link_by_owner_email', 1 ) ) {
			return 0;
		}
		if ( empty( $claims['owner_email'] ) || ! is_string( $claims['owner_email'] ) || empty( $claims['owner_email_verified'] ) || ( true !== $claims['owner_email_verified'] && 'true' !== $claims['owner_email_verified'] ) ) {
			return 0;
		}
		$owner = get_user_by( 'email', sanitize_email( $claims['owner_email'] ) );
		return $owner ? (int) $owner->ID : 0;
	}

	/**
	 * Reconnect an account retained across an uninstall.
	 *
	 * Uninstall keeps agent user accounts but removes their
	 * identity meta, so after a reinstall the same agent would be
	 * refused with email_taken forever. Undoing that automatically
	 * is only safe for placeholder accounts: the placeholder
	 * address is a hash of THIS subject, so it can only match an
	 * account created for this agent's sign-ins. Even then, the
	 * account rejoins at the site's safe default role — never at
	 * whatever role the retained shell happens to hold — and the
	 * reconnect is refused when no safe role exists. Verified-email
	 * collisions still need a human admin (see the readme FAQ) —
	 * an agent is never signed in to an existing account by email
	 * alone.
	 *
	 * @param int    $user_id     Existing account ID.
	 * @param string $email       Email from the current claims.
	 * @param string $placeholder This subject's placeholder address.
	 * @param array  $claims      Verified claims.
	 * @return WP_User|false The reconnected user, or false.
	 */
	private static function reconnect_retained_account( $user_id, $email, $placeholder, array $claims ) {
		if ( $email !== $placeholder ) {
			return false;
		}
		if ( '' !== (string) get_user_meta( $user_id, 'agentid_sub', true ) ) {
			// Already mapped to a subject — not a retained shell.
			return false;
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}
		$role = self::safe_default_role();
		if ( null === $role ) {
			return false;
		}
		update_user_meta( $user_id, 'agentid_is_agent', 1 );
		if ( array( $role ) !== (array) $user->roles ) {
			$user->set_role( $role );
		}
		self::refresh_agent_meta( $user_id, $claims );
		return $user;
	}

	/**
	 * Create the agent's own WP account from verified claims.
	 *
	 * Called only with claims from a verified ID token: AgentID_OIDC
	 * has checked the signature (ES256), issuer, audience, expiry
	 * and nonce, and actor_type is 'agent' — every check fails
	 * closed. The local account is what the agent's WordPress
	 * session, role and sign-in record attach to, which is why an
	 * SSO sign-in provisions one. Accounts are matched by the
	 * immutable sub claim, never by email; the one email-based
	 * path is the placeholder reconnect in
	 * reconnect_retained_account().
	 *
	 * The role comes from safe_default_role(): the site owner's
	 * configured default, but only when that role grants none of
	 * AgentID_Guards::hard_denied_caps(); otherwise the
	 * least-privileged provisionable stock role; if none exists,
	 * provisioning is refused. While this plugin is active, the
	 * capability ceiling in AgentID_Guards denies the hard-denied
	 * set to the account whatever role it holds.
	 *
	 * @param array $claims Verified claims.
	 * @return WP_User|WP_Error
	 */
	private static function provision_agent_user( array $claims ) {
		$email          = '';
		$email_verified = isset( $claims['email_verified'] ) && ( true === $claims['email_verified'] || 'true' === $claims['email_verified'] );
		if ( $email_verified && isset( $claims['email'] ) && is_string( $claims['email'] ) ) {
			$candidate = sanitize_email( $claims['email'] );
			if ( is_email( $candidate ) ) {
				$email = $candidate;
			}
		}
		$placeholder = 'agentid-' . substr( hash( 'sha256', (string) $claims['sub'] ), 0, 16 ) . '@agentid.invalid';
		if ( '' === $email ) {
			// The agent's email claim is absent or unverified: never
			// present it as an established contact address. The account
			// is keyed by the immutable subject, so a site-controlled,
			// non-deliverable placeholder derived from the sub works.
			$email = $placeholder;
		}
		$existing_id = email_exists( $email );
		if ( $existing_id ) {
			$reconnected = self::reconnect_retained_account( (int) $existing_id, $email, $placeholder, $claims );
			if ( $reconnected ) {
				return $reconnected;
			}
			// Never sign an agent in to an existing account by email,
			// and never shadow one either. A human admin resolves it.
			return new WP_Error( 'email_taken', 'Agent email already in use.' );
		}

		$base = '';
		if ( ! empty( $claims['preferred_username'] ) ) {
			$base = sanitize_user( (string) $claims['preferred_username'], true );
		}
		if ( '' === $base ) {
			$base = sanitize_user( strstr( $email, '@', true ), true );
		}
		if ( '' === $base ) {
			$base = 'agent-' . strtolower( substr( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $claims['sub'] ), 0, 8 ) );
		}
		// user_login is capped at 60 characters: bound the base so
		// the collision suffix always fits inside the cap.
		$username = substr( $base, 0, 60 );
		$suffix   = 1;
		while ( username_exists( $username ) ) {
			++$suffix;
			$tail     = '-' . $suffix;
			$username = substr( $base, 0, 60 - strlen( $tail ) ) . $tail;
		}

		$display = isset( $claims['name'] ) && is_string( $claims['name'] ) && '' !== $claims['name']
			? sanitize_text_field( $claims['name'] )
			: $username;
		if ( ! empty( $claims['owner_name'] ) && is_string( $claims['owner_name'] ) ) {
			/* translators: 1: agent display name, 2: owner name */
			$display = sprintf( __( '%1$s (agent of %2$s)', 'agentkey-sign-in-agentid' ), $display, sanitize_text_field( $claims['owner_name'] ) );
		}

		$role = self::safe_default_role();
		if ( null === $role ) {
			return new WP_Error( 'no_role', 'No provisionable role available.' );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'user_email'   => $email,
				'display_name' => $display,
				'role'         => $role,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'provision', 'Provisioning failed.' );
		}
		update_user_meta( $user_id, 'agentid_is_agent', 1 );
		self::refresh_agent_meta( $user_id, $claims );
		/**
		 * Fires after a new agent account is provisioned.
		 *
		 * @param int   $user_id New user ID.
		 * @param array $claims  Verified AgentID claims.
		 */
		do_action( 'agentid_agent_provisioned', $user_id, $claims );
		return get_user_by( 'id', $user_id );
	}

	/**
	 * Store / refresh the AgentID claims kept on an agent account.
	 *
	 * Only the fields the site needs for display, ownership and policy
	 * are kept. Tokens are never stored.
	 *
	 * @param int   $user_id User ID.
	 * @param array $claims  Verified claims.
	 */
	public static function refresh_agent_meta( $user_id, array $claims ) {
		update_user_meta( $user_id, 'agentid_sub', (string) $claims['sub'] );
		if ( isset( $claims['actor_type'] ) && is_string( $claims['actor_type'] ) ) {
			update_user_meta( $user_id, 'agentid_actor_type', sanitize_text_field( $claims['actor_type'] ) );
		}
		if ( ! empty( $claims['owner_sub'] ) && is_string( $claims['owner_sub'] ) ) {
			update_user_meta( $user_id, 'agentid_owner_sub', sanitize_text_field( $claims['owner_sub'] ) );
		}
		if ( ! empty( $claims['owner_email'] ) && is_string( $claims['owner_email'] ) ) {
			update_user_meta( $user_id, 'agentid_owner_email', sanitize_email( $claims['owner_email'] ) );
		}
		if ( ! empty( $claims['owner_name'] ) && is_string( $claims['owner_name'] ) ) {
			update_user_meta( $user_id, 'agentid_owner_name', sanitize_text_field( $claims['owner_name'] ) );
		}
		$owner_id = self::resolve_owner_id( $claims );
		if ( $owner_id > 0 && (int) $user_id !== $owner_id ) {
			update_user_meta( $user_id, 'agentid_owner_user_id', $owner_id );
		}
		update_user_meta( $user_id, 'agentid_last_login', time() );
	}

	/**
	 * Whether a user is an agent-provisioned (or linked agent) account.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_agent_user( $user_id ) {
		return 1 === (int) get_user_meta( $user_id, 'agentid_is_agent', true )
			|| '' !== (string) get_user_meta( $user_id, 'agentid_sub', true );
	}
}
