# Changelog

All notable changes to `pushery/polyslug-for-laravel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.21.0] - 2026-10-04

### Added

- **`$model->retireSlug()` stops one former slug from leading to its record.** A former slug redirects to the current address, which is what a rename forced by a trademark complaint must not do. A retired slug answers `polyslug.retired.status` (410 by default) once the application has answered, while every other former slug keeps redirecting and the record keeps its current address. Only a former slug can be retired. A new migration adds the nullable `retired_at` column; run `php artisan migrate`, and publish again first if you publish the package migrations.
- **`Model::polyslugResolveMany()` resolves a list of route values in two queries.** An API that receives public identifiers used to pay one token query per identifier before its own work began. The tokens are now decoded together and the records read together, through the resolution gate; each value may be a full route key or the bare token, and one that resolves to nothing is absent from the result. Encoders gain the optional `BulkIdentityDecoder` contract (`decodeMany()`, `decodeManyWithin()`), which `RandomTokenEncoder` and `SequentialTokenEncoder` implement.
- **`ResolvesSluggableChildren` binds a Polyslug child under scoped route bindings.** Under `scopeBindings()` Laravel binds `/owners/{owner}/pages/{page}` through the parent's relation and compares the whole route value with the child's key column, which a slug and a token never match, so the child answered 404; dropping the scoping let a foreign parent's address serve it. With the trait on the parent model, the value is resolved the way the child's own binding resolves it and the relation is narrowed to that record. It goes on the parent because `HasUuids` and `HasUlids` define the child-side method already.

### Changed

- **The Packagist page lists the package by what it does, and names its publisher.** `composer.json` carried the single keyword `laravel` and no `authors`. It now carries the thirteen search terms its description and README use, `slug`, `hreflang` and `sitemap` among them, and Pushery as the publisher.
- **`make:polyslug` keeps a namespace in the name and takes the root namespace from the application.** It wrote every model as `App\Models\{Name}` in `app/Models/` and dropped any namespace, so `Admin/Page` and `Blog/Page` both became `app/Models/Page.php` and the second call failed, and an application that maps `app/` to another namespace got a class the autoloader could not find. The command now extends Laravel's `GeneratorCommand`: `Blog/Page` scaffolds `App\Models\Blog\Page` in `app/Models/Blog/Page.php`, the root namespace is the one the application's `composer.json` maps to `app/`, a name PHP reserves is refused, an interactive call without a name asks for one, and the messages are the ones every `make:` command prints, such as `Model already exists.`
- **A misspelled value of six options is refused instead of falling back to the default.** `onDelete`, `emptyFallback` and `unicode` on `#[Polyslug]`, and the settings `locale.source`, `locale.missing` and `short_links.scheme`, were each compared against the one value that changes something, so `onDelete: 'relase'` kept slugs blocked and `'scheme' => 'sequental'` stayed random without a word. A value outside each set now throws `MisconfiguredPolyslug`, for the attribute when its configuration is built and for a setting where it is read. An unset or null setting still means the default.
- **A token alphabet refuses `_` and `.`, and a Sqids alphabet is held to the same characters.** `_` separates the slug from the token, and the read path splits at the last one, so a token containing it was cut and its record answered 404 at its own address: 19 of 40 fresh records with the alphabet `abcdefgh_`. A `.` let the counted scheme issue the tokens `.` and `..`, which a browser removes from the path. `polyslug.sqids.alphabet` and a Sqids `encoderOptions` alphabet went to Sqids unchecked. Each of them now throws `InvalidArgumentException` when it is built; an alphabet of letters, digits, `-` and `~` is unaffected.
- **`composer.json` requires `ext-hash`.** The digest of a scope key longer than its column is SHA-256, from `hash()`. The extension has been part of every PHP build since 7.4 and cannot be left out, so no installation is affected.

### Fixed

