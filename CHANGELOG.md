# Changelog

## Unreleased — OpenVillage modernization

### Added

- Docker Compose runtime for PHP 8.3, MariaDB 11.4, Redis 7.4, Apache, bootstrap,
  and the game worker.
- Local launcher, registration, privacy/terms pages, and readiness endpoint.
- Versioned database migrations, gameplay smoke checks, repository-hygiene
  checks, a combined verifier, and CI.
- OpenVillage identity, ruleset, operations, privacy, provenance, and release
  documentation.

### Changed

- Selected `main_script` as the canonical maintained game tree.
- Modernized password verification/migration, sessions, random tokens, and CSRF.
- Updated the application for PHP 8.3 and current MariaDB/Redis runtimes.
- Replaced hosted help/statistics/logout integrations with local behavior.
- Frozen the tested profile to Romans, Teutons, and Gauls on a 10× local world.

### Fixed

- Registration/activation/login routing and authenticated-page PHP failures.
- Database connection-age handling and worker polling/shutdown behavior.
- Regression-test auto-increment isolation and unsigned adventure ownership.
- World Wonder second-plan alliance semantics and fabricated online statistics.
- World Wonder Natar attack-level scheduling, two-wave timing, and deterministic
  army fixtures.
- Grey-area settlements now receive all 14 intended Natar waves.
- Login JavaScript failures and external tracking requests.

### Removed

- Payments, password-derived privileged login links, and obsolete hosted services.
- Generated/duplicate game trees, phpMyAdmin, legacy deploy services, and logs.
- Unused D3 and d3pie files.
- TweenMax and standalone/embedded MorphSVG copies; affected UI paths now use
  native DOM/SVG updates.
- Four unreachable duplicate graphic packs and their nonfunctional preference selector.
