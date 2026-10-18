<?php
/**
 * Safety guards for agent accounts.
 *
 * These protections ship in the free plugin on purpose: they are the
 * difference between "an agent signed in" and "an agent owns the
 * site", and safety defaults are not an upsell.
 *
 *  - Agent accounts cannot use password authentication (login form,
 *    XML-RPC), password reset, or application passwords. AgentID is
 *    the only way in.
 *  - An agent account's capabilities are ceilinged at its owner's
 *    capabilities (confused-deputy guard): an agent of a Subscriber
 *    can never exercise an Editor capability, whatever role the
 *    agent account itself was given.
 *  - If an agent account is ever promoted to a site-administering
 *    role, it is demoted back to the configured default role at its
 *    next sign-in.
 *  - Agent sessions expire after the configured number of hours,
 *    because AgentID key revocation stops new sign-ins but does not
 *    end sessions this site has already issued.
 *
 * @package AgentKeySignIn
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safety guards applied to agent accounts.
 */
class AgentID_Guards {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'authenticate', array( __CLASS__, 'block_password_auth' ), 40, 3 );
		add_filter( 'allow_password_reset', array( __CLASS__, 'block_password_reset' ), 10, 2 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'block_app_passwords' ), 10, 2 );
		add_filter( 'user_has_cap', array( __CLASS__, 'capability_ceiling' ), 10, 4 );
		add_filter( 'auth_cookie_expiration', array( __CLASS__, 'session_length' ), 10, 3 );
		add_action( 'wp_login', array( __CLASS__, 'demote_admin_agents' ), 10, 2 );
		add_action( 'show_user_profile', array( __CLASS__, 'profile_section' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_section' ) );
	}

	/**
	 * Resolve a WP_User from the mixed values filters hand us.
	 *
	 * @param mixed $user WP_User|int|null.
	 * @return WP_User|null
	 */
	private static function as_user( $user ) {
		if ( $user instanceof WP_User ) {
			return $user;
		}
		if ( is_int( $user ) && $user > 0 ) {
			$found = get_user_by( 'id', $user );
			return $found ? $found : null;
		}
		return null;
	}

	/**
	 * Block password authentication for agent accounts.
	 *
	 * Runs after core password verification (priority 20) and after
	 * the AgentID callback has already set its own cookie — this only
	 * refuses authentications that presented a password.
	 *
	 * @param WP_User|WP_Error|null $result   Auth result so far.
	 * @param string                $username Supplied username.
	 * @param string                $password Supplied password.
	 * @return WP_User|WP_Error|null
	 */
	public static function block_password_auth( $result, $username, $password ) {
		if ( '' === (string) $password || is_wp_error( $result ) || ! $result instanceof WP_User ) {
			return $result;
		}
		if ( AgentID_Auth::is_agent_user( $result->ID ) ) {
			return new WP_Error(
				'agentid_password_disabled',
				__( 'This is an agent account. It can only sign in with AgentID.', 'agentkey-sign-in-agentid' )
			);
		}
		return $result;
	}

	/**
	 * No password resets for agent accounts.
	 *
	 * @param bool $allow Whether reset is allowed.
	 * @param int  $user_id User ID.
	 * @return bool
	 */
	public static function block_password_reset( $allow, $user_id ) {
		if ( AgentID_Auth::is_agent_user( (int) $user_id ) ) {
			return false;
		}
		return $allow;
	}

	/**
	 * No application passwords for agent accounts.
	 *
	 * @param bool        $available Whether app passwords are available.
	 * @param WP_User|int $user      The user.
	 * @return bool
	 */
	public static function block_app_passwords( $available, $user ) {
		$resolved = self::as_user( $user );
		if ( $resolved && AgentID_Auth::is_agent_user( $resolved->ID ) ) {
			return false;
		}
		return $available;
	}

	/**
	 * Capabilities an agent account must never hold, under any role
	 * and regardless of its owner: the site-administration set.
	 *
	 * Denying only manage_options is not enough — an account that
	 * keeps install_plugins, promote_users or unfiltered_html can
	 * still take over a site between sign-ins. The same set decides
	 * which roles an agent may be provisioned with (see
	 * AgentID_Settings::provisionable_roles()) and which roles get
	 * demoted at sign-in (see demote_admin_agents()).
	 *
	 * @return string[]
	 */
	public static function hard_denied_caps() {
		return array(
			'manage_options',
			'edit_theme_options',
			'edit_users',
			'create_users',
			'delete_users',
			'promote_users',
			'promote_user',
			'remove_users',
			'install_plugins',
			'activate_plugins',
			'delete_plugins',
			'edit_plugins',
			'install_themes',
			'delete_themes',
			'edit_themes',
			'switch_themes',
			'update_core',
			'update_plugins',
			'update_themes',
			'update_languages',
			'edit_files',
			'edit_css',
			'unfiltered_html',
			'unfiltered_upload',
			'manage_network',
			'manage_network_users',
			'manage_network_plugins',
			'manage_network_themes',
			'manage_network_options',
			'upgrade_network',
			'setup_network',
			'delete_site',
		);
	}

	/**
	 * Ceiling an agent's capabilities at its owner's.
	 *
	 * @param array   $allcaps All capabilities granted to the user.
	 * @param array   $caps    Capabilities being checked.
	 * @param array   $args    Filter args.
	 * @param WP_User $user    The user.
	 * @return array
	 */
	public static function capability_ceiling( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof WP_User || ! AgentID_Auth::is_agent_user( $user->ID ) ) {
			return $allcaps;
		}
		// An agent account must never administer the site — no matter
		// what its role says, and no matter what its owner can do.
		// This deny is unconditional; the owner ceiling below only
		// ever removes further capabilities.
		foreach ( self::hard_denied_caps() as $denied_cap ) {
			$allcaps[ $denied_cap ] = false;
		}
		$owner_id = (int) get_user_meta( $user->ID, 'agentid_owner_user_id', true );
		if ( $owner_id <= 0 || $owner_id === (int) $user->ID ) {
			// No recorded owner: keep the account's role capabilities
			// (site administration already denied above).
			return $allcaps;
		}
		$owner = get_user_by( 'id', $owner_id );
		if ( ! $owner ) {
			// Owner is gone: fall back to read-only.
			return array( 'read' => true );
		}
		$owner_caps = is_array( $owner->allcaps ) ? $owner->allcaps : array();
		foreach ( $allcaps as $cap => $granted ) {
			if ( $granted && empty( $owner_caps[ $cap ] ) && 'read' !== $cap ) {
				$allcaps[ $cap ] = false;
			}
		}
		return $allcaps;
	}

	/**
	 * Short session lifetime for agent accounts.
	 *
	 * @param int  $length  Default cookie length in seconds.
	 * @param int  $user_id User ID.
	 * @param bool $remember Whether "remember me" was requested.
	 * @return int
	 */
	public static function session_length( $length, $user_id, $remember ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $remember is part of the auth_cookie_expiration filter signature; agent sessions intentionally ignore it.
		if ( AgentID_Auth::is_agent_user( (int) $user_id ) ) {
			$hours = (int) agentid_sso_get_option( 'session_hours', 12 );
			$hours = max( 1, min( 72, $hours ) );
			return $hours * HOUR_IN_SECONDS;
		}
		return $length;
	}

	/**
	 * Demote an agent account that somehow holds an administering role.
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user       The user who signed in.
	 */
	public static function demote_admin_agents( $user_login, $user ) {
		if ( ! $user instanceof WP_User || ! AgentID_Auth::is_agent_user( $user->ID ) ) {
			return;
		}
		// Any role the plugin would refuse to provision — because it
		// grants a hard-denied capability — is demoted on sight, not
		// just roles holding manage_options.
		$provisionable = AgentID_Settings::provisionable_roles();
		foreach ( (array) $user->roles as $role_slug ) {
			if ( ! isset( $provisionable[ $role_slug ] ) ) {
				$role = AgentID_Auth::safe_default_role();
				if ( null !== $role ) {
					$user->set_role( $role );
				}
				// No safe role exists: leave the role untouched —
				// the capability ceiling still hard-denies
				// site administration for this account.
				return;
			}
		}
	}

	/**
	 * Show AgentID identity details on the user profile.
	 *
	 * @param WP_User $profile_user The profile being viewed.
	 */
	public static function profile_section( $profile_user ) {
		$is_agent   = AgentID_Auth::is_agent_user( $profile_user->ID );
		$linked_sub = (string) get_user_meta( $profile_user->ID, 'agentid_linked_sub', true );
		if ( ! $is_agent && '' === $linked_sub && (int) get_current_user_id() !== (int) $profile_user->ID ) {
			return;
		}
		?>
		<h2><?php echo esc_html__( 'AgentID', 'agentkey-sign-in-agentid' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php if ( $is_agent ) : ?>
				<tr>
					<th><?php echo esc_html__( 'Agent identity', 'agentkey-sign-in-agentid' ); ?></th>
					<td>
						<code><?php echo esc_html( (string) get_user_meta( $profile_user->ID, 'agentid_sub', true ) ); ?></code>
						<p class="description">
							<?php
							$owner_name  = (string) get_user_meta( $profile_user->ID, 'agentid_owner_name', true );
							$owner_email = (string) get_user_meta( $profile_user->ID, 'agentid_owner_email', true );
							if ( '' !== $owner_name || '' !== $owner_email ) {
								printf(
									/* translators: 1: owner name, 2: owner email */
									esc_html__( 'Owner on record: %1$s (%2$s). This account signs in only with AgentID; passwords, password resets and application passwords are disabled for it.', 'agentkey-sign-in-agentid' ),
									esc_html( $owner_name ),
									esc_html( $owner_email )
								);
							} else {
								echo esc_html__( 'This account signs in only with AgentID; passwords, password resets and application passwords are disabled for it.', 'agentkey-sign-in-agentid' );
							}
							?>
						</p>
					</td>
				</tr>
			<?php endif; ?>
			<?php if ( (int) get_current_user_id() === (int) $profile_user->ID && agentid_sso_is_configured() ) : ?>
				<tr>
					<th><?php echo esc_html__( 'Link your agent', 'agentkey-sign-in-agentid' ); ?></th>
					<td>
						<?php if ( '' !== $linked_sub ) : ?>
							<code><?php echo esc_html( $linked_sub ); ?></code>
							<p class="description"><?php echo esc_html__( 'This agent identity is linked to your account as its owner.', 'agentkey-sign-in-agentid' ); ?></p>
						<?php else : ?>
							<a class="button" href="<?php echo esc_url( add_query_arg( 'agentid_link_nonce', wp_create_nonce( 'agentid_link_start' ), agentid_sso_start_url( '', 'link' ) ) ); ?>"><?php echo esc_html__( 'Link an AgentID agent', 'agentkey-sign-in-agentid' ); ?></a>
							<p class="description"><?php echo esc_html__( 'Signs you through AgentID once and records that agent as yours. The agent still gets its own account on this site — it never signs in as you.', 'agentkey-sign-in-agentid' ); ?></p>
						<?php endif; ?>
						<?php if ( isset( $_GET['agentid_linked'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Confirmation banner only. ?>
							<p><strong><?php echo esc_html__( 'Agent linked.', 'agentkey-sign-in-agentid' ); ?></strong></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}
}
