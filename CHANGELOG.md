# Changelog

All notable changes to `pushery/polyslug-for-laravel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.18.2] - 2026-09-06

### Changed

- **Nothing in the package moved — the only file here that ships is the entry you're reading.** Everything committed since 0.18.1 lives in files the release pipeline strips, so `src`, `config`, `database`, `resources` and `composer.json` are identical to what 0.18.1 published. The tag keeps the release line level with the development line; there's nothing to gain by upgrading and nothing to lose by skipping it.

## [0.18.1] - 2026-09-06

### Changed

- **Nothing in the shipped code — that is the entry, not something missing from it.** The only difference from 0.18.0 is in this package's own development dependencies. Composer ignores a package's `require-dev` when resolving it as a dependency, so an application installing Polyslug gets exactly what 0.18.0 gave it: same classes, same config, same behavior. The version exists so the release line carries the same tree as the development line, and there is nothing here to upgrade for.

## [0.18.0] - 2026-09-05

### Fixed

- **One type the resolver cannot address no longer costs the whole sitemap.** `PolyslugUrlResolver::url()` returns a `string`, so an implementation with nothing to return has only an exception to reach for — and `polyslug:sitemap` walks every configured type, so a single throw ended the run: no `<urlset>`, no file, a red scheduled job. `polyslug.sitemap.types` is configuration filtered on `Model` and `Sluggable`, and neither says anything about whether a type is routed, so a model nothing routes — or one whose route was renamed while the config stayed — is ordinary rather than exotic. Those records are skipped now and the rest of the document is written. The failure direction is why this mattered more than it sounds: a red scheduled run doesn't replace the file it was going to write, so the previous sitemap stayed in place and aged silently, which from outside looks exactly like a sitemap being kept current. Reported from a consuming application.

### Added

- **`canAddress()` lets a resolver say a model has no public address, without throwing.** It's optional and isn't declared on the interface, so an existing resolver needs no change: `polyslug:sitemap` reaches it through `method_exists()`. A declared refusal is silent, because naming a type as unaddressable is a decision somebody made; a resolver that throws is still survived, but those records are counted and the types named at the end of the run. Only one of the two is a decision, and the output says which happened.

## [0.17.1] - 2026-09-05

### Documentation

- **A gone page can carry its own head metadata, and it already could.** `polyslugIsGone()` answers `410` by throwing a real `HttpException`, and `laravel/head` resolves an error status off anything implementing `HttpExceptionInterface` — so `Head::errors(fn ($pages) => $pages->status(410, title: '…'))` reaches Polyslug's `410` with no wiring at all. That was true and undocumented, which is the same as untrue for anyone reading. Both halves are now held: the upstream resolver, and a real request through the canonical middleware arriving with that title on the head.

## [0.17.0] - 2026-09-05

### Added

- **A record served under several locales now appears once per address in the sitemap.** A `<url>` element is what search engines count as a submitted address; an `<xhtml:link>` inside it is an annotation *about* that address. Every locale but one therefore went unannounced — at three locales, two thirds of the addresses. Each address now gets its own entry, all of them carrying the same complete alternate set including a self-reference, and the written count reports URLs rather than records.

- **The sitemap splits past the protocol's ceilings and publishes an index.** One file may hold 50,000 URLs and 50 MB, and a file past either is rejected whole rather than truncated. With `--path` the command now writes `sitemap-1.xml`, `sitemap-2.xml`, … beside it and a `<sitemapindex>` at the target as soon as either limit is reached; below them the output is unchanged, one `<urlset>` and no index. An index has to name each part by absolute URL, so the command takes `app.url` or the new `--base-url`, and fails rather than writing relative locations. Both ceilings are configurable under `polyslug.sitemap.max_urls` and `polyslug.sitemap.max_bytes`. Each part is written as soon as it is full, so a large table's peak memory is one part instead of the whole document.

- **`polyslugLastModified()` supplies the sitemap's `<lastmod>`.** It is the one hint of the three that search engines still act on — `<priority>` and `<changefreq>` are documented as ignored and stay absent. It returns `null` by default rather than `updated_at`, on purpose: a timestamp that moves on every write turns the field into noise, and the documented response is to disregard it for the whole site. `return $this->updated_at;` is the whole implementation wherever that column tracks the content. The method lives on `HasPolyslug`, so a hand-written `Sluggable` keeps working unchanged.

- **`polyslug:backfill --queue` can be routed off the default queue.** A backfill walks the whole table, so on the default queue it sits in front of every password reset the application has. `--on-queue=` and `--on-connection=` decide per run; `polyslug.backfill.{connection,queue,tries,timeout}` decides once. All four default to `null`, so an installation that names none keeps exactly the behavior it had.

### Changed

- **`og:locale` is written only in the form Open Graph defines.** With Laravel's default `app.locale = de` the bridge emitted `content="de"`, which is outside the `language_TERRITORY` format — a scraper that cannot parse the value does not read a language from it, it falls back to its own default. A locale without a territory now produces no tag at all, which loses the claim and nothing else. `polyslug.open_graph.locale_map` is how a site names the pairs it wants (`'en' => 'en_US'`); a locale that already carries one, like `pt_BR`, still passes through.

- **A robots directive outside the documented vocabulary is now refused.** `['noindex', 'nofollw']` used to render, and a crawler drops the token it does not recognize — so a typo in a directive whose only job is to restrict looked like it worked. Both halves are checked: the name, and the value for the four directives that carry one, since `max-image-preview:huge` is as inert as a misspelled keyword.

- **The published manifest describes the published tree.** The mirror carries no test suite and no workbench, so `require-dev`, `scripts` and `autoload-dev` all named a checkout that is not there — `composer test` on a public clone answered "Command 'test' is not defined", and the dev autoloader mapped four namespaces onto absent directories. All three are root-only keys that no consumer reads from a dependency. `CONTRIBUTING.md` and the testing guide now say where the suite lives and what happens to a pull request instead.

### Fixed

- **A trailing slash is now redirected to the canonical URL.** `/blog/hello_aB3xK/` and `/blog/hello_aB3xK` served the same document with `200` apiece: the router matches both with an identical slug parameter, so nothing about the slug was stale and the duplicate was the path itself. The redirect target is compared by path before it is issued, so a route declared with a trailing slash of its own cannot loop.

- **hreflang codes are BCP 47 even when the application locale carries an underscore.** Locales spelled the way Laravel's own language files spell them — `pt_BR`, `de_AT` — reached the page head, the sitemap alternates and the `laravel/head` bridge as `hreflang="pt_BR"`. Search engines read `pt-BR`; an annotation they cannot parse is dropped, and with it the reciprocity of every page that named it. The rendered code now carries the hyphen. URLs and the keys of every URL map keep the locale's own spelling, and Open Graph keeps the underscore it wants; `Polyslug::hreflangCode()` is the one place that decides.

- **The sitemap no longer submits addresses the site itself refuses or redirects.** A record that reports `polyslugIsGone()` answers 410, and a superseded record whose successor is visible answers 301 — both were listed as `<url>` entries all the same. `polyslug:sitemap` now applies the precedence the canonical middleware applies, in the same order, and the successor itself stays listed.

- **A supersede pointer naming the record itself no longer redirects in a loop.** `polyslugSupersededBy()` returning the very model it was asked on produced a 301 to the address just requested, which a browser follows until it gives up. The middleware treats it as no successor at all and serves the page.

- **Four documentation claims corrected against measurement.** `308` was described as preserving the request method, but only `GET` and `HEAD` are ever redirected, so it changes nothing here; `410` was called a faster de-index signal than `404`, which Google does not document — the value is the explicit signal; the sitemap command was said to keep a large table out of memory, but the rendered document is assembled in memory before it is written; and the canonical redirect was said to preserve the query string verbatim, where the framework sorts and re-encodes it. The sitemap page also says what the command leaves out, and shows how to keep the file current on a schedule.

## [0.16.0] - 2026-09-05

### Added

- **A gated model can choose its own robots directive.** A model that `polyslugIsRoutable()` keeps out of hreflang sets and sitemaps still gets a `robots` meta tag, because it can still render for whoever holds the link — and that tag was always `none`. Per spec `none` means `noindex, nofollow`, which is a stronger statement than the gate makes: the gate is about indexability and says nothing about whether the links on the page can be trusted. For a draft, a gated preview or a tenant-internal page, `noindex, follow` is usually what is meant.

  Override `polyslugRobotsDirective(?string $locale = null): string|array` on the model to answer for yourself, as a list (`['noindex', 'follow']`) or a string (`'noindex, follow'`) — both normalize to the same tag, casing and spacing included. The locale handed in is the one the gate refused, so a model gated in some locales and not others can answer per locale.

  **Nothing changes for anyone who says nothing.** The method lives on the `HasPolyslug` trait rather than on the `Sluggable` contract, so a model that does not override it keeps `none`, and an application implementing the contract by hand has no such method at all — the bridge detects that and keeps `none` there too.

  The answer must still keep the page out of the index: it needs `noindex` or `none`, and anything else throws `MisconfiguredPolyslug` instead of silently undoing the gate. An empty answer is refused for a sharper reason than a permissive one — `laravel/head` renders *no* robots tag for it, and a page without one is indexable by default. That vendor behavior is pinned in `tests/Feature/LaravelHeadContractTest.php` rather than read out of its source, and `tests/Feature/RobotsDirectiveTest.php` holds the rest.

## [0.15.0] - 2026-09-05

### Fixed

- **A published config no longer stops receiving new settings.** The provider merged its shipped defaults with Laravel's `mergeConfigFrom`, which is a single `array_merge` at the top level: it asks one question per top-level key — is it there? A host that published `config/polyslug.php` has every top-level key, so a setting added *inside* one of those blocks by a later release never arrived. The block the host published won whole.

  The failure was silent in both directions that matter. Nothing errored and nothing logged; the new setting simply read as `null`, so a feature added in a minor release was off for exactly the hosts that had customized that area, and a corrected default never took effect. This package has added keys inside existing blocks more than once, so the hosts affected are real rather than hypothetical.

  The merge now recurses through maps while replacing lists whole — a list is a host's complete answer, and merging into it would resurrect an entry they deliberately deleted. `tests/Feature/ConfigMergeDepthTest.php` holds both directions.

- **A token column is now byte-exact on MySQL, so a mixed-case alphabet keeps the entropy it promises.** `$table->string('token')` states no collation, so the column inherits the connection's, and on MySQL that default is case-insensitive. `TokenAlphabet` explicitly invites an application to pass its own alphabet to "get the entropy back", and its validation admits `A-Z`; an application that takes the invitation got a 62-character alphabet the database counted as 36. At the default length of 16 that is roughly a factor of 2^12.5 — about 6,000× — on `/go/{token}` — the one path `RandomTokenScheme` exists for. Its collision escalation is calibrated against 36^n as well, so it fired later than the real space warranted.

  Measured per engine with the schema line verbatim: PostgreSQL 18 and SQLite compare byte-exactly and let `abc123` and `AbC123` coexist, while MySQL under `utf8mb4_unicode_ci` resolved one to the other and rejected the second as a duplicate. The new migration is therefore scoped to MySQL, and it reads the column's real type and character set instead of restating them, so a consumer that widened or narrowed the column keeps it.

  Case-insensitive to binary only ever splits equivalence classes, so a unique index that held before still holds and no row is rewritten. One behavior does change on MySQL: `/go/ABC123` no longer resolves to the record holding `abc123`. That is the correction rather than a side effect. It never resolved on the other two engines, so the same request had two answers depending on what was underneath.

- **The sitemap and the page head no longer name different primary addresses for the same record.** `polyslug:sitemap` built its `<xhtml:link>` alternates in its own loop instead of reading `hreflangLinks()`, and the two things it did on its own were the two it got wrong: `<loc>` took the alphabetically first locale while the head announces `x-default` (the fallback locale), and no `x-default` alternate reached the sitemap at all. With locales `de, en` and a fallback of `en`, `<loc>` said `/de/…` and the head said `/en/…` — one package answering one question two ways, which is precisely the disagreement a reciprocal hreflang set exists to prevent.

  The command now reads `hreflangLinks()`, so the locale set, the URLs and the primary address all come from the one place that computes them. Sitemaps regenerate on the next run; nothing in a consumer's code changes.

- **Slug resolution no longer scans the whole table.** Every incoming URL asks for `sluggable_type`, `locale`, `scope` and `lower(slug)` together, and until now `lower(slug)` appeared in exactly one index: the partial unique index that carries the one-current-slug guarantee. A new, deliberately non-partial index on the same signature is added, and the difference is not marginal.

  Measured outside a transaction after `ANALYZE`: on PostgreSQL 18 with 100,000 slug rows the resolution went from a sequential scan discarding 99,999 rows at 18.5 ms to an index scan at 0.094 ms. On MySQL 8.4 with 20,000 rows, from `type=ALL` over 19,283 rows at 18.4 ms to `type=ref` over 1 row at 0.120 ms. The cost was linear in the number of slugs, so it grew quietly with the table.

  The cause is statistics rather than reachability, which is why the fix is a second index and not a rewritten query: a database gathers no expression statistics from a *partial* expression index, so `lower(slug) = ?` fell back to a default selectivity guess of 500 expected matches where there is one, and on that estimate a scan really is cheaper. The planner was choosing correctly from wrong numbers.

  The partial unique index is untouched and still carries uniqueness. The new one carries only the seek and the statistics. Existing installations get it from the migration; nothing else changes.

### Changed

- **A dependency-update PR can no longer swap a supported Laravel major for another; it can only add one.** `renovate.json` now pins `rangeStrategy: widen` for composer `require`. The default was not what it looked like: Renovate documents `auto`, but composer ships its own resolver that turns `auto` into `update-lockfile` for a plain caret range, and composer versioning delegates `update-lockfile` to `replace` as soon as the new version falls outside the range. The day a new Laravel major ships, that default rewrites `^13.0` to `^14.0`, and every application pinned to 13 is locked out by a bot PR nobody read as a support decision. `widen` writes `^13.0 || ^14.0` instead. In-range updates are unaffected either way, and `require-dev` keeps the default, because a toolchain may state its own floors as deeply as it likes.

## [0.14.0] - 2026-09-04

### Added
- **`preserveCase: true` — keep the slug in the writing it was given.** A slug is folded when it is generated, so `Octo-Org` was stored as `octo-org` and the original writing was gone: the page could not render it, and neither could any URL built from the route key. That matters for a record mirroring something case-preserving *and* case-insensitive at once, which is what a GitHub handle is — `github.com/Octo-Org` and `github.com/octo-org` reach the same account, and the page shows the writing its owner chose.

  Nothing else moves, and that is the point: the unique index and all three read paths already compare `lower(slug)`, so `Octo-Org` and `octo-org` remain one name, a second record still gets a disambiguating suffix, and `/Octo-Org`, `/octo-org` and `/OCTO-ORG` all still resolve. Opt-in, so a model that does not set it is unchanged. Pair it with `idLess: true`, which is where the case reaches the URL at all.

  It is refused together with `unicode: 'native'`, and the reason is measurable rather than stylistic: uniqueness folds with the database's own `lower()`, PostgreSQL folds non-ASCII letters and SQLite does not, so an unfolded native slug would collide on one engine and not on another. `unicode: 'ascii'` transliterates first, so every stored slug is ASCII and every engine folds it the same way.

## [0.13.0] - 2026-09-04

### Added
- **`ProvidesAddressLocales` — for a record served under more addresses than it has slugs.** Every URL set Polyslug builds (`polyslugUrls()`, the hreflang links and tags, the `<head>` tags, the `polyslug:sitemap` entries) came from `slugLocales()`, the locales that hold slug text. That's the right list for most models, and the wrong one as soon as a single slug is served under several addresses — a project that pins each slug to one locale on purpose, because slug sources are single-language user content, and still routes every record under a locale prefix. `slugLocales()` reports one entry there forever, so `/de/u/lena` appeared in no sitemap and in no hreflang set, and nothing failed to say so.

  Implement the interface to declare the addresses, and both lists follow, because both are built from the same call:

  ```php
  final class Account extends Model implements ProvidesAddressLocales, Sluggable
  {
      use HasPolyslug;

      public function polyslugAddressLocales(): array
      {
          return ['en', 'de'];
      }
  }
  ```

  Opt-in, like `BulkIdentityEncoder`: a model that does not implement it keeps deriving its locales from its slug rows, unchanged. A declared locale still passes through `polyslugIsRoutable()`, and one with no slug of its own reuses the default locale's — which is what lets a single slug serve several addresses.

### Fixed
- **A route default no longer ends up in the canonical redirect.** A route that pins a default its own URI never declares — `Route::get('pages/{page}', …)->defaults('locale', 'en')`, which is how a locale-aware application serves the default language under the clean unprefixed URL — had that value welded onto every redirect this middleware issued: `/pages/canonical?locale=en`. Where the request carried a query string of its own the result was `?locale=en?ref=news`, which isn't a valid URL. Bound route parameters include every route default, and a named parameter the path cannot hold is appended to the query string instead; only the parameters the route declares are passed now.

  This affected both redirects, the self-healing one and the supersede one, and it touched every renamed row of every model at once. The canonical redirect is where an address is declared binding, so a stray parameter there was creating a second address for the same page. A parameter the route really does declare is unaffected and still appears in the path, and a query string the client sent is still carried over unchanged.

- **Eager-loading `slugs` works on PostgreSQL.** `Page::query()->with('slugs')->get()` is the documented way to collapse the per-model slug reads, and on PostgreSQL it failed the whole query with `operator does not exist: character varying = integer`. `sluggable_id` is a varchar, because a polymorphic key has to hold UUIDs and ULIDs as well as integers, and Eloquent writes the keys of an integer-keyed model straight into the statement text rather than binding them — so the comparison was varchar against a bare integer literal, which PostgreSQL has no operator for. The keys are bound now.

  Reading a slug without eager-loading was never affected, which is why this survived a release: a single bound `where "sluggable_id" = ?` compares cleanly, so the only broken path was the one that makes a list view cheap. MySQL and SQLite were never affected either — both compare across the two types on their own. If you are on PostgreSQL and dropped `with('slugs')` to get a page working again, you can put it back.

## [0.12.0] - 2026-08-27

### Added
- **`Route::polyslug()` works after `middleware()`, `prefix()`, `name()` and `domain()`.** Until now the macro existed only on the router, so the bare `Route::polyslug('/pages/{page}', …)` worked and every grouped form — `Route::middleware('auth')->polyslug(…)` — threw `BadMethodCallException` naming a framework class you never wrote. That is the shape you reach for the first time a route needs authentication or a prefix. The route still receives the group's own middleware plus `SubstituteBindings` and `polyslug.canonical`, in that order, so self-healing cannot silently no-op from a mis-ordered stack.

### Changed
- **`$action` on `Route::polyslug()` is typed `Closure|array|string|null` instead of `callable`.** The old type was wider than the framework itself: the one callable shape that is none of those three — an invokable object — never reached a controller, it fataled while the route was being registered. Nothing that worked before stops working; the signature simply stops advertising something that could not.

### Fixed
- **Every alternate Open Graph locale is announced, not just one of them.** `Head::polyslug($model)` on a model in three or more languages shipped a single `og:locale:alternate` tag, whichever locale sorted last. The `hreflang` set beside it was complete the whole time, so the page told crawlers about every language version and told Open Graph about one. A model in two languages was never affected: with a single alternate there is nothing to collide with.

  If you rely on `Head::polyslug()` for multilingual pages, the rendered `<head>` gains one `og:locale:alternate` tag per other locale after this upgrade. An alternate locale you declared by hand is kept, and a locale named twice still renders once.

### Documentation
- **The `laravel/head` integration now states its Laravel floor.** Every published `laravel/head` requires Laravel 13.17 or newer, while Polyslug itself requires 13.0 — so on an application pinned below 13.17 this one optional integration is unavailable and the rest of Polyslug is not. Composer refuses the install rather than degrading quietly, but nothing said so beforehand. The install section, the version policy, both requirements lists, the Composer `suggest` text and the bundled adoption guidance all carry it now.
- **The canonical claim is narrowed to what holds.** One resolver still means the canonical URL, the `hreflang` set and the sitemap cannot disagree about *which address* a record has. They can differ in *form*: `laravel/head` normalizes the canonical it renders — forcing HTTPS and stripping a trailing slash by default, either of which your application can flip — while the alternates and `polyslug:sitemap` are emitted verbatim. A resolver built on `route()` or `url()` over HTTPS produces no difference at all.
- **The `alternates()` merge caveat is stated.** A hand-written entry survives only for a locale Polyslug does not know. The merge is per key and Polyslug writes second, so for a locale the model is routable in, the resolver URL replaces the hand-written one.
- **The unbound-resolver table names the tag that still ships.** Without a bound `PolyslugUrlResolver` the canonical tag, the `hreflang` set and `og:locale` are withheld — but the `robots` directive is not, because it needs no resolver. A gated model stays out of the index either way.
- **Keeping drawn tokens out of exception messages.** On Laravel 13.27 or newer, `mask_bindings_in_exception_messages` on the connection holding `polyslug_tokens` leaves the query placeholders in place, so a token cannot reach an exception message, a `failed_jobs` row or an APM span. It is your application's connection setting, so Polyslug documents it rather than shipping it.

## [0.11.0] - 2026-08-27

### Added
- **`slugless: true` — the URL is the token alone.** The mirror image of `idLess`: that one
  drops the id and keeps the slug, this one drops the slug and keeps the id, so
  `/lists/my-shopping-list_k3f9dlq7xm2bv4tc` becomes `/lists/k3f9dlq7`. There is no
  delimiter in front of it — with one part, a separator is a character in every URL that
  says nothing, and on a model chosen for short URLs that is the whole cost of the feature.

  It declares no `source`, so renaming the record cannot change its URL. That is the
  property a shared or printed link needs and a descriptive URL cannot give.

  Switching an existing model does not break its published links. A request for the old
  `my-title_TOKEN` form resolves through the token at the end of it and is `301`ed to the
  short form, the same self-healing the package already gives across an encoder change.
  Without that second decode pass, turning the option on would have turned every published
  link into a `404` — including links in print, on other people's pages, and in a search
  index.

  Four options are refused rather than ignored on a slugless model, because each would do
  nothing and an option that silently does nothing reads as a behavior you have changed:
  `idLess` (together they leave nothing to route on), `maxLength`, `reserved` and `source`.

- **The random token's length and alphabet are settings.** `polyslug.random_token.length`
  (default 16, unchanged) per application, or `#[Polyslug(encoderOptions: ['length' => 8])]`
  for one model — so a list whose URL people retype can be short while the rest of the
  application stays long.

  **The length is a FLOOR, not a fixed width**, and that is what makes a short one a real
  choice rather than a trap. Two characters is 1,296 tokens; a thousand records in, every
  draw is a coin flip, and at 1,296 the space is gone. A width that keeps colliding now
  yields to one character more — 36x the space — instead of throwing. Without that, the
  failure mode was a `CouldNotIssueToken` raised from `encode()`, which runs while a URL is
  being *rendered*: a 500 on a `GET`, months after the setting was chosen, on whichever
  record happened to be next.

  Changing the setting is safe on a live application. A token is looked up in
  `polyslug_tokens`, never recomputed from the key, so every URL already issued keeps
  resolving and only new records use the new length.

