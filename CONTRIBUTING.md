# Contributing

Thanks for considering a contribution. This package holds itself to a strict quality
bar, and every change is expected to keep all of the gates green.

## Getting started

```bash
git clone git@github.com:pushery/polyslug-for-laravel.git
cd polyslug-for-laravel
composer install
```

## Quality gates

All of the following must pass. The aggregate static + test gate is:

```bash
composer qa
```

which runs, and each can be run on its own:

| Command | Gate |
|---|---|
| `composer format:test` | Code style — Laravel Pint, zero diffs (`composer format` to fix). |
| `composer rector:test` | Refactoring — Rector with the PHP rule set, dry-run clean (`composer rector` to apply). |
| `composer analyse` | Static analysis — Larastan at `max` level, no errors. |
| `composer test:type-coverage` | 100% type coverage of `src/`. |
| `composer test:coverage` | 100% line coverage of `src/`. |

The suite uses [Pest](https://pestphp.com) and Orchestra Testbench.

Beyond the static and coverage gates above, the suite also includes a real-browser
end-to-end pass (`composer test:browser`, after `npx playwright install chromium`) and
mutation testing (`composer mutate`, minimum 85%). Run both before opening a pull request.

## Pull request expectations

- Keep `composer qa` green.
- Add tests for behavior changes.
- Update `README.md` and `CHANGELOG.md` (`## [Unreleased]`) when behavior or
  configuration changes.
- Keep commits focused and the public API stable, or call out the break explicitly.
