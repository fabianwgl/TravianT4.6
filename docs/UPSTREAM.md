# Upstream synchronization

The fork's `main` branch mirrors `advocaite/TravianT4.6:main`. The imported
upstream commit is recorded in [`.upstream-main`](../.upstream-main), and can be
checked without modifying the checkout:

```sh
./scripts/check-upstream.sh
```

The modernization branch has sanitized, re-authored history. GitHub therefore
reports it as both ahead of and behind upstream even when the upstream baseline
is already present. Those graph counts compare commit identities; they do not
by themselves prove that code is missing.

## Importing a new upstream revision

1. Run the check and note the new upstream commit.
2. Review the upstream range from the recorded commit to the new commit.
3. Port relevant patches as small responsibility-based commits. Resolve them
   against the maintained `main_script` tree and current runtime.
4. Run `./scripts/verify.sh` and the public-repository hygiene check.
5. Update `.upstream-main` in the same commit that completes the import.

Do not directly merge legacy upstream history into a sanitized public branch.
Promoting the finished modernization branch to fork `main` is a separate release
operation and may require a guarded history replacement.
