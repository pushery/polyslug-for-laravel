<?php

declare(strict_types=1);

namespace Polyslug\Concerns;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Polyslug\Contracts\BulkIdentityDecoder;
use Polyslug\Contracts\BulkIdentityEncoder;
use Polyslug\Contracts\IdentityEncoder;
use Polyslug\Contracts\Sluggable;
use Polyslug\Contracts\SlugGenerator;
use Polyslug\Contracts\StoresTokensPerRecord;
use Polyslug\Contracts\TokenScheme;
use Polyslug\Encoders\RandomTokenEncoder;
use Polyslug\Encoders\SequentialTokenEncoder;
use Polyslug\Encoders\SqidsEncoder;
use Polyslug\Events\SlugChanged;
use Polyslug\Events\SlugReclaimed;
use Polyslug\Exceptions\CouldNotIssueToken;
use Polyslug\Exceptions\CouldNotWriteSlug;
use Polyslug\Models\PolyslugShortLink;
use Polyslug\Models\PolyslugSlug;
use Polyslug\Polyslug;
use Polyslug\PolyslugConfig;
use Polyslug\PolyslugConfigResolver;
use Polyslug\Relations\StringKeyedMorphMany;
use Polyslug\Support\ConfigChoice;
use Polyslug\Support\DeletionState;
use Polyslug\Support\LocaleColumn;
use Polyslug\Support\ReservedWords;
use Polyslug\Support\SlugRequest;
use Polyslug\Support\TokenAlphabet;
use Stringable;
use Throwable;

use function Illuminate\Support\enum_value;

/**
 * Gives an Eloquent model polymorphic, encoder-backed slugs. Declare the source with
 * the #[Polyslug] attribute and `implements Sluggable`; slugs are generated on save
 * and old slugs are superseded (kept as history) when the source changes.
 *
 * @mixin Model
 */
trait HasPolyslug
{
    /**
     * How many times shortLink() re-attempts a claim before giving up.
     *
     * Eight, matching the identity store, and for the same reason: a scheme that widens its
     * output every three lost draws needs room for two widenings, after which the space is
     * 1,296x the one that was full.
     */
    private const int POLYSLUG_SHORT_LINK_ATTEMPTS = 8;

    /**
     * How many keys polyslugResolveMany() reads in one query.
     *
     * Eloquent writes integer keys into the SQL, but it binds every other key, and PostgreSQL and
     * MySQL refuse a statement past 65,535 parameters. A thousand keeps a UUID or ULID set of any
     * size inside that, and a set of up to a thousand costs the one query it always did.
     */
    private const int POLYSLUG_RESOLVE_SLICE = 1_000;

    /**
     * Whether the resolution in progress admits soft-deleted records: set for the length of a
     * binding on a route declared with `->withTrashed()`.
     */
    private bool $polyslugIncludesTrashed = false;

    protected static function bootHasPolyslug(): void
    {
        static::saved(static function (Model $model): void {
            if ($model instanceof Sluggable) {
                $model->polyslugSync();
            }
        });

        static::deleted(static function (Model $model): void {
            if ($model instanceof Sluggable) {
                $model->polyslugOnDeleted();
            }
        });
    }

    /**
     * @return MorphMany<PolyslugSlug, $this>
     */
    public function slugs(): MorphMany
    {
        // Deliberately NOT `$this->morphMany(...)`. That would hand back a plain MorphMany,
        // whose eager constraint inlines integer keys into the SQL text and cannot compare
        // against the varchar `sluggable_id` on PostgreSQL — see StringKeyedMorphMany.
        //
        // Built here rather than by overriding `newMorphMany()`, because that hook is shared:
        // overriding it would silently change every OTHER morphMany on a consumer's model too.
        $instance = $this->polyslugSlugModel();

        // The morph column names are written out rather than derived through `getMorphs()`:
        // this package ships the migration that creates them, so they are fixed, and the
        // helper's signature does not accept the nulls that asking it for the defaults needs.
        return new StringKeyedMorphMany(
            $instance->newQuery(),
            $this,
            $instance->qualifyColumn('sluggable_type'),
            $instance->qualifyColumn('sluggable_id'),
            $this->getKeyName(),
        );
    }

    /**
     * A slug model on the connection the `slugs()` relation reads from.
     *
     * Every write, rival check and slug lookup starts here, so a record on a connection of its own
     * writes its slugs where it reads them. The connection is the slug model's own where it names
     * one and the record's otherwise, the rule Eloquent applies to every relation.
     */
    private function polyslugSlugModel(): PolyslugSlug
    {
        return $this->newRelatedInstance(PolyslugSlug::model());
    }

    /**
     * Warm the identity tokens for a whole set of models in one round trip.
     *
     * The companion to eager-loading `slugs`. That removes the per-model SLUG query; this
     * removes the per-model TOKEN query, which is what the database-backed default encoder
     * costs on the first render of each row:
     *
     *     $pages = Page::query()->with('slugs')->paginate();
     *     Page::polyslugPreload($pages);
     *
     * Ordinary work afterwards — route keys, hreflang sets, sitemaps — issues no further
     * token queries, because the encoder is bound as a singleton and answers from the memo
     * this call filled.
     *
     * A NO-OP WHERE THERE IS NOTHING TO WIN, and silently so, on purpose. Sqids, UUID, ULID
     * and the raw key derive their token from the key alone; they do not implement
     * BulkIdentityEncoder, and calling this with them costs one array walk. So a consumer
     * may write it unconditionally without first knowing which encoder is configured — the
     * point of an optimization hint is that it does not become a configuration question.
     *
     * Models are grouped by their RESOLVED encoder rather than assumed to share one:
     * `#[Polyslug(encoder: ...)]` is per-model, so a mixed set would otherwise send keys to
     * the wrong store.
     *
     * @param  iterable<mixed>  $models  narrowed by the instanceof below rather than by the
     *                                   signature: typing this as iterable<Sluggable> makes
     *                                   the guard provably dead on a class that uses the
     *                                   trait WITHOUT implementing Sluggable — which is a
     *                                   supported shape here, and PHPStan says so
     */
    public static function polyslugPreload(iterable $models): void
    {
        /** @var array<string, array{encoder: BulkIdentityEncoder, type: string, keys: list<string>}> $batches */
        $batches = [];

        foreach ($models as $model) {
            // instanceof static, because the private helpers below are reachable only on an
            // instance of this very class. A foreign Sluggable is skipped rather than
            // fataling: preloading is a hint, and a hint must not be able to break a render.
            if (! $model instanceof static) {
                continue;
            }

            $encoder = $model->polyslugEncoder();

            if (! $encoder instanceof BulkIdentityEncoder) {
                continue;
            }

            // Grouped by encoder AND morph type, because a type-scoped store looks a key up
            // under its owner: one batch spanning two types would ask for every key under the
            // first one, find nothing, and turn the preload into the per-row queries it exists
            // to remove.
            // Non-empty for a stored-token encoder, since getMorphClass() always answers with
            // a class name; the untyped lane below is reached by the encoder KIND, not by an
            // empty type. A second `!== ''` test used to guard the branch and could never
            // fire — a condition that cannot be false reads as a case that happens.
            $type = $encoder instanceof StoresTokensPerRecord ? $model->getMorphClass() : '';
            $handle = spl_object_id($encoder).'|'.$type;
            $batches[$handle] ??= ['encoder' => $encoder, 'type' => $type, 'keys' => []];
            $batches[$handle]['keys'][] = $model->polyslugKeyString();
        }

        foreach ($batches as $batch) {
            $encoder = $batch['encoder'];

            if ($encoder instanceof StoresTokensPerRecord) {
                $encoder->encodeManyWithin($batch['type'], $batch['keys']);

                continue;
            }

            // An encoder that batches but cannot hold an owner — the contract as it stood
            // before 0.11. It keeps one shared space, which is what it always had, and this
            // line is the compatibility promise StoresTokensPerRecord makes by EXTENDING
            // IdentityEncoder instead of replacing it.
            $encoder->encodeMany($batch['keys']);
        }
    }

