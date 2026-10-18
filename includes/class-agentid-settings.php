<?php
/**
 * Admin settings + setup wizard for AgentKey Sign-In for AgentID.
 *
 * @package AgentKeySignIn
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings and setup wizard.
 */
class AgentID_Settings {

	const PAGE_SLUG = 'agentkey-sign-in-agentid';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_agentid_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'admin_notices', array( __CLASS__, 'not_configured_notice' ) );
	}

	/**
	 * Add the settings page under Settings.
	 */
	public static function add_menu() {
		add_options_page(
			__( 'AgentKey Sign-In for AgentID', 'agentkey-sign-in-agentid' ),
			__( 'AgentKey Sign-In for AgentID', 'agentkey-sign-in-agentid' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register the options with the Settings API.
	 */
	public static function register_settings() {
		register_setting(
			'agentid_sso_group',
			'agentid_sso_options',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_options' ),
				'default'           => agentid_sso_default_options(),
			)
		);
	}

	/**
	 * Roles that an agent account may be provisioned with.
	 *
	 * Any role granting a site-administering capability — the set in
	 * AgentID_Guards::hard_denied_caps(): manage_options, plugin and
	 * theme management, user promotion, unfiltered HTML, and the
	 * rest — is refused: an agent identity must never be able to
	 * administer the site, even between sign-ins.
	 *
	 * @return array role slug => label
	 */
	public static function provisionable_roles() {
		$roles  = array();
		$denied = AgentID_Guards::hard_denied_caps();
		foreach ( wp_roles()->roles as $slug => $role ) {
			$caps = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();
			foreach ( $denied as $denied_cap ) {
				if ( ! empty( $caps[ $denied_cap ] ) ) {
					continue 2;
				}
			}
			$roles[ $slug ] = translate_user_role( $role['name'] );
		}
		return $roles;
	}

	/**
	 * A fallback provisioning role, always validated against the
	 * provisionable set: the least-privileged of the stock roles an
	 * agent may hold, or null when none of them is available (a role
	 * editor granted Subscriber a hard-denied capability, or the
	 * role was removed). Callers must treat null as "no safe role
	 * exists" rather than inventing one.
	 *
	 * @return string|null
	 */
	public static function fallback_role() {
		$roles = self::provisionable_roles();
		foreach ( array( 'subscriber', 'contributor', 'author' ) as $candidate ) {
			if ( array_key_exists( $candidate, $roles ) ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Sanitize the options array.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize_options( $input ) {
		$existing = get_option( 'agentid_sso_options', array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$out = agentid_sso_default_options();

		// The Settings API hands this callback values that options.php
		// has already unslashed, so do NOT unslash again (a second pass
		// eats literal backslashes). Credentials are opaque byte
		// strings: sanitize_text_field() would also strip percent
		// sequences and tags from them, silently changing a valid
		// secret into a wrong one. Accept strings verbatim apart from
		// surrounding whitespace, which is never part of a credential.
		$out['client_id'] = isset( $input['client_id'] ) && is_string( $input['client_id'] ) ? trim( $input['client_id'], " \t\n\r\0\x0B" ) : '';

		// An empty secret field means "keep the stored secret".
		$submitted_secret = isset( $input['client_secret'] ) && is_string( $input['client_secret'] ) ? trim( $input['client_secret'], " \t\n\r\0\x0B" ) : '';
		if ( '' !== $submitted_secret ) {
			$out['client_secret'] = $submitted_secret;
		} elseif ( ! empty( $input['clear_secret'] ) ) {
			$out['client_secret'] = '';
		} else {
			$out['client_secret'] = isset( $existing['client_secret'] ) ? (string) $existing['client_secret'] : '';
		}

		$scopes = isset( $input['scopes'] ) && is_string( $input['scopes'] ) ? sanitize_text_field( $input['scopes'] ) : '';
		$parts  = array_filter( array_map( 'trim', explode( ' ', $scopes ) ) );
		if ( ! in_array( 'openid', $parts, true ) ) {
			array_unshift( $parts, 'openid' );
		}
		$allowed_scopes = array( 'openid', 'email', 'profile', 'owner_profile', 'owner_email' );
		$parts          = array_values( array_intersect( $parts, $allowed_scopes ) );
		$out['scopes']  = implode( ' ', $parts );

		$role = isset( $input['default_role'] ) && is_string( $input['default_role'] ) ? sanitize_key( $input['default_role'] ) : '';
		if ( ! array_key_exists( $role, self::provisionable_roles() ) ) {
			// Never substitute an unvalidated role: use the validated
			// fallback, or keep the requested value when no safe role
			// exists at all (provisioning then fails with a clear
			// error instead of assigning a rejected role).
			$fallback = self::fallback_role();
			if ( null !== $fallback ) {
				$role = $fallback;
			}
		}
		$out['default_role'] = $role;

		$out['link_by_owner_email'] = ! empty( $input['link_by_owner_email'] ) ? 1 : 0;
		$out['show_login_button']   = ! empty( $input['show_login_button'] ) ? 1 : 0;

		$hours                = isset( $input['session_hours'] ) ? absint( $input['session_hours'] ) : 12;
		$out['session_hours'] = max( 1, min( 72, $hours ) );

		// Configuration changed: drop cached discovery / JWKS.
		delete_transient( 'agentid_sso_discovery' );
		delete_transient( 'agentid_sso_jwks' );

		return $out;
	}

	/**
	 * Enqueue admin assets on our page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'agentid-sso-admin',
			AGENTID_SSO_URL . 'assets/js/admin.js',
			array(),
			AGENTID_SSO_VERSION,
			true
		);
		wp_localize_script(
			'agentid-sso-admin',
			'agentidSsoAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'agentid_test_connection' ),
				'testing'    => __( 'Testing…', 'agentkey-sign-in-agentid' ),
				'copied'     => __( 'Copied', 'agentkey-sign-in-agentid' ),
				'copy'       => __( 'Copy', 'agentkey-sign-in-agentid' ),
				'copyFailed' => __( 'Copy failed — select the text and copy it manually.', 'agentkey-sign-in-agentid' ),
				'testFailed' => __( 'Test failed.', 'agentkey-sign-in-agentid' ),
			)
		);
	}

	/**
	 * Notice when the plugin is active but not configured.
	 */
	public static function not_configured_notice() {
		if ( ! current_user_can( 'manage_options' ) || agentid_sso_is_configured() ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && 'settings_page_' . self::PAGE_SLUG === $screen->id ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'AgentKey Sign-In for AgentID is active but no AgentID application is connected yet.', 'agentkey-sign-in-agentid' ),
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Finish setup', 'agentkey-sign-in-agentid' )
		);
	}

	/**
	 * AJAX: test discovery + JWKS against the live issuer.
	 */
	public static function ajax_test_connection() {
		check_ajax_referer( 'agentid_test_connection' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'agentkey-sign-in-agentid' ) ), 403 );
		}
		$discovery = AgentID_OIDC::discovery( true );
		if ( is_wp_error( $discovery ) ) {
			wp_send_json_error( array( 'message' => $discovery->get_error_message() ) );
		}
		$jwks = AgentID_OIDC::jwks( true, $discovery );
		if ( is_wp_error( $jwks ) ) {
			wp_send_json_error( array( 'message' => $jwks->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: issuer, 2: number of signing keys */
					__( 'Connected. Issuer %1$s responded and published %2$d signing key(s).', 'agentkey-sign-in-agentid' ),
					$discovery['issuer'],
					count( $jwks['keys'] )
				),
				'issuer'  => $discovery['issuer'],
				'keys'    => count( $jwks['keys'] ),
			)
		);
	}

	/**
	 * Render the settings page: a 3-step setup wizard plus options.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$options    = get_option( 'agentid_sso_options', array() );
		$has_secret = is_array( $options ) && ! empty( $options['client_secret'] );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'AgentKey Sign-In for AgentID', 'agentkey-sign-in-agentid' ); ?></h1>
			<p><?php echo esc_html__( 'Let verified AI agents sign in to this site as themselves — with a human owner on record — instead of sharing a person\'s password.', 'agentkey-sign-in-agentid' ); ?></p>
			<p>
				<?php echo esc_html__( 'This is an independent, community-built integration. It is not affiliated with, endorsed by, or sponsored by AgentMail. AgentID is AgentMail\'s sign-in service for AI agents — credit and thanks to the AgentID project:', 'agentkey-sign-in-agentid' ); ?>
				<a href="https://www.agentid.com" target="_blank" rel="noopener noreferrer">agentid.com</a> ·
				<a href="https://github.com/agentmail-to" target="_blank" rel="noopener noreferrer">github.com/agentmail-to</a>
			</p>

			<h2><?php echo esc_html__( 'Setup', 'agentkey-sign-in-agentid' ); ?></h2>
			<ol>
				<li>
					<?php echo esc_html__( 'Create a free application for this site in the AgentID console (one application per site; only you can do this for your site).', 'agentkey-sign-in-agentid' ); ?>
					<a href="https://console.agentid.com" target="_blank" rel="noopener noreferrer">console.agentid.com</a>
				</li>
				<li>
					<?php echo esc_html__( 'In that application, register this exact Redirect URI:', 'agentkey-sign-in-agentid' ); ?>
					<br>
					<code id="agentid-redirect-uri"><?php echo esc_html( agentid_sso_redirect_uri() ); ?></code>
					<button type="button" class="button button-small" data-copy-target="agentid-redirect-uri"><?php echo esc_html__( 'Copy', 'agentkey-sign-in-agentid' ); ?></button>
					<br>
					<?php echo esc_html__( 'Token endpoint authentication: client_secret_basic. Require PKCE (S256). Request the owner_profile and owner_email scopes if you want owner details attached to agent accounts.', 'agentkey-sign-in-agentid' ); ?>
				</li>
				<li><?php echo esc_html__( 'Paste the Client ID (and Client Secret, if your application has one) below, save, then test the connection.', 'agentkey-sign-in-agentid' ); ?></li>
			</ol>

			<form method="post" action="options.php">
				<?php settings_fields( 'agentid_sso_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="agentid_client_id"><?php echo esc_html__( 'Client ID', 'agentkey-sign-in-agentid' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="agentid_client_id" name="agentid_sso_options[client_id]" value="<?php echo esc_attr( agentid_sso_get_option( 'client_id', '' ) ); ?>" autocomplete="off">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="agentid_client_secret"><?php echo esc_html__( 'Client Secret', 'agentkey-sign-in-agentid' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="agentid_client_secret" name="agentid_sso_options[client_secret]" value="" autocomplete="new-password" placeholder="<?php echo $has_secret ? esc_attr__( 'Stored — leave blank to keep', 'agentkey-sign-in-agentid' ) : ''; ?>">
							<?php if ( $has_secret ) : ?>
								<label>
									<input type="checkbox" name="agentid_sso_options[clear_secret]" value="1">
									<?php echo esc_html__( 'Remove the stored secret (public client / PKCE-only)', 'agentkey-sign-in-agentid' ); ?>
								</label>
							<?php endif; ?>
							<p class="description"><?php echo esc_html__( 'Stored in this site\'s database like other SSO credentials. Never shown again after saving.', 'agentkey-sign-in-agentid' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="agentid_scopes"><?php echo esc_html__( 'Scopes', 'agentkey-sign-in-agentid' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="agentid_scopes" name="agentid_sso_options[scopes]" value="<?php echo esc_attr( agentid_sso_get_option( 'scopes', '' ) ); ?>">
							<p class="description"><?php echo esc_html__( 'Space-separated. Allowed: openid, email, profile, owner_profile, owner_email. The openid scope is always included.', 'agentkey-sign-in-agentid' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="agentid_default_role"><?php echo esc_html__( 'Default role for new agent accounts', 'agentkey-sign-in-agentid' ); ?></label></th>
						<td>
							<select id="agentid_default_role" name="agentid_sso_options[default_role]">
								<?php foreach ( self::provisionable_roles() as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( agentid_sso_get_option( 'default_role', 'subscriber' ), $slug ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__( 'Roles that can administer the site are never offered here, and are refused if configured by other means.', 'agentkey-sign-in-agentid' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Owner linking', 'agentkey-sign-in-agentid' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="agentid_sso_options[link_by_owner_email]" value="1" <?php checked( agentid_sso_get_option( 'link_by_owner_email', 1 ), 1 ); ?>>
								<?php echo esc_html__( 'When an agent\'s verified owner email matches an existing user on this site, record that user as the agent account\'s owner. The agent still gets its own account and never signs in as the owner.', 'agentkey-sign-in-agentid' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="agentid_session_hours"><?php echo esc_html__( 'Agent session length (hours)', 'agentkey-sign-in-agentid' ); ?></label></th>
						<td>
							<input type="number" min="1" max="72" id="agentid_session_hours" name="agentid_sso_options[session_hours]" value="<?php echo esc_attr( (string) agentid_sso_get_option( 'session_hours', 12 ) ); ?>">
							<p class="description"><?php echo esc_html__( 'AgentID revocation stops new sign-ins but does not end sessions a site has already issued, so agent sessions are kept short. 1–72 hours.', 'agentkey-sign-in-agentid' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Login button', 'agentkey-sign-in-agentid' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="agentid_sso_options[show_login_button]" value="1" <?php checked( agentid_sso_get_option( 'show_login_button', 1 ), 1 ); ?>>
								<?php echo esc_html__( 'Show "Sign in with AgentID" on the WP login form (and the WooCommerce login form, if WooCommerce is active). You can also use the [agentid_login] shortcode or the AgentID Login Button block anywhere.', 'agentkey-sign-in-agentid' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php echo esc_html__( 'Connection test', 'agentkey-sign-in-agentid' ); ?></h2>
			<p>
				<button type="button" class="button" id="agentid-test-connection"><?php echo esc_html__( 'Test connection to AgentID', 'agentkey-sign-in-agentid' ); ?></button>
				<span id="agentid-test-result" role="status"></span>
			</p>
			<p class="description"><?php echo esc_html__( 'Fetches the live discovery document and signing keys from auth.agentid.com. A full sign-in test needs a real agent: after saving your credentials, sign out and use the "Sign in with AgentID" button.', 'agentkey-sign-in-agentid' ); ?></p>
		</div>
		<?php
	}
}
