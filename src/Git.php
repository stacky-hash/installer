<?php

declare(strict_types=1);

namespace StackToast\Installer;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The git commands this installer runs.
 *
 * Arguments are passed as an array, never a shell string, so a path with a
 * space behaves the same on Windows as on a Unix shell.
 *
 * Every call runs non-interactively. Git Credential Manager opens a window
 * when it cannot authenticate, and a CLI that waits on an invisible dialog
 * looks like a hang; refusing the prompt turns that into an error the caller
 * can report.
 */
class Git
{
    /**
     * Seconds any one git call may take. A clone of the full history over a
     * slow link is the long pole.
     */
    private const TIMEOUT = 600;

    public function available(): bool
    {
        return $this->binary() !== null;
    }

    public function version(): ?string
    {
        $process = $this->run(['--version']);

        if (! $process instanceof Process || ! $process->isSuccessful()) {
            return null;
        }

        if (preg_match('/(\d+\.\d+(\.\d+)?)/', $process->getOutput(), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Whether this machine's credentials can see a repository.
     *
     * GitHub answers 404 rather than 403 for a private repository the caller
     * cannot see, so a failure here does not distinguish "not a customer"
     * from "no such repository" — which is the behaviour that keeps the SKU
     * list from leaking.
     */
    public function canReach(string $url): bool
    {
        $process = $this->run(['ls-remote', '--exit-code', '--quiet', $url, 'HEAD']);

        return $process instanceof Process && $process->isSuccessful();
    }

    /**
     * The highest semantic version tag on a remote, or null when it has none.
     *
     * Read from the remote rather than after cloning, so a repository with no
     * release is reported before anything is written to disk.
     */
    public function latestTag(string $url): ?string
    {
        $process = $this->run(['ls-remote', '--tags', '--refs', $url]);

        if (! $process instanceof Process || ! $process->isSuccessful()) {
            return null;
        }

        $tags = [];

        foreach (preg_split('/\R/', $process->getOutput()) ?: [] as $line) {
            if (preg_match('#refs/tags/(v?\d+\.\d+\.\d+[^\s]*)$#', trim($line), $matches) === 1) {
                $tags[] = $matches[1];
            }
        }

        if ($tags === []) {
            return null;
        }

        usort($tags, static fn (string $a, string $b): int => version_compare(
            ltrim($a, 'vV'),
            ltrim($b, 'vV')
        ));

        return end($tags);
    }

    /**
     * Clone a repository at a tag, keeping its history.
     *
     * Not a shallow clone: upgrades merge a later tag onto this history, and
     * `--depth 1` leaves nothing to merge onto.
     */
    public function cloneAtTag(string $url, string $tag, string $directory): bool
    {
        $process = $this->run(['clone', '--branch', $tag, '--', $url, $directory]);

        return $process instanceof Process && $process->isSuccessful();
    }

    public function renameRemote(string $directory, string $from, string $to): bool
    {
        $process = $this->run(['remote', 'rename', $from, $to], $directory);

        return $process instanceof Process && $process->isSuccessful();
    }

    public function addRemote(string $directory, string $name, string $url): bool
    {
        $process = $this->run(['remote', 'add', $name, $url], $directory);

        return $process instanceof Process && $process->isSuccessful();
    }

    /**
     * What the last call wrote to stderr, for a message a human reads.
     */
    public function lastError(): string
    {
        return trim($this->lastError);
    }

    private string $lastError = '';

    /**
     * @param  array<int, string>  $arguments
     */
    protected function run(array $arguments, ?string $directory = null): ?Process
    {
        $binary = $this->binary();

        if ($binary === null) {
            $this->lastError = 'git was not found on PATH.';

            return null;
        }

        $process = new Process(
            [$binary, ...$arguments],
            $directory,
            [
                'GIT_TERMINAL_PROMPT' => '0',
                'GIT_ASKPASS' => 'echo',
                'GCM_INTERACTIVE' => 'never',
            ],
            null,
            self::TIMEOUT
        );

        $process->run();

        $this->lastError = $process->getErrorOutput();

        return $process;
    }

    private ?string $binaryPath = null;

    private bool $binaryResolved = false;

    private function binary(): ?string
    {
        if (! $this->binaryResolved) {
            $this->binaryPath = (new ExecutableFinder)->find('git');
            $this->binaryResolved = true;
        }

        return $this->binaryPath;
    }
}
