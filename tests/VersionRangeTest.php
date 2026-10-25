<?php

declare(strict_types=1);

namespace StackToast\Installer\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StackToast\Installer\Environment\VersionRange;

class VersionRangeTest extends TestCase
{
    /**
     * The range Vite declares, which is the one that matters.
     */
    private const VITE = '^20.19.0 || >=22.12.0';

    /**
     * @return array<string, array{string, bool}>
     */
    public static function nodeVersions(): array
    {
        return [
            '20.19.0 exactly' => ['20.19.0', true],
            '20.20.1' => ['v20.20.1', true],
            '20.18.3 is below the caret floor' => ['20.18.3', false],
            '21.7.0 is outside the caret and below the floor' => ['21.7.0', false],
            '22.11.0 is below the second floor' => ['22.11.0', false],
            '22.12.0 exactly' => ['22.12.0', true],
            '24.1.0' => ['v24.1.0', true],
            '18.20.0' => ['18.20.0', false],
        ];
    }

    #[DataProvider('nodeVersions')]
    public function test_the_vite_range(string $version, bool $expected): void
    {
        $this->assertSame($expected, VersionRange::satisfies($version, self::VITE));
    }

    public function test_a_two_part_version_is_read_as_a_patch_zero(): void
    {
        $this->assertTrue(VersionRange::satisfies('22.12', self::VITE));
        $this->assertFalse(VersionRange::satisfies('22.11', self::VITE));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unreadableRanges(): array
    {
        return [
            'a tilde' => ['~20.19.0'],
            'a comparison this does not implement' => ['<21'],
            'an interval' => ['>=20 <23'],
            'a wildcard' => ['20.x'],
            'empty' => [''],
        ];
    }

    /**
     * An unrecognised range must not be guessed at: the caller reports it as
     * untested rather than passing or failing a machine wrongly.
     */
    #[DataProvider('unreadableRanges')]
    public function test_an_unreadable_range_is_null(string $range): void
    {
        $this->assertNull(VersionRange::satisfies('22.12.0', $range));
    }

    public function test_an_unreadable_version_is_null(): void
    {
        $this->assertNull(VersionRange::satisfies('not-a-version', self::VITE));
    }
}
