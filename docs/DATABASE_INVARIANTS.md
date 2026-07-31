# Database invariants

This document defines the state that OpenVillage code must preserve. The legacy
schema does not enforce every rule. Add a database constraint only after an
upgrade audit proves existing worlds satisfy it and the create, capture, and
delete paths are covered by regression tests.

## World state

- `config` contains exactly one row. Worker and web updates without a `WHERE`
  clause rely on this singleton.
- `wdata.id` is the canonical map tile ID. A playable `vdata.kid` refers to an
  occupied tile with the same ID.
- `users.id` and `vdata.kid` are stable identities. They must not be reused to
  represent a different player or map tile inside a running world.
- `users.uuid` is globally unique inside the world database.

## Accounts and villages

- Every playable village has exactly one row with the same `kid` in `vdata`,
  `fdata`, `units`, `tdata`, and `smithy`.
- `vdata.owner` refers to the owning `users.id`. A normal player's `users.kid`
  refers to one of that player's villages.
- In steady state, a normal player with villages has one capital. Capture and
  deletion code may change several rows, so the whole transition must be
  atomic before a uniqueness constraint is considered.
- `users.total_villages`, `users.total_pop`, and `users.cp_prod` are cached
  aggregates. Village creation, capture, deletion, building, and demolition
  must update the base rows and aggregates together.
- Rows that refer to a player, village, alliance, item, artifact, movement, or
  report must be deleted, reassigned, or intentionally archived when their
  owner is deleted. The current schema has few foreign keys, so application
  lifecycle code owns this invariant.

## Economy and armies

- `vdata.lastmupdate` is a Unix timestamp in milliseconds. Resource production
  is settled to that time before resource balance or upkeep changes.
- Stored wood, clay, and iron do not exceed `maxstore`; stored crop does not
  exceed `maxcrop`. Crop can fall below zero while starvation is enabled, so a
  non-negative crop constraint would be incorrect.
- Unit counts in `units`, `movement`, `enforcement`, and `trapped` represent
  disjoint locations of the same armies and must never be duplicated by a
  transition.
- `vdata.upkeep` is derived from home troops, relevant incoming/returning
  movements, reinforcements, trapped troops, Horse Drinking Trough effects,
  artifacts, and World Wonder rules. Any troop-location change must settle
  resources first and then update every affected village's upkeep.
- Silver balance changes and their `accounting` rows commit together. Replaying
  the same business action must not create a second balance change.

## Scheduled events

Every queue row has one stable primary-key identity. The required completion
invariant is:

1. Concurrent workers may claim an event only once.
2. The event remains recoverable until all database effects commit.
3. A crash rolls back both the effect and event consumption.
4. Retrying or redelivering the same event cannot duplicate its effect.

`research`, normal `building_upgrade`, `demolition`, `training`, and
`alliance_bonus_upgrade_queue` satisfy this invariant: `TransactionalTask`
locks the row and commits its game effect and queue mutation in one MariaDB
transaction. Master Builder and partial training rows use the same lock and
transaction, but may remain queued when more work is pending. Runtime
regressions prove crash rollback, retry, and duplicate suppression.

The following paths still require evidence-backed conversion and therefore
must not be described as crash-safe:

| Queue or trigger | Clock | Current risk |
| --- | --- | --- |
| `movement` | Unix milliseconds | Row is deleted before battle, arrival, or return effects. |
| `send` | Unix seconds | Row is deleted before resources or merchants are applied. |
| voting, purchase-message, ban, and notification queues | Unix seconds or immediate | Row is deleted before its reward, message, state change, or external notification. |
| recurring config timestamps and trade routes | Unix seconds | Next-run time can advance before the complete effect. |

Database-only effects should use a row lock and one transaction. External mail
or notification effects require an outbox with a stable delivery key; a
database transaction alone cannot make a network send exactly once. Leases and
retry limits are required only for work that cannot remain inside a short
database transaction. Poison events must be retained with an error reason, not
silently deleted.

## Clock units

- Configuration, buildings, research, market sends, and most recurring jobs use
  Unix seconds.
- Movements and `vdata.lastmupdate` use Unix milliseconds.
- Training uses seconds at speeds up to 20, milliseconds above 20, and
  nanoseconds above 20,000. A producer and consumer must use the same configured
  unit.
- Persist absolute wall-clock times. Monotonic clocks may measure local
  duration but must not be stored as cross-process due times.

## Migration policy

- `main_script/include/schema/T4.4.sql` defines clean installations.
- `main_script/include/schema/migrations` contains ordered, immutable forward
  migrations for existing worlds.
- Never edit an applied migration. Add a new migration and retain the recorded
  checksum.
- Back up before schema changes. Schema rollback means restoring the compatible
  application revision and the pre-upgrade database backup.
- Before adding foreign keys, unique keys, or checks, run orphan/duplicate
  audits and exercise registration, settlement, capture, and deletion on both
  clean and upgraded databases.