- **`SequentialTokenEncoder` — the shortest URL there is.** The same stored mapping as the
  random encoder, filled by counting instead of drawing: `0`, `1`, … `z`, then `00`. A
  hundred records still fit in two characters, which is what a link shortener is after.

  It is predictable, and that is the entire trade rather than an oversight: the token after
  `k3f8` is `k3f9`, so the set can be walked, and the token reports how many records exist
  and roughly when this one appeared. Fine for public content nobody is hiding; wrong for
  anything the URL alone protects, which is why it is not the default and why a minimum
  length is not offered as a fix — that moves where the counting starts, it does not
  scatter what follows.

  The counting is bijective, so it walks every width completely before growing one. An
  ordinary base conversion follows `z` with `10` and can never emit `00`, discarding about
  3% of every width — and the discarded tokens are exactly the shortest-looking ones.

  Switching to it over a table full of random tokens starts counting past them rather than
  colliding with them, so every existing URL keeps resolving.

- **The `/go` short link takes its own scheme, length and alphabet** under
  `polyslug.short_links`, separately from the identity token — a link that is printed,
  spoken and put on a QR code wants a different trade from the one inside every URL. Bind
  `Polyslug\Contracts\TokenScheme` to replace the scheme entirely.

  A null length takes the *scheme's* default (10 random, 1 counted) rather than one number
  for both, because ten random characters is a short link while ten counted ones is
  `0000000000` for the first record.

