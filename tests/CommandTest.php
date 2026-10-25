<?php

declare(strict_types=1);

namespace StackToast\Installer\Tests;

use PHPUnit\Framework\TestCase;
use StackToast\Installer\Application;
use StackToast\Installer\Brand;
use StackToast\Installer\Commands\NewCommand;
use StackToast\Installer\Commands\WhoamiCommand;
use StackToast\Installer\Environment\Doctor;
use StackToast\Installer\Git;
use StackToast\Installer\Stacks\StackCatalogue;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What each command does without touching the network.
 *
 * `new` is exercised against a Git double, because the behaviour worth
 * pinning is what a customer without access is told — and that path must not
 * depend on a repository existing.
 */
class CommandTest extends TestCase
{
    public function test_stacks_lists_every_edition(): void
    {
        $tester = new CommandTester((new Application)->find('stacks'));
        $tester->execute([]);

        $output = $tester->getDisplay();

        foreach (StackCatalogue::bundled()->all() as $stack) {
            $this->assertStringContainsString($stack->name, $output);
        }

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_stacks_json_is_machine_readable(): void
    {
        $tester = new CommandTester((new Application)->find('stacks'));
        $tester->execute(['--json' => true]);

        /** @var array<int, array<string, mixed>> $decoded */
        $decoded = json_decode($tester->getDisplay(), true);

        $this->assertIsArray($decoded);
        $this->assertSame('livewire', $decoded[0]['name']);
        $this->assertCount(count(StackCatalogue::bundled()->all()), $decoded);
    }

    public function test_check_reports_on_this_machine(): void
    {
        $tester = new CommandTester((new Application)->find('check'));
        $tester->execute(['--json' => true]);

        /** @var array{ready: bool, checks: array<int, array<string, mixed>>} $decoded */
        $decoded = json_decode($tester->getDisplay(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('ready', $decoded);

        $labels = array_column($decoded['checks'], 'label');

        $this->assertContains('PHP extensions', $labels);
        $this->assertContains('Composer', $labels);
        $this->assertContains('git', $labels);
    }

    public function test_check_skips_the_database_when_no_dsn_is_given(): void
    {
        $tester = new CommandTester((new Application)->find('check'));
        $tester->execute(['--json' => true]);

        /** @var array{checks: array<int, array<string, string>>} $decoded */
        $decoded = json_decode($tester->getDisplay(), true);

        foreach ($decoded['checks'] as $check) {
            if ($check['label'] === 'Database reachable') {
                $this->assertSame('skip', $check['outcome']);

                return;
            }
        }

        $this->fail('The database check was not reported at all.');
    }

    public function test_check_reports_intl_as_required(): void
    {
        // The money helpers format through NumberFormatter, so an install
        // without intl fails on every invoice and billing email.
        $tester = new CommandTester((new Application)->find('check'));
        $tester->execute([]);

        $this->assertContains('intl', Doctor::REQUIRED_EXTENSIONS);
    }

    public function test_new_refuses_a_name_that_is_not_a_directory_name(): void
    {
        $tester = new CommandTester((new Application)->find('new'));

        foreach (['../escape', 'has space', '/absolute', '-dash', ''] as $name) {
            $tester->execute(['name' => $name]);

            $this->assertSame(2, $tester->getStatusCode(), "Accepted {$name}");
        }
    }

    public function test_new_tells_a_non_customer_where_to_buy(): void
    {
        $git = new class extends Git
        {
            public function available(): bool
            {
                return true;
            }

            public function canReach(string $url): bool
            {
                return false;
            }
        };

        $application = new Application;
        $application->add(new NewCommand($git));

        $tester = new CommandTester($application->find('new'));
        $tester->execute(['name' => 'my-app', '--path' => sys_get_temp_dir()], ['interactive' => false]);

        $output = $tester->getDisplay();

        $this->assertStringContainsString('No access', $output);
        $this->assertStringContainsString(Brand::SITE_URL, $output);
        $this->assertStringContainsString(Brand::INVITES_URL, $output);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertDirectoryDoesNotExist(sys_get_temp_dir().DIRECTORY_SEPARATOR.'my-app');
    }

    public function test_new_refuses_an_unknown_edition(): void
    {
        $tester = new CommandTester((new Application)->find('new'));
        $tester->execute(
            ['name' => 'my-app', '--stack' => 'svelte', '--path' => sys_get_temp_dir()],
            ['interactive' => false]
        );

        $this->assertStringContainsString('Unknown edition', $tester->getDisplay());
        $this->assertSame(2, $tester->getStatusCode());
    }

    public function test_new_stops_when_a_release_has_no_tag(): void
    {
        $git = new class extends Git
        {
            public function available(): bool
            {
                return true;
            }

            public function canReach(string $url): bool
            {
                return true;
            }

            public function latestTag(string $url): ?string
            {
                return null;
            }
        };

        $application = new Application;
        $application->add(new NewCommand($git));

        $tester = new CommandTester($application->find('new'));
        $tester->execute(['name' => 'my-app', '--path' => sys_get_temp_dir()], ['interactive' => false]);

        $this->assertStringContainsString('no release tag', $tester->getDisplay());
        $this->assertSame(1, $tester->getStatusCode());
    }

    public function test_whoami_reports_every_edition(): void
    {
        $git = new class extends Git
        {
            public function available(): bool
            {
                return true;
            }

            public function canReach(string $url): bool
            {
                return str_contains($url, 'stacktoast.git');
            }
        };

        $application = new Application;
        $application->add(new WhoamiCommand($git));

        $tester = new CommandTester($application->find('whoami'));
        $tester->execute(['--json' => true]);

        /** @var array<string, bool> $decoded */
        $decoded = json_decode($tester->getDisplay(), true);

        $this->assertTrue($decoded['livewire']);
        $this->assertFalse($decoded['react']);
    }
}
