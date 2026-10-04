# Upstream synchronization

The fork's `main` branch is the maintained OpenVillage integration branch. It
contains the modernization work and subsequent gameplay fixes; it does not
mirror `advocaite/TravianT4.6:main`. The last imported upstream commit is recorded
in [`.upstream-main`](../.upstream-main), and can be checked without modifying
the checkout:

```sh
./scripts/check-upstream.sh
```

The modernization history was sanitized and re-authored before integration.
GitHub may therefore report both ahead-of and behind-upstream commits even when
the upstream baseline is already present. Those graph counts compare commit
identities; they do not by themselves prove that code is missing. Start new work
from the fetched `origin/main` so integrated fixes are not lost or repeated.

## Importing a new upstream revision

1. Run the check and note the new upstream commit.
2. Review the upstream range from the recorded commit to the new commit.
3. Port relevant patches as small responsibility-based commits. Resolve them
   against the maintained `main_script` tree and current runtime.
4. Run `./scripts/verify.sh` and the public-repository hygiene check.
5. Update `.upstream-main` in the same commit that completes the import.

Do not directly merge legacy upstream history into a sanitized public branch.
The modernization branch has already been integrated into fork `main`. Preserve
that public history and publish new fixes as small commits; synchronizing new
work does not require replacing history.