- **A configurable alphabet on both schemes.** `0-9a-z` by default; pass your own to drop
  the characters people confuse when reading a code off paper, or to widen it. It must be
  made of URL-unreserved characters and must not repeat one — a repeat makes the numbering
  ambiguous, and the second record handed an ambiguous token would lose to the unique index
  on every attempt, forever.

- **`polyslug:doctor` reports whether a `PolyslugUrlResolver` is bound.** Reported, never
  failed: an application that uses none of the three features needing it needs no resolver,
  and failing a doctor run over an unused contract teaches people to ignore the command. What
  the report removes is the guessing — two of those three fail silently without the binding,
  and `/go` structurally cannot say why without turning its `404` into an existence oracle.

- **`polyslug:doctor` checks the token settings and reports how full each token space is.**

  The settings check builds every configured scheme up front. Without it, a length of zero
  or a `/` in an alphabet first refuses while a URL is being *rendered*, in production, for
  a setting that shipped with a green test suite — because nothing in a test suite renders
  a URL for a record that does not exist yet.

  The space report names each width that is at least a quarter full, and says the
  consequence is longer URLs rather than an outage, so nobody reads it as an emergency:

  ```text
  ! identity tokens: 400 of 1,296 2-character tokens are taken (31%).
    New tokens widen to 3 characters as this fills.
  ```

  It never fails the run. A filling width is not a fault, and on a counted scheme it is
  exactly what is supposed to happen.

