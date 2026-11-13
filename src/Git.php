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

    /**
     * Put a fresh clone on a branch of its own.
     *
     * A clone at a tag leaves HEAD detached: a first commit would belong to
     * no branch, and `git push origin main` would have nothing to send.
     */
    public function createBranch(string $directory, string $name): bool
    {
        $process = $this->run(['checkout', '--quiet', '-b', $name], $directory);

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
     * The root of the work tree a directory is inside, or null when it is
     * not inside one.
     */
    public function topLevel(string $directory): ?string
    {
        $process = $this->run(['rev-parse', '--show-toplevel'], $directory);

        if (! $process instanceof Process || ! $process->isSuccessful()) {
            return null;
        }

        $path = trim($process->getOutput());

        return $path === '' ? null : $path;
    }

    /**
     * A directory's path from the root of its work tree: empty at the root,
     * `backend` one level down. Forward slashes, no trailing slash.
     */
    public function prefix(string $directory): string
    {
        $process = $this->run(['rev-parse', '--show-prefix'], $directory);

        return $process instanceof Process && $process->isSuccessful()
            ? trim(str_replace('\\', '/', trim($process->getOutput())), '/')
            : '';
    }

    public function hasCommits(string $directory): bool
    {
        return $this->succeeds(['rev-parse', '--verify', '--quiet', 'HEAD^{commit}'], $directory);
    }

    /**
     * The branch a repository is on, or null when HEAD is detached.
     */
    public function currentBranch(string $directory): ?string
    {
        $process = $this->run(['symbolic-ref', '--quiet', '--short', 'HEAD'], $directory);

        if (! $process instanceof Process || ! $process->isSuccessful()) {
            return null;
        }

        $branch = trim($process->getOutput());

        return $branch === '' ? null : $branch;
    }

    /**
     * Whether nothing is staged anywhere in the repository.
     */
    public function indexIsClean(string $directory): bool
    {
        return $this->succeeds(['diff', '--cached', '--quiet'], $directory);
    }

    /**
     * Whether a directory has no uncommitted change, staged or not.
     */
    public function isClean(string $directory): bool
    {
        $process = $this->run(['status', '--porcelain', '--', '.'], $directory);

        return $process instanceof Process && $process->isSuccessful() && trim($process->getOutput()) === '';
    }

    /**
     * Remotes as name => fetch URL.
     *
     * @return array<string, string>
     */
    public function remotes(string $directory): array
    {
        $process = $this->run(['remote', '-v'], $directory);

        if (! $process instanceof Process || ! $process->isSuccessful()) {
            return [];
        }

        $remotes = [];

        foreach (preg_split('/\R/', $process->getOutput()) ?: [] as $line) {
            if (preg_match('/^(\S+)\s+(\S+)\s+\(fetch\)$/', trim($line), $matches) === 1) {
                $remotes[$matches[1]] = $matches[2];
            }
        }

        return $remotes;
    }

    /**
     * Make a remote keep its release tags in their own namespace.
     *
     * A plain fetch follows tags into `refs/tags/`, where a release `v1.2.0`
     * and the repository's own `v1.2.0` are one name. With this, every later
     * `git fetch <remote>` puts releases under `refs/stacktoast/tags/`.
     */
    public function configureReleaseFetch(string $directory, string $remote): bool
    {
        return $this->succeeds(['config', "remote.{$remote}.tagOpt", '--no-tags'], $directory)
            && $this->succeeds(['config', '--replace-all', "remote.{$remote}.fetch", "+refs/heads/*:refs/remotes/{$remote}/*"], $directory)
            && $this->succeeds(['config', '--add', "remote.{$remote}.fetch", '+refs/tags/*:'.self::RELEASE_REFS.'*'], $directory);
    }

    /**
     * Fetch a remote's branches and release tags, the tags into their
     * namespace whatever the remote is configured to do.
     */
    public function fetchReleases(string $directory, string $remote): bool
    {
        return $this->succeeds([
            'fetch', '--no-tags', $remote,
            "+refs/heads/*:refs/remotes/{$remote}/*",
            '+refs/tags/*:'.self::RELEASE_REFS.'*',
        ], $directory);
    }

    /**
     * Move the tags a clone brought into the release namespace.
     *
     * Only for a repository that holds nothing but the release: every tag in
     * it is a release tag.
     */
    public function moveTagsToReleaseNamespace(string $directory): bool
    {
        $process = $this->run(['for-each-ref', '--format=%(refname:strip=2)', 'refs/tags'], $directory);

        if (! $process instanceof Process || ! $process->isSuccessful()) {
            return false;
        }

        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $tag) {
            if ($tag === '') {
                continue;
            }

            if (! $this->succeeds(['update-ref', self::RELEASE_REFS.$tag, 'refs/tags/'.$tag], $directory)
                || ! $this->succeeds(['tag', '--delete', $tag], $directory)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The full ref of a release tag, or null when it has not been fetched.
     *
     * @param  string  $tag  As the release names it, e.g. v1.2.0
     */
    public function releaseRef(string $directory, string $tag): ?string
    {
        $ref = self::RELEASE_REFS.$tag;

        return $this->succeeds(['rev-parse', '--verify', '--quiet', $ref.'^{commit}'], $directory) ? $ref : null;
    }

    public function isAncestor(string $directory, string $ref): bool
    {
        return $this->succeeds(['merge-base', '--is-ancestor', $ref, 'HEAD'], $directory);
    }

    /**
     * Add a release under a directory of an existing repository, as a merge.
     *
     * The `ours` merge records the release as a parent and changes no file;
     * `read-tree` then places its tree under the prefix. The release commit
     * becomes an ancestor, which is what a later upgrade merges from, and
     * `git subtree add --squash` would not leave one.
     *
     * @param  string  $directory  The root of the enclosing repository
     * @param  string  $prefix  Forward slashes, no trailing slash
     */
    public function addUnderPrefix(string $directory, string $prefix, string $ref, string $message): bool
    {
        if (! $this->succeeds(['merge', '-s', 'ours', '--no-commit', '--allow-unrelated-histories', $ref], $directory)) {
            return false;
        }

        if ($this->succeeds(['read-tree', "--prefix={$prefix}/", '-u', $ref], $directory)
            && $this->succeeds(['commit', '--quiet', '-m', $message], $directory)) {
            return true;
        }

        $error = $this->lastError;

        // Back to the commit it started from, with the files it placed gone.
        // An abort rather than a hard reset: unstaged work elsewhere in the
        // repository is not this command's to discard.
        $this->run(['merge', '--abort'], $directory);

        $this->lastError = $error;

        return false;
    }

    /**
     * Record a release as an ancestor of a tree that already holds its files.
     *
     * Changes no file. Only correct when the tree is that release: on any
     * other tree git would read every shipped file as deleted on this side.
     */
    public function linkTo(string $directory, string $ref, string $message): bool
    {
        return $this->succeeds(['merge', '-s', 'ours', '--allow-unrelated-histories', '-m', $message, $ref], $directory);
    }

    /**
     * Shipped files that differ from a release, as path => M or D.
     *
     * Read tree to tree, so it works for an install in a subdirectory, where
     * the release holds at its root what the install holds under a prefix.
     *
     * @return array<string, string>
     */
    public function changedFromRelease(string $directory, string $ref): array
    {
        $prefix = $this->prefix($directory);
        $process = $this->run(
            ['diff', '--name-status', '--no-renames', $ref, $prefix === '' ? 'HEAD' : 'HEAD:'.$prefix],
            $directory
        );

        if (! $process instanceof Process || ! $process->isSuccessful()) {
            return [];
        }

        $changed = [];

        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $line) {
            // An added path is the customer's own file, not a shipped one.
            if (preg_match('/^([MD])\s+(.+)$/', $line, $matches) === 1) {
                $changed[$matches[2]] = $matches[1];
            }
        }

        return $changed;
    }

    /**
     * Where release tags are kept, apart from a repository's own tags.
     */
    public const RELEASE_REFS = 'refs/stacktoast/tags/';

    /**
     * @param  array<int, string>  $arguments
     */
    private function succeeds(array $arguments, string $directory): bool
    {
        $process = $this->run($arguments, $directory);

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
