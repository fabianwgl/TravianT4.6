# Operations

OpenVillage is supported as a localhost-bound Docker Compose application. A
public deployment requires a separate deployment design, privacy policy,
content moderation process, provenance review, TLS, independent review, and
operator contact details.

## Install and start

```sh
cp .env.example .env
# Replace every placeholder secret in .env.
docker compose up -d --build
./scripts/verify.sh
```

Open `http://127.0.0.1:8080/`. The `bootstrap` service waits for MariaDB and
Redis, imports a new world once, and applies every forward migration. Re-running
it is safe:

```sh
docker compose run --rm bootstrap
```

Never reuse the example secrets outside an isolated local machine. Keep `.env`
out of version control.

## Routine checks

```sh
docker compose ps
curl -fsS http://127.0.0.1:8080/health.php
docker compose logs --since=15m app worker
./scripts/verify.sh
```

The worker should remain running and recent logs must not contain PHP fatal
errors. The health endpoint reports readiness only after MariaDB, Redis, and the
installed game configuration are available.

Each forked automation worker has a unique logical identity. If any child exits
unexpectedly, the parent terminates the remaining children and exits so the
container restart policy can restore the complete worker set. Normal shutdown
signals every tracked child, waits up to 15 seconds, and then reaps it.

Inspect quarantined scheduled events before and after upgrades:

```sql
SELECT task_table, task_id, attempts, last_error, last_failed_at
FROM scheduled_task_failures
ORDER BY last_failed_at DESC;
```

The retained JSON payload is recovery data. Repair the underlying cause and
recreate the event deliberately; do not blindly copy unknown payloads into a
live queue.

The verifier rejects a running application image whose maintained source does
not match the checkout. Rebuild with `docker compose up -d --build --wait`
after changing application or regression-test files.

Before a release, also prove installation against empty disposable volumes:

```sh
./scripts/verify-clean-install.sh
```

The script uses an isolated Compose project and a process-specific local port,
exercises registration through authenticated gameplay, then removes only that
disposable project's containers and volumes. Set `CLEAN_APP_PORT` when a
specific free port is required. The routine verifier intentionally skips this
state-changing smoke flow on an existing world.

## Data and backups

Persistent data lives in the Compose volumes `database-data`, `redis-data`, and
`runtime-data`. The worker writes compressed database backups under the runtime
volume. Backups contain player and operator data and must be protected like the
live database.

Database ownership, aggregate, economy, army, clock, and scheduled-event rules
are defined in [Database invariants](DATABASE_INVARIANTS.md). Treat those rules
as migration prerequisites even where the legacy schema does not yet enforce
them.

Before an upgrade, create and verify an explicit backup:

```sh
./scripts/backup.sh
```

Restore is intentionally guarded because it replaces the game database:

```sh
RESTORE_CONFIRM=yes ./scripts/restore.sh backups/openvillage-YYYYMMDDTHHMMSSZ.sql.gz
```

Keep at least one encrypted copy outside the Docker host. Periodically restore a
backup into a disposable world and run `./scripts/verify.sh`; an untested backup
is not recovery evidence.

## Upgrade

1. Back up the database and record the current commit.
2. Pull the intended release or commit.
3. Rebuild and run migrations with `docker compose up -d --build`.
4. Run `docker compose run --rm bootstrap` explicitly.
5. Run `./scripts/verify.sh` and inspect app/worker logs.
6. Roll back the application commit if verification fails. Database migrations
   are forward-only; restore the pre-upgrade backup when a schema rollback is
   required.

## Stop and erase

`docker compose down` stops containers but preserves data. `docker compose down
-v` permanently removes the world volumes and every account. Resolve the exact
Compose project and confirm backups before using `-v`.