    public function currentSlug(?string $locale = null): ?string
    {
        return $this->currentSlugRow($locale)?->slug;
    }

    public function polyslugRouteKey(?string $locale = null): string
    {
        $locale ??= $this->polyslugLocale();
        $config = $this->polyslugConfig();

        // Token-only mode: the URL is the encoded identity alone, with no delimiter in front
        // of it. The delimiter exists to separate two parts; with one part it is a character
        // in every URL that says nothing — and on a model chosen for short URLs, a character
        // that says nothing is the whole cost of the feature.
        if ($config->slugless) {
            return $this->polyslugEncodedKey();
        }

        $path = $this->polyslugPath($locale);

        // Slug-only mode: the URL is the slug/path alone, no "_{encodedId}".
        if ($config->idLess) {
            return $path;
        }

        return Polyslug::compose($path, $this->polyslugEncodedKey());
    }

    public function polyslugRouteKeyForLocale(string $locale): string
    {
        return $this->polyslugRouteKey($locale);
    }

    public function polyslugParent(): ?Sluggable
    {
        return null;
    }

    public function polyslugPath(?string $locale = null, int $maxDepth = 20): string
    {
        $locale ??= $this->polyslugLocale();
        $own = $this->polyslugSlugForRouteKey($locale) ?? '';
        $parent = $this->polyslugParent();

        if ($parent === null || $maxDepth <= 0) {
            return $own;
        }

        // Prepend the ancestors' path (computed from their CURRENT slugs), so renaming or
        // reparenting an ancestor changes this URL — the canonical middleware then 301s the
        // stale one. maxDepth bounds a parent cycle.
        $prefix = $parent->polyslugPath($locale, $maxDepth - 1);

        return $prefix === '' ? $own : $prefix.'/'.$own;
    }

    /**
     * The stable /go/{token} short link for this model in one locale, issued on first use.
     *
     * Its own token space, drawn from the bound TokenScheme — `polyslug.short_links` — so a
     * printed or spoken link can be short and counted while the identity token inside every
     * URL stays long and unguessable, or the other way round.
     *
     * CLAIMED IN A LOOP rather than through firstOrCreate, and that is what makes a short
     * setting usable here at all. The table carries two unique indexes, and firstOrCreate
     * only ever recovers from one of them: it retries by re-reading the TARGET, so a row
     * rejected because another record already holds that TOKEN finds nothing on the re-read
     * and surfaces as a query exception — at 10 random characters that is unreachable, at
     * four it is a matter of time, and it would land while a page is being rendered.
     */
    public function shortLink(?string $locale = null): string
    {
        $locale = LocaleColumn::storable($locale ?? $this->polyslugLocale());

        $target = [
            'sluggable_type' => $this->getMorphClass(),
            'sluggable_id' => $this->polyslugKeyString(),
            'locale' => $locale,
        ];

        $scheme = Container::getInstance()->make(TokenScheme::class);

        for ($attempt = 0; $attempt < self::POLYSLUG_SHORT_LINK_ATTEMPTS; $attempt++) {
            $existing = PolyslugShortLink::model()::query()->where($target)->value('token');

            if (is_string($existing)) {
                return $existing;
            }

            $token = $scheme->draw($attempt, static function (): int {
                // A lower bound on how many short links exist, from the highest row id — the
                // same hint the identity store uses, and only a counted scheme ever asks.
                $max = PolyslugShortLink::model()::query()->max('id');

                return is_numeric($max) ? (int) $max : 0;
            });

            if (PolyslugShortLink::model()::query()->insertOrIgnore([...$target, 'token' => $token, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]) > 0) {
                return $token;
            }

            // Zero rows means one of the two unique indexes refused: another writer claimed
            // this target (the next pass reads their token and returns it) or another record
            // already holds this token (the next pass asks the scheme for the next one).
            // Which one it was is not knowable portably here, and looping answers both.
        }

        throw new CouldNotIssueToken(
            $this->polyslugKeyString(),
            self::POLYSLUG_SHORT_LINK_ATTEMPTS,
            table: PolyslugShortLink::model()::query()->getModel()->getTable(),
        );
    }

    private function polyslugSlugForRouteKey(string $locale): ?string
    {
        $slug = $this->currentSlug($locale);

        if ($slug !== null) {
            return $slug;
        }

        // The requested locale has no slug: fall back to the default locale's slug,
        // or emit a slug-less (id-only) key — per config polyslug.locale.missing.
        if (ConfigChoice::read('locale.missing', ['fallback', 'id-only'], 'fallback') === 'fallback') {
            return $this->currentSlug($this->polyslugDefaultLocale());
        }

        return null;
    }

    public function polyslugSync(?string $locale = null): void
    {
        $this->polyslugWriteFromSource($this->polyslugConfig(), $locale);
    }

    /**
     * Sync WITHOUT taking a name another record still holds — the backfill counterpart.
     *
     * ON THE Sluggable CONTRACT, which makes this a breaking change for a consumer that
     * implements the interface WITHOUT taking this trait. It was trait-only first, and the
     * package's own backfill is what settled it: a capability the contract does not carry
     * cannot be called on anything typed as Sluggable — not by a consumer, and not by this
     * package. Half a capability is worse than a named break in a release that already
     * carries one. A model using the trait needs no change.
     *
     * Same relationship to polyslugSync() that {@see seedSlug()} has to setSlug(), and the
     * package's own `polyslug:backfill` uses this one. Running a backfill over existing rows
     * of a `reclaimActive` model would otherwise hand one record's name to another purely by
     * the order the rows came back, and report nothing.
     */
    public function polyslugSeed(?string $locale = null): void
    {
        $this->polyslugWriteFromSource($this->polyslugConfig()->withoutActiveReclaim(), $locale);
    }

    private function polyslugWriteFromSource(PolyslugConfig $config, ?string $locale): void
    {
        $locale ??= $this->polyslugLocale();

        // fresh: a write decides against what is current NOW, never against a collection
        // loaded before this request touched anything.
        $current = $this->currentSlugRow($locale, fresh: true);

        // A slugless model has one possible slug — the empty one — so once a row exists for
        // this locale there is nothing a save could change. Named rather than left to the
        // wasChanged() test below, which reads an EMPTY column list as "did anything change
        // at all" and would therefore re-run the whole write path on every unrelated update.
        //
        // A row whose stored scope is no longer the record's is written again even when the
        // source stayed: the record moved, to another tenant, owner or parent, and its slug has
        // to compete, collide and resolve in the scope it now belongs to.
        if ($current !== null && ($config->slugless || $config->immutable || (! $this->wasChanged($config->source) && $current->scope === $this->polyslugScope($config)))) {
            return;
        }

        // Handing the row on is the whole point of naming it: writeSlug()'s first attempt
        // asked the identical question again, in the same call stack, with nothing in
        // between that could change the answer.
        $this->writeSlug($locale, $this->polyslugSource($config), $config, known: $current);
    }

    public function setSlug(string $locale, ?string $source = null): void
    {
        $config = $this->polyslugConfig();

        $this->writeSlug($locale, $source ?? $this->polyslugSource($config), $config);
    }

    /**
     * Write a slug WITHOUT taking a name another record still holds.
     *
     * The seeding counterpart to setSlug(), and the distinction is not stylistic. On a model
     * with `reclaimActive: true`, setSlug() retires whoever currently holds the name — right
     * for a webhook, where the source has already handed the name over and that handover IS
     * the truth. It is wrong for a backfill: two existing records wanting one name are a
     * conflict in the data, not a handover, and taking there decides ownership by row order
     * while nobody finds out.
     *
     * So this one lets the holder block. The newcomer gets a counter suffix, or — on an
     * idLess model, where a suffix would change the URL's meaning — the write refuses with
     * CouldNotWriteSlug. Both are reports; neither is a silent reassignment.
     *
     * A NAMED METHOD rather than a boolean on setSlug(), for a reason that outlives the
     * choice: reclaimActive requires `reclaim`, so a flag could only ever turn the behavior
     * OFF. A parameter whose `true` means "do what the model already said" is a trap, and the
     * one place the difference matters is the call site — which is exactly what a name shows
     * and a boolean hides.
     *
     * On a model without `reclaimActive` this is identical to setSlug(), and saying so is the
     * point: a consumer can seed unconditionally without asking how each model is configured.
     */
    public function seedSlug(string $locale, ?string $source = null): void
    {
        $config = $this->polyslugConfig()->withoutActiveReclaim();

        $this->writeSlug($locale, $source ?? $this->polyslugSource($config), $config);
    }

