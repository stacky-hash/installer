<?php

declare(strict_types=1);

namespace StackToast\Installer\Environment;

/**
 * Whether a version satisfies a node-style range.
 *
 * Understands only the two forms the application's tooling declares —
 * `^major.minor.patch` and `>=major.minor.patch`, joined by `||`. Anything
 * else returns null rather than guessing, so an unrecognised range is
 * reported as untested instead of wrongly passed or wrongly failed.
 *
 * A term has to be consumed whole for that promise to hold: read loosely,
 * `>=20 <23` matches its first half and silently drops the ceiling.
 */
final class VersionRange
{
    /**
     * A version as a range term may write it, and nothing else.
     */
    private const EXACT = '/^(\d+)(?:\.(\d+))?(?:\.(\d+))?$/';

    /**
     * A version as a tool reports it: `v22.14.0`, or with a suffix.
     */
    private const REPORTED = '/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/';

    /**
     * @return bool|null True or false when the range is understood, null when it is not
     */
    public static function satisfies(string $version, string $range): ?bool
    {
        $version = self::reported($version);

        if ($version === null) {
            return null;
        }

        $satisfied = false;

        foreach (explode('||', $range) as $term) {
            $result = self::satisfiesTerm($version, trim($term));

            if ($result === null) {
                return null;
            }

            $satisfied = $satisfied || $result;
        }

        return $satisfied;
    }

    private static function satisfiesTerm(string $version, string $term): ?bool
    {
        if (str_starts_with($term, '>=')) {
            $floor = self::exact(substr($term, 2));

            return $floor === null ? null : version_compare($version, $floor, '>=');
        }

        if (str_starts_with($term, '^')) {
            $floor = self::exact(substr($term, 1));

            if ($floor === null) {
                return null;
            }

            $ceiling = (((int) explode('.', $floor)[0]) + 1).'.0.0';

            return version_compare($version, $floor, '>=')
                && version_compare($version, $ceiling, '<');
        }

        return null;
    }

    /**
     * A range term's version, or null when the term carries anything more.
     */
    private static function exact(string $version): ?string
    {
        return self::parse(trim(ltrim(trim($version), 'vV=')), self::EXACT);
    }

    /**
     * A reported version, taking the leading numbers and ignoring a suffix.
     */
    private static function reported(string $version): ?string
    {
        return self::parse(ltrim(trim($version), 'vV='), self::REPORTED);
    }

    private static function parse(string $version, string $pattern): ?string
    {
        if (preg_match($pattern, $version, $matches) !== 1) {
            return null;
        }

        return sprintf(
            '%d.%d.%d',
            (int) $matches[1],
            (int) ($matches[2] ?? 0),
            (int) ($matches[3] ?? 0)
        );
    }
}
