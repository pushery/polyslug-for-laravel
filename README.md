<p align="center">
  <a href="https://github.com/pushery/polyslug-for-laravel">
    <img src="art/header.png" alt="Polyslug" width="100%">
  </a>
</p>

# Polyslug

[![Latest Version](https://img.shields.io/packagist/v/pushery/polyslug-for-laravel.svg)](https://packagist.org/packages/pushery/polyslug-for-laravel)
[![PHP Version](https://img.shields.io/packagist/dependency-v/pushery/polyslug-for-laravel/php.svg)](https://packagist.org/packages/pushery/polyslug-for-laravel)
[![Laravel Versions](https://badge.laravel.cloud/badge/pushery/polyslug-for-laravel?style=flat)](https://packagist.org/packages/pushery/polyslug-for-laravel)
[![PHPStan](https://img.shields.io/badge/PHPStan-max-blue.svg)](https://phpstan.org)
[![Code Style](https://img.shields.io/badge/code%20style-pint-orange.svg)](https://laravel.com/docs/pint)
[![License](https://img.shields.io/packagist/l/pushery/polyslug-for-laravel.svg)](LICENSE)

**Polymorphic, multilingual routable identity for Eloquent.** Pretty URLs that are
safe to expose, safe to rename, and correct across languages — with leak-free IDs,
self-healing canonical redirects, and hreflang built in.

```
/blog/laravel-routing-explained_aB3xK
      └──────── slug ─────────┘ └id─┘
   changeable, localized, SEO-friendly   stable, opaque, resolves the model
```

A slug should be free to change. A URL should never break. Those two goals usually
fight each other. Polyslug settles the fight by splitting a URL into two independent
parts — a **human-readable slug** (pretty, per-locale, editable) and a **stable
opaque identity** (an encoded token that resolves the model). Rename the slug all you
like: the identity still resolves, and old URLs redirect themselves to the new
canonical one.

---

## Install

```bash
composer require pushery/polyslug-for-laravel
php artisan migrate
```

Mark a model as sluggable, point a route at it, and you are done:

```php
#[Polyslug(source: 'title')]
class Page extends Model implements Sluggable
{
    use HasPolyslug;
}

Route::polyslug('/pages/{page}', [PageController::class, 'show'])->name('pages.show');
```

Rename the page and the old URL `301`s to the new one, by itself.

## Why Polyslug?

- 🔒 **Leak-safe IDs by default.** URLs carry an encoded token, not `/pages/1523`. No
  exposed row counts, no enumerable primary keys. The encoder is pluggable — Sqids,
  UUID, ULID, or your own.
- ♻️ **Self-healing URLs.** Rename freely. A stale slug on a `GET`/`HEAD` request is
  `301`-redirected to the current canonical URL automatically — no redirect tables to
  hand-maintain, no dead links, no lost link equity.
- 🌍 **Multilingual with hreflang out of the box.** One slug per locale, and a
  reciprocal `hreflang` set (plus `x-default`) generated from the **same** resolver
  that builds your canonical URL — so they can never drift apart.
- 🧩 **Polymorphic routing.** Serve every content type — pages, articles, products —
  through a single `{type}/{polyslug}` route and one registry.
- 🧭 **Stable resolution.** Route-model binding decodes the identity, not the slug, so
  a mistyped or outdated slug still finds the right model (then redirects). An unknown
  or malformed token is a clean `404` — never a fuzzy match.
- 🏢 **Scoped uniqueness.** Uniqueness can be scoped per tenant, per locale, per
  category — whatever columns you name.
- 🗂️ **History, events & immutability.** Superseded slugs are kept so old URLs keep
  resolving; a `SlugChanged` event fires on every change; slugs can be frozen.
- ✅ **Serious about correctness.** PHPStan at `max`, 100% line + type coverage, and a
  mutation-tested suite that runs on SQLite, PostgreSQL, **and** MySQL 8.4.

## Documentation

**Full docs at [docs.pushery.com/polyslug-for-laravel](https://docs.pushery.com/polyslug-for-laravel/).**

- [Installation](https://docs.pushery.com/polyslug-for-laravel/installation) — requirements, the migration, publishing
- [Quick start](https://docs.pushery.com/polyslug-for-laravel/quick-start) — a sluggable model and a self-healing route
- [Features](https://docs.pushery.com/polyslug-for-laravel/features) — encoders, hreflang, nested paths, sitemaps, short links
- [Recipes](https://docs.pushery.com/polyslug-for-laravel/recipes) — twelve app shapes wired end to end
- [Reference](https://docs.pushery.com/polyslug-for-laravel/reference/configuration) — every config key, option, command, event and contract

## Requirements

- PHP 8.4+
- Laravel 13+
- PostgreSQL, MySQL 8.4+, or SQLite — the uniqueness guarantees are enforced natively on
  each, and the full suite runs against all three. So Polyslug works on Laravel Cloud
  (serverless Postgres + MySQL 8.4 LTS) with no extra configuration.

## Security

Please review the [security policy](SECURITY.md) and report vulnerabilities
privately rather than opening a public issue.

## Built by Pushery

This package is built and maintained by [Pushery](https://www.pushery.com) — a
Berlin-based studio building Laravel applications, SaaS products, and open-source
tools.

Building a Laravel UI? [WireKit](https://wirekit.app), Pushery's open-source
Livewire component kit, gives you a polished component library out of the box.
Browse the rest of our work at [pushery.com](https://www.pushery.com).

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
