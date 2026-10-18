<?php
/**
 * Uninstall cleanup for AgentKey Sign-In for AgentID.
 *
 * Removes the plugin's options and cached transients. User accounts
 * created for agents are intentionally left in place — they belong to
 * the site — but their AgentID identity meta is removed so a later
 * reinstall starts from a clean slate. On reinstall, accounts whose
 * email is the subject-derived placeholder reconnect automatically
 * (see AgentID_Auth::reconnect_retained_account()); accounts with a
 * verified email need an administrator to reconnect them (readme FAQ).
 *
 * @package AgentKeySignIn
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$agentid_cleanup_site = static function () {
	delete_option( 'agentid_sso_options' );
	delete_transient( 'agentid_sso_discovery' );
	delete_transient( 'agentid_sso_jwks' );
};

if ( is_multisite() ) {
	// Options (including the client secret) and the cached issuer
	// data are per-site on multisite: visit every blog so nothing is
	// left behind in another site's options table.
	$agentid_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $agentid_site_ids as $agentid_site_id ) {
		switch_to_blog( $agentid_site_id );
		$agentid_cleanup_site();
		restore_current_blog();
	}
} else {
	$agentid_cleanup_site();
}

$agentid_meta_keys = array(
	'agentid_is_agent',
	'agentid_sub',
	'agentid_actor_type',
	'agentid_owner_sub',
	'agentid_owner_email',
	'agentid_owner_name',
	'agentid_owner_user_id',
	'agentid_linked_sub',
	'agentid_last_login',
	'agentid_disabled',
);
foreach ( $agentid_meta_keys as $agentid_meta_key ) {
	delete_metadata( 'user', 0, $agentid_meta_key, '', true );
}
