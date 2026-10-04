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
Statcounter, Telegram, hosted help services, or third-party transactional email.
It binds the web port to `127.0.0.1`. Docker image retrieval is an installation
activity, not an in-game data processor.

## Player controls

Account owners can use **Account options > Download account data** and confirm
their current password to download a JSON file for the current world. Sitters
and administrators impersonating another player cannot use this control.
Downloads require the account form token, are not cached, and are returned
directly without saving a server-side export file. The endpoint permits five
export attempts per 15 minutes and one download per minute;
the attempt limit also counts incorrect passwords and applies across sessions.

The file includes an explicit scope description. It is a current-world account
export, not a database backup or a portable world save. Passwords, tokens, and
session credentials are never included. Requests for operator logs, backups,
or records in separate global services still require authenticated operator
handling. Do not identify a global account solely by matching its email address.
Keep the downloaded file private: it contains personal data and private game
messages.

Delivered reports can name other participants and contain intelligence already
available to the account owner. Deleted reports, hidden opponent upgrade values,
and other players' maximum auction bids are excluded. Unreadable legacy reports
are identified as unavailable without preventing the rest of the download.

Players can also schedule account deletion from Account options. The legacy
deletion workflow is asynchronous. Full personal-data erasure and its completion
deadline remain unverified; scheduling gameplay deletion does not prove that
logs, global records, or backups have been erased.

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