- **The redirect to a successor keeps the request's query string.** A superseded record answered `/pages/old-title?utm_source=news` with a `301` to its successor without the query, while the self-heal redirect of the same middleware carries it over. Both redirects now append the request's query string, with the keys sorted and re-encoded by the framework, so a campaign or filter parameter on the old address arrives on the new one.
- **On MySQL, the time columns hold a date after 2038 and read the same under every session time zone.** `created_at`, `updated_at`, `deleted_at` and `retired_at` of the three tables were `TIMESTAMP`, which ends at 2038-01-19 03:14:07 UTC: measured on MySQL 8.4, strict mode refused an insert of 2040-01-01. A `TIMESTAMP` is also converted through the session's time zone, so a row written as 07:00 under +02:00 read 05:00 under +00:00. The new `store_polyslug_times_as_datetime_on_mysql` migration turns them into `DATETIME`, and every value reads as it did before. It rewrites each table once, which takes time on a large table. Run `php artisan migrate`; if you publish the package migrations, publish them again first. PostgreSQL and SQLite are unaffected.
- **A slug that keeps its capitals works on a PostgreSQL database with a Turkish locale.** Uniqueness and every slug lookup compare `lower(slug)`, and a database created with a Turkish or Azerbaijani locale folds `I` to the dotless `ı`, while the package folds the name it looks for in PHP, where `I` is `i`. Measured on PostgreSQL 18 with an ICU `tr-TR` database and `preserveCase`: the second record titled "IOS App" was refused with `CouldNotWriteSlug` instead of getting `IOS-App-2`, and the first record's own URL answered 404. The new `pin_polyslug_slug_collation` migration pins the `slug` column to the `C` collation on such a database, which folds ASCII and nothing else, the way the slugs are stored; it rebuilds the indexes on the column once. On every other database it does nothing. Run `php artisan migrate`; if you publish the package migrations, publish them again first.
- **Eager-loading `slugs` works over a set of any size.** Every record was one parameter of a single query, and PostgreSQL and MySQL refuse a statement past 65,535 parameters: measured on PostgreSQL 18 and MySQL 8.4, `with('slugs')` over 60,000 records went through and over 70,000 ended in a query error. Beyond a thousand records the relation now reads the slugs a thousand records a query, each one bound as before; a smaller set costs the one query it always did.
- **`polyslugPreload()` and `polyslugResolveMany()` take a set of any size.** Each sent one statement for the whole set, with a parameter for every token and five for every token it wrote, and PostgreSQL and MySQL refuse a statement past 65,535 parameters: measured on PostgreSQL 18 and MySQL 8.4, a preload of 14,000 records without a token and a resolve of 70,000 tokens each ended in a query error. The token store now reads and writes a thousand tokens at a time; a set of up to a thousand costs the same statements as before.
- **A slug write the database rolled back for a deadlock is made again instead of failing the save.** Two records taking each other's names at the same moment lock the same index entries in opposite order, and InnoDB rolls one of them back: measured on MySQL 8.4 with two processes swapping names under `reclaimActive`, 44 of 1,000 rounds on each side ended in an exception. A deadlock, a lock wait that runs out and a serialization failure now count as a lost race, and the write tries again, up to `polyslug.write.max_attempts`. Inside a transaction the application opened, the error goes back to that code as Laravel's `DeadlockException`, because only it can run the transaction again.
- **A locale longer than the 16 characters its column holds is refused before anything is written.** On MySQL the write paths insert with `INSERT IGNORE`, which stored such a locale cut to its first 16 characters: no read under the full locale found the slug, and a second locale sharing those characters was taken for a concurrent write. PostgreSQL refused it with a query error, and SQLite stored it whole. Writing a slug or a short link for such a locale, the slug written when a record is saved included, now throws `InvalidArgumentException` on every engine before the package's tables are touched.
- **The refusal of an unknown robots directive offers every directive the check accepts.** Its list was written next to a validator that kept growing and lacked `index`, `follow`, `nositelinkssearchbox` and `nocache`, so a typo of `follow` was answered with a list that did not contain it. The list is now read from the validator itself.
- **A token that cannot be claimed names its table.** `CouldNotIssueToken` said "a random token" for every failure, while identity tokens and short links throw it under the random and the counted scheme alike. The message now names `polyslug_tokens` or `polyslug_short_links`.
- **The documentation and the Boost skill say that a uniqueness suffix comes on top of `maxLength`, and which types `polyslug:doctor` checks.** The second record with the same title gets `-2` after the trimmed slug, and the doctor checks the resolution gate of each type registered in `polyslug.types`.
- **A resolution gate that sorts its query no longer drops records from the sitemap.** `polyslug:sitemap` reads rows in rounds of 500 by primary key, and Laravel's `lazyById()` keeps an order the query already has in front of the key. A `polyslugResolveQuery()` that sorted, by title for example, sorted every round by title while the cursor moved by key, so rows were skipped or listed twice: 1,200 records produced 999 addresses, 500 of them distinct. The command now drops the gate's order before it pages.
- **A record whose successors come back to it is listed in the sitemap.** A record that names itself as its successor, or two records that name each other, are served at their own address by the canonical middleware, and the sitemap left them out. The middleware and the sitemap now read the same successor chain.
- **Short links and counted tokens say that their rows must stay, and why.** The token store described its counter as one a deleted row does not move back, and the short-link page called a printed link stable forever. Both hold only while the rows stay: a counted token is numbered from the highest row that remains, so deleting the newest row of `polyslug_short_links` or `polyslug_tokens` hands its token, and every link already printed or published with it, to the next record. The package deletes neither table's rows, and the short-link and encoder pages now ask applications to keep them too.
- **Two records that name each other as successor no longer redirect to each other forever.** The loop guard caught only a record that named itself, so a pair merged by hand answered every request with a 301 to the other address. The supersede redirect now follows the chain of successors, each one through the resolution gate, and sends the visitor straight to the last record they may see. A chain that comes back to a record it already passed counts as no successor, and its pages are served.
- **`polyslug:backfill` reads a chunk's current slugs in one query instead of one per row.** It asked every row whether it had a current slug, so a run over a large table that had nothing to fill paid a query per row to learn that, and the queued job did the same for its rows. Both now load the current slug rows with each chunk. With `--queue` the command reads only the keys it hands to the jobs, not every column of every row.
- **A queue worker finds a token issued after an earlier job asked for it.** The stored-token encoders were bound as singletons and remember what they read, misses included. A worker keeps its singletons from one job to the next, so a token that did not exist yet when one job looked stayed unknown in every job after it, and a URL that resolved everywhere else answered 404 in that worker. The encoders are scoped now, so Laravel hands every job, and every request under Octane, a fresh one; within one job or request they stay shared, as before. A model's own Sqids encoder (`encoderOptions` on a Sqids model) is now built once per job or request instead of on every route key.
- **Saving a record whose title many others share no longer costs one query per namesake.** The collision search asked about one suffix at a time, so the 201st record titled the same paid 201 uniqueness queries on its save. It now asks about the base alone and then about the suffixes in batches that double from two, each batch one lookup of the exact names the unique index compares: the 201st record pays eight. The slug is the same as before, the lowest free suffix, and a batch made of reserved names alone costs no query.
- **On MySQL, names and record keys that differ only in an accent or in case stay apart.** Every key column of the package's tables inherited the connection's collation, and Laravel's default `utf8mb4_unicode_ci` ignores accents and case. In `polyslug_slugs` the unique keys hash bytes while the lookups followed the collation: with `unicode: 'native'` a record titled Cafe got `cafe-2` next to `café`, an id-less URL for `cafe` resolved to the record holding `café`, and a `reclaimActive` takeover of `cafe` retired `café` from a record that was never competing for it. Scopes that differed only in case collapsed the same way. In `polyslug_tokens` and `polyslug_short_links` the unique indexes followed the collation themselves, so two records keyed `Abc` and `abc` shared one token and one short link, and the URL of `abc` resolved to `Abc`. The new `pin_polyslug_key_collation` migration pins these columns to the binary collation of their character set and keeps each column's width, default and comment; `php artisan migrate` runs it, and it rebuilds each table it changes once. A record that shared another's token gets its own the next time its URL is rendered. An application that publishes the migrations instead (`PolyslugServiceProvider::ignoreMigrations()`) publishes them again with `--tag=polyslug-migrations`. It does nothing on PostgreSQL and SQLite, which already compare byte for byte.
- **A counted token space of a model's own starts at its shortest tokens.** A model that gave `SequentialTokenEncoder` an alphabet or a length of its own through `encoderOptions` was numbered past every row in the token table, so its short tokens were skipped once other models had tokens: after 20 rows of another model, a model counting in `xyz` at two characters got `yxz` for its first record. Such a space is now numbered from the model's own rows and starts at `xx`. When another model's token sits in it, the count walks past it and, after four tries, counts past every row as the shared space does; options that repeat the application's setting share the application's space. The constructor of `SequentialTokenEncoder` takes an optional `ownSpace` argument for such a space.
- **`polyslug:doctor` reports a model's own token alphabet when it fills.** A model whose `encoderOptions` name an alphabet of its own draws from a space the report measured against the application's alphabet, so a model on `'xyz'` with all nine two-character tokens taken was reported as fine. Every model type that holds tokens is now read through the encoder it builds, and a space of its own gets its own line once it is a quarter full; types that share an alphabet are reported together. A model whose encoder cannot be built is named instead of stopping the run.
- **A model that overrides only the token length keeps the application's alphabet.** `encoderOptions: ['length' => …]` built the model's own encoder with the default alphabet (`0-9 a-z`), so an application that set `polyslug.random_token.alphabet` or `polyslug.sequential_token.alphabet` got tokens on that model made of the characters it had left out. The alphabet now falls back to the configured one, the way the length already did for a model that names only an alphabet. Tokens are stored, so the ones already issued keep resolving; new ones use the configured alphabet.
- **A nested slug-only URL reaches its own record when another parent has a child of the same name.** Resolution looked only at the last segment and took the newest holder, so with `scope: 'parent_id'` the canonical URL `/electronics/phones` answered with a permanent redirect to `/accessories/phones`, and the record under Electronics was reachable at no address. The rest of the path decides now: the record whose current path is the one requested, and for an old path left by a renamed or moved ancestor, the record whose parent the ancestor part still resolves to. A leaf that only one record holds costs no extra query.
- **A slug-only URL seeks on the slug when the model names no resolution scope.** The resolution index put `scope` third, and a resolution names the scope only when the model overrides `polyslugResolutionScope()`, so the default resolution could seek on the type and locale only. Measured with 100,000 slug rows of one type: MySQL 8.4 did not use the index at all and read every row, 100 ms against 0.013 ms now; PostgreSQL 18 bridged the gap with one index search per scope value, 4.41 ms against 0.024 ms at 1,000 scope values. A new migration rebuilds the index with `lower(slug)` ahead of `scope`, building the new one before it drops the old. Run `php artisan migrate`; if you publish the package migrations, publish again first. The 0.15.0 entry said every URL asks for the scope as well; only a model that names its resolution scope does.
- **`polyslug:sitemap` reads slugs and tokens once per chunk instead of once per record.** Each chunk of 500 rows now arrives with its `slugs` relation eager-loaded and its tokens preloaded, where every record used to issue its own slug and token queries: measured 30 slug and 10 token queries for ten records and 60 and 20 for twenty, against 1 and 1 for either now. The token memo no longer grows for the life of the process either: it keeps at most 10,000 entries per memo and lets go of the older half beyond that, which also bounds a decode memo that kept every miss.
- **`polyslug:sitemap` no longer empties the sitemap when the database fails during a run.** The command caught everything thrown while it built a record's entries, the package's own reads included, so a failed query wrote an empty sitemap, exited 0 and blamed the resolver. Only what the resolver throws is counted as a skip now, a database error fails the run, and every file is renamed into place only once the whole set is written: a failed run, a refused index included, leaves the previous sitemap in place and no new parts beside it.
- **A route that names a field (`{page:uuid}`) binds a Polyslug model by that column.** The field was ignored and the value decoded as a slug and a token, while Laravel builds the URL of such a route from the column, so every link the route generated for itself answered 404. The column is now compared the way Laravel compares it, behind the resolution gate, and the canonical middleware no longer treats such a parameter as a stale slug. Under `ResolvesSluggableChildren` a named field is resolved and narrowed to the parent like any other child binding.
- **A route declared with `->withTrashed()` binds a Polyslug model.** Laravel binds such a route through `resolveSoftDeletableRouteBinding()`, which compared the whole route value with the key column, so every record answered 404 there, deleted or not. The value is now decoded the way an ordinary binding decodes it, through the resolution gate, with deleted records admitted. `ResolvesSluggableChildren` does the same for a scoped child.
- **A long title gives a slug its column holds.** Without `maxLength` a slug had no length limit, and the column holds 255 characters. PostgreSQL refused a longer slug after the record itself was saved, so the request failed and the record had no slug; MySQL cut it short, and the next record with the same title could never be written. A slug is now cut to the column's length, `Schema::defaultStringLength()` as the migration used it, and a uniqueness suffix shortens the base instead of running past the end. The scope key has the same column: a key that does not fit, from a scope column of free text, is stored as its SHA-256 digest, and a key that fits is stored as before.
- **The migrations run on a connection with a table prefix.** Their hand-written DDL named `polyslug_slugs` without the prefix the schema builder had given the table, so migrating stopped at the first index. On MySQL, which does not roll DDL back, the slug table stayed behind without the unique indexes that carry the one-current-slug guarantee. Every hand-written statement now names its table through the connection's grammar. A migration that already ran is unaffected; an application that published the migrations has its own copies and takes the change by publishing them again.
- **A slug-only record whose source yields no slug still has an address.** On an `idLess` model the default `emptyFallback: 'id-only'` stored the empty slug as it does on an id-based model, where the URL falls back to the token. A slug-only URL has no token, so a CJK or emoji title gave the route key `""`, and `route()` threw for every page that linked the record; the next ones got `-2` and `-3`. The record's encoded id now takes the slug's place.
- **A `separator` the read path splits at is refused.** With `separator: '_'` a slug-only record of two words, `two_words`, was read as the slug `two` and the token `words` and answered 404 at its own address. A separator other than `-`, `.` or `~` now throws `MisconfiguredPolyslug` when the model's configuration is built.
- **The Boost skill's description reaches Boost whole.** It was an unquoted YAML value, and ` #` starts a comment there, so `boost:install` read it only up to "Covers the" and dropped the sentence that tells an agent when to load the skill.
- **`SqidsEncoder` names the extension it needs, and `polyslug:doctor` checks it.** Sqids computes with `bcmath` or `gmp`, which only Sqids itself suggested, so Composer never showed it to an application, and without either extension the encoder threw on the first token a request rendered or, as a legacy decoder, read. `composer.json` now suggests both, the requirements name them, and `polyslug:doctor` builds the configured encoder and every legacy decoder and reports one it cannot build, with the reason. It used to pass such a legacy decoder and stop at such an encoder.
- **Two scopes of several columns no longer share a stored key.** The scope key joined the column values with `|` as they were, so `(owner: 'a|project:b', project: '')` and `(owner: 'a', project: 'b|project:')` produced the same key: the second record competed with the first for its slug, and on a slug-only model a lookup in one of the scopes could return the record of the other. A model with more than one scope column now stores the key escaped when a value contains `|`; every other key stays as it was. A record that already holds such a key gets the escaped one the next time it is saved, and until then a lookup under its scope does not find it, so save those records once after updating.
- **A record that moves scope takes its slug with it.** The stored scope key was written only when a slug row was inserted, so a record moved to another tenant, owner or parent kept its current row in the old scope. Uniqueness, the collision search and the slug-only lookup all ran there: a second record in the new scope got the same slug, the old scope handed out `-2` for a name nobody held there, and a slug-only URL in the new scope answered 404. A save that changes a scope column now writes the slug into the new scope; the old row stays behind as history, as after a rename.
- **An enum, a date or a `Stringable` column counts in the scope key and the slug source.** A cast attribute is an object, and the package read only scalars, so such a column contributed nothing. Every record of an enum-scoped model shared one scope, a slug collided across regions, and a slug-only lookup in one region could answer with a record of another. Now an enum counts by its value, a date by the string the model stores, a `Stringable` by its text. Rows written before this release carry an empty value for such a column: call `polyslugSync()` once on each record of an affected model to move its row into its scope. A model that names an enum in its `source` gets it in the slug of each record written from now on; an existing slug changes the next time its source does.
- **`SlugChanged` and `SlugReclaimed` wait for your transaction to commit.** Both now implement `ShouldDispatchAfterCommit`. A model saved inside your own `DB::transaction()` used to announce its new slug, or its takeover of a name, as soon as the package's write finished, before your transaction committed. When it rolled back, a listener had already reacted to a slug that never existed. Outside a transaction they fire as before.
- **A model on a connection of its own keeps its slugs there.** The `slugs` relation read on the model's connection, while every write, collision check and slug lookup used the default one. Such a model had no current slug after `create()`, and its first rename threw `CouldNotWriteSlug`. All of them now use the relation's connection: the slug model's own where it names one, the model's otherwise. A custom `SlugGenerator` receives it as `SlugRequest::$connection`.
- **An environment value below a key's floor keeps the default.** `POLYSLUG_SQIDS_MIN_LENGTH=-3` passed the number test and reached Sqids, which refuses a negative length, so every Sqids token threw. `POLYSLUG_WRITE_MAX_ATTEMPTS` and `POLYSLUG_BACKFILL_TIMEOUT` accepted `0`, `0.5` and `-3` the same way; the write retry and the backfill job already fell back to their defaults at the point of use, and `config()` now reports the value they use. The floors are `0` for `sqids.min_length` and `1` for the other two.
- **The configuration reference names the four environment variables.** `POLYSLUG_SQIDS_MIN_LENGTH`, `POLYSLUG_WRITE_MAX_ATTEMPTS`, `POLYSLUG_BACKFILL_TIMEOUT` and `POLYSLUG_REQUIRE_SCOPE` arrived in 0.19.0 and appeared only in this changelog.
- **`PolyslugConfig::enforcesUniqueSlug()` is documented again.** Its description stood above `withoutActiveReclaim()`, where PHP attaches a docblock to nothing, so an IDE showed no text for the method.

