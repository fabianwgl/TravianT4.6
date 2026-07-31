# TravianT4.6 Project Improvements

> Status: verified local/private-play alpha
> Audit baseline: commit `0f517012`, reviewed 2026-07-30
> Implementation verification: 2026-07-31

## Goal

Build a secure, reproducible, and legally distributable open-source game based on
a clearly defined T4.6-style ruleset.

The repository contains substantial gameplay code, including registration,
villages, combat, settlement, heroes, alliances, artifacts, and the World Wonder
endgame. It is a modernization and verification project, not automatically a
ground-up rewrite.

Do not publicly host the current code or enable payments before the P0 gates are
complete.

## Guiding decisions

- Preserve behavior with characterization tests before large refactors.
- Choose one canonical source tree before fixing duplicated code.
- Define the exact target ruleset before judging feature parity.
- Replace unverified branding, artwork, audio, and text with original or
  demonstrably licensed content.
- Test crash recovery and duplicate delivery before redesigning the event engine.
- Keep optional modern features outside the initial compatibility target.

## Priority roadmap

| Priority | Outcome |
| --- | --- |
| P0 | Contained, legally defensible, reproducible, and secure development baseline |
| P1 | Trustworthy playable alpha with a verified complete game round |
| P2 | Operable public beta with privacy, observability, and project governance |
| P3 | Optional rulesets, tribes, features, and architectural improvements |

## P0 — Stop-ship

### 0. Contain unsafe functionality

- [x] Keep public hosting and payments disabled.
- [x] Remove persistent admin and multihunter bearer-login URLs.
- [ ] Rotate any credentials or tokens that were ever used outside local testing.
- [x] Remove the destructive root cleanup cron with the retired Manager service.
- [x] Remove phpMyAdmin from the public application surface.
- [ ] Purge or rotate production connection metadata found in repository history.

### 1. Decide the product and legal boundary

- [x] Define whether this is a clean-room-compatible implementation or a
      preservation project.
- [x] Select a distinct project name and visual identity.
- [ ] Replace unverified Travian logos, artwork, audio, marketing text, and
      translations.
- [ ] Inventory every third-party component and asset with source and license.
- [ ] Add `NOTICE`, SPDX metadata, an SBOM, and a documented contribution policy.
- [ ] Obtain qualified legal review before distributing branded assets.