    /**
     * @return list<string>
     */
    public function slugLocales(): array
    {
        // Reads the eager-loaded relation for the same reason currentSlugRow() does, and
        // it matters more here: this is what polyslugUrls() calls first, so every hreflang
        // set, every <head> and every sitemap entry used to open with its own query.
        if ($this->relationLoaded('slugs')) {
            return array_values(
                $this->currentSlugsInMemory(null)
                    ->map(fn (PolyslugSlug $slug): string => $slug->locale)
                    ->sort()
                    ->values()
                    ->all()
            );
        }

        return array_values(
            $this->slugs()
                ->where('is_current', true)
                ->orderBy('locale')
                ->get()
                ->map(fn (PolyslugSlug $slug): string => $slug->locale)
                ->all()
        );
    }

    /**
     * @return list<string>
     */
    public function slugHistory(?string $locale = null): array
    {
        return array_values(
            $this->slugs()
                ->where('locale', $locale ?? $this->polyslugLocale())
                ->where('is_current', false)
                ->orderByDesc('id')
                ->get()
                ->map(fn (PolyslugSlug $slug): string => $slug->slug)
                ->all()
        );
    }

    /**
     * Retire a former slug: a request for it no longer redirects to this record.
     *
     * A former slug redirects to the current address, which keeps old links alive after a
     * rename. A rename that must not keep pointing at the record, such as one forced by a
     * trademark complaint, retires the old slug instead: the canonical middleware then answers
     * `polyslug.retired.status` (410 by default) once the application has answered, and every
     * other former slug keeps redirecting. Clearing `retired_at` on the row reverses it.
     *
     * Only a former slug can be retired. The current one is the record's address, and retiring
     * it would leave the record answering at an address that refuses it, so that throws: rename
     * first. Returns how many rows were retired, which is 0 for a slug this record never held
     * or one already retired.
     */
    public function retireSlug(string $slug, ?string $locale = null): int
    {
        $locale ??= $this->polyslugLocale();

        if (Str::lower($this->currentSlug($locale) ?? '') === Str::lower($slug)) {
            throw new InvalidArgumentException(sprintf(
                'The slug [%s] is the current address of this %s and cannot be retired; rename the record first.',
                $slug,
                static::class,
            ));
        }

        return $this->slugs()
            ->where('locale', $locale)
            ->where('is_current', false)
            ->whereNull('retired_at')
            ->whereRaw('lower(slug) = ?', [Str::lower($slug)])
            ->update(['retired_at' => Carbon::now()]);
    }

    /**
     * Whether a route value names a slug of this record that was retired.
     *
     * Asked by the canonical middleware about a value that is not the current address, so a
     * request for the current one costs nothing. The slug is read out of the value the way the
     * model's route binding reads it: the leaf of a slug-only path, or the slug part in front of
     * the token. A slugless model carries no slug, so nothing of it is ever retired.
     */
    public function polyslugIsRetiredAddress(string $routeValue, ?string $locale = null): bool
    {
        $config = $this->polyslugConfig();

        if ($config->slugless) {
            return false;
        }

        [$slug] = $config->idLess ? [$routeValue] : Polyslug::split($routeValue);

        return $this->slugs()
            ->where('locale', $locale ?? $this->polyslugLocale())
            ->whereNotNull('retired_at')
            ->whereRaw('lower(slug) = ?', [Str::lower(Str::afterLast($slug, '/'))])
            ->exists();
    }

    /**
     * Build an absolute URL for each locale that has a current slug.
     *
     * @param  callable(string $locale, string $routeKey): string  $urlUsing
     * @return array<string, string>
     */
    public function polyslugUrls(callable $urlUsing): array
    {
        $urls = [];

        // The ANNOUNCED locales, which are the slug locales unless the model declares
        // otherwise — see Polyslug::announcedLocales() and the ProvidesAddressLocales contract.
        foreach (Polyslug::announcedLocales($this, $this->slugLocales(...)) as $locale) {
            if ($this->polyslugIsRoutable($locale)) {
                $urls[$locale] = $urlUsing($locale, $this->polyslugRouteKey($locale));
            }
        }

        return $urls;
    }

    /**
     * The reciprocal hreflang set: one self-referential entry per locale plus x-default.
     * The same resolver feeds this and the canonical URL, so they cannot disagree.
     *
     * @param  callable(string $locale, string $routeKey): string  $urlUsing
     * @return array<string, string>
     */
    public function hreflangLinks(callable $urlUsing, ?string $xDefault = null): array
    {
        $urls = $this->polyslugUrls($urlUsing);

        if ($urls === []) {
            return [];
        }

        $xDefault ??= $this->polyslugDefaultLocale();
        $urls['x-default'] = $urls[$xDefault] ?? reset($urls);

        return $urls;
    }

    /**
     * Render <link rel="alternate" hreflang="..."> tags for this model.
     *
     * @param  callable(string $locale, string $routeKey): string  $urlUsing
     */
    public function hreflangTags(callable $urlUsing, ?string $xDefault = null): HtmlString
    {
        $tags = [];

        foreach ($this->hreflangLinks($urlUsing, $xDefault) as $hreflang => $url) {
            $tags[] = sprintf('<link rel="alternate" hreflang="%s" href="%s">', e(Polyslug::hreflangCode($hreflang)), e($url));
        }

        return new HtmlString(implode("\n", $tags));
    }

    /**
     * Render <xhtml:link rel="alternate" hreflang="..."> alternates for a sitemap URL entry.
     *
     * @param  callable(string $locale, string $routeKey): string  $urlUsing
     */
    public function sitemapAlternateTags(callable $urlUsing, ?string $xDefault = null): HtmlString
    {
        $tags = [];

        foreach ($this->hreflangLinks($urlUsing, $xDefault) as $hreflang => $url) {
            $tags[] = sprintf('<xhtml:link rel="alternate" hreflang="%s" href="%s"/>', e(Polyslug::hreflangCode($hreflang)), e($url));
        }

        return new HtmlString(implode("\n", $tags));
    }