## [0.20.1] - 2026-10-03

### Changed

- **`composer.json` no longer suggests `laravel/ai`.** The suggestion described a tool this repository uses to test itself, not something an application installing the package needs.

## [0.20.0] - 2026-09-22

### Added

- **The package's models are replaceable.** Map `PolyslugSlug` or `PolyslugShortLink` to your own subclass in the new `polyslug.models` config key, and Polyslug uses that class on every path: every query, every row it writes, and the `slugs` relation. A class that does not exist, or does not extend the package class, is ignored in favor of the package class. `PolyslugSlug::model()` and `PolyslugSlug::resolve()` give your own code the same answer. Existing rows need no migration: every type column Polyslug writes holds the type of the model that owns the row, never the class of the row itself.

### Changed

- **The backfill timeout reads as one expression in the published config.** `config/polyslug.php` wrote the same ternary over three lines; `POLYSLUG_BACKFILL_TIMEOUT` and the value it produces are unchanged, so a consumer who republishes the file sees one line where three stood and nothing else.

## [0.19.0] - 2026-09-19

### Added

- **Four config keys read the environment, so a host can tighten them without publishing the config file.** Publishing freezes every *other* default in that file too, including a security default a later release corrects, so reaching one switch used to cost all of them.

  | key | variable | what it decides |
  |---|---|---|
  | `sqids.min_length` | `POLYSLUG_SQIDS_MIN_LENGTH` | the padding floor for short-link tokens. Early ids encode to three or four characters |
  | `write.max_attempts` | `POLYSLUG_WRITE_MAX_ATTEMPTS` | how often a slug write retries against a concurrent writer before `CouldNotWriteSlug` |
  | `backfill.timeout` | `POLYSLUG_BACKFILL_TIMEOUT` | the backfill job's timeout. How long a chunk takes is a property of your table |
  | `resolution.require_scope` | `POLYSLUG_REQUIRE_SCOPE` | whether a scoped model whose caller names no scope is refused |

  Every default is unchanged, so an application that sets none of them behaves exactly as before. The three numeric ones are tested rather than cast: `(int) env(...)` reads an unset variable and a typo alike as `0`, and `0` means "off" for all three — a wrong setting keeps the documented default instead of quietly removing the floor. `POLYSLUG_REQUIRE_SCOPE` is compared against `true`, so write `POLYSLUG_REQUIRE_SCOPE=true`; `1` is a string to Laravel's `env()` and does not enable it.

  `sitemap.max_urls` and `sitemap.max_bytes` are deliberately **not** in the list. They are the sitemap protocol's own per-file ceilings — 50,000 URLs and 50 MB — and the only direction anybody would move them is up, which produces a file search engines reject.