The root [`LICENSE`](../LICENSE) does not automatically relicense bundled
third-party material. For example, the bundled
[`phpMyAdmin`](../sections/pma/composer.json#L14) has its own license.

### 2. Freeze the target ruleset

- [x] Document the target snapshot and expected behavior.
- [x] Specify tribes, server speeds, map sizes, beginners protection, artifacts,
      building plans, Natar attacks, and World Wonder victory.
- [x] Decide whether Egyptian and Hun mechanics belong to the initial release.
- [ ] Create a parity matrix mapping each rule to code, fixtures, and tests.
- [x] Treat the `T4.4.sql` filename as an audit warning, not proof of
      incompatibility.

### 3. Create a reproducible local runtime

- [x] Select one canonical tree from `main_script` and `main_script_dev`.
- [x] Remove legacy `Controller1`, `Ajax_old`, generated server copies, and drift.
- [x] Pin a supported PHP, database, Redis, and web-server stack.
- [x] Add a one-command containerized local installation.
- [x] Centralize configuration and remove `/travian`, domain, root-database, and
      systemd assumptions.
- [x] Repair PHP 8 syntax failures, including
      [`Village.php`](../main_script/include/Core/Village.php#L1327).
- [ ] Rebuild dependency manifests and lockfiles from maintained versions.
- [ ] Reconstruct or replace the missing frontend source; do not edit only the
      compiled Angular bundle.
- [x] Verify clean imports for both global and game databases.
- [x] Add health and readiness checks.

The current [`README`](../README.md#L4) targets unsupported PHP 7.3–7.4, while
provisioning performs host-level changes in
[`ServerManager.php`](../TaskWorker/include/Core/ServerManager.php#L46).

### 4. Restore the security boundary

- [x] Replace SHA-1 passwords with Argon2id or bcrypt using a safe migration path.
- [ ] Replace weak tokens and recovery codes with `random_bytes()` values,
      expiration, single use, and rate limiting.
- [x] Regenerate session IDs after authentication and configure secure cookies.
- [ ] Require authentication and action-level authorization for every mutation.
- [x] Restore POST-only CSRF validation; the current
      [`checkAjaxToken()`](../main_script/include/Controller/AjaxCtrl.php#L49)
      immediately succeeds.
- [ ] Replace admin bypasses with deny-by-default RBAC, MFA, and audit logging.
- [x] Remove privileged links derived from passwords or global tokens.
- [ ] Run web, mail, and worker services as unprivileged users.
- [ ] Replace shell construction from task or request data with narrow,
      allow-listed operations.
- [ ] Add negative integration tests for authentication, CSRF, RBAC, and IDOR.

## P1 — Trustworthy playable alpha

### 5. Prove a complete game round

- [ ] Build an accelerated end-to-end test:
      register → build → train → trade → attack → settle → conquer → artifacts →
      plans → World Wonder level 100 → winner.
- [ ] Add deterministic clocks, random seeds, map generation, and fixtures.
- [ ] Add golden tests for resources, construction, training, combat, conquest,
      hero, artifact, and endgame formulas.
- [ ] Run linting, static analysis, schema imports, and smoke tests in CI.
- [x] Test supported server speeds and map profiles.

### 6. Make data and workers recoverable

- [x] Introduce versioned forward migrations and documented rollback policy.
- [x] Document database invariants before adding constraints.
- [x] Add backup, restore, and upgrade rehearsals.
- [ ] Test task and event behavior across crashes and duplicate delivery.
- [ ] Add atomic task claims, leases, retry limits, and idempotency where tests
      demonstrate gaps.
- [x] Give every worker a unique identity and reliable shutdown tracking.
- [x] Replace sub-millisecond busy polling with bounded polling or a due-event
      queue.
- [x] Fix the reversed database connection-age check in
      [`DB.php`](../main_script/include/Core/Database/DB.php#L146).

Some scheduled paths delete an event before completing its effect, such as
[`Automation.php`](../main_script/include/Core/Automation.php#L100). Tests should
establish the required remediation scope before a broad engine rewrite.

### 7. Close confirmed gameplay gaps

- [x] Correct World Wonder second-plan diplomacy in
      [`BuildingHelper.php`](../main_script/include/Game/Buildings/BuildingHelper.php#L166).
- [x] Define and test building-plan capture and activation semantics.
- [x] Validate and test the preserved Natar World Wonder army profile, crossed
      attack levels, two-wave timing, and all 14 grey-area settlement waves.
- [x] Replace hard-coded 2019 general statistics with live calculations.
- [x] Restore or remove the missing Plus graph backend.
- [x] Finish first-horse exchange and normal hero horse selling.
- [x] Correct Master Builder queued-level and projected-resource calculations.
- [x] Audit quest silver, punishment upkeep, public-peace notices, and vacation
      notifications.
- [x] Complete Egyptian and Hun wall, simulator, hero-speed, and manual behavior
      only if those tribes are in the frozen ruleset.

### 8. Add privacy controls

- [x] Create a personal-data inventory and processor list.
- [ ] Define retention for profiles, messages, IP logs, admin logs, and payments.
- [ ] Implement account export and deletion.
- [x] Remove unconditional newsletter enrollment.
- [x] Minimize Telegram, email, and payment data flows.
- [ ] Publish privacy and age policies before a public beta.

## P2 — Public beta readiness

- [ ] Add structured logs, correlation IDs, worker heartbeat, queue lag, and
      overdue-event metrics.
- [ ] Add health dashboards, alerts, incident response, and operator runbooks.
- [ ] Run load, rate, soak, backup-restore, and disaster-recovery tests.
- [ ] Add dependency, license, secret, and vulnerability scanning to CI.
- [ ] Add `SECURITY.md`, contribution standards, issue templates, and releases.
- [ ] Document support windows and database/runtime upgrade policy.
- [ ] Complete an independent security review before public exposure.

## P3 — Optional improvements

- [ ] Additional tribes or current-era Travian mechanics.
- [ ] Hospital and wounded-unit mechanics if included by a future ruleset.
- [ ] Modern responsive and accessible frontend.
- [ ] Alternative queues, services, or language migrations after parity tests
      protect behavior.
- [ ] Custom events, seasons, automation, and non-compatibility game modes.

## Milestones

| Milestone | Exit gate |
| --- | --- |
| M0 — Scope and provenance | Frozen ruleset, clean-room boundary, asset/license inventory |
| M1 — Local baseline | One-command clean install on a supported stack; CI is green |
| M2 — Secure alpha | Auth, sessions, CSRF, RBAC, workers, and payments meet P0 gates |
| M3 — Trustworthy engine | Deterministic domain tests, migrations, crash and restore tests |
| M4 — Complete round | Accelerated end-to-end test reaches and records WW level 100 |
| M5 — Public beta | Privacy, observability, load testing, runbooks, and security review |
| 1.0 | Documented ruleset parity with no unresolved release-blocking findings |

## Peer-review notes

The roadmap was independently reviewed across gameplay, architecture, and
security/legal/operations, followed by priority cross-review.

The review explicitly avoided these overclaims:

- Existing feature paths do not prove that a complete round works.
- An old schema filename does not prove database incompatibility.
- The compiled Angular application is obsolete and unrebuildable, but not proven
  nonfunctional.
- Zero foreign keys do not automatically make the game schema incorrect.
- Event-processing risks justify crash tests first, not an automatic wholesale
  rewrite.

## Audit limitations

- Static source review only; no production deployment was attempted.
- No full game round was executed.
- No current dependency advisory or penetration test was performed.
- Database compatibility beyond the original MariaDB-era dumps remains unverified.
