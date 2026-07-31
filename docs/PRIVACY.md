# Privacy and data handling

This document describes the maintained local distribution. An operator who
opens a server to other people becomes responsible for a jurisdiction-specific
privacy notice, lawful basis, age rules, retention schedule, request handling,
and incident response.

## Stored data

The application can store:

- account names, email addresses, password hashes, language, and profile data;
- villages, troops, resources, alliances, messages, reports, and other gameplay;
- sessions, activation/recovery tokens, preferences, and sitter relationships;
- login/IP, administrator, application, and worker logs;
- database and runtime backups containing copies of the above.

MariaDB is the primary store. Redis contains sessions and runtime state. Docker
volumes and operator-created backups retain data independently of containers.

## Default processors and network flows

The maintained stack does not enable payments, newsletters, analytics,
removed-analytics, Telegram, hosted help services, or third-party transactional email.
It binds the web port to `127.0.0.1`. Docker image retrieval is an installation
activity, not an in-game data processor.

## Player controls

Authenticated players can schedule account deletion from the in-game account
options. The legacy deletion workflow is asynchronous and should be verified by
the operator before promising a completion deadline. A self-service account
export is not implemented; until it is, an operator must handle export requests
from the database using an authenticated, documented request process.

Deleting containers is not deletion. To erase an entire local installation,
delete its MariaDB, Redis, and runtime volumes and every separate backup.

## Suggested local retention

- Keep application and worker logs only as long as needed to diagnose failures.
- Rotate IP and administrator logs on a documented schedule.
- Retain backups according to a fixed recovery window, then erase them.
- Never commit databases, logs, `.env`, session files, or player exports.
- Encrypt off-host backups and restrict them to named operators.

These are operational defaults, not legal advice. A public operator must publish
exact periods and contact details before accepting players.