### Fixed

- **The shipped models are no longer `final`, so a host can extend them.** `PolyslugSlug` and `PolyslugShortLink` are seams: an application that needs a relation, a scope, a cast or an observer on one of them subclasses it, and `final` closed that with a fatal error while the class loads rather than a message anybody could act on. The route left open was copying the model into the application, where it drifts from this one at every update. Writing the subclass is now possible; **making this package use it is not yet** — `PolyslugSlug::class` is still named directly at five call sites, so the package goes on instantiating its own class. That half is a config seam with its own decisions and is tracked separately.

### Changed

- **Two packages the shipped code imports are now declared in `require`: `symfony/http-foundation` and `symfony/http-kernel`.** Both were reached only through illuminate's dependency tree, behind `Response` and `HttpException`. **Nothing new is installed** — the lockfile holds 180 packages before and after, and both arrive with illuminate either way. What changes is who promises their version: an undeclared import is one Composer may resolve below what this code calls, and the failure then surfaces as a missing method in a class this manifest never names. Each floor is the base of its major (`^7.0 || ^8.0`) rather than the tighter constraint `laravel/framework` happens to carry, because a floor decides who may install this package. The lean dependency stance is untouched: this package still requires ten `illuminate/*` splits and not the framework.

- **`MissingPolyslugConfig` reads more directly:** *"Model [X] uses HasPolyslug but has no #[Polyslug] attribute."* Code that matches the old message text has to match the new one.
- **`composer.json` suggests `laravel/ai`.** Nothing an application installs changes.

