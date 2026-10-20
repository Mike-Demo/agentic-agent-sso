# Testing & submission checklist — AgentKey Sign-In for AgentID

Verified during the v0.1.0 build (2026-10-14):

- [x] AgentID OIDC discovery fetched live: issuer `https://auth.agentid.com`,
      ES256 only, PKCE S256, scopes `openid email profile owner_profile owner_email`,
      token auth `client_secret_basic` / `client_secret_post` / `none`.
- [x] PHP lint clean on all files (PHP 8.3).
- [x] JWT verifier standalone test (`php tests/test-jwt-verify.php`): 9/9 pass —
      valid token accepted; tampered signature, wrong audience, expired token,
      wrong nonce, unknown `kid`, non-agent `actor_type`, and missing
      `actor_type` all rejected (fail closed).
- [x] WP Coding Standards per the WP Codex / Plugin Developer Handbook:
      `phpcs` (WordPress standard + PHPCompatibilityWP) reports 0 errors,
      0 warnings. Run it with the config in `phpcs.xml.dist`.
- [x] Product-name audit: "WordPress" appears in no product name, slug,
      repo name, or title — "WP" is used instead.

WP Playground run (2026-10-14, Playground CLI 3.1.58, WP latest, PHP 8.3;
blueprint in `tests/playground-blueprint.json`):

- [x] Site boots with the plugin mounted and activated — no fatals.
- [x] Settings page renders; options saved by the blueprint appear in the
      form; the page shows the exact redirect URI to register.
- [x] "Sign in with AgentID" button renders on the WP login form.
- [x] **Test Connection succeeds against the live issuer** — discovery +
      JWKS fetched, 1 signing key reported. Note: in Cane's VM all
      outbound HTTPS from Playground PHP initially failed (TLS error)
      because the VM only permits egress via its HTTP proxy; defining
      WP's standard `WP_PROXY_HOST` / `WP_PROXY_PORT` / `WP_PROXY_USERNAME`
      / `WP_PROXY_PASSWORD` constants for the Playground process fixed it.
      On a normal machine no workaround is needed. Host curl to the same
      URLs worked throughout, confirming an environment quirk, not a
      plugin or AgentID problem.
- [x] **Flow start verified**: starting a sign-in redirects to
      `https://auth.agentid.com/v0/authorize` with the correct
      response_type, client_id, redirect_uri, scopes (incl. owner
      scopes), state, nonce, and PKCE S256 challenge. AgentID answered
      403 for the dummy client ID, as expected.
- [x] **Full sign-in with a real app — VERIFIED LIVE 2026-10-14.**
      A dedicated test app ("Agent SSO WP Plugin Playground Test") was
      created in the owner's AgentID organization and its real client
      ID/secret configured in the Playground site. A real agent
      (mikedemo.jobsearch@agentmail.to) completed the full flow —
      authorize, AgentMail inbox authorization via API, consent,
      callback, token exchange, ES256 ID-token verification — and was
      provisioned just-in-time in WordPress: role **Subscriber**,
      display name "Job Search (agent of Mike Demopoulos)", user meta
      `agentid_sub`, `agentid_owner_sub`, `agentid_owner_email`
      (mdemop@mikedemo.com), `agentid_owner_name`, `agentid_is_agent`,
      `agentid_actor_type=agent`, `agentid_last_login`. The resulting
      session opened the profile page as the agent.
      **Password guard proven both ways**: a wrong password gets core's
      generic error (the guard runs after core verification by design);
      with a temporarily-known correct password the sign-in was refused
      with the plugin's own message — "This is an agent account. It can
      only sign in with AgentID." (The test account's password hash was
      then reset to a fresh random value; no usable password exists.)
      Findings from this leg:
      * The AgentID **console accepts loopback redirect URIs**
        (`http://127.0.0.1:9400/...` and `http://localhost:9400/...`
        both registered fine), but AgentID's **edge (CloudFront/WAF)
        blocks authorize requests whose redirect_uri host is a
        loopback or private IP literal** (403 before the app ever
        runs). Hostnames pass the edge; `localhost` passes too. Net:
        agents cannot sign in to a site reached by IP literal —
        a public hostname is required in practice.
      * AgentID binds the authorization transaction to the browser
        that started it (transaction cookie): replaying the
        `/v0/authorize/continue` step from a different client is
        refused ("Authorization transaction is forbidden"). The test
        harness worked around the browser/loopback split by pointing
        the site URL at a public echo page, capturing the callback
        there, and replaying it locally with the flow cookie.
      * Authorization codes are short-lived: a code replayed ~3.5
        minutes after issue failed the token exchange
        (`invalid_grant`); replay within about a minute succeeded.
      * One callback attempt failed at ID-token verification because
        the JWKS fetch from inside Playground was mangled by the test
        VM's egress proxy (the same environment flakiness noted
        elsewhere in this file). With the JWKS cache seeded, the
        identical flow verified cleanly. The plugin failed closed in
        the meantime, as designed.
      Still to exercise live: WooCommerce login form, the owner link
      flow end-to-end, admin demotion, and session length.