### Fixed
- **A model naming a stored-token encoder explicitly paid one query per rendered row.**
  `RandomTokenEncoder` memoizes what it reads, which is what makes a rendered list cost one
  query instead of one per row — but the class was bound nowhere, so
  `#[Polyslug(encoder: RandomTokenEncoder::class)]` had the container build a *fresh*
  instance on every resolution, with an empty memo, while the default path (through the
  `IdentityEncoder` singleton) kept exactly one. `polyslugPreload()` was affected the same
  way: it groups models by the object identity of their encoder, so it filled a memo that
  was discarded before the first route key was built. Both encoders are now bound as
  singletons, and per-model settings resolve to one shared instance per distinct setting.

- **`shortLink()` could throw on a token collision instead of recovering.** It was a
  `firstOrCreate`, which recovers from one of the table's two unique indexes: it retries by
  re-reading the *target*, so a row rejected because another record already held that
  *token* found nothing on the re-read and surfaced as a query exception. At ten random
  characters that is unreachable, which is why it never showed — at four it is a matter of
  time, and it would land while a page is rendering. It now claims in a loop like the
  identity store, recovering from either index.

### Changed
- **BREAKING — `Sluggable` gained `seedSlug()` and `polyslugSeed()`, and a backfill no longer
  takes a name another record still holds.** A model using `HasPolyslug` needs no change; a
  consumer implementing the interface *without* the trait has two methods to add.

  `reclaimActive` is a property of the MODEL, but taking a name is a property of the WRITE. A
  webhook carries a handover the source has already made, so taking is correct. A backfill
  carries no such thing: two records that already exist and both want one name are a conflict
  in the data, and taking there decides who owns the address by the order the rows came back —
  green, silent, and visible only to whoever follows the old URL. `polyslug:backfill` had
  exactly that defect; it seeds now.

  A named method rather than a flag on `setSlug()`, because `reclaimActive` requires `reclaim`
  and a boolean could therefore only ever turn the behavior *off* — a parameter whose `true`
  means "do whatever the model already said" is a trap. It was trait-only first, and the
  package's own backfill settled it: a capability the contract does not carry cannot be called
  on anything typed as `Sluggable`, not by a consumer and not by this package. Half a
  capability is worse than a named break in a release that already carries one.

  On a model that is not `reclaimActive` seeding and claiming are identical, which is what
  makes `seedSlug()` safe to call without first checking how each model is configured.
  Reported by a consumer during a capability diff (chronik).

