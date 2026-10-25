<?php

declare(strict_types=1);

namespace StackToast\Installer\Environment;

use PDO;
use PDOException;
use StackToast\Installer\Git;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * What the application needs of a machine, and what this one has.
 *
 * Runs before anything is bought or cloned, so the requirements are the
 * application's rather than this package's. Only a Fail stops an install;
 * a Warn is something that will bite later in a way a message can prevent.
 */
class Doctor
{
    /**
     * Extensions the application cannot run without.
     *
     * `intl` is the one that surprises people: the money helpers format
     * through NumberFormatter, so every invoice and every billing email
     * needs it, and Laravel's own requirements do not include it.
     *
     * @var array<int, string>
     */
    public const REQUIRED_EXTENSIONS = [
        'ctype',
        'curl',
        'dom',
        'fileinfo',
        'filter',
        'hash',
        'intl',
        'mbstring',
        'openssl',
        'pcre',
        'pdo',
        'session',
        'tokenizer',
        'xml',
    ];

    /**
     * Extensions something degrades without.
     *
     * @var array<string, string>
     */
    public const OPTIONAL_EXTENSIONS = [
        'gd' => 'Invoice PDFs render without images.',
        'zip' => 'Needed only if you add archive handling of your own.',
    ];

    /**
     * The PDO drivers the application is tested against.
     *
     * @var array<int, string>
     */
    public const DATABASE_DRIVERS = ['mysql', 'pgsql', 'sqlite'];

    public function __construct(
        private readonly Git $git = new Git,
        private readonly ExecutableFinder $finder = new ExecutableFinder,
    ) {}

    /**
     * @param  array{php?: string, node?: string, directory?: string, dsn?: string, username?: string, password?: string}  $context
     * @return array<int, Result>
     */
    public function run(array $context = []): array
    {
        return [
            $this->php($context['php'] ?? '8.4.0'),
            ...$this->extensions(),
            $this->databaseDriver(),
            $this->composer(),
            $this->gitBinary(),
            ...$this->node($context['node'] ?? '^20.19.0 || >=22.12.0'),
            $this->bash(),
            $this->writable($context['directory'] ?? getcwd() ?: '.'),
            $this->database($context['dsn'] ?? null, $context['username'] ?? null, $context['password'] ?? null),
        ];
    }