- [ ] **AgentMail permission**: written OK from AgentMail to use the
      AgentID name in the plugin name/slug, or fall back to a purely
      descriptive name before submitting. (The only remaining
      submission gate besides the .org listing assets.)
- [x] **Exact legal URLs**: AgentID's Terms
      (agentid.com/legal/terms) and Privacy Policy
      (agentid.com/legal/privacy) verified live and linked in
      readme.txt.
- [x] **Outside security review**: GitHub Copilot full code review
      commissioned and completed 2026-10-14 (issue #1 / PR #2):
      0 Critical / 1 High / 5 Medium / 7 Low — all findings fixed in
      v0.1.1 (see the v0.1.1 section below).
- [x] **Plugin Check plugin** run on the test install: v2.1.0,
      shipped file set 0 errors / 0 warnings (see the compliance
      section below). A screenshot set for the .org listing is still
      to be captured at submission time.

WordPress.org submission compliance (checked 2026-10-14 against
https://wordpress.org/plugins/developers/add/ and the guidelines it
binds — no submission made yet):

- [x] The page's three named rejection causes audited in code and
      enforced by tooling: output escaping, input sanitization,
      nonces + capability checks on all form/AJAX handling (a forged
      nonce was verified rejected in the Playground run above; PHPCS
      with the WordPress standard is clean).
- [x] readme.txt required headers all present; short description
      trimmed to 141 characters after Plugin Check flagged truncation
      at 150; tags trimmed to 5.
- [x] External-service disclosure (Guideline 6) names every endpoint,
      the data sent/received, and links AgentID's actual Terms
      (agentid.com/legal/terms) and Privacy Policy
      (agentid.com/legal/privacy) — both URLs verified live.
- [x] LICENSE now carries the full GPLv2 text (it previously held a
      short notice only). No trialware or locked features anywhere.
- [x] **Official Plugin Check plugin (v2.1.0) run in Playground**:
      29 of 34 checks executed (the 5 runtime enqueue checks cannot
      run in the single-worker Playground server — NOT VERIFIED here;
      enqueue behaviour itself was verified manually on the login
      page). Against the shipped file set: **0 errors, 0 warnings**.
      Raw run against the dev tree flagged only files that are
      excluded from the release zip (tests/, phpcs.xml.dist,
      TESTING.md) plus three real items, all fixed: missing
      languages/ folder for the Domain Path header (added), readme
      short-description length (trimmed), and a justified
      `phpcs:ignore` for deliberately firing core's `wp_login`
      action after an AgentID sign-in. Fixes re-verified by
      re-running the affected checks.
- [ ] Still outstanding for submission: AgentMail's written OK to the
      AgentID name/slug (the Copilot full code review is complete and
      its findings shipped in v0.1.1; the live end-to-end sign-in is
      verified — see the Playground section above).
- [x] **Aikido monitoring (2026-10-14)**: the repo is connected and
      active in the owner's Aikido workspace (Free plan; an existing
      repo was deactivated to free the slot). Manual "Start Scan" is
      a paid-plan feature; on the Free plan scans run automatically
      and on repo activity, so pushes double as scan triggers.

