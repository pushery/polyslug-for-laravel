# Contributing

Thanks for considering a contribution — issues and pull requests are both welcome.

## Reporting an issue

Use the GitHub issue templates (bug report / feature request). Include the package
version and a minimal reproduction, and never paste secrets or credentials.

## Pull requests

- Keep the public API stable, or call out the break explicitly.
- Describe the behavior change and the case that proves it. This repository does not
  carry the test suite (see below), so name the scenario in the pull request and a
  maintainer writes the covering test and runs it against your branch.
- Update the [documentation](https://docs.pushery.com/polyslug-for-laravel/) and the
  `CHANGELOG.md` `## [Unreleased]` section.
- Keep each commit focused.

## Where the tests are

This repository is the published package: `src`, `config`, `database`, `resources` and
the release metadata. Development happens in a private repository, and the test suite,
the workbench application and the tooling configuration live there — so there is no
`composer test` to run here, and `vendor/bin/pest` has nothing to collect.

That is deliberate rather than an oversight, and it does not slow a pull request down:
every branch is merged into the private repository first, where the full bar below runs
against it, and anything it finds comes back as review comments before the change is
released.

## Local requirements

**PHP 8.4 and Laravel 13.0** — the floors the package declares, and the whole of what a
clone of this repository needs. The development toolchain sits one patch version higher
(Pest 5 pulls in `symfony/process`, which wants `>=8.4.1`), but it is not installed from
here, so that floor is the private repository's problem rather than yours.

## Quality bar

This package holds itself to a strict quality bar — Laravel Pint, Larastan at `max`,
Rector, and Pest with 100% line and type coverage, plus mutation testing, a
real-browser end-to-end suite, and cross-engine tests against real PostgreSQL 18 and
MySQL 8.4 (the engines it runs on in production, where the one-current-slug guarantee
is enforced by two genuinely different mechanisms — a functional partial index on
PostgreSQL, virtual generated key columns on MySQL). Every one of those runs in
continuous integration on the way to a release. That bar is what a pull request is
measured against, and it runs on the private repository rather than here — so a change
that keeps the public API stable and names the case it changes is easy to accept.
