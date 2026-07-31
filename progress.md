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

## Current work

- Create responsibility-scoped commits and rebuild the sanitized publish history.

## TODO

- Publish the sanitized history after explicit approval of the required public
  `main` history rewrite.
