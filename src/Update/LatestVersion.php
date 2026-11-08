<?php

declare(strict_types=1);

namespace StackToast\Installer\Update;

/**
 * Whether a newer release of this package has been published.
 *
 * The edition catalogue is bundled and read from disk, so an edition added
 * after a customer installed the CLI is invisible to them until they update
 * it. Nothing otherwise tells them there is anything to update.
 *
 * Read-only and advisory: this reports a version, it does not install one.
 */
final class LatestVersion
{
    /**
     * Packagist's metadata for this package. Static JSON from a CDN, with no
     * query string and nothing in it that is executed.
     */
    public const URL = 'https://repo.packagist.org/p2/stacktoast/installer.json';

    /**
     * Seconds to wait. Short on purpose: this runs alongside a command whose
     * job is something else, and a slow mirror must not hold it up.
     */
    private const TIMEOUT = 2;

    /**
     * A released version, and nothing else. A pre-release or a branch alias
     * is not something to send a customer to.
     */
    private const RELEASE = '/^v?(\d+\.\d+\.\d+)$/';

    /**
     * The newest published release, when it is newer than the one running.
     *
     * @param  (callable(): (string|null))|null  $fetch  Supplies the metadata; omitted, it is read from Packagist
     * @return string|null The newer version, or null when there is none, or when it could not be determined
     */
    public static function newerThan(string $current, ?callable $fetch = null): ?string
    {
        $json = $fetch === null ? self::read() : $fetch();

        if ($json === null) {
            return null;
        }

        return self::fromJson($json, $current);
    }

    /**
     * The newest release in Packagist metadata, when it beats $current.
     *
     * Never throws: the answer is advice, and unusable metadata means no
     * advice rather than a failed command.
     */
    public static function fromJson(string $json, string $current): ?string
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! is_array($decoded['packages'] ?? null)) {
            return null;
        }

        $newest = null;

        /** @var mixed $releases */
        foreach ($decoded['packages'] as $releases) {
            if (! is_array($releases)) {
                continue;
            }

            /** @var mixed $release */
            foreach ($releases as $release) {
                $version = is_array($release) ? ($release['version'] ?? null) : null;

                if (! is_string($version) || preg_match(self::RELEASE, $version, $matches) !== 1) {
                    continue;
                }

                if ($newest === null || version_compare($matches[1], $newest, '>')) {
                    $newest = $matches[1];
                }
            }
        }

        if ($newest === null || version_compare($newest, $current, '<=')) {
            return null;
        }

        return $newest;
    }

    /**
     * The metadata, or null if it could not be read for any reason.
     *
     * `STACKTOAST_NO_UPDATE_CHECK` suppresses the request, for a CI job that
     * should not reach the network and for this package's own tests.
     */
    private static function read(): ?string
    {
        if ((string) getenv('STACKTOAST_NO_UPDATE_CHECK') !== '') {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT,
                'header' => "Accept: application/json\r\nUser-Agent: stacktoast-installer\r\n",
                // Read a 4xx or 5xx body rather than returning false, so a
                // response that is not JSON is handled in one place below.
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $contents = @file_get_contents(self::URL, false, $context);

        return $contents === false ? null : $contents;
    }
}
