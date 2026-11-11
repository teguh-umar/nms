# HANDOFF → OpenCode · nms 1.0.0 Plan 1 (foundation and OpManager parity)

Owner: the Claude session for NMS Integration (`nms-integration-18` in hub §6). It reviews everything you commit.
Written 2026-11-11.

**Before the first edit**, load these skills:
- `pgn-suite-handoff`, which loads `handoff-discipline`;
- `glpi-plugin-development`, for GLPI 11 API questions.

## 1. Where everything is

- **Plugin repo:** `PGN GLPI Suite/plugins/NMS Integration/`.
  - Branch **`main-nms`**. Its base is the commit that adds this handoff, on top of `9f217b2` (the opmanager 1.3.2 tree,
    history restarted).
  - Do not commit to, check out, merge or delete `main` or `nms-spec`. They hold the old unscrubbed history and stay
    until the user decides.
  - The repo has no remote. Never add one and never push.
- **Spec:** `docs/superpowers/specs/2026-11-05-nms-integration-design.md`, approved by the user on 2026-11-10.
- **Plan:** `docs/superpowers/plans/2026-11-10-nms-plan1-foundation.md`.
  - **You do Tasks 1–9, in order.**
  - Task 0 is done. Task 10 (runbook, version, CHANGELOG, zip, tag) is the owner's.
  - The plan's **Global Constraints** and **Review Focus** apply to every task. Each Review Focus item names the task
    and the test that pins it.
- **Reference spec for the hygiene feature (D34):** `docs/superpowers/specs/2026-11-06-opmanager-unmanaged-hygiene-design.md`,
  §8 lists every table, column, right and config key that Task 4, 5 and 8 must carry.
- **Code graph:** run `graphify query "<question>"` in this folder before grepping. The graph may still describe the old
  1.2.0 tree until the owner rebuilds it, so confirm with grep. Class names in strings are invisible to the graph.

## 2. Current state

- **Version:** the tree is opmanager 1.3.2 unchanged (`setup.php` still says opmanager). Task 1 renames it.
- **Test command** (local PHP has no php.ini):
  `php -d extension_dir="C:/Users/teguh.umar/Downloads/php/ext" -d extension=openssl -d extension=curl -d extension=mbstring tests/run_all.php`
  The last line must be `SUITES OK`. It is green at the base.
- **Test files load classes by hand.** A new test must `require` every class and interface it uses, in dependency order.
  A missing require is a fatal, not a FAIL line. When a task moves or renames a class (Task 1, Task 3), update every
  test's `require` and `use` lines.
- **The plan names some constants and keys from the 1.3.2 structure without reading every file** (for example
  `AssetSections::ORDER`, `Menu::ORDER`, `SuiteConfig::ORDER`, and the natural keys in Task 8 step 4). If the real code
  differs, follow the code's existing name or key and say so in your report. Do not invent a second mechanism.

## 3. Rules for every task

- **One commit per task.**
  - Work test-first: failing test, then code, then passing test, then commit.
  - Use the commit message the plan gives, with the trailer line `Co-Authored-By: <your model> <noreply@...>`.
  - Never `--amend`, `--no-verify` or push.
- **Checks before each commit:**
  - Run `php -l` on each changed PHP file.
  - Run the full suite, and report its real last line.
  - Grep for unimported global classes (`Foo::` without a `use` line or a leading `\`).
- **OpManager behaviour must not change.** Every existing 1.3.2 test keeps its assertions. You may change only
  namespaces, `require`/`use` lines, constructors (to pass a connection or driver), and identity strings renamed by
  Task 1. If an assertion itself has to change, stop and report instead.
- **opmanager stays untouched.** nms reads `glpi_plugin_opmanager_*` and `plugin:opmanager` only inside
  `src/Migration/FromOpmanager.php`, and never writes them.
- **Secrets.** No API key, password or webhook token in any output, log, exception message, rendered HTML or fixture.
  Fixtures use the literal fake values `key-xyz` and `token-xyz`, and the tests check that neither leaks.
- **No real data.**
  - Fixtures use invented names only: `opm.example.test`, `core-sw-01`, `vm-app-01`, `10.20.x.x`.
  - Never copy names, IPs, subnets or users from screenshots, the hub file, other plugins' docs or the VM.
- **Do not touch:** `graphify-out/`, `CLAUDE.local.md`, `.claude/`, `lib/pgnsuite/` (shared library; its SHAs are
  locked by `tests/lib_sha_test.php`).

## 4. When you stop

Stop after Task 9, or earlier if a rule above makes you stop. Report:
- each commit hash with its task number;
- the real last line of the suite after each task;
- every place where you followed the code instead of the plan's name, with the reason;
- anything left unverified.

The owner reviews, then does Task 10.
