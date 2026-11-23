# VAXIA tests

PHPUnit tests for the custom Drupal 7 modules, written before the Drupal 10/11
upgrade. Most tests are black-box (pages, forms, permissions, saved rows), so they
can be re-pointed at the new site.

## First-time setup (about 10 minutes)

```
cd tests/docker
./fetch-contrib.sh              # D7 core + contrib + zen into tests/.build/
docker compose up -d --build    # web (PHP 7.4 + Apache, :8077) and db (MariaDB 10.6)
./install.sh                    # installs the site, writes tests/.build/baseline.sql
```

Re-run `./install.sh` after changing any module's install/features/rules code.

## Running

```
tests/run-tests.sh                          # everything except @group knownbug
tests/run-tests.sh modules/rpg/vacation     # one module
tests/run-tests.sh --group knownbug         # tests that pin known bugs (they fail)
```

The site is browsable at http://localhost:8077 (admin / admin), bound to 127.0.0.1 only.

## CircleCI

`.circleci/config.yml` runs the same Docker stack on CircleCI machine executors:
setup (checkout, cached contrib download) → smoke (build the PHP 7.4 image, lint) →
install (`docker/install.sh`, harness guard) → test boxes (test files split by
timing data, one PHPUnit run per file, junit results) plus a known-bug report and
a collated `php-errors` log.

Pick a run with the `RUN` pipeline parameter (Trigger Pipeline > Add Parameter):
`PULLREQUEST` (default), `FULLSET`, `KNOWNBUG`, `SMOKE`, or `DEBUG` with `GROUP`
(a module's `@group`, e.g. `strikes`) and `BOXES`.

The known-bug job doesn't fail on the known bugs. It fails when one of them
starts passing, so a fixed bug's test gets moved out of `@group knownbug`.

## How it works

Tests are black-box, so they can be pointed at the Drupal 10/11 rebuild:

- **Arrange** through `$this->site` (`src/Site.php`). It creates users, characters,
  rooms, and posts, sets configuration that has no admin form, and does "time
  travel" (backdating a record, resetting a daily cron guard). It's the only code
  that touches Drupal: re-implement it for the new site and the tests stay the same.
- **Act** over HTTP: login, forms, the Workflow tab, `cron.php`. Each user gets
  their own browser session (`login($account)` switches between them).
  `triggerAjax()` fires an element's `#ajax` (as a browser does on change) and
  applies the returned markup, so the next `submitForm()` sees the rebuilt form.
- Shared flows live in traits under `src/`: `DiceTrait` (post dice rolls with
  items in a chat room, read the rendered results), `ProposalTrait` (create,
  launch, vote on, close proposals), `StrikeFormTrait`.
- Production grants many permissions to anonymous, authenticated and player
  users. A test of a permission gate revokes it for the test with
  `withoutPermission($permission, $roles, $fn)`.
- **Assert** over HTTP: page text, messages, admin screens (`rolesOf`,
  `isBlocked`, `workflowState`, `profileText`, `roleHasPermission`), plus
  captured mail.
- `HarnessTest` fails if a module test calls a Drupal or module function directly.
- The database is restored from `baseline.sql` once per test class, so tests in a
  class share it. Keep assertions about your own users and content, or reset
  shared settings in `setUp()`.
- PHP notices that pages print, and Rules errors, go to `.build/php-errors.log`
  (cleared on each run). `pageErrorsFrom('x.module')` returns the current test's.
- Tests live in `modules/<same path as the module>/`.

## Harness fixtures

- `fixtures/install-patches/` holds patches for install-blocking bugs. They're applied
  only during `install.sh` and always reverted.
- `fixtures/prod-*.php` recreate production configuration the code depends on but
  doesn't export: role ids and permissions, workflow state ids, tab roles and the
  character workflow, realm term ids, fields, and settings. Values are confirmed against a 2017 production
  backup (only ids and names were taken from it).
- `fixtures/modules/quiz_rules` stubs the vanished quiz_rules project.

Bugs found so far are listed in `KNOWN-BUGS.md`.
