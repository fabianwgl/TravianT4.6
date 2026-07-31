# Changelog

## Unreleased — OpenVillage modernization

### Added

- Docker Compose runtime for PHP 8.3, MariaDB 11.4, Redis 7.4, Apache, bootstrap,
  and the game worker.
- Local launcher, registration, privacy/terms pages, and readiness endpoint.
- Versioned database migrations, gameplay smoke checks, repository-hygiene
  checks, a combined verifier, and CI.
- A recoverable scheduled-task failure ledger with five-attempt quarantine for
  poison events.
- OpenVillage identity, ruleset, operations, privacy, provenance, and release
  documentation.
- Database ownership, aggregate, economy, army, clock, migration, and
  scheduled-event invariants.
- An exact upstream baseline, read-only drift check, and guarded import guide
  for the sanitized modernization history.
- A pinned PHPStan static-analysis gate covering the maintained PHP source tree.
- A ruleset parity matrix linking supported behavior to canonical code and
  executable regression evidence.
- POST-only Rally Point troop and movement mutations with per-village checker
  validation and generated forms.
- POST-only account deletion and pending-email cancellation controls with
  checker validation.
- POST-only sitter assignment and removal controls with checker validation.
- Authenticated smoke coverage for the account-options page and its checker
  token, plus the sitter-options page.

### Changed

- Selected `main_script` as the canonical maintained game tree.
- Modernized password verification/migration, sessions, random tokens, and CSRF.
- Updated the application for PHP 8.3 and current MariaDB/Redis runtimes.
- Replaced hosted help/statistics/logout integrations with local behavior.
- Frozen the tested profile to Romans, Teutons, and Gauls on a 10× local world.
- Updated GitHub Actions to the current Node 24 checkout runtime.
- Added savepoint-backed nested database transactions for atomic worker effects
  that invoke existing transactional services.
- Added locked, idempotent referral reward processing with crash/retry coverage.
- Added transactional oasis deletion with recoverable failures and replay tests.
- Added transactional recurring trade-route dispatch and retry coverage.
- Added global notification delivery keys and migration tracking.
- Added idempotent delivery keys for activation mail outboxes.
- Added a disposable accelerated complete-round regression from registration
  through World Wonder level 100 and winner rendering.
- Added deterministic golden formula vectors for economy, construction,
  training, villages, heroes, artifacts, combat, and World Wonder endgame.
- Added process-local deterministic clock/seed controls and ordered disposable
  map fixtures for repeatable complete-round tests.
- Added static analysis to local verification and GitHub Actions, with runtime
  fixes for nested helper redeclarations, alliance loss charts, installer
  returns, and legacy CAPTCHA properties.

### Fixed

- Registration/activation/login routing and authenticated-page PHP failures.
- Database connection-age handling and worker polling/shutdown behavior.
- Regression-test auto-increment isolation and unsigned adventure ownership.
- World Wonder second-plan alliance semantics and fabricated online statistics.
- World Wonder Natar attack-level scheduling, two-wave timing, and deterministic
  army fixtures.
- Grey-area settlements now receive all 14 intended Natar waves.
- Quest silver now has atomic accounting; village-scoped troop punishment now
  recalculates crop upkeep for home, trapped, and reinforced armies.
- Public-truce notices render content, and vacation duration and infobox state
  remain synchronized on entry and abort.
- GitHub Actions now installs its verification tools, and repository hygiene
  fails closed when ripgrep is unavailable.
- Local verification now rejects stale application images instead of silently
  testing code from an earlier build.
- Research completion now locks and consumes its queue row in the same
  transaction as the technology effect, so crashes retry safely and duplicate
  delivery cannot apply the effect twice.
- Building completion and demolition now commit their queue consumption and
  game-state effects together, with duplicate delivery suppressed by row locks.
- Training and alliance-bonus completion now atomically persist queue state,
  troops/upkeep or bonus effects, and duplicate-delivery suppression.
- Merchant sends now commit queue consumption, resource movement, and the next
  route in one transaction and ignore duplicate delivery.
- Movement completion now commits battles, arrivals, or returns with event
  consumption under one row lock; return-arrival replay is regression-tested.
- Forked automation workers now have unique identities, unexpected child exits
  fail the parent for container recovery, and shutdown signals and reaps every
  tracked child.
- Voting rewards, purchase messages, and expired bans now commit queue and
  player-facing state atomically and suppress duplicate delivery.
- Referral rewards now commit referral state and gift gold atomically, with
  replay-safe processing and recoverable failures.
- Oasis deletion now commits release, enforcement returns, and movement
  cancellation together with queue consumption.
- Recurring trade routes now lock source resources and advance their schedule
  only after a successful dispatch or deliberate no-op.
- Notification queue rows now retry safely across the game/global databases;
  stable delivery keys suppress duplicate global notices.
- Activation reminder jobs now queue mail before marking reminders delivered;
  replayed reminders reuse a stable outbox key instead of duplicating mail rows.
- Winner and no-winner pages now preserve literal CSS percentages in translated
  markup instead of treating them as `vsprintf` format tokens.
- Nullable database and resource values are normalized before PHP 8.3 string
  helpers run, removing avoidable deprecation noise from normal gameplay.
- The registration smoke flow now submits the sector field used by the
  confirmation form, so clean-world activation is exercised accurately.
- Login JavaScript failures and external tracking requests.

### Removed

- Payments, password-derived privileged login links, and obsolete hosted services.
- Generated/duplicate game trees, phpMyAdmin, legacy deploy services, and logs.
- Unused D3 and d3pie files.
- TweenMax and standalone/embedded MorphSVG copies; affected UI paths now use
  native DOM/SVG updates.
- Four unreachable duplicate graphic packs and their nonfunctional preference selector.