    /**
     * `$known` carries what the caller already established about the current row by reading it
     * fresh in this same call stack. Three states, deliberately distinct: the row itself, `null`
     * for "I looked and there is none", `false` for "I did not look". Collapsing the middle one
     * into `false` would hand the duplicate read straight back to the path that issues the most
     * of them — `polyslug:backfill` walks rows that have no slug and whose source has not
     * changed, so `null` is its normal answer, not its edge case.
     *
     * @param  PolyslugSlug|false|null  $known  the caller's fresh answer, or false if it has none
     */
    private function writeSlug(string $locale, string $source, PolyslugConfig $config, PolyslugSlug|false|null $known = false): void
    {
        LocaleColumn::storable($locale);

        $scope = $this->polyslugScope($config);
        $attempts = $this->polyslugMaxWriteAttempts();
        $slugModel = $this->polyslugSlugModel();

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            // fresh, and this is the attempt that makes it non-negotiable: the loop re-asks
            // after a failed attempt precisely because another writer may have moved the row.
            // A cached collection would hand back the same superseded row on every pass.
            //
            // Only the FIRST pass may take the caller's answer, and only because that read
            // happened microseconds ago with no write between. Every later pass exists
            // BECAUSE the world moved, so reusing $known there would defeat the retry.
            $current = $attempt === 1 && $known !== false
                ? $known
                : $this->currentSlugRow($locale, fresh: true);

            $desired = Container::getInstance()->make(SlugGenerator::class)->generate(
                new SlugRequest(
                    source: $source,
                    sluggableType: $this->getMorphClass(),
                    locale: $locale,
                    scope: $scope,
                    exceptId: $this->polyslugKeyString(),
                    // Resolved HERE rather than in the generator, because only the model can
                    // answer it: the seam is the model's, and a generator receives a request,
                    // not a record.
                    reserved: $this->polyslugReservedWords(ReservedWords::inherited($config)),
                    connection: $slugModel->getConnectionName(),
                    identity: $config->idLess ? fn (): string => $this->polyslugEncodedKey() : null,
                ),
                $config,
            );

            // The same text in another scope is a different row: a record that moved keeps its
            // slug but has to hold it in the scope it now belongs to.
            if ($current !== null && $current->slug === $desired && $current->scope === $scope) {
                return;
            }

            // Demote-old + insert-new in one transaction that always COMMITS. insertOrIgnore
            // skips (returns 0), without throwing, a slug a concurrent writer claimed between
            // our generate and insert; on that miss we restore the demoted row inside the same
            // transaction, so the model always keeps exactly one current slug. Because the write
            // never rolls back a nested savepoint, it stays correct inside an outer transaction
            // on every engine — MySQL's savepoint rollback is unreliable once DDL has implicitly
            // committed the outer transaction. Exhausting the attempts throws outside any
            // transaction, leaving the original current slug untouched.
            $displaced = [];

            $inserted = $this->polyslugWriteTransaction($slugModel->getConnection(), function () use ($slugModel, $current, $locale, $scope, $desired, $config, &$displaced): int {
                // The takeover belongs INSIDE this transaction, next to the insert it makes room
                // for. Retiring the holder first and inserting afterwards would leave the name
                // owned by nobody if the insert then lost a race.
                if ($config->reclaimActive) {
                    $displaced = $this->polyslugRetireCurrentHolders($desired, $locale, $scope);
                }

                $current?->update(['is_current' => false]);

                $inserted = $slugModel->newQuery()->insertOrIgnore([
                    'sluggable_type' => $this->getMorphClass(),
                    'sluggable_id' => $this->polyslugKeyString(),
                    'locale' => $locale,
                    'scope' => $scope,
                    'slug' => $desired,
                    'is_current' => true,
                    // A non-idLess unique:false model opts its rows out of the current_unique
                    // index so records may share a slug (the id in the URL disambiguates), and
                    // so does a slugless model, whose slug is empty for every record.
                    // idLess is always unique (enforced by MisconfiguredPolyslug at config time).
                    'enforce_unique' => $config->enforcesUniqueSlug(),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                if ($inserted === 0) {
                    $current?->update(['is_current' => true]);

                    // WHAT THIS ATTEMPT RETIRED STAYS RETIRED, and that is not an oversight
                    // beside the restore above — it is the only correct answer here.
                    //
                    // This branch is reached because the insert lost a race, and under
                    // reclaimActive the only thing that can beat it is another writer committing
                    // this exact name. So by the time we get here somebody else holds it.
                    // Re-asserting the retired claim would collide with current_unique exactly
                    // as our insert just did, and throw out of a transaction this write never
                    // rolls back.
                    //
                    // Nothing is left ownerless either, which is the property the restore would
                    // have been protecting: the rival owns the name, and the next attempt takes
                    // it from them the same way this one took it from the previous owner. An
                    // earlier version restored the rows conditionally, guarded by "unless a rival
                    // holds it" — the guard was always true, so the restore was a line no run
                    // could reach.
                    $displaced = [];
                }

                return $inserted;
            });

            // The engine rolled this attempt back because another writer held what it needed. That
            // is a lost race like the zero above, and the next attempt reads the current row afresh.
            if ($inserted === null) {
                continue;
            }

            if ($inserted > 0) {
                $dispatcher = Container::getInstance()->make(Dispatcher::class);

                // Announced only after this write's transaction committed, and only for the attempt
                // that actually landed: a listener that reacts by re-syncing the displaced record
                // must not run against a handover that was rolled back. Both events dispatch
                // after commit, so inside an enclosing transaction they wait for its commit and
                // are dropped with its rollback.
                foreach ($displaced as $row) {
                    $dispatcher->dispatch(new SlugReclaimed(
                        $this,
                        $locale,
                        $desired,
                        (string) $row->sluggable_type,
                        (string) $row->sluggable_id,
                    ));
                }

                $dispatcher->dispatch(new SlugChanged($this, $locale, $desired, $current?->slug));

                return;
            }
        }

        throw new CouldNotWriteSlug($this->getMorphClass(), $source);
    }

    /**
     * One attempt's transaction, or null when the engine rolled it back because another writer
     * held what it needed: a deadlock, a lock wait that ran out, a serialization failure.
     *
     * Two records taking each other's names at the same moment lock the same unique-index entries
     * in opposite order, and InnoDB answers by rolling one of them back. Measured on MySQL 8.4 with
     * two processes swapping names under reclaimActive: 44 of 1,000 rounds on each side. Only the
     * attempt is lost, so the caller tries again, the way it does when its insert loses a race.
     *
     * Inside an enclosing transaction the engine has rolled back that one as well, and Laravel
     * reports it as a DeadlockException. That is not retried here: only the code that opened the
     * enclosing transaction can run it again.
     *
     * @param  Closure(): int  $write
     */
    private function polyslugWriteTransaction(ConnectionInterface $connection, Closure $write): ?int
    {
        try {
            return $connection->transaction($write);
        } catch (Throwable $e) {
            // Laravel's own reading of the error, the one it retries a transaction by. The database
            // service provider binds it wherever Eloquent runs at all.
            $detector = Container::getInstance()->make(ConcurrencyErrorDetector::class);

            if ($e instanceof DeadlockException || ! $detector->causedByConcurrencyError($e)) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * Retire every OTHER record's current row for this exact name, and hand the rows back so
     * the caller can restore them if its own insert then loses a race.
     *
     * Scoped exactly like the uniqueness probe it complements — same type, locale, scope,
     * enforce_unique and case-folded slug — because the rows it must clear are precisely the
     * rows the current_unique index would otherwise refuse the insert over. A wider net would
     * retire a name nobody was competing for.
     *
     * @return list<PolyslugSlug>
     */
    private function polyslugRetireCurrentHolders(string $slug, string $locale, string $scope): array
    {
        /** @var list<PolyslugSlug> $rows */
        $rows = $this->polyslugRivalHolders($slug, $locale, $scope)->get()->all();

        foreach ($rows as $row) {
            $row->update(['is_current' => false]);
        }

        return $rows;
    }

    /**
     * Rows of OTHER records that currently hold this exact name.
     *
     * Named rather than inlined into its one caller, because the SCOPING is the subtle part and
     * this is where it is stated: same type, locale, scope, enforce_unique and case-folded slug
     * as the uniqueness probe in DefaultSlugGenerator — which is precisely the set
     * current_unique covers. Widen it and the takeover retires a name nobody was competing for;
     * narrow it and the insert is refused by a row the retire did not clear.
     *
     * @return Builder<PolyslugSlug>
     */
    private function polyslugRivalHolders(string $slug, string $locale, string $scope): Builder
    {
        return $this->polyslugSlugModel()->newQuery()
            ->where('sluggable_type', $this->getMorphClass())
            ->where('locale', $locale)
            ->where('scope', $scope)
            ->where('is_current', true)
            ->where('enforce_unique', true)
            ->where('sluggable_id', '!=', $this->polyslugKeyString())
            ->whereRaw('lower(slug) = ?', [Str::lower($slug)]);
    }

    private function polyslugMaxWriteAttempts(): int
    {
        $attempts = Container::getInstance()->make(ConfigRepository::class)->get('polyslug.write.max_attempts', 5);

        return is_int($attempts) && $attempts >= 1 ? $attempts : 5;
    }

    public function getRouteKey(): string
    {
        return $this->polyslugRouteKey();
    }

    public function resolveRouteBinding(mixed $value, mixed $field = null): ?static
    {
        if (is_string($field) && $field !== '') {
            return $this->polyslugResolveByField($value, $field);
        }

        $routeValue = is_scalar($value) ? (string) $value : '';

        if ($this->polyslugConfig()->slugless) {
            return $this->resolveSluglessRouteBinding($routeValue);
        }

        [$slug, $encodedId] = Polyslug::split($routeValue);

        if ($encodedId === null || $encodedId === '') {
            // No id part: slug-only models resolve by their slug instead.
            return $this->polyslugConfig()->idLess ? $this->resolveBySlug($slug) : null;
        }

        $id = $this->polyslugDecode($encodedId);

        if ($id === null) {
            return null;
        }

        return $this->polyslugResolveByKey($id);
    }

    /**
     * Resolve many route values in a few queries rather than one per value.
     *
     * Each value is read the way a route binding reads it, `slug_TOKEN` or the bare token, and
     * the bare token is accepted for every model: it is the public identifier an API passes
     * around. The tokens are decoded in one query per thousand tokens when the encoder can batch
     * (BulkIdentityDecoder), and the records are read through the resolution gate in one query
     * per thousand keys.
     * A value that resolves to nothing is absent from the result. A slug-only model has no token
     * to batch on, and resolves its values one at a time.
     *
     * @param  iterable<mixed>  $values
     * @return array<array-key, static> the record of each value, keyed by the value
     */
    public static function polyslugResolveMany(iterable $values): array
    {
        $model = static::query()->getModel();
        $wanted = [];

        foreach ($values as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $wanted[] = (string) $value;
            }
        }

        $wanted = array_values(array_unique($wanted));

        if ($model->polyslugConfig()->idLess) {
            $resolved = [];

            foreach ($wanted as $value) {
                $record = $model->resolveRouteBinding($value);

                if ($record !== null) {
                    $resolved[$value] = $record;
                }
            }

            return $resolved;
        }

        // The tokens each value may carry, in the order a route binding tries them: a slugless
        // model reads the whole value first and its older `slug_TOKEN` form second; every other
        // model reads the part after the delimiter, or the whole value when there is none.
        $slugless = $model->polyslugConfig()->slugless;
        $candidates = [];

        foreach ($wanted as $value) {
            [, $tail] = Polyslug::split($value);
            $tail = $tail === null || $tail === '' ? null : $tail;

            $candidates[$value] = $slugless ? array_values(array_filter([$value, $tail])) : [$tail ?? $value];
        }

        $keys = $model->polyslugDecodeMany(array_values(array_unique(array_merge(...array_values($candidates)))));
        $records = [];

        foreach (array_chunk(array_values(array_unique($keys)), self::POLYSLUG_RESOLVE_SLICE) as $slice) {
            foreach ($model->polyslugResolveQuery($model->polyslugBindingQuery())->whereKey($slice)->get() as $record) {
                // The gate is free to answer with a query for another model, as polyslugResolveByKey()
                // says; such a row is not this model's record.
                if ($record instanceof static) {
                    $records[$record->polyslugKeyString()] = $record;
                }
            }
        }

        $resolved = [];

        foreach ($candidates as $value => $tokens) {
            foreach ($tokens as $token) {
                $key = $keys[$token] ?? null;

                if ($key !== null && isset($records[(string) $key])) {
                    $resolved[$value] = $records[(string) $key];

                    break;
                }
            }
        }

        return $resolved;
    }

    /**
     * The keys of many tokens of this model, decoded together where the encoder can.
     *
     * Scoped to this model's type where the encoder files tokens under one, as polyslugDecode()
     * is. Whatever the batch leaves unanswered goes the single way, which covers an encoder that
     * cannot batch and a token only a legacy decoder knows; a miss of a store-backed encoder is
     * remembered by the store, so that costs no second query.
     *
     * @param  list<string>  $tokens
     * @return array<array-key, int|string>
     */
    private function polyslugDecodeMany(array $tokens): array
    {
        $encoder = $this->polyslugEncoder();
        $keys = [];

        if ($encoder instanceof BulkIdentityDecoder && $encoder instanceof StoresTokensPerRecord) {
            $keys = $encoder->decodeManyWithin($this->getMorphClass(), $tokens);
        } elseif ($encoder instanceof BulkIdentityDecoder) {
            $keys = $encoder->decodeMany($tokens);
        }

        foreach ($tokens as $token) {
            if (! array_key_exists($token, $keys)) {
                $key = $this->polyslugDecode($token);

                if ($key !== null) {
                    $keys[$token] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * Resolve a route value on a route that admits soft-deleted records: `->withTrashed()`.
     *
     * Laravel binds such a route through this method rather than resolveRouteBinding(), and its
     * own version compares the whole value with the key column, so every record answered 404,
     * deleted or not. The value is resolved the way resolveRouteBinding() resolves it, the gate
     * included, with deleted records admitted.
     */
    public function resolveSoftDeletableRouteBinding(mixed $value, mixed $field = null): ?static
    {
        $this->polyslugIncludesTrashed = true;

        try {
            return $this->resolveRouteBinding($value, $field);
        } finally {
            $this->polyslugIncludesTrashed = false;
        }
    }

    /**
     * Resolve a token-only URL — the whole value is the token, because there is no slug in
     * front of it to split off.
     *
     * IT STILL ACCEPTS THE OLD DESCRIPTIVE URL, and that is the point of the second pass
     * rather than an indulgence. Switching an existing model to slugless would otherwise
     * turn every published `my-title_TOKEN` link into a 404 — including links in print,
     * in other people's pages, and in a search index. Falling back to the part after the
     * last delimiter resolves those, and the canonical middleware then 301s the visitor
     * to the short form, so old links self-heal exactly as they do across an encoder
     * change.
     *
     * The canonical form is tried FIRST, so an ordinary hit costs one decode. Trying the
     * legacy shape on a token that has no delimiter would be a second decode on every
     * request for nothing; trying it first would ask the encoder to decode a string that
     * is not a token, which every shipped encoder answers with null anyway — a round trip
     * spent proving what the shape already said.
     */
    private function resolveSluglessRouteBinding(string $routeValue): ?static
    {
        $id = $this->polyslugDecode($routeValue);

        if ($id === null) {
            [, $legacy] = Polyslug::split($routeValue);

            $id = $legacy === null || $legacy === '' ? null : $this->polyslugDecode($legacy);
        }

        return $id === null ? null : $this->polyslugResolveByKey($id);
    }

    /**
     * Resolve this model type by primary key THROUGH the resolution gate
     * (polyslugResolveQuery), so tenant/visibility scoping applies uniformly to every
     * resolution path — bound routes, the polymorphic resolver, slug-only, and /go.
     * `mixed` because every caller hands over an untyped value (a route parameter, a
     * decoded token); the gate's whereKey() narrows it.
     */
    public function polyslugResolveByKey(mixed $key): ?static
    {
        $resolved = $this->polyslugResolveQuery($this->polyslugBindingQuery())->whereKey($key)->first();

        // The gate is an override point, and an override is free to return a query for a
        // different model. Handing that row back would put a foreign record behind this
        // model's route, with this model's type on it, so it resolves to nothing instead.
        return $resolved instanceof static ? $resolved : null;
    }

    /**
     * Resolve a binding that names a column, `{page:uuid}`: the value is compared with that
     * column the way Laravel compares it, behind the resolution gate.
     *
     * Laravel builds the URL of such a route from the same column, so decoding the value as a
     * slug and a token instead answered 404 at every address the route generated for itself.
     * The gate comes first and the column second, in the order polyslugResolveByKey() uses,
     * because a gate may answer with a query of its own and must not drop the constraint.
     * resolveRouteBindingQuery() is called rather than written out, so an override of it is
     * honored and the check HasUuids and HasUlids put there still refuses a malformed
     * identifier before it reaches the database.
     */
    private function polyslugResolveByField(mixed $value, string $field): ?static
    {
        $gated = $this->polyslugResolveQuery($this->polyslugBindingQuery());
        $resolved = self::polyslugConstrainToField($this, $gated, $value, $field)->first();

        return $resolved instanceof static ? $resolved : null;
    }

    /**
     * Apply a binding field to a query through the model's own resolveRouteBindingQuery().
     *
     * The model is taken as Model because that is the signature that accepts a builder. The
     * override in HasUuids and HasUlids narrows only its docblock and passes the builder on.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private static function polyslugConstrainToField(Model $model, Builder $query, mixed $value, string $field): BuilderContract
    {
        return $model->resolveRouteBindingQuery($query, $value, $field);
    }

    /**
     * The query a resolution starts from. Soft-deleted rows are in it for the length of a
     * binding on a route declared with `->withTrashed()`.
     *
     * @return Builder<static>
     */
    private function polyslugBindingQuery(): Builder
    {
        $query = $this->newQuery();

        if ($this->polyslugIncludesTrashed) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query;
    }

    /**
     * Re-resolve this instance through its own resolution gate.
     *
     * One query, and it is the same one route binding already paid for — which is why this
     * is never called on a bound model. It is for a model the package obtained some other
     * way and is about to disclose something about.
     */
    public function polyslugResolveSelf(): ?static
    {
        return $this->polyslugResolveByKey($this->getKey());
    }

    /**
     * Resolve a slug-only URL by its slug: current slugs first (canonical), then
     * superseded ones (the canonical middleware then 301s to the current URL).
     *
     * SCOPE IS THE CALLER'S TO NAME, and the resolve-query gate does not stand in for it. The
     * two answer different questions: the gate separates by what the environment says is
     * visible (session, tenant, request context). A scope that lives in a path segment —
     * `/@alice/toolkit` versus
     * `/@bob/toolkit` — is an ARGUMENT of the resolution, not environment state, so it
     * reaches neither this query nor the gate. Both rows may legally hold the slug, because
     * the unique index is scope-bound too; the lookup then returns whichever sorts first.
     * A nested path is the exception, because its ancestor part names the parent:
     * polyslugPickByPath() reads it.
     *
     * Override polyslugResolutionScope() to hand the scope over. With
     * `polyslug.resolution.require_scope` enabled, a scope-bound model whose caller names
     * no scope is REFUSED here instead of resolved across scopes — because the damage never
     * came from the missing filter, it came from its absence looking exactly like a hit.
     */
    private function resolveBySlug(string $value): ?static
    {
        // Nested slug-only URLs carry the ancestor path; the model's own slug is the leaf.
        $slug = Str::afterLast($value, '/');

        $config = $this->polyslugConfig();
        $scope = $this->polyslugResolutionScope();

        if ($scope === null && $config->scope !== [] && $this->polyslugRequiresResolutionScope()) {
            return null;
        }

        $query = $this->polyslugSlugModel()->newQuery()
            ->where('sluggable_type', $this->getMorphClass())
            ->where('locale', $this->polyslugLocale())
            ->whereRaw('lower(slug) = ?', [Str::lower($slug)]);

        if ($scope !== null) {
            $query->where('scope', $this->polyslugScopeKey(
                $config,
                static fn (string $column): mixed => $scope[$column] ?? null,
            ));
        }

        // A record that held the slug more than once has a row for each time, so each key is
        // resolved once, in the order of its newest row.
        $ids = $query
            ->orderByDesc('is_current')
            ->orderByDesc('id')
            ->pluck('sluggable_id')
            ->unique()
            ->all();

        $candidates = [];

        foreach ($ids as $id) {
            $model = $this->polyslugResolveByKey($id);

            if ($model === null) {
                continue;
            }

            if (! str_contains($value, '/')) {
                return $model;
            }

            $candidates[] = $model;
        }

        return $this->polyslugPickByPath($candidates, $value);
    }

    /**
     * Which of the records holding a leaf slug a nested slug-only path addresses.
     *
     * `scope: 'parent_id'` lets two parents each have a child called `phones`, and the leaf
     * alone cannot tell them apart: the newest holder used to win, so the canonical URL of the
     * other one answered with a permanent redirect to it. The rest of the path decides now.
     * First the record whose current path is the one requested; then, for an old path that an
     * ancestor's rename or move left behind, the record whose parent the ancestor part still
     * resolves to, through the parent's own resolution and its earlier slugs. Only when
     * neither tells them apart does the newest holder win, as before. A single holder is
     * returned without looking at the path, so the usual case costs nothing extra.
     *
     * @param  list<static>  $candidates
     */
    private function polyslugPickByPath(array $candidates, string $path): ?static
    {
        if (count($candidates) < 2) {
            return $candidates[0] ?? null;
        }

        $wanted = Str::lower(trim($path, '/'));

        foreach ($candidates as $candidate) {
            if (Str::lower($candidate->polyslugPath()) === $wanted) {
                return $candidate;
            }
        }

        $ancestors = Str::beforeLast($wanted, '/');

        foreach ($candidates as $candidate) {
            $parent = $candidate->polyslugParent();

            if ($parent instanceof Model && $parent->resolveRouteBinding($ancestors)?->is($parent) === true) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    /**
     * The scope this resolution happens in, as `column => value` — or null when the caller
     * cannot name one.
     *
     * Null is the default and keeps the historical behavior: no scope filter at all.
     * Override it on a model whose scope lives in the URL rather than in the environment
     * (a path segment, a subdomain, a header), and the slug lookup is then separated by
     * exactly the key the write path stored — same columns, same builder, so the two
     * cannot drift apart.
     *
     * A column the returned array omits contributes an empty value to the key, which is
     * what a model with an unset scope attribute stores too. Naming a partial scope is
     * therefore a real answer, not a half-answer.
     *
     * @return array<string, mixed>|null
     */
    public function polyslugResolutionScope(): ?array
    {
        return null;
    }

    /**
     * The reserved words this model's slugs must avoid, given everything it inherits.
     *
     * The inherited list is the model's own `#[Polyslug(reserved: [...])]`, plus
     * `polyslug.reserved.global`, plus — when `polyslug.reserved.from_routes` is on — the
     * first segment of every registered route. Returning it unchanged is the default and
     * keeps the historical behavior.
     *
     * IT COULD ONLY EVER ADD, AND THAT IS WHY THIS EXISTS. For a model that sits behind a
     * prefix by construction — `/@{owner}/{repo}` — a slug can never shadow a route, because
     * the `@` separates the namespaces completely. Every reservation there is a false
     * positive, and it fails SILENTLY: the generator appends a counter suffix rather than
     * refusing, so a legitimately named record becomes `api-2`. For externally assigned
     * identifiers `api`, `docs`, `demo` and `media` are not the edge, they are the middle.
     *
     * Filter, replace or extend — returning `[]` opts out entirely. The alternative was
     * rebinding SlugGenerator, i.e. rebuilding the collision core to be rid of a list.
     *
     * @param  list<string>  $inherited
     * @return list<string>
     */
    public function polyslugReservedWords(array $inherited): array
    {
        return $inherited;
    }

    /**
     * Whether a scope-bound model must be given a scope before a slug-only URL resolves.
     *
     * Off by default, and deliberately: switching it on refuses every scope-bound model
     * whose seam is not yet implemented, which is correct but is not something an update
     * may do silently to a consumer. Turn it on once the models that need it answer.
     */
    private function polyslugRequiresResolutionScope(): bool
    {
        return Container::getInstance()->make(ConfigRepository::class)->get('polyslug.resolution.require_scope') === true;
    }

    /**
     * Decode a token with the current encoder, falling back to any configured
     * legacy decoders (in order) so URLs made by a previous encoder still resolve.
     */
    private function polyslugDecode(string $encodedId): int|string|null
    {
        $encoder = $this->polyslugEncoder();

        // Scoped to this model's type where the encoder can scope. A token belonging to a
        // DIFFERENT model type then decodes to null — a clean 404 — rather than to an id this
        // model would resolve against its own table. The legacy decoders below stay untyped:
        // they exist to keep URLs from a previous encoder alive, and those tokens never had
        // an owner to check against.
        $id = $encoder instanceof StoresTokensPerRecord
            ? $encoder->decodeWithin($this->getMorphClass(), $encodedId)
            : $encoder->decode($encodedId);

        if ($id !== null) {
            return $id;
        }

        $legacy = Container::getInstance()->make(ConfigRepository::class)->get('polyslug.legacy_decoders', []);

        foreach (is_array($legacy) ? $legacy : [] as $decoder) {
            if (! is_string($decoder)) {
                continue;
            }
            if (! class_exists($decoder)) {
                continue;
            }
            $instance = Container::getInstance()->make($decoder);

            if ($instance instanceof IdentityEncoder) {
                $id = $instance->decode($encodedId);

                if ($id !== null) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * The resolution gate. Override it to constrain which rows a slug may resolve to.
     *
     * `static` in the generic, because that is what `$this->newQuery()` carries on a model
     * that is not final. larastan up to 3.11.0 typed that call as a builder for the class
     * itself, which made a `Builder<static>` gate uncallable from such a model, so the gate
     * declared `self` for a while. larastan 3.12.0 keeps `static` on the call
     * (larastan/larastan#2544), and from there on `self` is the spelling a non-final model
     * cannot satisfy, because `Builder` is invariant in its model. A final
     * model is unaffected either way: `static` and the class are the same type there.
     * polyslugResolveByKey() still narrows the row it finds, because an override may answer
     * with a query for a different model.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function polyslugResolveQuery(Builder $query): Builder
    {
        return $query;
    }

    public function polyslugIsRoutable(?string $locale = null): bool
    {
        return true;
    }

    /**
     * The robots directive written for a locale this model's gate keeps out.
     *
     * `none` is the historical answer and stays the default, but it is the STRONGER of
     * the two statements available: per spec it means `noindex, nofollow`. The gate is
     * about indexability — it says a page must not appear in results. It says nothing
     * about whether the links ON that page can be trusted, and `nofollow` asserts they
     * cannot. For a draft, a gated preview or a tenant-internal page the wanted answer
     * is usually `noindex, follow`, which keeps the page out of the index while link
     * equity still flows through it.
     *
     * Answer with a string (`'noindex, follow'`) or a list (`['noindex', 'follow']`);
     * both are normalized to the same tag, so the rendered output cannot depend on
     * which spelling was typed. A list may also hold string-backed enum cases, such as
     * laravel/head's `RobotsRule::NoIndex`; each one counts as its value.
     *
     * The answer must still PREVENT INDEXING — it needs `noindex` or `none`. This is
     * the branch for a page the gate hides, so a permissive directive would contradict
     * the reason the branch was entered, and an empty one is worse than permissive:
     * laravel/head renders no tag at all for it, and a page with no robots meta is
     * indexable by default. Both are refused with MisconfiguredPolyslug rather than
     * silently un-gating the page.
     *
     * @return string|list<string|BackedEnum>
     */
    public function polyslugRobotsDirective(?string $locale = null): string|array
    {
        return 'none';
    }

    public function polyslugSupersededBy(): ?Sluggable
    {
        return null;
    }

    public function polyslugIsGone(): bool
    {
        return false;
    }

    /**
     * When this record's CONTENT last changed, for the sitemap's <lastmod>. Null emits none.
     *
     * ON THE TRAIT, NOT ON THE CONTRACT, for the reason polyslugRobotsDirective() is: an
     * application implementing Sluggable by hand keeps compiling and keeps emitting no
     * <lastmod>, which is what it had before. The sitemap reaches it through method_exists().
     *
     * Null by DEFAULT rather than `updated_at`, and that is the design rather than a stub.
     * `lastmod` is the one sitemap hint search engines still read, and they read it only while
     * it stays accurate: a timestamp that moves on every write — a view counter, a cached
     * column, a nightly re-import that touches every row — turns the field into noise, and the
     * documented response is to disregard it for the whole site rather than for the row. So
     * nothing is emitted until a model says what a meaningful change is. Wherever `updated_at`
     * only moves with the content, `return $this->updated_at;` is the whole implementation.
     */
    public function polyslugLastModified(): ?DateTimeInterface
    {
        return null;
    }

    public function polyslugOnDeleted(): void
    {
        if ($this->polyslugIsForceDeleting()) {
            // Hard or force delete: remove the slug rows so no orphaned URLs linger in
            // the resolver or a sitemap.
            $this->slugs()->forceDelete();

            return;
        }

        // Soft delete: free the slug for reuse only when the model opts in.
        if ($this->polyslugConfig()->onDelete === 'release') {
            $this->slugs()->delete();
        }
    }

    private function polyslugIsForceDeleting(): bool
    {
        return DeletionState::isForceDeleting($this);
    }

    /**
     * The current slug row for a locale.
     *
     * Reads an EAGER-LOADED `slugs` relation when one is present, so a rendered list pays
     * one query for every model instead of one per model per read. Without this, `slugs()`
     * hands back the relation BUILDER and every read issues a fresh SELECT — which made
     * `->with('slugs')` actively worse than not eager-loading at all: one extra query, and
     * nothing using it.
     *
     * `$fresh` is not an optimization switch, it is a correctness one, and it is why this
     * takes a parameter rather than always preferring the relation. A loaded collection
     * describes the world at the moment it was loaded. Every READ may use it; no WRITE may,
     * because a write decides what to store against what is current NOW — and `writeSlug()`
     * re-asks inside its retry loop precisely because a concurrent writer may have moved the
     * row since the previous attempt. Answering that from a cached collection would make the
     * retry loop consult the same stale row forever.
     */
    private function currentSlugRow(?string $locale = null, bool $fresh = false): ?PolyslugSlug
    {
        $locale ??= $this->polyslugLocale();

        if (! $fresh && $this->relationLoaded('slugs')) {
            // sortByDesc('id')->first(), matching the query's `orderByDesc('id')` exactly.
            // Taking the first match instead would take the OLDEST current row and disagree
            // with every non-eager read — a divergence no correctness test would show,
            // because both answers are real slugs of the same model.
            return $this->currentSlugsInMemory($locale)->sortByDesc('id')->first();
        }

        return $this->slugs()
            ->where('locale', $locale)
            ->where('is_current', true)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The loaded relation, narrowed to the current rows of one locale.
     *
     * @return Collection<int, PolyslugSlug>
     */
    private function currentSlugsInMemory(?string $locale): Collection
    {
        /** @var Collection<int, PolyslugSlug> $slugs */
        $slugs = $this->getRelation('slugs');

        return $slugs->filter(
            fn (PolyslugSlug $slug): bool => $slug->is_current
                && ($locale === null || $slug->locale === $locale)
        );
    }

    private function polyslugConfig(): PolyslugConfig
    {
        return PolyslugConfigResolver::resolve($this);
    }

    private function polyslugEncoder(): IdentityEncoder
    {
        $config = $this->polyslugConfig();
        $instance = $config->encoder === null ? Container::getInstance()->make(IdentityEncoder::class) : Container::getInstance()->make($config->encoder);

        if (! $instance instanceof IdentityEncoder) {
            throw new InvalidArgumentException(sprintf(
                'The #[Polyslug] encoder on %s must implement %s.',
                static::class,
                IdentityEncoder::class,
            ));
        }

        // Per-model Sqids options give this model its own token space.
        if ($config->encoderOptions !== [] && $instance instanceof SqidsEncoder) {
            return $this->polyslugSqidsEncoder($config->encoderOptions);
        }

        // Per-model token settings, so one model can have short URLs without every model
        // paying for it — a public list whose URL people retype is a different problem from
        // a share link that has to survive being guessed at, and both can live in one app.
        if ($config->encoderOptions !== [] && ($instance instanceof RandomTokenEncoder || $instance instanceof SequentialTokenEncoder)) {
            return $this->polyslugTokenEncoder($instance, $config->encoderOptions);
        }

        return $instance;
    }

    /**
     * The Sqids encoder for a given set of per-model options, built once per request or job.
     *
     * A Sqid is computed from the key and holds nothing, so sharing one is not about state, as
     * it is for the stored-token encoders below. It is about cost: building one shuffles the
     * alphabet and compiles the blocklist, and this runs for every route key and for every
     * locale of an hreflang set.
     *
     * @param  array<string, mixed>  $options
     */
    private function polyslugSqidsEncoder(array $options): SqidsEncoder
    {
        $alphabet = $options['alphabet'] ?? null;
        $minLength = $options['min_length'] ?? null;
        $alphabet = is_string($alphabet) ? $alphabet : null;
        $minLength = is_int($minLength) ? $minLength : 0;

        $container = Container::getInstance();
        $key = SqidsEncoder::class.':'.($alphabet === null ? '-' : 'a='.$alphabet).':'.$minLength;

        if (! $container->bound($key)) {
            $container->scoped($key, static fn (): SqidsEncoder => new SqidsEncoder($alphabet, $minLength));
        }

        // The key is written nowhere else and only ever with this type.
        /** @var SqidsEncoder $encoder */
        $encoder = $container->make($key);

        return $encoder;
    }

    /**
     * The shared stored-token encoder for a given set of per-model options.
     *
     * Shared rather than constructed per call, and the reason is the same one that makes the
     * container bindings shared: these encoders memoize what they have read, so a fresh
     * instance per call means one query per rendered row and a polyslugPreload() that fills a
     * memo nobody reads.
     *
     * Kept in the container as a scoped entry rather than in a static property on the trait.
     * Laravel drops scoped entries after every request under Octane and after every job a
     * queue worker runs, so the memo never describes an older database than the request or
     * job that reads it. A static would survive both and grow a memo nothing ever clears.
     *
     * An override that changes nothing leaves it alone: the container-bound instance is
     * already configured from the application's own settings, so "no per-model override", "an
     * override that says nothing" and "an override that repeats the application's setting"
     * all land on the same object, and a counted scheme on the same shared space.
     *
     * Any other override is a space of the model's own. A counted one is numbered from the
     * model's own rows rather than past every row in the table, so its shortest tokens come
     * first; a random one draws the same way wherever it is.
     *
     * @param  array<string, mixed>  $options
     */
    private function polyslugTokenEncoder(RandomTokenEncoder|SequentialTokenEncoder $instance, array $options): IdentityEncoder
    {
        $length = $options['length'] ?? null;
        $alphabet = $options['alphabet'] ?? null;

        // Each setting the override leaves out falls back to the one the ENCODER already
        // carries, not to the class default: an override that names only an alphabet is asking
        // to change the alphabet, and one that names only a length is asking to change the
        // length. Resetting the other to its default would undo the application's own setting
        // while looking like it did nothing.
        $width = is_int($length) ? $length : $instance->scheme()->length();
        $characters = is_string($alphabet) ? new TokenAlphabet($alphabet) : $instance->scheme()->alphabet();

        if ($width === $instance->scheme()->length() && $characters->alphabet === $instance->scheme()->alphabet()->alphabet) {
            return $instance;
        }

        $container = Container::getInstance();
        $key = $instance::class.':'.$width.':'.$characters->alphabet;

        // The closure captures what kind of encoder to build, never the instance it came from:
        // a binding outlives every flush of its instance, and a captured encoder would keep its
        // memo alive with it.
        $sequential = $instance instanceof SequentialTokenEncoder;

        if (! $container->bound($key)) {
            $container->scoped($key, static fn (): IdentityEncoder => $sequential ? new SequentialTokenEncoder($width, $characters, ownSpace: true) : new RandomTokenEncoder($width, $characters));
        }

        // The key is written nowhere else and only ever with this type, so the annotation
        // states a fact rather than asking to be trusted.
        /** @var IdentityEncoder $encoder */
        $encoder = $container->make($key);

        return $encoder;
    }

    /**
     * This record's token.
     *
     * The morph type goes with it whenever the encoder can hold one. A stored-token encoder
     * then keeps a space per type, so Page#1 and Wishlist#1 no longer share a token — and one
     * model's URL no longer yields another model's URL for the same id. A computed encoder
     * (Sqids, UUID, ULID, the raw key) derives its token from the key alone and cannot be
     * given an owner, which is why the capability is asked for rather than assumed.
     */
    private function polyslugEncodedKey(): string
    {
        $encoder = $this->polyslugEncoder();

        return $encoder instanceof StoresTokensPerRecord
            ? $encoder->encodeWithin($this->getMorphClass(), $this->polyslugKeyString())
            : $encoder->encode($this->polyslugKeyString());
    }

    private function polyslugKeyString(): string
    {
        $key = $this->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    private function polyslugLocale(): string
    {
        // The application's current locale, read the way the framework itself stores
        // it: Application::getLocale() returns config('app.locale'), and setLocale()
        // writes there — so this is the same value, not an approximation, and it needs
        // no Foundation helper.
        $locale = Container::getInstance()->make(ConfigRepository::class)->get('app.locale');

        return is_string($locale) ? $locale : '';
    }

    private function polyslugDefaultLocale(): string
    {
        $config = Container::getInstance()->make(ConfigRepository::class);
        $configured = $config->get('polyslug.locale.fallback_locale');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        // Same reasoning as above: getFallbackLocale() is config('app.fallback_locale').
        $fallback = $config->get('app.fallback_locale');

        return is_string($fallback) ? $fallback : '';
    }

    private function polyslugSource(PolyslugConfig $config): string
    {
        $parts = [];

        foreach ($config->source as $column) {
            $value = $this->polyslugColumnValue($this->getAttribute($column));

            if (is_scalar($value)) {
                $parts[] = (string) $value;
            }
        }

        return implode(' ', $parts);
    }

    private function polyslugScope(PolyslugConfig $config): string
    {
        return $this->polyslugScopeKey($config, fn (string $column): mixed => $this->getAttribute($column));
    }

    /**
     * The stored scope key for a set of column values.
     *
     * ONE builder for both directions on purpose: the write path fills it from the model's
     * own attributes, the read path from whatever the caller named. A key assembled in two
     * places is a key that eventually disagrees with itself, and the disagreement would
     * look like "no such slug".
     *
     * @param  callable(string): mixed  $valueFor
     */
    private function polyslugScopeKey(PolyslugConfig $config, callable $valueFor): string
    {
        $values = [];

        foreach ($config->scope as $column) {
            $value = $this->polyslugColumnValue($valueFor($column));

            // The cast states the intent; it does not change the result. Concatenation coerces
            // every scalar to the same string either way, so this is for the reader and the
            // analyzer rather than for the key.
            $values[] = is_scalar($value) ? (string) $value : '';
        }

        // A value that carries the separator lets two scopes of several columns spell one key:
        // (owner: 'a|project:b', project: '') and (owner: 'a', project: 'b|project:') both read
        // 'owner:a|project:b|project:'. Such a key is written with every value percent-encoded,
        // behind a leading separator. A plain key starts with a column name, and no column name
        // carries the separator, so the two forms never meet. Every other key, every key of a
        // single column included, stays what it was written as.
        $escaped = count($values) > 1 && str_contains(implode('', $values), '|');
        $parts = [];

        foreach ($config->scope as $index => $column) {
            $parts[] = $column.':'.($escaped ? rawurlencode($values[$index]) : $values[$index]);
        }

        $key = ($escaped ? '|' : '').implode('|', $parts);

        // The key is stored in a column as long as the schema's default string length. One that
        // would not fit is stored as its digest, which every read builds the same way; one that
        // fits is stored as it is, so the keys already written stay what they were. A scope value
        // can come from a user, and two keys that shared a digest would share a scope, so the
        // digest is one whose collisions cannot be constructed.
        return mb_strlen($key) > SchemaBuilder::$defaultStringLength ? 'sha256:'.hash('sha256', $key) : $key;
    }

    /**
     * A column value as the slug source and the scope key read it.
     *
     * A cast attribute is an object, and an object is no scalar: an enum, a date or a
     * Stringable used to fall out of the source and contribute nothing to the scope key, so two
     * regions shared one scope. An enum counts by its value, a date by the string the model
     * stores for it, a Stringable by its text. An array still contributes nothing, because no
     * encoding of it would survive a change of cast.
     */
    private function polyslugColumnValue(mixed $value): mixed
    {
        $value = enum_value($value);

        return match (true) {
            $value instanceof DateTimeInterface => $this->fromDateTime($value),
            $value instanceof Stringable => (string) $value,
            default => $value,
        };
    }
}
