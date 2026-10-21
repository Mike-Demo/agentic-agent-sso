# Security audit triage — 2026-10-21

Parent: [Mike-Demo/agentkey-sign-in-agentid#9](https://github.com/Mike-Demo/agentkey-sign-in-agentid/issues/9)

## Disposition and prioritized backlog

- **Confirmed findings: 0.** There are no confirmed findings to rank by risk,
  group by root cause, or create remediation issues for.
- **Needs-validation leads: 3.** File the single checklist issue below. These
  are unconfirmed claims, not severity-rated findings; their order follows the
  parent issue and does not imply a risk ranking.
- **Rejected candidate:** `stale-owner-capability-ceiling-never-cleared` remains
  rejected. Do not reopen it without new evidence.

Issue creation is unavailable in this session. The title and body below are
ready to file in this repository instead. No security fixes are proposed or
implemented by this triage.

## Issue title

[security][needs-validation] Audit leads to validate

## Issue body

Parent audit: https://github.com/Mike-Demo/agentkey-sign-in-agentid/issues/9

Track all three unconfirmed leads from the 2026-10-21 static-source audit.
No code was executed and no deployed endpoint was probed during that audit.
No severity is assigned to these leads.

The missing-fact text below is reproduced exactly as supplied in the parent,
including the truncated endings (`…`). Obtain the complete wording from the
audit owner before considering validation complete; do not infer omitted text.
The root causes below are audit claims, not newly verified findings.

- [ ] **agentid_disabled is checked only at the OIDC callback; an already-issued WordPress session for a disabled agent is never re-checked or destroyed**
  - Fingerprint: `disabled-agent-existing-session-not-revoked`
  - Claimed root cause: agentid_disabled is enforced only at new sign-in in AgentID_Auth::callback, with no per-request re-check in AgentID_Guards and no session-token destruction when the flag is set.
  - Missing fact: In the target deployment, (1) what mechanism sets agentid_disabled (no setter or UI exists in this repo) and whether that mechanism destroys the user's WP session tokens, and (2) in the target WordPress version, whether the auth-cookie validation path (determine_current_user/validate_auth_cookie) or any deployed companion code re-checks agentid_di…

- [ ] **Hard-denied capability ceiling and sign-in demotion operate on roles/user_has_cap only; a multisite super-admin grant to an agent account is never checked**
  - Fingerprint: `multisite-super-admin-hard-deny-needs-validation`
  - Claimed root cause: Hard-denied capabilities are enforced solely through the user_has_cap allcaps rewrite and role demotion, with no explicit super-admin check for agent accounts.
  - Missing fact: In the target WordPress version, the cap-resolution order in WP_User::has_cap() for a multisite super admin: does the super-admin grant return true before/independently of the user_has_cap filter's allcaps=false entries for hard_denied_caps, or are those filtered entries honoured - and does any core path (e.g. map_meta_cap/site administration scre…

- [ ] **Unauthenticated AgentID flow start creates a server-side transient and sets a cookie on every request, with no rate limit or quota**
  - Fingerprint: `unauthenticated-flow-start-transient-flooding`
  - Claimed root cause: Unauthenticated flow start unconditionally writes a server-side transient per request with no rate limit or quota, allegedly allowing anonymous callers to bloat shared transient storage and consume workers.
  - Missing fact: Deployment facts not in the repo: the transient backend in the target install (wp_options DB rows vs a persistent object cache such as Redis/Memcached, where TTL'd small keys behave differently), any host/edge/WAF rate limiting on admin-post.php, and a measured comparison of sustained flow-start cost versus ordinary unauthenticated WordPress reque…

For each lead, record the target WordPress version, applicable deployment
configuration, validation evidence, and a confirmed/rejected/still-unresolved
disposition. Leave unresolved leads unchecked. If evidence confirms a finding,
create a separate remediation issue linked to this checklist and the parent,
with severity, reachability, evidence, root cause, and the smallest safe fix;
then prioritize confirmed findings by real-world risk and fix safety.