v0.1.1 (2026-10-14) — fixes from the Copilot full review (GitHub
issue #1 / PR #2), each verified after the change:

- [x] Capability ceiling now hard-denies `manage_options` for agent
      accounts before the owner intersection (the review's one High).
- [x] Profile link flow requires the `agentid_link_start` nonce —
      verified live in Playground: the profile button carries it,
      the flow starts with it, and a nonce-less request fails closed
      to the login page with the `link_nonce` error.
- [x] OIDC verifier: azp enforced (required for multi-audience
      tokens, must match whenever present), iat required and numeric,
      nbf validated. JWT test suite extended 9 → 16 cases, all pass.
- [x] Agent email used as the account email only when the issuer
      marks it verified; otherwise a non-deliverable placeholder
      derived from the agent's `sub` is used. Claim values are
      type-checked before sanitizers everywhere they are consumed.
- [x] Uninstall iterates every site on multisite, removing options
      and issuer-cache transients per blog.
- [x] Minimum WordPress raised to 6.3 (block API v3); admin JS
      strings localized; copy button reports clipboard failure
      honestly (localized strings verified present on the settings
      page).
- [x] readme external-services disclosure now covers the
      admin-triggered connection test.
- [x] Re-verified in Playground on a fresh site: plugin activates,
      settings renders as 0.1.1, Test Connection succeeds live,
      Plugin Check re-run on the affected checks is clean for the
      shipped file set. Note: forced (uncached) fetches from
      Playground in Cane's VM fail intermittently in bursts — the
      egress proxy mangles some responses; the cached path and fresh
      instances work, and an instrumented run showed clean HTTP 200
      responses with correct bodies. Environment flakiness, not a
      plugin defect.
- [x] `tests/playground-blueprint.json` is now actually tracked in
      the repo (the review correctly noted it was missing).

## 2026-10-14 — v0.1.2: renamed to Agentic Agent SSO + second-scan fixes

- RENAMED at the owner's direction (Copilot scan #2 flagged the old name under Guideline 17: generic term + third-party trademark): plugin name "Agentic Agent SSO", slug/text domain `agentic-agent-sso`, repo github.com/Mike-Demo/agentic-agent-sso (old URL redirects). Internal `agentid_` option/meta/code prefixes unchanged (they name the IdP, and existing data carries over). WordPress.org slug `agentic-agent-sso` verified unclaimed before the rename.
- Block fix: added `blocks/login-button/index.asset.php` (deps wp-blocks, wp-element, wp-block-editor, wp-components, wp-server-side-render, wp-i18n; version 0.1.2) — the editor script previously registered with no dependencies and the block never appeared in the inserter.
- Capability hard-deny expanded from `manage_options` alone to the full site-administration set (AgentID_Guards::hard_denied_caps()); provisioning (provisionable_roles) and sign-in demotion now use the same set.
- resolve_owner_id(): explicit profile links (agentid_linked_sub) are honoured before, and independently of, verified owner-email matching — linked agents always receive their owner's ceiling.
- Login flow errors now render via the wp_login_errors filter (was login_form_agentid_error printing before login_header()).
- readme.txt: token-exchange data disclosure completed (code, PKCE verifier, redirect URI, client secret via HTTP Basic, userinfo access token); privacy section covers agentid_linked_sub, the flow cookie and flow transients; Upgrade Notice added (0.1.2 + 0.1.1); explicit unaffiliation Disclaimer added (also as a `## Disclaimer` section in README.md).
- Added `.distignore` so dev files (tests/, docs/, TESTING.md, phpcs.xml.dist, README.md) stay out of distribution archives; phpcs.xml.dist minimum WP corrected 6.0 → 6.3.
- v0.1.2 verification (Playground, WP 7.1.3, fresh site from tests/playground-blueprint.json): plugin activates under the new slug; settings page renders at Settings → Agentic Agent SSO; login error for code `state` renders inside the form's error div after the doctype (wp_login_errors fix confirmed live); block `agentic-agent-sso/login-button` IS registered and its editor script carries deps wp-blocks, wp-element, wp-block-editor, wp-components, wp-server-side-render, wp-i18n at version 0.1.2; provisionable roles on a stock site are exactly author/contributor/subscriber (editor excluded — it holds unfiltered_html); an agent account holding the administrator role is denied manage_options, install_plugins, activate_plugins, promote_users, delete_users, unfiltered_html, edit_files, update_core, switch_themes, edit_theme_options and delete_site by the ceiling, keeps `read`, and is demoted to subscriber on wp_login. Native PHP 8.3: `php -l` 10/10 clean; JWT standalone suite ALL TESTS PASSED; PHPCS (WPCS + PHPCompatibilityWP) 0 errors / 0 warnings — run in-Playground with Generic.PHP.Syntax excluded (PHP-WASM cannot shell out to a php binary; that sniff's role is covered by the native `php -l`).

## 2026-10-14 — v0.1.3: Auto quality-review fixes

Fixes for the Copilot AUTO quality review (issue #5, draft PR #6, report docs/code-review/copilot-quality-review-auto-2026-10-14.md on Copilot's branch; 0 Critical / 0 High / 7 Medium / 6 Low). Dispositions: docs/code-review/quality-review-dispositions.md.

- C1: flow cookie scoped to '/' via AgentID_Auth::flow_cookie_path() (COOKIEPATH follows the home URL; the callback lives under admin_url() and split-path installs lost the cookie). Verified live: callback response clears the cookie with path=/.
- C2: generated logins bounded to 60 chars including the collision suffix. Verified by probe: 70-char preferred_username provisions at exactly 60 chars; collision variant also 60.
- C3: token-exchange Basic credentials rawurlencode()d per credential (RFC 6749 §2.3.1). Code-verified; live AgentID credentials are alphanumeric so the edge is not reachable in the live rig.
- C4: AgentID_Settings::fallback_role() (least-privileged provisionable stock role or null) now backs settings sanitization, safe_default_role() and demotion; provisioning fails with a new `no_role` error when no safe role exists. Verified by probe: Subscriber poisoned with install_plugins → excluded, fallback contributor end-to-end (sanitize, safe_default_role, provisioned role).
- C5: WooCommerce login button returns to checkout (when embedded there) or My Account. Code-verified (WooCommerce is not installed in the Playground rig).
- C6: placeholder-email accounts retained across uninstall reconnect automatically (AgentID_Auth::reconnect_retained_account()); verified-email collisions still refuse. Verified by probe both ways (same user ID + sub restored; verified case stays email_taken). FAQ documents the admin path for verified-email accounts.
- S1: sanitize_options no longer double-unslashes (Settings API input is already unslashed) and stores client_id/client_secret byte-for-byte (string check + explicit-charlist trim). Verified by probe: secret containing backslash, %41, plus, space and colon round-trips IDENTICAL.
- Lows: Q1 jwks() reuses a known discovery document (connection test fetches discovery once); Q2 unreachable `no_email` error removed; V1 README disclaimer no longer spells out the former name; T2 owner-ceiling wording qualified in README + readme; T3 privacy section documents placeholder account emails. T1 (integration test coverage) acknowledged in dispositions — the probes above were run ad hoc in Playground, not yet committed as automated tests.
- Verification: native php -l all files clean; JWT suite ALL TESTS PASSED; PHPCS 0 errors / 0 warnings (in-Playground run, Generic.PHP.Syntax excluded as before — its role covered by php -l; the run caught and we fixed a rawurlencode warning and two PHPCompatibility trim-charlist errors in the new code before release).

## 2026-10-14 — v0.1.4: Wonder review fixes + live sign-in re-verified

Fixes for the fresh Wonder-model review (docs/code-review/wonder-review-v0.1.3-2026-10-14.md; 0 Critical / 0 High / 1 Medium / 5 Low; dispositions in the same file):

- M1: userinfo claims are merged into the verified ID-token claims through a whitelist — `email`, `name`, `preferred_username`, `owner_sub`, `owner_email`, `owner_name` as strings only, `email_verified` / `owner_email_verified` as bool/string only. Userinfo can no longer overwrite verified token claims or inject non-scalar values.
- L2: discovery() guards `id_token_signing_alg_values_supported` with is_array() before in_array(); a malformed document returns a WP_Error instead of raising a TypeError under PHP 8.
- L1, L3, L4, L5 recorded as backlog in the review's disposition section.

Verification:

- Native `php -l` all files clean; JWT suite ALL TESTS PASSED.
- PHPCS (WPCS + PHPCompatibilityWP, in-Playground, Generic.PHP.Syntax excluded as before): 0 errors / 0 warnings across the 8 shipped PHP files. The harness used the PHPCS API (Runner builds its Config from argv; report to file) — the bin/phpcs include route produces no output under this stack. A control copy with deliberate violations (eval, duplicate class, trailing whitespace) placed in includes/ was caught (3 errors / 1 warning), proving the clean result is real; control removed afterwards.
- **Full live sign-in re-verified against v0.1.4 — PASSED.** Rig: original Playground site (real test app "Agent SSO WP Plugin Playground Test", site URL temporarily pointed at the registered https://agentid-catch.space.fast), previous agent account deleted first so the run exercised fresh JIT provisioning. The agent completed authorize → callback → token exchange → ES256 verification → provisioning: new user `mikedemo-jobsearch_agentmail-to`, Subscriber, display "Job Search (agent of Mike Demopoulos)", meta agentid_sub / agentid_owner_sub / agentid_owner_email (mdemop@mikedemo.com) / agentid_owner_name / agentid_actor_type=agent / agentid_last_login all set, and the issued session loaded /wp-admin/profile.php as the agent (HTTP 200). The userinfo whitelist merge (M1) is exercised by this run: the display name and owner fields arrived via userinfo and landed correctly. A second replay of the same callback was refused with the `state` error — flow transients are single-use, as designed.
- Harness note (AgentID-side, not a plugin defect): under AgentID's current wait-page build, a scripted (curl) client can start a flow and authorize via the inbox API (202), but /v0/authorize/continue refuses it ("Authorization transaction is forbidden"; the transaction sits in `needs_enrollment` for non-browser clients). The completed run drove the wait page in a real browser, which auto-advanced to the callback once its own checks passed; the callback URL was captured at the catcher page and replayed locally with the flow cookie inside the code's lifetime. Also observed: Playground's PHP server 302-redirects a first request to itself to set cookies — scripted replays must pre-warm a cookie jar, as before.
- Not re-run for v0.1.4: the returning-user sign-in path (live-verified on v0.1.1, probe-verified on v0.1.3; refresh_agent_meta consumes the same merged claims this run exercised) and WooCommerce (not installed in the rig).

## 2026-10-14 — post-submission: Plugin Check warning on the 0.1.3 upgrade notice (fixed)

Demo ran Plugin Check against the submitted package (2026-10-15 00:03 UTC) and it flagged exactly one finding: **WARNING `upgrade_notice_limit` — the 0.1.3 upgrade notice was 305 characters (limit 300)**. For the record: the earlier 0 errors / 0 warnings Plugin Check run predated the 0.1.3 readme — that notice was added in v0.1.3, and the checker was not re-run on the final package for v0.1.3/v0.1.4 (PHPCS was). That gap is on us, and this is the correction.

- Fix: the 0.1.3 notice is trimmed to 255 characters (meaning unchanged; the changelog entry carries the detail). All four upgrade notices now measure 162–280 characters, verified by count.
- Demo's report showed no other findings, and the warning is a readme display limit, not a code or security issue; the submission's automated scan had already passed.
- The rebuilt `agentic-agent-sso-0.1.4.zip` carries the fixed readme, so the fix lands with the post-approval SVN trunk commit (or any reviewer-requested re-upload).
- A full in-Playground re-run of the checker UI was attempted twice after the fix and did not complete in the single-worker WASM environment; the fix is verified against the check's own rule (the character limit) on top of Demo's otherwise-clean report.
- Rig note: during this work the VM restarted and the pre-screenshot backup of the original test rig was lost; that rig's admin password (a throwaway local test account) was replaced with a fresh random hash rather than restored. The test data and the agent account are unaffected.
- Update (same evening): per the submission confirmation email's "upload a new version before we begin with the review" option, the corrected 0.1.4 zip was re-uploaded to the pending submission on 2026-10-14 — WordPress.org confirmed: "New version of Agentic Agent SSO uploaded for review." The submitted-files table now lists the new zip (v0.1.4) above the original; the submission remains Awaiting Review with slug `agentic-agent-sso` unchanged, so the reviewers will see the fixed readme from the start.

## 2026-10-18 — review round 1: nonce / capability audit (issue 2) + Contributors fix (issue 3)

WordPress.org's automated pre-review (AUTOPREREVIEW, 2026-10-18) pended the submission with three items: (1) rename for distinctiveness — being handled separately with Demo, (2) "Nonces and User Permissions Before Processing Requests", (3) readme Contributors lists `mikedemo` but the account owner is `mpmike`.

Full audit of every request handler in the shipped files (grep for $_GET/$_POST/$_REQUEST/$_COOKIE/$_SERVER/$_FILES across all plugin PHP; no $_SERVER or $_FILES reads exist):

| Handler | Input read | Integrity / permission control | Verdict |
|---|---|---|---|
| Settings save (options.php, via `register_setting`) | `agentid_sso_options` | Settings API core nonce + `manage_options` (menu capability and options.php), plus `sanitize_options` | Compliant, no change |
| `wp_ajax_agentid_test_connection` | none | `check_ajax_referer( 'agentid_test_connection' )` + `current_user_can( 'manage_options' )` before any work; logged-in only (no nopriv) | Compliant, no change |
| `AgentID_Auth::start()` with `intent=link` | `intent`, `agentid_link_nonce`, `redirect_to` | `is_user_logged_in()` + `wp_verify_nonce( ..., 'agentid_link_start' )`; `redirect_to` passed through `wp_validate_redirect()` | Compliant, no change |
| `AgentID_Auth::start()` (sign-in) | `redirect_to` | Starts an OAuth flow; changes no site state beyond a 10-minute transient + flow cookie keyed by a 192-bit random `state`. A WP nonce cannot protect signed-out flows (anonymous nonces are session-less), so the OAuth `state` is the CSRF control, enforced at the callback | Justified; now documented in the class docblock |
| `AgentID_Auth::callback()` | `error`, `state`, `code`, flow cookie | Request originates as a redirect from the IdP (cannot carry a WP nonce). Verified: `state` matches the single-use server-side transient (deleted before token exchange), `hash_equals()` against the HttpOnly flow cookie, PKCE S256 verifier, and the OIDC `nonce` claim inside the ES256-verified ID token | Justified; now documented in the class docblock |
| `AgentID_Auth::finish_linking()` | verified claims only | Re-checks `get_current_user_id()` equals the `link_user_id` captured under the nonce at flow start; refuses subs already linked to another account | Compliant, no change |
| `AgentID_Auth::login_errors()` | `agentid_code` | Maps the code to a fixed in-code message list; raw input never output; no state change | Fine, no change |
| `AgentID_Button::on_wp_login()` | `$_REQUEST['redirect_to']` | Read-only passthrough into the sign-in link; `wp_validate_redirect()` + escaped on output | Fine, no change |
| `AgentID_Guards::profile_section()` banner | `isset( $_GET['agentid_linked'] )` | Prints a fixed string only; no input output, no state change | Fine, no change |

Changes made in this pass:
- `includes/class-agentid-auth.php`: file docblock now carries the full request-integrity model above, so the human re-reviewer can see the nonce/capability map without reverse-engineering the flow.
- `readme.txt`: Contributors `mikedemo` → `mpmike` (issue 3). The pre-review note calls this one non-mandatory; fixed anyway.

Not changed, deliberately: no nonce was bolted onto the OAuth start/callback endpoints. For signed-out visitors a WordPress nonce is shared and session-less (it authenticates nothing), and the callback cannot receive one from the identity provider; the enforced `state` + PKCE + cookie binding is the protocol's equivalent control and is strictly stronger.

## 2026-10-18 — review round 1: rename to AgentKey Sign-In for AgentID (v0.1.5)

At Demo's pick from the shortlist, the plugin is renamed **AgentKey Sign-In for AgentID**, slug `agentkey-sign-in-agentid` (slug verified unclaimed on the directory API before the pick; nothing in the directory uses "AgentID"). Following the v0.1.2 rename playbook: display name updated in the plugin header and readme, text domain → `agentkey-sign-in-agentid`, block name → `agentkey-sign-in-agentid/login-button`, settings PAGE_SLUG → `agentkey-sign-in-agentid`, @package → AgentKeySignIn, main file renamed to `agentkey-sign-in-agentid.php`, local dir + GitHub repo renamed to match. Internal `agentid_*` option/meta/code prefixes deliberately KEPT (they name the service; settings and account data carry over). Version bumped 0.1.4 → 0.1.5 (header, AGENTID_SSO_VERSION, block.json, index.asset.php, readme Stable tag) with a 0.1.5 changelog entry and a 224-character upgrade notice. The readme's 0.1.2 changelog/upgrade entries still name "Agentic Agent SSO" — that is the historical record of that release and is intentionally preserved.
- Round-1 response completed 2026-10-18: `agentkey-sign-in-agentid-0.1.5.zip` (23 entries, readme byte-checked, all upgrade notices 162–280 chars) uploaded to the pending submission — WordPress.org confirmed "New version of AgentKey Sign-In for AgentID uploaded for review." with no warnings shown; the submission header now uses the new display name (assigned slug still `agentic-agent-sso` until the team processes the reservation). Reply sent on the review thread the same morning: requests the `agentkey-sign-in-agentid` slug reservation and gives the one-paragraph nonce clarification (OAuth state + PKCE on the flow endpoints; WP nonce on linking; Settings API / check_ajax_referer elsewhere). One process note: the first rename push briefly deleted `docs/code-review/copilot-full-review.md` because the file was missing from the local tree; it was restored byte-identical in the next commit (64842b1) and the local copy recovered.

## 2026-10-20 — review round 2: JIT provisioning justification (v0.1.6)

The human review of v0.1.5 (Review ID R agentkey-sign-in-agentid/mpmike/18Oct26/T3) changed the slug to `agentkey-sign-in-agentid` and raised one issue: creating/logging in users — explain the technical necessity and show extreme care. Before replying, Demo had Copilot review the planned response (issue #7, report at docs/code-review/copilot-round2-response-review-2026-10-20.md): verdict "send with fixes". Verified sound: token checks, actor_type fail-closed, sub matching, role fallback, wp_login firing. Copilot's catches that changed the response: (1) "whole lifetime" guard claims — the guards are hooks and apply while the plugin is active; (2) "no shared identity provider" — the issuer is shared, per-site is the application/credentials; (3) the hard-deny list is finite and omitted WooCommerce management caps; (4) placeholder reconnect retained roles. One calibration from our own probe: the STOCK WooCommerce shop_manager role also holds edit_theme_options/export/import and was already excluded; the genuine hole was custom commerce roles carrying only Woo caps — closed by this fix, with the stock-role path now denied on multiple independent caps.

Changes in v0.1.6: hard_denied_caps() 32 → 81 (adds export, import, manage_woocommerce, view_woocommerce_reports, and the 15-cap management families for product / shop_order / shop_coupon from WC_Install::get_core_capabilities() in WooCommerce 9.3.3; read_* and assign_*_terms deliberately excluded, Customer role unaffected); reconnect_retained_account() now rejoins at safe_default_role() and refuses when none exists (previously retained any provisionable role); provisioning docblock documents the model; readme gains a "Why does the plugin create a user account" FAQ (incl. the authenticate-filter boundary disclosure), qualified safety bullets, WooCommerce + reinstall FAQ updates, changelog + 243-char upgrade notice.

Verification (2026-10-20, before the reply was sent): php -l ALL CLEAN (PHP 8.3.6); JWT suite ALL PASSED; PHPCS in-Playground 4/4 changed files, exit 0 (0 errors / 0 warnings, Generic.PHP.Syntax excluded per the standing recipe). Behavior probes on a fresh Playground site (mu-plugin probes 16/17): deny count 81; provisionable_roles excludes a faithful WooCommerce 9.3.3 shop_manager clone, a custom commerce-only role, administrator and editor; includes customer-like (read-only), a read_product/assign-terms helper, subscriber/contributor/author; safe_default_role() rejects a configured commerce role → subscriber; capability ceiling denies manage_woocommerce + edit_products to an agent holding the commerce role while keeping read, and a non-agent control user keeps them; demotion resets the commerce role to subscriber at sign-in; reconnect rejoins a retained author-role shell at subscriber with sub recorded, and is refused when the stock fallback roles are poisoned. Two ceiling assertions failed in the first probe run because the plugin had been activated mid-request (its init hooks had not run in that request); the retest with the plugin active from request start passed 5/5 including the filter-registration check. NOT re-run: official Plugin Check UI (stalls in this single-worker WASM environment, as on 2026-10-14; Demo's own Plugin Check run on the uploaded zip is the backstop) and a live AgentID sign-in (the token/callback path is untouched by 0.1.6 except the reconnect role assignment, which is probe-verified).
- Round-2 response completed 2026-10-20: `agentkey-sign-in-agentid-0.1.6.zip` (23 entries, byte-checked) uploaded — WordPress.org confirmed "New version of AgentKey Sign-In for AgentID uploaded for review." with no warnings; the justification reply was sent on the review thread the same day (necessity argument; corrected issuer/credentials framing; role policy incl. the new WooCommerce denials; active-plugin qualifiers; reconnect change; authenticate-boundary disclosure). Now awaiting the reviewer's next round. Copilot's draft PR #8 stays parked (report-only deliverable, per pattern).
