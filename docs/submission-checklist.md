# WordPress.org submission checklist — AgentKey Sign-In for AgentID

Status as of 2026-10-18: **SUBMITTED; review round 1 in progress.** The plugin was
submitted on 2026-10-14 as "Agentic Agent SSO" (initial slug `agentic-agent-sso`,
automated scan passed). The review team's automated pre-review (2026-10-18)
pended the submission with three items: rename for distinctiveness, a
nonce/permissions audit, and the readme Contributors username. At the owner's
choice the plugin is renamed **AgentKey Sign-In for AgentID** (new slug
`agentkey-sign-in-agentid`, requested in the reply on the review thread);
v0.1.5 carries the rename and the other fixes. The remaining steps below apply
once the review concludes.

## The package

| Item | State |
| --- | --- |
| Plugin zip | `agentkey-sign-in-agentid-0.1.5.zip` — folder `agentkey-sign-in-agentid/`, built per `.distignore` (no dev files, no `.wordpress-org/`) |
| Version / stable tag | 0.1.5 |
| License | GPLv2 (LICENSE file included) |
| Plugin Check (official, v2.1.0) | 0 errors / 0 warnings on the v0.1.2-era package; a post-submission run flagged one readme warning (0.1.3 upgrade notice 305 chars, limit 300) — fixed to 255 chars and the corrected zip re-uploaded to the pending submission pre-review on 2026-10-14, see TESTING.md |
| PHPCS (WPCS + PHPCompatibilityWP) | 0 errors / 0 warnings |
| Code reviews | Copilot full review (v0.1.1), Copilot Auto quality review (v0.1.3), Wonder-model review (v0.1.4) — see `docs/code-review/`; all findings fixed or dispositioned |
| Live verification | Full real-agent AgentID sign-in verified end-to-end on v0.1.1 and again on v0.1.4 (see TESTING.md) |

## Listing assets (in `.wordpress-org/` in this repo)

These map to the SVN `assets/` folder after approval — they are deliberately NOT in
the plugin zip (`.distignore` excludes the directory).

| File | Size | What it is |
| --- | --- | --- |
| `banner-772x250.png` | 772×250 | Listing banner |
| `banner-1544x500.png` | 1544×500 | Listing banner, retina |
| `icon-256x256.png` | 256×256 | Plugin icon |
| `icon-128x128.png` | 128×128 | Plugin icon, small |
| `screenshot-1.png` | 1440×1205 | Settings → AgentKey Sign-In for AgentID setup wizard |
| `screenshot-2.png` | 1440×1000 | "Sign in with AgentID" on wp-login |
| `screenshot-3.png` | 1440×1000 | Profile: "Link your agent" |
| `screenshot-4.png` | 1440×1000 | Agent account: identity + owner on record, password paths disabled |
| `screenshot-5.png` | 1440×1000 | AgentID Login Button block in the editor |

Screenshot captions live in readme.txt (`== Screenshots ==`) and match the numbering
above. Captions and screenshots must stay in sync — if a screenshot is added, removed
or reordered, update the captions in the same commit.

### How the assets were made (2026-10-14)

- Screenshots are real captures of the plugin running in the local Playground test
  rig (headless Chromium, logged in as the site admin). For the captures, the rig
  used a throwaway dummy Client ID and the agent account's identity values were
  temporarily replaced with sample data ("Alex Example", `alex@example.com`, a
  sample subject) so no real identifiers appear in public listing images. The rig
  was restored afterwards and the live sign-in was not affected.
- Icon and banners are vector artwork (persona + key mark on the brand gradient),
  rendered to PNG at the exact required sizes. Source: `docs/assets-source.html` —
  edit and re-render to change them; keep the exported pixel sizes exact.

## Remaining steps (owner)

1. Sign in at https://wordpress.org/plugins/developers/add/ with the owner's
   WordPress.org account and upload the current zip (`agentkey-sign-in-agentid-0.1.5.zip` for the review-round-1 response).
2. Wait for the review email (the plugin review team replies to the account email).
   If they request changes, fix on `main`, bump the version, rebuild the zip, and
   reply on the review thread.
3. After approval, the SVN repo is created under
   `https://plugins.svn.wordpress.org/agentkey-sign-in-agentid/`:
   - commit the plugin files (the zip's contents) to `trunk/`,
   - copy `trunk/` to `tags/0.1.5/`,
   - commit the `.wordpress-org/` files to `assets/`.
4. Verify the live listing page renders the banner, icon, screenshots and readme
   (the readme validator at wordpress.org/plugins/developers/readme-validator/
   can pre-check formatting).

## Standing notes

- The plugin name contains no "WordPress" (WP is used where needed), and the
  listing copy states the project is independent and not affiliated with AgentMail,
  with credit to the AgentID project — keep both intact in future edits.
- Do not add screenshots that show real client IDs, secrets, email addresses or
  subject identifiers; stage sample data like the 2026-10-14 captures did.
- RECAPTURE BEFORE THE SVN COMMIT: the 2026-10-14 screenshots predate the
  rename — screenshot-1 shows the settings page under the old name ("Agentic
  Agent SSO"). Recapture at least screenshot-1 (check 3–5 as well) against the
  renamed plugin before committing `assets/`.
