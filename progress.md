Original prompt: Improve the Travian 4.6 fork using project-improvements/README.md into a final, functional, acceptable product; keep the public repository free of personal or sensitive information; do not run the security scan yet; finish and update the game; publish atomic commits grouped by responsibility.

## Completed

- Selected `main_script` as the canonical game tree and removed legacy generated/privileged services.
- Added a PHP 8.3, MariaDB 11.4, Redis 7.4, Apache, bootstrap, and worker Docker stack.
- Verified a clean database import/install and healthy long-running containers.
- Modernized password/session/token handling and restored AJAX CSRF validation.
- Disabled payments and password-derived administrator login links.
- Fixed PHP 8 syntax failures, database ping timing, WW second-plan diplomacy, and fake online statistics.
- Added a local launcher, registration, privacy/terms pages, and health endpoint.
- Fixed `/game/` front-controller routing and verified registration → activation → village → login.
- Fixed PHP 8 authenticated-page failures caused by AJAX token width and empty build queues.
- Verified village fields, village center, map, building, and profile pages against a real local account.
- Removed third-party Statcounter tracking, legacy public links, obsolete footer identity, and the login JavaScript crash.
- Added a local OpenVillage UI identity override and verified it visually with Playwright.
- Added the MariaDB client required by automated worker backups.
- Added automated gameplay smoke checks, repository-hygiene checks, CI, and a combined verifier.
- Added versioned forward migrations and verified the first migration on an existing world.
- Removed remaining hosted help/tracking links, personal project metadata, and unused D3/GSAP bundles.
- Removed all standalone and embedded copies of the separately licensed MorphSVG plugin and replaced its live calls with native SVG path updates.
- Removed four unreachable duplicate graphic packs and the nonfunctional pack selector.
- Added exact ruleset, operations, privacy, provenance, third-party, release, and changelog documentation.
- Added guarded full-database backup and restore scripts.
- Added deterministic ruleset, combat, authentication-migration, and WW-plan regression coverage.
- Verified backup/restore and an isolated clean installation from empty volumes.
- Fixed regression-fixture auto-increment pollution and aligned adventure
  ownership with unsigned player IDs.
- Verified the rebuilt stack, a disposable empty-volume install, and the final
  browser render after canonical graphic-pack pruning.
- Corrected Master Builder target levels, net crop timing, resource reservation,
  and due-task rescheduling; added deterministic queue regressions.
- Restored authenticated AJAX by sending the canonical page token, replaced
  dynamic response execution with a structured dialog command, and made legacy
  timers and dialog positioning compatible with the runtime CSP.
- Verified the rebuilt authenticated quest/building flow with zero browser
  errors, then passed the full verifier and a disposable empty-volume install.
- Extended public-history hygiene to remove consumer email addresses from
  legacy commits, vendored asset headers, and commit metadata.
- Completed transactional first-horse exchange for 100 silver with a required
  replacement horse, accounting records, replay rejection, and DB regressions.
- Verified first-horse exchange and ordinary horse auction dialogs in a real
  browser with correct inventory/silver changes and zero browser errors.
- Made Natar World Wonder attacks deterministic across crossed levels, restored
  all 14 grey-area waves, and added exact army/timing database regressions.
- Verified the two-wave incoming-attack UI in a real browser with zero errors,
  then removed the disposable movement fixture and restored its counter.
- Passed the full verifier and a disposable empty-volume installation with the
  atomic Natar movement batches and complete attack-profile fixture.
- Diagnosed the public CI failure as a missing `rg`, added the runner dependency
  and a fail-closed hygiene prerequisite, then passed the local verifier.
- Updated the checkout action to its Node 24 runtime and recorded the exact
  upstream baseline with a read-only drift check and safe import procedure.
- Added atomic quest-silver accounting and corrected scoped troop-punishment
  upkeep across home, trapped, and reinforced armies.
- Restored public-truce content, corrected vacation-day clamping, synchronized
  vacation infobox state, and covered all four audit areas with regressions.
- Rebuilt the stack, verified quest accounting and vacation/truce infoboxes in
  a real browser with zero console errors, and removed every temporary fixture.
- Made the local verifier compare maintained-source checksums and fail with a
  rebuild command when the running application image is stale.
- Made research completion transactional and verified crash rollback, safe
  retry, and duplicate-delivery suppression against MariaDB.
- Documented database invariants and the recovery status of every major
  scheduled-event family before adding constraints.
- Added and regression-tested nested database transaction savepoints so queued
  effects can safely call existing transactional game services.
- Made building completion and demolition transactional and regression-tested
  their game effects against duplicate delivery.
- Made training and alliance-bonus completion transactional and covered troop,
  upkeep, bonus, retry, and duplicate-delivery state with database regressions.
- Made merchant-send processing transactional and verified resource and route
  state against duplicate delivery.
- Made movement completion transactional and verified returning troop arrivals
  against duplicate delivery.
- Added unique automation-worker identities, unexpected-exit detection, and
  graceful signal/reap shutdown tracking for every forked child.
- Added bounded task retries and recoverable poison-event quarantine, including
  failure-ledger cleanup after a successful retry.
- Passed both upgraded-world verification and a disposable empty-volume install
  with the new failure-ledger migration and crash/replay regressions.
- Made voting rewards, purchase messages, and expired-ban processing
  transactional; covered messages, access, infoboxes, and replay in MariaDB.
- Made referral rewards row-locked and idempotent, including crash rollback,
  bounded retry, and duplicate-delivery regression coverage.
- Made oasis deletion transactional with oasis release, enforcement returns,
  movement cancellation, crash rollback, and duplicate-delivery coverage.
- Made recurring trade routes lock their source village and advance only after
  dispatch/no-op completion, with crash and replay regressions.
- Added global notification delivery keys and a transactional game queue
  consumer, proving cross-database crash retry without duplicate notices.

## Current work

- Review activation reminder delivery semantics and then run the full verifier
  and sanitized publication flow.

## TODO

- Promote the finished sanitized release branch to public `main` only after
  explicit approval of the guarded history replacement.
