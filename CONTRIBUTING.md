# Contributing

Thanks for considering a contribution — issues and pull requests are both welcome.

## Reporting an issue

Use the GitHub issue templates (bug report / feature request). Include the package
version and a minimal reproduction, and never paste secrets or credentials.

## Pull requests

- Keep the public API stable, or call out the break explicitly.
- Add tests for any behavior change.
- Update the [documentation](https://docs.pushery.com/polyslug-for-laravel/) and the
  `CHANGELOG.md` `## [Unreleased]` section.
- Keep each commit focused.

## Quality bar

This package holds itself to a strict quality bar — Laravel Pint, Larastan at `max`,
Rector, and Pest with 100% line and type coverage, plus mutation testing, a
real-browser end-to-end suite, and cross-engine tests against real PostgreSQL 18 and
MySQL 8.4 (the engines it runs on in production, where the one-current-slug guarantee
is enforced by two genuinely different mechanisms — a functional partial index on
PostgreSQL, virtual generated key columns on MySQL). The maintainers run the full
gate locally before every release, so a pull request that keeps the public API stable
and ships tests for its change is easy to accept.
