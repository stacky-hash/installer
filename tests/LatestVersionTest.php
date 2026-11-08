<?php

declare(strict_types=1);

namespace StackToast\Installer\Tests;

use PHPUnit\Framework\TestCase;
use StackToast\Installer\Application;
use StackToast\Installer\Update\LatestVersion;

class LatestVersionTest extends TestCase
{
    public function test_it_reports_a_newer_release(): void
    {
        $this->assertSame('1.2.0', LatestVersion::fromJson($this->metadata(['1.0.0', '1.1.0', '1.2.0']), '1.0.0'));
    }

    public function test_it_reports_nothing_when_current_is_newest(): void
    {
        $this->assertNull(LatestVersion::fromJson($this->metadata(['1.0.0', '1.1.0']), '1.1.0'));
    }

    public function test_it_reports_nothing_when_current_is_ahead(): void
    {
        // A customer running a release candidate of the next version should
        // not be told to downgrade.
        $this->assertNull(LatestVersion::fromJson($this->metadata(['1.1.0']), '1.2.0'));
    }

    public function test_it_ignores_branch_aliases_and_pre_releases(): void
    {
        $json = $this->metadata(['1.0.0', 'dev-main', '2.0.0-beta.1', '9999999-dev']);

        $this->assertNull(LatestVersion::fromJson($json, '1.0.0'));
    }

    public function test_it_accepts_a_leading_v(): void
    {
        $this->assertSame('1.3.0', LatestVersion::fromJson($this->metadata(['v1.3.0']), '1.0.0'));
    }

    public function test_it_takes_the_highest_version_not_the_last_one(): void
    {
        // Packagist orders its own metadata, and that order is not something
        // to depend on.
        $this->assertSame('1.10.0', LatestVersion::fromJson($this->metadata(['1.10.0', '1.9.0']), '1.0.0'));
    }

    public function test_unusable_metadata_is_no_advice_rather_than_an_error(): void
    {
        $this->assertNull(LatestVersion::fromJson('not json', '1.0.0'));
        $this->assertNull(LatestVersion::fromJson('{}', '1.0.0'));
        $this->assertNull(LatestVersion::fromJson('{"packages":[]}', '1.0.0'));
        $this->assertNull(LatestVersion::fromJson('{"packages":{"x":[{"version":1}]}}', '1.0.0'));
    }

    public function test_a_fetch_that_fails_reports_nothing(): void
    {
        $this->assertNull(LatestVersion::newerThan('1.0.0', static fn (): ?string => null));
    }

    public function test_the_shipped_version_is_a_release(): void
    {
        // A caret constraint on a 0.x version never crosses to the next
        // minor, so `composer global update` would never reach a new release.
        $this->assertMatchesRegularExpression('/^[1-9]\d*\.\d+\.\d+$/', Application::VERSION);
    }

    /**
     * Packagist's v2 metadata, carrying the given versions.
     *
     * @param  array<int, string>  $versions
     */
    private function metadata(array $versions): string
    {
        return (string) json_encode([
            'packages' => [
                'stacktoast/installer' => array_map(
                    static fn (string $version): array => ['version' => $version],
                    $versions
                ),
            ],
        ]);
    }
}