- **BREAKING — a stored token now belongs to a RECORD, not to an id.** `polyslug_tokens`
  gained a `key_type` column and its unique index widened from `key_value` to
  `(key_type, key_value)`, so `RandomTokenEncoder` and `SequentialTokenEncoder` keep one
  token space per model type. `Page#1` and `Wishlist#1` no longer share a token, and a token
  addressed to another model type resolves to `null` — a clean `404` — instead of an id this
  model would look up in its own table.

  The table was keyed by the primary-key **value** alone, so every pair of tables collided at
  id 1. Resolution stayed correct, because the route names the model type; what it cost was a
  property `RandomTokenEncoder` advertises when it calls its output unguessable. Knowing one
  model's URL was enough to construct every other Polyslug model's URL for that id, and from
  there the resolution gate was the only thing left standing. `polyslug_short_links`, solving
  the same problem one table over, already keyed on the full target — only one of the two
  tables separated the model types, and neither said so.

  **Run `php artisan migrate`. Existing tokens are migrated, not reissued** — the migration
  reads each token's owner from `polyslug_slugs`, which already records the pair, so a record
  keeps the token its published URLs contain. Where several types claim one id, the oldest
  slug row wins: whoever published first is the answer that breaks the fewest links. A token
  that cannot be attributed stays in the untyped lane, keeps resolving, and is adopted by the
  record it belongs to the first time that record renders a URL. Skipping the migration would
  leave no row matching `(type, id)` and mint a new token for every record on the first
  render — see [Upgrading to 0.11](https://docs.pushery.com/polyslug-for-laravel/features/identity-encoders#upgrading-to-011).

  A custom encoder needs no change. The capability is opt-in through the new
  `StoresTokensPerRecord` contract, which **extends** `IdentityEncoder` rather than replacing
  it: a computed token — Sqids, a UUID, the raw key — is a function of the key alone and
  cannot be given an owner, so it is asked for rather than assumed.

- **`source` is optional on the `#[Polyslug]` attribute**, so a slugless model can declare
  none. Omitting it on any other model is refused with `MisconfiguredPolyslug` rather than
  producing a silent empty slug for every record — the attribute signature cannot express
  "required unless another flag is set", so the config constructor states it.

- **Random tokens are drawn uniformly from their alphabet.** They were `Str::lower(Str::random())`,
  which folds a 62-character draw onto 36 characters, so a letter landed twice as often as a
  digit — about 5.12 bits per character instead of 5.17. At sixteen characters that is noise,
  which is why it never mattered; at the short lengths this release supports it is the
  difference between the documented token space and the real one, and a security parameter
  that overstates itself is worse than a shorter one that does not. Existing tokens are
  unaffected — they are stored, not recomputed.

- **A token claim now re-attempts eight times rather than five.** A scheme widens its output
  every three lost draws, so five attempts reached one widening and two draws into the
  second. Eight reach the second widening completely, after which the space is 1,296x the
  one that was full.

### Documentation
- **New [The URL resolver](https://docs.pushery.com/polyslug-for-laravel/features/url-resolver)
  page, written because a developer lost time to its absence.** Short links, sitemaps and the
  `laravel/head` tags all need one class the application writes itself — the package cannot
  know your routes — and the short-links page mentioned it in a subordinate clause twenty
  lines below a setup that looked complete. Following that setup yields a `404` on every link.

  The asymmetry is what made this worth fixing rather than tidying: `polyslug:sitemap` prints
  an error naming the contract when it cannot build a URL, but `/go` cannot — its `404` has to
  stay indistinguishable from an unknown token, or the route becomes an existence oracle. The
  feature with the *worse* failure signal had the *thinner* documentation.

  The short-links page now opens with the three setup steps in the order they are needed, the
  quick start names the step before anyone can trip over it, and the resolver page states what
  each of the three features does when the binding is missing. The Boost skill and guideline
  carry the same, since a consuming app reads those out of `vendor/`.

- New **[Token-only URLs](https://docs.pushery.com/polyslug-for-laravel/features/token-only-urls)**
  page: when the shape fits, how to choose the length and the scheme, what switching costs,
  and what the option refuses.
- **`maxLength` now says what it does not do.** It trims the slug and has never touched the
  token after it, and the reference said only "trim to at most this many characters" — which
  is the natural place to look when a URL is too long, and the wrong one. Named in the
  attribute reference, the model reference, the Boost guideline and the skill.
- The token table's reference, the encoder page and the Boost skill all describe the new
  `(morph type, id)` key, the untyped lane that keeps pre-0.11 URLs resolving, and why the
  owner column is `NOT NULL` rather than nullable. **[Upgrading to 0.11](https://docs.pushery.com/polyslug-for-laravel/features/identity-encoders#upgrading-to-011)**
  is new, and it leads with the one thing that must not be skipped.

  The finding these pages used to state as the current behavior was first pinned by a test,
  and that test is now the proof of its fix rather than of its existence.


## [0.10.0] - 2026-08-23

### Security
- **A supersede redirect now sends its successor through the resolution gate before naming
  it.** 0.7.0 reversed this middleware so a canonical redirect could no longer overtake the
  application's authorization — that fixed *when* the redirect is decided. It did not fix
  *whose* row is named in it, and for a `polyslugSupersededBy()` redirect those are two
  different rows.

  The requested model is gated twice over: route binding resolved it through
  `polyslugResolveQuery()`, and the application then answered 2xx for it. The successor is
  gated by neither. It arrives as a return value, not as a resolution, and its route key —
  in practice its title — was written into a `Location` header without anyone asking whether
  the requester may see it. A consumer whose gate is closed and whose action authorizes
  correctly could still disclose a foreign row's title.

  A successor the gate rejects now produces no redirect for that parameter; the
  application's own response is returned untouched, and the ordinary self-heal redirect for
  the row the request legitimately holds still runs. Nothing is asked of the consumer, which
  is the same reasoning 0.7.0 gave for deferring the resolution gate: the method is named
  `supersededBy`, not `supersededByIfVisible`, and a security property does not belong in a
  method whose signature gives no hint of it.

### Added
- **`reclaimActive: true` takes a name its previous owner still holds.** `reclaim` frees a
  *retired* name; it does nothing about one that another record still holds actively — and that is
  exactly the state a mirror lands in when its upstream events arrive out of order.

  Concretely: upstream renames A from `x` to `y` and gives `x` to B, which is two deliveries.
  In the expected order A is already retired when B arrives and `reclaim` handles it. If A's
  delivery is lost — and a webhook sender does not always retry — A still holds `x` actively
  when B arrives, B is named `x-2`, and the canonical URL disagrees with the thing it mirrors
  from then on. The rejection surfaces as a constraint error on a webhook that retries forever,
  so the cost lands in a queue rather than in a screen.

  The takeover retires the previous owner's row inside the same transaction as the insert, so
  the name is never owned by nobody; the retired row stays as history and its old URL still
  resolves and 301s.

  ⚠️ **The displaced record is left with no current slug for that locale** until its own source
  is synced — the package cannot know what it should be called instead. Listen for the new
  **`SlugReclaimed`** event, which names the claimant and the displaced record by type and key.

  Off by default, and it requires `reclaim` (and therefore `idLess`) — rejected otherwise with
  `MisconfiguredPolyslug`. For an app-owned name a takeover would be a way to seize someone
  else's published URL, which is why none of this is the default.

- **`polyslugReservedWords(array $inherited): array` — a model can now filter, replace or clear
  the reserved-word list it inherits.** Until now the list could only ever be added to:
  `polyslug.reserved.global` plus, with `from_routes` on, the first segment of every registered
  route, plus the model's own `#[Polyslug(reserved: [...])]` — with no way to say "that list is
  not mine".

  For a model that sits behind a prefix by construction — `/@{owner}/{repo}` — a slug can never
  shadow a route, because the prefix separates the namespaces completely. Every inherited
  reservation there is a false positive, and it fails silently: the generator appends a counter
  suffix rather than refusing, so a legitimately named record becomes `api-2`. For externally
  assigned identifiers, `api`, `docs`, `demo` and `media` are not the edge case, they are the
  middle of the distribution.

  Return the argument unchanged (the default) and nothing about the current behavior moves.
  Return `[]` to opt out entirely. The seam is offered the ROUTE-DERIVED words too, which is the
  half a per-model `reserved: [...]` could never reach. The only previous escape was rebinding
  `SlugGenerator` — rebuilding the collision core to be rid of a list.

  It lives on the `HasPolyslug` trait, not on the `Sluggable` contract, matching
  `polyslugResolutionScope()`: a model seam the trait itself calls, so implementing the contract
  directly is unaffected.

- **`polyslugResolveSelf()`** — re-resolves an instance through its own resolution gate,
  returning the same row when the caller may see it and `null` when it may not. It is what
  the supersede fix above is built on, and it is useful anywhere a model was obtained
  outside a resolution path and is about to be disclosed.

  ⚠️ It is declared on the `Sluggable` contract. Models using the `HasPolyslug` trait — the
  documented pairing — get it for free. A class that implements the contract *without* the
  trait must add it.

## [0.9.0] - 2026-08-21

### Added
- **`reclaim: true` releases a retired slug-only name instead of reserving it forever.** By
  default a retired slug stays reserved, so renaming `api` to `api-v2` leaves `api` blocked
  and a later record asking for it gets `api-2`. That is the right guarantee for a name the
  application owns — without it, a rename becomes a way to take over a URL somebody else
  published.

  It is the wrong guarantee for a name the application only mirrors. When an external source
  reassigns the name, reserving it makes the canonical URL disagree with the thing it
  mirrors. With `reclaim`, the newcomer takes the name, the previous owner keeps the name it
  moved to, and the URL serves the new owner; the retired row stays as history.

  Off by default, and refused outright without `idLess` — on a model whose URL carries an
  encoded id a retired slug is already free to reuse, so the flag would silently do nothing.

- **The badge row is held to what the gate enforces, by a test.** Every number a static
  badge claims is derived from `composer.json` and compared against it: the coverage and
  type-coverage floors, the mutation floor, and the required test-framework major. Lower a
  floor without editing the badge — or edit the badge without moving the floor — and the
  suite goes red. The optional badges are checked in both directions, so the README can
  neither advertise a capability the repository lacks nor stay silent about one it has.

### Changed
- **The README badge row follows the shared canon: identity above, quality below.** The
  identity row (version, PHP, Laravel, license) is now sourced entirely from Packagist, and
  a second row states what the quality gate actually enforces — test framework, line
  coverage, type coverage, static-analysis level and code style — followed by the two claims
  this package can back up: that the suite runs against real PostgreSQL and MySQL servers,
  and the mutation floor it holds.

  A hardcoded badge is a fact frozen at the moment someone typed it. The license badge in
  particular now reads the license from Packagist, so it cannot go on asserting MIT after
  the license changes.

### Fixed
- **A slug-only URL on a scoped model resolved across scopes.** The write path separates by
  `scope`; the lookup did not, so on a model scoped per owner or tenant two records could
  legitimately hold the same slug and `/@alice/toolkit` could resolve to Bob's record. The
  resolution gate does not cover this — it filters by what the environment says is visible,
  while a scope sitting in a path segment is an argument of the resolution, and a gate that
  never receives it cannot separate by it.

  Models hand the scope over by overriding `polyslugResolutionScope(): ?array`, and the
  lookup is then filtered by exactly the key the write path stored — one builder for both
  directions, so the two cannot drift.

  **The default is unchanged**: a model that does not answer resolves exactly as before. Set
  `polyslug.resolution.require_scope` to refuse a scoped slug-only lookup that names no scope
  rather than returning whichever row sorts first. The damage never came from the missing
  filter but from its absence looking exactly like a hit.

  The docblock on that method claimed the opposite outcome, and the shipped Boost skill said
  the gate covered it; both are corrected.

## [0.8.2] - 2026-08-19

### Changed
- **A slug write asks the database for the current row once, not twice.** `polyslugSync()`
  read it in order to decide *whether* to write, and `writeSlug()`'s first attempt read the
  identical row again in order to decide *what* to write — back to back in one call stack,
  with nothing in between that could change the answer. The first answer is now handed on.

  Measured on SQLite, per model: creating one costs 1 relation read instead of 2, renaming
  one costs 1 instead of 2, and `polyslug:backfill` costs 1 instead of 2 per row it fills.

  **The retry loop is untouched, and that is the point of the three-state hand-off.**
  `$known` is consumed by the first attempt only; every later pass re-reads, because a
  retry exists precisely *because* another writer moved the row. "I looked and found
  nothing" stays distinguishable from "I did not look", since the backfill path — rows with
  no slug whose source has not changed — would otherwise get its duplicate read straight
  back.

  A save that leaves the slug source alone is unchanged at one read: it has to learn whether
  a current row exists before it may skip the write, and no ordering of that test removes
  the question.

- **The manifest gains a process timeout and widens the profiler's directory scope.**
  `config.process-timeout: 0` removes Composer's 300-second kill from every script it
  starts, so a long-running script reports its own result instead of being cut off and
  blamed on a timeout.

  The scripts that need a profiler now run through `@php -d pcov.directory=.`. pcov reads
  only within its configured directory scope, and with that scope left unset it never
  reached `config/` or `database/`, so those directories looked untouched while being
  fully exercised. Scope and source set are one pair: either without the other describes
  something that is not the case.

  **Nothing a consumer installs changes.** `require`, the source, the config, the
  migrations and the published `resources` are byte-identical to 0.8.1; what moved is
  `scripts`, `config` and one `require-dev` constraint, none of which a consuming
  application resolves.

- **`CONTRIBUTING.md` states the toolchain's PHP floor.** The package installs on 8.4.0, but
  working on it needs **8.4.1** — Pest 5 pulls in `symfony/process`, which requires `>=8.4.1`.
  On exactly 8.4.0 `composer install` fails naming `symfony/process` rather than Pest, which
  sends people looking in the wrong place. Nothing about what the package requires changed.

- **`laravel/head` is now developed against `^0.2.0`.** The optional companion released
  0.2.0 (Inertia SSR gateway support, an Octane + Inertia fix, and a link-attribute
  injection fix). Every arm of the bridge canary still passes against it — canonical,
  the locale⇒URL alternates map with `x-default`, `hiddenFromRobots`, the named `locale`
  argument on `og()`, `meta(property: true)` and the merge-rather-than-replace behavior of
  repeated `alternates()` calls — so `src/Support/PolyslugHead.php` needed no re-fit. The
  release adds capability on the transport side rather than the document side, so there is
  nothing new for Polyslug to feed it. `laravel/head` remains a suggestion, never a runtime
  requirement.

## [0.8.1] - 2026-08-04

### Changed
- **The manifest no longer declares development-only patching.** Until now the package
  applied a local patch to its own mutation runner: `pest-plugin-mutate` read the
  `--coverage-php` report as an object, and `php-code-coverage` 14 writes an array, so a
  mutation run died before the first mutant. That fix shipped upstream in
  `pest-plugin-mutate` v5.0.1, so the patch is gone — together with
  `cweagans/composer-patches`, which was required for that one patch and nothing else, its
  `extra` keys and its plugin allowance.

  A version floor replaces it: `pestphp/pest-plugin-mutate: ^5.0.1` in `require-dev`. The
  patch guaranteed the behavior whatever version resolved; without it only a floor does,
  and the plugin arrives through `pest` transitively, where nothing else would pin it.

  **Nothing a consumer installs changes.** `require`, the source, the config, the
  migrations and the published `resources` are byte-identical to 0.8.0; the entries that
  moved are `require-dev` and one `extra` key, neither of which a consuming application
  resolves. It is recorded here because the manifest is a shipped file, and a shipped file
  that changes deserves a version rather than a silent amendment to the last one.

## [0.8.0] - 2026-08-03

### Added
- **`Model::polyslugPreload($models)`** warms the identity tokens for a whole set in one
  round trip — the companion to eager-loading `slugs`. That removes the per-model *slug*
  query; this removes the per-model *token* query, which is what the default
  `RandomTokenEncoder` costs the first time each row is encoded. Together, a rendered list
  of links issues no query per row at all:
  ```php
  $pages = Page::query()->with('slugs')->paginate();
  Page::polyslugPreload($pages);
  ```
  It is a no-op on an encoder that derives its token from the key alone (Sqids, UUID, ULID,
  the raw key), and deliberately a silent one — the point of an optimization hint is that you
  can write it without first knowing which encoder is configured.
- **`Polyslug\Contracts\BulkIdentityEncoder`**, implemented by `RandomTokenEncoder`. It is a
  **second** interface extending `IdentityEncoder` rather than a new method on it, so an
  encoder you wrote yourself keeps satisfying its contract untouched; callers fall back to
  `encode()` per key when it is absent. Its result is required to be identical to encoding
  one key at a time — same tokens, same collision handling — so it optimizes the round trips
  and never the guarantees.

## [0.7.0] - 2026-08-03

### Added
- **Eager-loading the `slugs` relation now makes route keys free.** `Model::with('slugs')`
  used to be worse than nothing: `currentSlug()`, `polyslugRouteKey()` and `slugLocales()`
  went through the relation *builder*, so every read issued its own `SELECT` and the eager
  load was one extra query nobody used. They now read the loaded collection, which turns a
  rendered list of links from one query per model into one query for all of them — and with
  it every caller built on top: `polyslugUrls()`, `hreflangLinks()`, `hreflangTags()`,
  `sitemapAlternateTags()`, `Head::polyslug()` and the sitemap command, all unchanged.
  ```php
  $pages = Page::query()->with('slugs')->paginate();  // links now cost nothing extra
  ```
  - **Writes deliberately do not use it.** `polyslugSync()` and `setSlug()` always re-read
    the current row, because a write decides against what is current *now* — and the write
    path re-asks inside its retry loop precisely because another writer may have moved the
    row in between.
  - `slugHistory()` also keeps querying: the natural eager-load recipe narrows the relation
    to current rows, so answering history from it would report an empty past — a wrong
    answer wearing the costume of a fast one.

- **`composer test:affected`** runs only the tests that exercise the code you just changed,
  via Pest's test-impact analysis (`pest --tia`). It is meant for the edit-run loop while
  contributing; the full suite stays the one that decides. Pest's `--dirty` filter does not
  cover this case: it keeps only changed files under `tests/`, so changing a file in `src/`
  and nothing else selects no tests at all rather than the ones that run it.

### Security
- **A canonical redirect can no longer overtake the application's own authorization.**
  `EnsureCanonicalSlug` used to answer before the route action ran, so on a stale slug it
  sent a `301` whose `Location` header was built from the resolved row's canonical slug —
  and a slug is usually the title. On a model whose `polyslugResolveQuery()` is still the
  open default, any slug resolves to any row, so a request the application would have
  refused received the answer anyway, in a header. The middleware now decides what it
  would say **before** the action runs and says it only **after**, and only over a
  successful (2xx) response; a refusal, or a redirect the action issued itself, is
  returned untouched.
  - The same deferral covers the two shapes the original report missed: a
    `polyslugSupersededBy()` redirect leaked the **successor's** title the same way, and a
    `polyslugIsGone()` `410` was a state oracle — it separated "exists and was withdrawn"
    from "no such row" for a row the request was never allowed to see. All three now
    return whatever the application returned.
  - **Neither the resolution gate nor middleware order could have fixed this**, which is
    why it needed a behavior change. Route binding runs through the same gate, so a bound
    model has already passed it; and `Route::polyslug()` wires
    `[SubstituteBindings, polyslug.canonical]` into the route, where Laravel's priority
    sort does not lift an unprioritized `Authorize` in front of them — so even a consumer
    who correctly writes `->middleware('can:...')` was affected. Authorization performed
    inside the action had no escape at all.
  - **What this costs:** on a request that will be redirected, the route action now runs
    and its response is discarded. A `GET` action should have no side effects, but "should"
    is the operative word — a view counter will now count a request that ends in a 301.
    That is the deliberate trade: a redirect that overtakes an authorization is more
    expensive than an action that runs once too often.

### Fixed
- **Documentation that described code other than the code that shipped.** Found by reading
  the public surface out of the source and comparing it against every document that
  describes it, so these are corrections rather than polish:
  - The Boost skill printed a `polyslug:backfill` invocation that cannot run — the model
    class is a required argument, not an option, and `--locale` was missing. It also
    described `polyslug:doctor` without its resolution-gate report and left `redirect.status`
    out of the config-key list; `polyslug:doctor`'s own description had the same omission.
  - A failed slug write was documented as "rolled back". It is not: the write commits and
    restores the demoted row in place, deliberately, so it never depends on a nested
    savepoint. The promised outcome — the model keeps its previous slug — was always
    correct. The exceptions reference also told readers to inspect `getPrevious()`, which is
    always `null` here, because a lost race is a return value rather than a thrown error.
  - `Sluggable`'s own docblocks, the text an IDE shows, described a narrower contract than
    the code honors: `polyslugRouteKey()` returns the path alone on an `idLess` model, and
    `polyslugUrls()` also filters on `polyslugIsRoutable()`.
  - `reserved.from_routes` was the only config key with no inline comment, against that
    file's own promise that every option carries one.

## [0.6.0] - 2026-07-31

### Added
- **Optional `laravel/head` integration.** With Laravel's `<head>` package installed,
  `Head::polyslug($model)` writes the four head facts Polyslug is the authority on: the
  canonical URL (from the bound `PolyslugUrlResolver`, not the request), the reciprocal
  `hreflang` set, the Open Graph locale set, and `robots: none` for a model
  `polyslugIsRoutable()` keeps out of the routable set. It writes nothing else — title,
  description, cards and structured data stay the application's.
  - The canonical URL is the reason it exists. `laravel/head` falls back to the request
    URL, so on a route without the `polyslug.canonical` middleware — where a stale slug
    renders instead of redirecting — it names the outdated URL as the authority.
  - The robots directive closes a quieter leak: a gated model still renders for whoever
    may see it, so without it a single shared link is enough to index a hidden page.
  - `laravel/head` stays optional in both directions. It is a `suggest`, the macro
    registers only behind `class_exists()`, and Polyslug's runtime dependency set is
    unchanged.

### Fixed
- **Both Laravel Boost artifacts still named `SqidsEncoder` as the encoder default.** The
  default became `RandomTokenEncoder` in 0.5.0, and Boost reads these files out of
  `vendor/` to advise inside consuming applications — so the one setting where stale
  guidance is dangerous rather than merely wrong was being handed to an assistant as
  current. Both now name the real default and say what `SqidsEncoder` actually exposes
  (primary key, creation order, growth rate) instead of the vaguer "obfuscation, not
  security".

### Changed
- **The test toolchain moved to Pest 5**, and the browser toolchain to Playwright 1.62.1
  together with the CI image that bakes the matching browser binaries. The two are a pair:
  moving the npm client without the image is what makes a browser step fail with
  "Executable doesn't exist".
- Development dependencies were refreshed within their constraints. `composer.json` now
  also carries a `suggest` entry for `laravel/head` — the package's own runtime
  requirements are unchanged and still consist of slim `illuminate/*` components plus
  `sqids/sqids`.

## [0.5.1] - 2026-07-26

### Documentation
- **The installation page advertised two publish tags that no longer exist.**
  `--tag=polyslug-views` and `--tag=polyslug-lang` were removed in 0.4.0 along with the
  placeholder views and translations, so anyone following the documented steps got an
  error. It now lists the two real tags and the umbrella one, and says why there is
  nothing else to publish.
- The configuration reference still showed `SqidsEncoder` as the encoder default — the
  one setting where stale documentation is dangerous rather than merely wrong.
- `CouldNotIssueToken` (added in 0.5.0) has an exceptions-reference entry.
- `polyslug:doctor` was documented as checking encoders and indexes only. The
  resolution-gate report added in 0.5.0 is now described in both the command reference
  and the diagnostics guide — including that it reports without failing, which is the
  part that decides how a reader should act on it.

No code changes; this release is documentation only.

## [0.5.0] - 2026-07-26

### Fixed
- **`RandomTokenEncoder` no longer 500s on a concurrent first render.** `encode()` did a
  read-then-write against a unique index, so two requests rendering the same
  never-before-encoded model both missed the lookup and both inserted — the loser took a
  constraint violation, and because `encode()` runs on the URL-render path that surfaced
  as an intermittent 500 on a `GET`. The loser now adopts the winner's token, so both
  requests emit the same canonical URL. Proven against real PostgreSQL and MySQL 8.4.
- **The same fix survives MySQL's REPEATABLE READ.** A caller encoding inside
  `DB::transaction()` could not see the row it kept colliding with; the retry now reads
  `FOR UPDATE` after a lost attempt. PostgreSQL never exhibited this, which is why the
  proof runs on both engines.

### Changed
- **`RandomTokenEncoder` is the default encoder.** `SqidsEncoder` remains fully
  supported, but its token decodes straight back to the primary key — every URL leaked
  the key, the creation order and the growth rate. That is a trade worth making
  deliberately, not one you get by not deciding.
- `polyslug:doctor` now reports every registered type that never overrode
  `polyslugResolveQuery()`. Those models resolve any slug to any row — correct for public
  content, a silent authorization bypass for anything owner-scoped. It reports and still
  exits successfully: the check makes the choice visible, it does not make it.

### Removed
- **Breaking.** `routes/polyslug.php` and its `loadRoutesFrom()` call are gone. The file
  held only comments; `ShortLinkController` is mounted by the consuming application at a
  path of its choosing, as its own docblock and the `Sluggable` contract both describe.

### Upgrading from 0.4.x
If you **published** `config/polyslug.php`, nothing changes — your file still names the
encoder it always did. If you **did not**, you inherit `RandomTokenEncoder` and existing
URLs stop resolving. Either pin the old encoder, or take the migration and let old links
self-heal:

```php
'encoder'         => RandomTokenEncoder::class,
'legacy_decoders' => [SqidsEncoder::class],
```

Old URLs keep resolving through the legacy decoder and are `301`ed to the new format as
they are visited — no flag day, no broken bookmarks.

## [0.4.0] - 2026-07-26

### Removed
- **Breaking.** The package no longer ships views or translations, and the
  `polyslug-views` / `polyslug-lang` publish tags and the `polyslug::` view and
  translation namespaces are gone with them. Both directories held nothing but the
  generator's placeholders — a comment-only Blade file and seven copies of *"This is
  an example Polyslug translation string."* — and no shipped code ever resolved a
  translation key or rendered a view. Polyslug routes and resolves; it renders nothing
  and emits no user-facing text (its exception messages and console output address
  developers). If you published either tag, the published files were placeholders and
  can be deleted.

### Fixed
- **Publishing no longer fatals on a lean install.** `vendor:publish` resolved its
  targets through the `config_path()` / `database_path()` / `resource_path()` /
  `lang_path()` global helpers, which ship only with `laravel/framework` — a package
  this one does not require. They are gone, along with every other Foundation-only
  helper in shipped code (`app()`, `config()`, `abort()`, `event()`, `now()`), all now
  resolved through the container and the `illuminate/contracts` interfaces.
- **Published migrations sort correctly.** The bundled migration is published with
  `publishesMigrations()`, so its `0001_01_01_000000` ordering prefix is rewritten to
  the publish date. Previously it sorted before every migration the host application
  already had, and so ran before the tables it may reference existed.
- **The dependency declaration matches what the code uses.** `composer.json` required
  only `illuminate/contracts` and `illuminate/support` while the code used Eloquent,
  the router, HTTP, the console, Blade and the filesystem. Eight components are now
  declared: `collections`, `console`, `container`, `database`, `filesystem`, `http`,
  `routing` and `view`.

### Added
- `vendor:publish --tag=polyslug` publishes every resource group at once, alongside
  the existing per-group tags.

### Documentation
- The full documentation now lives at
  [docs.pushery.com/polyslug-for-laravel](https://docs.pushery.com/polyslug-for-laravel/),
  restructured into pages you can link to: installation, quick start, how it works, a page
  per feature, one per persona recipe, a reference section (configuration, attribute
  options, model API, commands, events, contracts, exceptions, database) and guides for
  testing, diagnostics and troubleshooting. Nothing was dropped in the move — the
  reference and database pages document surface the README never covered. The README is
  now a short showcase that links there.

## [0.3.0] - 2026-07-13

### Added
- The package now ships its translation set in all seven default locales — **de, en,
  es, fr, it, nl, pt** — under `lang/*`. A boot test enforces that every one of the
  seven has its own `messages.php` and resolves its strings in its own words (no silent
  English fallback), so the locale coverage can never regress.

## [0.2.1] - 2026-07-11

### Documentation
- The README "Recipes" section is now a complete cookbook covering all ten app personas — added Social/UGC (shared slugs via `unique: false`), Marketplace (per-seller scope + a resolution gate), Headless CMS (the polymorphic type registry and one catch-all route), Government/enterprise (immutable slugs, 410 Gone, and supersede redirects), Events/ticketing (QR short links that survive renames), and Real-estate/geo (nested location paths). Every recipe was verified against the source.

## [0.2.0] - 2026-07-11

### Added
- `#[Polyslug(unique: false)]` now lets non-idLess records **share** a slug instead of failing. A non-idLess URL is `slug_id` and resolves by the encoded id, so duplicate slugs are unambiguous. Such rows are written with a new `enforce_unique = false` flag and excluded from the slug-uniqueness index, while the one-current-row guarantee is untouched — enforced identically on SQLite, PostgreSQL (partial-index predicate) and MySQL 8.4 (generated key column). The bundled `0002_…_add_enforce_unique_to_polyslug_slugs` migration adds the column and rebuilds the index; existing slugs keep their uniqueness because the column defaults to `true`.

### Changed
- Combining `idLess: true` with `unique: false` is now rejected at configuration time with the new `Polyslug\Exceptions\MisconfiguredPolyslug`. An idLess URL is the slug alone, so an idLess model resolves *by* its slug and the slug must stay unique.

### Removed
- `Polyslug\Exceptions\SlugCollision` (added in v0.1.4). With `unique: false` now allowing shared slugs for non-idLess models, there is no collision to fail on — the v0.1.4 fail-fast was the honest interim; this is the full behavior.

## [0.1.4] - 2026-07-11

### Fixed
- `#[Polyslug(unique: false)]` now fails fast with a dedicated `Polyslug\Exceptions\SlugCollision` when the generated slug already belongs to another model in the same `(type, locale, scope)`, instead of looping into the generic `CouldNotWriteSlug` (which reads like a transient write conflict and misled you into suspecting concurrency). `unique: false` disables the numeric `-2`/`-3` suffix, so the slug must be collision-free within its scope — the new exception says exactly that, names the offending slug, and is thrown at generation before any write attempt. Choose a distinct source, add a `scope` that separates the records, or drop `unique: false` to restore the suffix. Verified on SQLite, PostgreSQL, and MySQL 8.4.

## [0.1.3] - 2026-07-11

### Changed
- The cross-engine tests now run as dedicated `Postgres` and `MySql` test suites in a single `composer test:database` pass, instead of re-running the whole suite once per engine via `DB_CONNECTION`. Each suite points the default connection at a real server, probes it first, and **skips gracefully** when it is unreachable so a bare checkout stays green; exporting `REQUIRE_DB_TESTS=1` turns a missing engine into a hard failure so a green run really did prove both. The suites assert the one-current-slug and case-insensitive-slug guarantees are enforced identically on PostgreSQL (functional partial index) and MySQL 8.4 (virtual generated key columns). The GitHub Actions test job gains PostgreSQL 17 and MySQL 8.4 service containers so the parity is enforced there too. Point the suites at your servers with `PG_TEST_*` / `MYSQL_TEST_*` (`MYSQL_TEST_PORT=3308` for Herd's MySQL 8.4).

### Fixed
- The README PHP-version badge rendered "not found": the upstream `packagist/php-v` shields.io endpoint returns empty for every package right now. It now reads from `packagist/dependency-v/pushery/polyslug-for-laravel/php`, which shows the required PHP version from the published `composer.json`. Badge only — no code or dependency change.

## [0.1.2] - 2026-07-05

### Fixed
- The `config/polyslug.php` sitemap comment told you to bind `Polyslug\Contracts\SitemapUrlResolver`, a class that does not exist — the contract is `Polyslug\Contracts\PolyslugUrlResolver` (as the README and the `polyslug:sitemap` command already stated). Following the config comment would have bound a non-existent class.

### Documentation
- The routing examples now name their route (`->name('pages.show')`), so the `route('pages.show', $page)` calls in the README run as written when copied verbatim.

## [0.1.1] - 2026-07-05

### Added
- MySQL 8.4 support (the database Laravel Cloud runs alongside serverless PostgreSQL). The uniqueness guarantees are enforced natively on MySQL via generated key columns that mirror the functional partial unique index used on PostgreSQL/SQLite, and the full test suite now runs against all three engines.

### Changed
- The slug write completes each demote-and-insert in a transaction that always commits — skipping a slug a concurrent writer claimed with `insertOrIgnore` and restoring the current row in place — instead of relying on a caught duplicate-key error and a nested savepoint rollback. This keeps slug writes correct when a model is saved inside an outer transaction on MySQL, where a nested savepoint rollback is unreliable.

## [0.1.0] - 2026-07-05

Initial public release: polymorphic, multilingual routable identity for Eloquent —
leak-safe encoded IDs, self-healing canonical redirects, per-locale slugs + hreflang.

### Changed
- Slug generation no longer throws by default when a source has no sluggable characters (a CJK/emoji-only title). The new `emptyFallback: 'id-only'` (default) stores an empty slug — the URL becomes `_{id}` — so a save can never fail after commit; opt back into the previous behavior with `#[Polyslug(emptyFallback: 'throw')]`.

### Added
- Laravel Boost integration: ships an AI guideline (`resources/boost/guidelines/core.blade.php`) and a `polyslug-development` skill (`resources/boost/skills/polyslug-development/SKILL.md`) that Boost auto-loads on `boost:install`, so AI coding assistants get accurate Polyslug conventions and the full option reference.
- Recipes: a README section with worked per-use-case setups (multi-tenant SaaS, multilingual news, nested e-commerce categories, slug-only docs, enumeration-safe IDs, and short links).
- Slug-only URLs: `#[Polyslug(idLess: true)]` drops the `_{id}` suffix — the URL is the slug alone. Resolution is by slug: the current slug resolves directly, a superseded slug 301s to the current URL, and retired slugs stay reserved so an old URL can never be reassigned to a different model. The resolve-query gate still applies; the slug must be unique per (type, locale, scope).
- Short links: `$model->shortLink()` mints a stable token, and the shipped `Polyslug\Http\Controllers\ShortLinkController` (route it at `/go/{token}`) 301s it to the model's current canonical URL — so a printed/QR link survives slug renames. Uses the bound `PolyslugUrlResolver`.
- Nested (hierarchical) slugs: override `polyslugParent()` to compose ancestor slugs into the route-key path (`/electronics/phones/iphone_TOKEN`). Paths are computed from ancestors' current slugs, so a rename/reparent self-heals via the canonical redirect (no cascade or stored path); scope on the parent key gives per-parent uniqueness; recursion is depth-bounded against cycles.
- Native (non-Latin) slugs: `#[Polyslug(unicode: 'native')]` preserves Unicode letters/numbers (Chinese, Cyrillic, Greek, accented Latin, …) instead of ASCII-transliterating, lower-cased at generation so the case-insensitive unique index is consistent across PostgreSQL and SQLite (assumes NFC-normalized input).
- Diagnostics: `php artisan polyslug:doctor` verifies the encoder config (encoder + legacy decoders implement `IdentityEncoder`) and that the uniqueness-guaranteeing indexes exist.
- Per-model encoder options: `#[Polyslug(encoderOptions: ['alphabet' => '…', 'min_length' => 12])]` gives a model its own `SqidsEncoder` token space (distinct from the global one and from other models).
- Encoder migration: `polyslug.legacy_decoders` lists previous encoders to try when the current one can't decode a token, so switching `polyslug.encoder` doesn't break existing URLs — they resolve via the legacy decoder and 301 to the new format.
- `RandomTokenEncoder`: a leak-free encoder mapping each key to an unguessable random token in the `polyslug_tokens` table — hides row count, order, and value for integer-keyed, enumeration-sensitive models.
- Sitemap generator: `polyslug:sitemap` streams all registered sluggable models into an XML sitemap with reciprocal `hreflang` alternates, using a bound `Polyslug\Contracts\PolyslugUrlResolver` and honoring `polyslugIsRoutable()`.
- Test assertions: the `Polyslug\Testing\InteractsWithPolyslug` trait adds `assertSlugRedirects`, `assertHasCurrentSlug`, `assertSlugResolves`, and `assertSlugNotResolvable` for consumers' test suites.
- Routing & Blade helpers: the `Route::polyslug($uri, $action)` macro registers a route with `SubstituteBindings` + `polyslug.canonical` in the correct order, and the `@polyslugHreflang($model, $resolver)` Blade directive renders the hreflang tags.
- Queued backfill: `polyslug:backfill --queue [--chunk=N]` dispatches chunked `Polyslug\Jobs\BackfillSlugsJob` jobs across queue workers for large tables, instead of one synchronous run.
- Redirect analytics: with `polyslug.analytics.enabled`, the canonical middleware dispatches a `Polyslug\Events\SlugRedirected` event on each self-heal (requested key, canonical URL, model, locale, status) — a fire-and-forget hook for link-rot metrics or CDN purging.
- Soft-delete slug release: `#[Polyslug(onDelete: 'release')]` frees a slug for reuse when its model is soft-deleted (default `'keep'` reserves it); a hard/force delete always cascades the slug rows so none are orphaned.
- Gone & superseded content: `polyslugSupersededBy()` 301s a model's URL to a successor (discontinued → replacement, preserving link equity), and `polyslugIsGone()` returns a configurable 410 (`polyslug.gone.status`) for permanently-removed content — both honored by the canonical middleware ahead of same-model self-heal.
- App-wide reserved slugs: `polyslug.reserved.global` is merged with each model's `reserved` list, so generated slugs never shadow sensitive routes (login, admin, api, …).
- Route-shadow guard: `polyslug.reserved.from_routes` seeds the reserved list from every registered route path, so a generated slug can never collide with a real route.
- Dynamic per-model configuration: implement `Polyslug\Contracts\ConfiguresPolyslug` and return a `PolyslugConfig` from `polyslug()` to compute slug rules at runtime (per-tenant reserved words, per-environment encoder, …) — resolved fresh and overriding the `#[Polyslug]` attribute. Fixes the previously documented-but-inert `polyslug()` override.
- Resolution visibility gate: override `polyslugResolveQuery()` (provided by `HasPolyslug`) to constrain which rows a slug may resolve to (tenant / published scope), enforced uniformly across bound routes and the polymorphic resolver — a model outside the scope resolves to a `404` indistinguishable from a nonexistent one (no existence oracle). `Sluggable::polyslugIsRoutable()` keeps unpublished models/locales out of hreflang sets and sitemaps.
- Locale-explicit routing: `polyslug.locale.source = 'route'` makes the canonical-redirect middleware use the `{locale}` route segment (instead of the ambient app locale), preventing wrong-language 301 loops on `/{locale}/…` routes. New `Sluggable::polyslugRouteKeyForLocale()` builds a route key for an explicit locale (safe in CLI/queues), and `polyslug.locale.missing` (`fallback`|`id-only`) controls the key when a locale has no slug.
- Concurrency-safe slug writes: the demote-old + insert-new steps run in a single transaction and retry (up to `polyslug.write.max_attempts`, default 5) when a concurrent writer claims the slug, and a new partial unique index guarantees exactly one current slug per (type, id, locale, scope). Exhausting the retries throws `Polyslug\Exceptions\CouldNotWriteSlug`.
- Per-model encoder override: `#[Polyslug(encoder: UuidEncoder::class)]` overrides the global identity encoder for a single model.
- Expanded README into a full feature overview with a quick-start and a "how it works" section.

### Fixed
- `Polyslug::isValidSlug()` now rejects slugs containing a trailing newline.
- `RawIdEncoder` rejects non-canonical leading-zero tokens (e.g. `007`), so each record has a single canonical URL — consistent with `SqidsEncoder`.