## [0.18.9] - 2026-09-14

### Fixed

- **Static analysis passes again for a model that is not final, on larastan 3.12.** 0.18.8 declared `polyslugResolveQuery()` as `Builder<self>`, because larastan up to 3.11.0 typed `$this->newQuery()` as a builder for the class itself. larastan 3.12.0 keeps `static` on that call (larastan/larastan#2544), and since `Builder` is invariant in its model, a non-final model's builder no longer satisfied a `self` gate there. The gate declares `Builder<static>` again. A final model sees no difference. `polyslugResolveByKey()` still narrows the row it finds, so an override that answers with a query for a different model still gets `null`. An override that copied the 0.18.8 docblock can go back to `Builder<static>`.

## [0.18.8] - 2026-09-11

### Fixed

- **A model that extends another one can call its own resolution gate again, and a gate that answers with a foreign query no longer puts a foreign record behind your route.** `polyslugResolveQuery()` declared `Builder<static>` while the builder handed to it is one for the class itself; `Builder` is invariant in its model, so from any model that is not final the two spellings were different types and the call could not be made at all. The gate now declares the type the builder really carries, and `polyslugResolveByKey()` narrows the row back — an override answering with a query for a different model gets `null` instead of that model's record. Nothing changes for a final model with a gate that queries itself, which is every gate the package ships.

## [0.18.7] - 2026-09-11

### Fixed

- **`polyslug:sitemap` no longer lists a record its own resolution gate hides.** The command read every configured table directly, while route binding and every resolution path in the package go through `polyslugResolveQuery()`. A record the gate refuses therefore answered 404 at its address while the sitemap submitted that address, and its slug, built from a title nobody had released yet, stood in a public document. The rows now come through the same gate, so the sitemap submits what an anonymous request can open: a gate that reads a session or a tenant sees here what a crawler sees. An application that implements `Sluggable` without `HasPolyslug` has no gate to apply and keeps its whole table.

## [0.18.6] - 2026-09-10

### Changed

- **The manifest now declares the PHP extensions the shipped code actually calls** — `ext-ctype` and `ext-mbstring`. No code changed. `src/Encoders/SqidsEncoder.php` calls into ctype and `src/Support/TokenAlphabet.php` into mbstring, and neither was required here.

  **Nothing changes for an install that already worked, and that is worth stating plainly rather than leaving you to check:** `illuminate/support`, a direct dependency of this package, already requires `ext-ctype`, `ext-filter` and `ext-mbstring`, and `illuminate/console` requires `ext-mbstring` too. Every PHP that could resolve this package therefore already had both. What changes is where the requirement is written. A transitive guarantee is a property of somebody else's manifest — nothing that reads ours can see it, `composer check-platform-reqs` included, and it can be narrowed upstream without a signal here.

## [0.18.5] - 2026-09-09

### Fixed

- The attribute-options page shipped `<meta name="description" content="Every">`. Its front
  matter was unquoted and began with `#[Polyslug]`, and a `#` after whitespace opens a comment
  in YAML, so everything from there on was discarded.
- Five pages said `polyslug:doctor` reports an unbound `PolyslugUrlResolver` and one said it
  does not. It does. The page that denied it now describes the distinction that makes both
  halves true: the doctor reports it without failing the command, while `polyslug:sitemap`
  refuses outright, since it cannot produce output without a resolver.
- The database reference described two migrations against five, which made its rollback
  paragraph a statement from three migrations ago -- it named what a rollback undoes and left
  three out.

### Added

- `--base-url`, `canAddress()` and `polyslugLastModified()` are documented in the reference,
  which promised "every option" while these three appeared only on feature pages.

## [0.18.4] - 2026-09-08

### Fixed

- **`SqidsEncoder` silently collapsed an out-of-range numeric key onto `PHP_INT_MAX` instead of refusing it.** The encoder accepts a string key when every character is a digit, and PHP's `(int)` cast saturates rather than failing — so `'9223372036854775808'` and `9223372036854775807` produced the same token. Two records shared one URL, and whichever lost decoded back to a key it did not own. Such a key now raises `InvalidArgumentException` naming the key, like the encoder's other refusals do. Leading zeros are unaffected: `'007'` is still the same key as `7`, which is the one difference the cast is allowed to make.
- **A refusal in `make:polyslug` had stopped being observable, and the arm that watched it could not see why.** An empty model name is refused, and the arm asserting that listed the models directory through `File::files()` — which runs through Finder and ignores dotfiles, so the one filename an empty name produces, `.php`, was invisible to exactly the assertion written to catch it. Worse, the stray file outlived the test and made every later run of that arm pass for the wrong reason: the command then failed with "already exists" instead of "a model name is required". Nothing a consumer installs changes here; the scaffolder behaves as it always did. It is listed because it is why the previous release's test evidence was weaker than it read.
- **The published documentation linked Recipes as a bare directory from a subpage, which breaks once the portal serves canonical URLs with a trailing slash.** A bare directory link is URL-relative, so on `/polyslug-for-laravel/quick-start/` it resolved one level too deep. Recipes now has a real landing page — an ordered table of the twelve app shapes and what each one solves — and both links to it are file links, which resolve the same way with or without the trailing slash. Nothing in the package changed; the docs portal publishes only from a released ref, which is why it rides a release.

## [0.18.3] - 2026-09-08

### Changed

- **No change to the shipped package.** An application installing Polyslug gets what 0.18.2 gave it, byte for byte: same classes, same config, same behavior, nothing to upgrade for and nothing lost by skipping it.

## [0.18.2] - 2026-09-06

### Changed

- **No change to the shipped package.** `src`, `config`, `database`, `resources` and `composer.json` are identical to what 0.18.1 published, so there's nothing to gain by upgrading and nothing to lose by skipping it.

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

  The failure was silent in both directions that matter. Nothing errored and nothing logged; the new setting read as `null`, so a feature added in a minor release was off for exactly the hosts that had customized that area, and a corrected default never took effect. This package has added keys inside existing blocks more than once, so the hosts affected are real rather than hypothetical.

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
- **`$action` on `Route::polyslug()` is typed `Closure|array|string|null` instead of `callable`.** The old type was wider than the framework itself: the one callable shape that is none of those three — an invokable object — never reached a controller, it fataled while the route was being registered. Nothing that worked before stops working; the signature stops advertising something that could not.

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
