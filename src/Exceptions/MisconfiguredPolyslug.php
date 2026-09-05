<?php

declare(strict_types=1);

namespace Polyslug\Exceptions;

use RuntimeException;

/**
 * Thrown when a #[Polyslug] attribute (or a PolyslugConfig from a polyslug() override)
 * combines mutually exclusive options.
 *
 * Two shapes of mistake end up here. One is a pair that contradicts itself — `idLess: true`
 * with `unique: false`, where the URL is the slug alone and so the slug must stay unique to
 * resolve; or `slugless: true` with `idLess: true`, which drops both halves of the URL. The
 * other is an option that would quietly do NOTHING, which is refused for the reason the
 * reclaim messages give: whoever set it believes a behavior changed, and the only way they
 * would find out otherwise is from the behavior they were trying to change.
 */
final class MisconfiguredPolyslug extends RuntimeException
{
    public static function reclaimRequiresIdLess(): self
    {
        return new self(
            'A #[Polyslug] model cannot set `reclaim: true` without `idLess: true`: on a model whose '
            .'URL carries an encoded id, a retired slug is already free to reuse, so reclaim would '
            .'change nothing. Add `idLess: true` if the URL is the slug alone, or drop `reclaim`.'
        );
    }

    public static function reclaimActiveRequiresReclaim(): self
    {
        return new self(
            'A #[Polyslug] model cannot set `reclaimActive: true` without `reclaim: true`: '
            .'reclaimActive widens reclaim from retired names to a name another record still '
            .'holds, so on its own it would take the name from a live holder and then be refused '
            .'by that holder\'s own retired rows. Add `reclaim: true`, or drop `reclaimActive`.'
        );
    }

    public static function sluglessExcludesIdLess(): self
    {
        return new self(
            'A #[Polyslug] model cannot combine `slugless: true` with `idLess: true`: slugless drops '
            .'the slug and keeps the encoded id, idLess drops the encoded id and keeps the slug, so '
            .'together they leave nothing for the URL to carry. Pick the half the URL should be.'
        );
    }

    public static function sluglessExcludesMaxLength(): self
    {
        return new self(
            'A #[Polyslug] model cannot combine `slugless: true` with `maxLength`: maxLength trims '
            .'the SLUG, and a slugless model has none — the length of its URL is the length of the '
            .'encoder token. Set `polyslug.random_token.length`, or '
            .'`encoderOptions: [\'length\' => …]` for this model alone, and drop `maxLength`.'
        );
    }

    public static function sluglessExcludesReserved(): self
    {
        return new self(
            'A #[Polyslug] model cannot combine `slugless: true` with `reserved`: reserved words keep '
            .'a generated SLUG from taking a name, and a slugless model generates none. Drop '
            .'`reserved`, or drop `slugless: true` if the URL should carry a name after all.'
        );
    }

    public static function sluglessTakesNoSource(): self
    {
        return new self(
            'A #[Polyslug] model cannot combine `slugless: true` with `source`: the source columns '
            .'exist to build a slug, and a slugless URL carries none — leaving them would read as if '
            .'renaming the record still changed its URL. Drop `source`.'
        );
    }

    public static function sourceIsRequired(): self
    {
        return new self(
            'A #[Polyslug] model must declare `source` — the column(s) its slug is built from. The '
            .'only exception is `slugless: true`, whose URL is the encoder token alone and has no '
            .'slug to build.'
        );
    }

    public static function preserveCaseExcludesNativeUnicode(): self
    {
        return new self(
            'A #[Polyslug] model cannot combine `preserveCase: true` with `unicode: \'native\'`: uniqueness is '
            .'enforced by a case-insensitive index, and `lower()` does not agree across engines on non-ASCII '
            .'letters — PostgreSQL folds them, SQLite does not. An unfolded native slug would therefore collide '
            .'on one engine and not on another. `unicode: \'ascii\'` transliterates first, so every stored slug '
            .'is ASCII and every engine folds it the same way.'
        );
    }

    /**
     * @param  list<string>  $directives
     */
    public static function robotsDirectiveMustPreventIndexing(string $model, array $directives): self
    {
        $given = $directives === [] ? 'nothing' : '`'.implode(', ', $directives).'`';

        return new self(
            $model.'::polyslugRobotsDirective() returned '.$given.', which does not keep the page out '
            .'of the index. This method is only consulted for a locale polyslugIsRoutable() already '
            .'refused, so the directive must contain `noindex` or `none` — anything else contradicts '
            .'the gate that hides the page. An empty answer is refused for a sharper reason: it renders '
            .'NO robots tag at all, and a page without one is indexable by default. Use '
            .'`[\'noindex\', \'follow\']` to keep the page out of the index while its links still '
            .'count, or drop the override to keep the historical `none`.'
        );
    }

    /**
     * @param  list<string>  $unknown
     */
    public static function robotsDirectiveIsNotVocabulary(string $model, array $unknown): self
    {
        return new self(
            $model.'::polyslugRobotsDirective() returned `'.implode(', ', $unknown).'`, which is not a '
            .'robots directive any crawler defines. An unrecognized token is silently ignored, so a '
            .'typo in a directive meant to restrict a page does nothing and looks like it worked — '
            .'`nofollw` for `nofollow` renders a tag a reader would swear is correct. Use one of: '
            .'all, noindex, nofollow, none, noarchive, nosnippet, indexifembedded, notranslate, '
            .'noimageindex, max-snippet:N, max-image-preview:none|standard|large, max-video-preview:N, '
            .'unavailable_after:DATE.'
        );
    }

    public static function idLessRequiresUnique(): self
    {
        return new self(
            'A #[Polyslug] model cannot combine `idLess: true` with `unique: false`: an idLess URL is '
            .'the slug alone, so the slug must stay unique to resolve. Drop `unique: false`, or drop '
            .'`idLess: true` so the encoded id can disambiguate records that share a slug.'
        );
    }
}
