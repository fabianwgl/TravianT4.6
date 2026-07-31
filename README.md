# OpenVillage 4.6

OpenVillage is a local-first preservation and modernization fork of a T4.6-style
browser strategy game. The maintained distribution runs on PHP 8.3, MariaDB
11.4, Redis 7.4, and Apache through Docker Compose.

> This independent preservation project is not affiliated with or endorsed by
> Travian Games. Legacy art and text remain under provenance review; see
> [NOTICE](NOTICE.md) before redistributing or hosting the project publicly.

## Quick start

1. Install Docker with Compose support.
2. Copy `.env.example` to `.env` and replace every placeholder secret.
3. Start the world:

   ```sh
   docker compose up -d --build
   ```

4. Open <http://127.0.0.1:8080/>.

The launcher creates accounts and guides players through tribe selection,
starting-sector selection, village creation, and login. The application binds
to localhost by default; public hosting is intentionally outside the supported
release boundary.

## Verify a checkout

With the stack running:

```sh
./scripts/verify.sh
```

The verification suite checks configuration, migrations, PHP syntax,
public-repository hygiene, service readiness, deterministic runtime behavior,
and worker health without changing the current world. CI additionally parses
every maintained JavaScript file and exercises an end-to-end player flow in a
throwaway world.

Release verification from empty isolated volumes is available through:

```sh
./scripts/verify-clean-install.sh
```

That disposable check includes registration, activation, village creation,
login, and authenticated gameplay routes.

## Documentation

- [Ruleset and parity](docs/RULESET.md)
- [Operations](docs/OPERATIONS.md)
- [Privacy](docs/PRIVACY.md)
- [Release readiness](docs/RELEASE.md)
- [Upstream synchronization](docs/UPSTREAM.md)
- [Provenance and redistribution](NOTICE.md)
- [Third-party inventory](THIRD_PARTY.md)
- [Changelog](CHANGELOG.md)
- [Contribution guide](CONTRIBUTING.md)
- [Modernization roadmap](project-improvements/README.md)

## Current release boundary

- Payments and legacy hosted services are disabled.
- The canonical game source is `main_script`; generated and privileged duplicate
  trees are not part of this distribution.
- Romans, Teutons, and Gauls are the supported initial tribes.
- This release targets reproducible local/private play while full-round parity,
  provenance replacement, and independent review continue.
- The current classification and unresolved 1.0 gates are explicit in
  [the release-readiness document](docs/RELEASE.md).
