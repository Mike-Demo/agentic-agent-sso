# AgentKey Sign-In for AgentID

**Sign in with AgentID for WP and WooCommerce** — an independent, community-built
integration that lets verified AI agents sign in to a WP site as themselves, with a
human owner on record, instead of sharing a person's password.

> **Credit & standing:** [AgentID](https://www.agentid.com) is a product of
> **AgentMail** — see their work at [github.com/agentmail-to](https://github.com/agentmail-to)
> ([@agentmail-to](https://github.com/agentmail-to)). This plugin is **not**
> affiliated with, endorsed by, or sponsored by AgentMail, and it does not replace
> any part of their product. It exists for one reason: to make AgentID easier to
> implement on WP and WooCommerce sites. Your site still registers its own free
> application in the AgentID console and talks to AgentID's service under their terms.

## Disclaimer

**AgentKey Sign-In for AgentID is an independent, open-source community project. It is not
made by, affiliated with, endorsed by, or sponsored by AgentMail, and it is not an
official AgentID product.** AgentID and AgentMail are trademarks of their
respective owners; both names appear in this project only to describe the service
the plugin connects to. Support for this plugin comes from its own community, not
from AgentMail. (The plugin was renamed in v0.1.2 so its name stands on its own
rather than building on the AgentID trademark.)

## How it works

1. The site owner creates a free application for their site at
   [console.agentid.com](https://console.agentid.com) (only the owner can do this —
   it asserts ownership of the site).
2. They paste the Client ID/secret into the plugin settings and register the
   redirect URI the settings page shows them.
3. Agents use the **Sign in with AgentID** button (WP login form, WooCommerce
   login form, `[agentid_login]` shortcode, or the Gutenberg block). The plugin
   runs the standard OIDC authorization-code flow with PKCE (S256), verifies the
   ES256 ID token against AgentID's JWKS, and signs the agent in to its **own**
   WP account.

## Safety model

Agent sign-in without guardrails is how agents end up owning websites. This
plugin ships the guardrails in the free version, on purpose:

- **Never admin.** Agents are never provisioned with a role that can administer
  the site; an agent account promoted to one is demoted at its next sign-in.
- **Capability ceiling.** When the agent's owner is recorded, the account's
  capabilities are capped at the owner's (no confused-deputy escalation);
  site-administration capabilities are hard-denied for every agent account,
  owner or not.
- **AgentID is the only way in.** Agent accounts cannot use password sign-in,
  password resets, or application passwords. Users are matched by the immutable
  AgentID subject (`sub`), never by email alone; email collisions fail closed.
- **Short sessions.** Agent sessions default to 12 hours (configurable 1–72),
  because revoking an agent at AgentID stops new sign-ins but does not end
  sessions a site has already issued.
- **Fail-closed token checks.** Issuer, audience, expiry, nonce, PKCE, state
  bound to the browser cookie, and `actor_type` must all check out; a token
  from any other identity provider is refused.

## Development

- Follows the [WP Codex](https://codex.wordpress.org/Main_Page) / Plugin
  Developer Handbook and the WP Coding Standards — run `phpcs` (config in
  `phpcs.xml.dist`) before committing.
- Layout: `agentkey-sign-in-agentid.php` (bootstrap), `includes/` (OIDC client,
  auth flow, settings, guards, button surfaces), `blocks/login-button/`,
  `assets/`, `tests/` (standalone CLI test for the ES256 JWT verifier).
- No build step, no Composer dependencies, no telemetry, no phone-home.

## License

GPLv2 or later — see [LICENSE](LICENSE).

## Trademarks

AgentID and AgentMail are trademarks of their respective owners, used here
descriptively to identify the service this plugin integrates with. "WP" and
WooCommerce references are likewise descriptive only.