    /**
     * Whether any result is fatal.
     *
     * @param  array<int, Result>  $results
     */
    public function failed(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->outcome === Outcome::Fail) {
                return true;
            }
        }

        return false;
    }

    protected function php(string $minimum): Result
    {
        $found = PHP_VERSION;

        if (version_compare($found, $minimum, '>=')) {
            return Result::pass("PHP {$minimum} or newer", $found);
        }

        return Result::fail(
            "PHP {$minimum} or newer",
            $found,
            "Install PHP {$minimum}. On Windows, point your PATH at the newer build; php.ini carries over."
        );
    }

    /**
     * @return array<int, Result>
     */
    protected function extensions(): array
    {
        $missing = array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $extension): bool => ! extension_loaded($extension)
        ));

        $results = [$missing === []
            ? Result::pass('PHP extensions', count(self::REQUIRED_EXTENSIONS).' present')
            : Result::fail(
                'PHP extensions',
                'missing '.implode(', ', $missing),
                'Enable them in php.ini and restart any PHP-FPM or web server process.'
            )];

        foreach (self::OPTIONAL_EXTENSIONS as $extension => $consequence) {
            $results[] = extension_loaded($extension)
                ? Result::pass("ext-{$extension} (optional)", 'present')
                : Result::warn("ext-{$extension} (optional)", 'missing', $consequence);
        }

        return $results;
    }

    protected function databaseDriver(): Result
    {
        $available = array_values(array_intersect(self::DATABASE_DRIVERS, PDO::getAvailableDrivers()));

        if ($available === []) {
            return Result::fail(
                'A PDO driver',
                'none of '.implode(', ', self::DATABASE_DRIVERS),
                'Enable pdo_mysql, pdo_pgsql or pdo_sqlite. SQLite needs no server and is enough to get started.'
            );
        }

        return Result::pass('A PDO driver', implode(', ', $available));
    }

    protected function composer(): Result
    {
        $version = $this->commandVersion('composer', ['--version', '--no-interaction']);

        return $version === null
            ? Result::fail('Composer', 'not found on PATH', 'Install Composer 2: https://getcomposer.org/download/')
            : Result::pass('Composer', $version);
    }

    protected function gitBinary(): Result
    {
        if (! $this->git->available()) {
            return Result::fail(
                'git',
                'not found on PATH',
                'Install git. The installer clones over HTTPS and keeps upstream as a remote, which is how upgrades merge.'
            );
        }

        return Result::pass('git', $this->git->version() ?? 'present');
    }

    /**
     * @return array<int, Result>
     */
    protected function node(string $range): array
    {
        $node = $this->commandVersion('node', ['--version']);

        if ($node === null) {
            return [
                Result::fail(
                    "Node {$range}",
                    'not found on PATH',
                    'Install Node. Without it there is no asset build, so no CSS and no JavaScript.'
                ),
                Result::fail('npm', 'not found on PATH', 'Ships with Node.'),
            ];
        }

        $satisfies = VersionRange::satisfies($node, $range);

        $results = [match ($satisfies) {
            true => Result::pass("Node {$range}", $node),
            false => Result::fail(
                "Node {$range}",
                $node,
                'Vite declares this range; an older Node fails the build rather than warning.'
            ),
            null => Result::skip("Node {$range}", "found {$node}; the range could not be read"),
        }];

        $npm = $this->commandVersion('npm', ['--version']);

        $results[] = $npm === null
            ? Result::fail('npm', 'not found on PATH', 'Ships with Node; a PATH that has node but not npm is usually a partial install.')
            : Result::pass('npm', $npm);

        return $results;
    }

    /**
     * Bash is no longer required: the PHP wizard replaced the shell one and
     * runs natively on Windows. Reported because the shell script still
     * ships and some docs still reference it.
     */
    protected function bash(): Result
    {
        return $this->finder->find('bash') === null
            ? Result::warn('bash (optional)', 'not found on PATH', 'Only configure-stacktoast.sh needs it; configure-stacktoast.php does not.')
            : Result::pass('bash (optional)', 'present');
    }

    protected function writable(string $directory): Result
    {
        if (! is_dir($directory)) {
            return Result::fail('A writable working directory', "{$directory} does not exist", 'Create it, or run this from somewhere else.');
        }

        return is_writable($directory)
            ? Result::pass('A writable working directory', $directory)
            : Result::fail('A writable working directory', "{$directory} is not writable", 'Run this somewhere you own, or fix the permissions.');
    }

    /**
     * Opt-in: without a DSN there is nothing to connect to, and inventing
     * one would report a failure the customer does not have.
     */
    protected function database(?string $dsn, ?string $username, ?string $password): Result
    {
        if ($dsn === null || $dsn === '') {
            return Result::skip('Database reachable', 'no --db given');
        }

        try {
            new PDO($dsn, $username, $password, [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            return Result::pass('Database reachable', $dsn);
        } catch (PDOException $e) {
            return Result::fail('Database reachable', $e->getMessage(), 'Check the host, port, database name and credentials.');
        } catch (Throwable $e) {
            return Result::fail('Database reachable', $e->getMessage(), 'The DSN could not be used.');
        }
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function commandVersion(string $binary, array $arguments): ?string
    {
        $path = $this->finder->find($binary);

        if ($path === null) {
            return null;
        }

        $process = new Process([$path, ...$arguments], null, null, null, 30);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());

        if (preg_match('/(\d+\.\d+(\.\d+)?)/', $output, $matches) === 1) {
            return $matches[1];
        }

        return $output === '' ? null : $output;
    }
}
