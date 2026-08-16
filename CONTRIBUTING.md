# Contributing

Contributions should be small, reviewable, and grouped by one responsibility.

1. Fork the repository and create a focused branch.
2. Preserve existing game behavior unless the ruleset documents the change.
3. Add or update regression coverage for every gameplay or runtime fix.
4. Run `./scripts/verify.sh` with the local stack running.
5. Describe behavior changes, migration needs, and verification evidence in the
   pull request.

Do not commit credentials, production data, player data, private URLs, local
paths, generated logs, database dumps, or material without clear provenance.
New assets and dependencies must include their source and license.
