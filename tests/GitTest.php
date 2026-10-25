<?php

declare(strict_types=1);

namespace StackToast\Installer\Tests;

use PHPUnit\Framework\TestCase;
use StackToast\Installer\Git;
use Symfony\Component\Process\Process;

/**
 * The git calls, run for real against a repository on disk.
 *
 * A local path is a git URL, so clone, tag discovery and the remote rewiring
 * are exercised without a network or an account. Those four calls are the
 * whole of `new`, and a double cannot tell us whether git accepts the
 * arguments we build.
 */
class GitTest extends TestCase
{
    private string $root;

    private string $upstream;

    protected function setUp(): void
    {
        parent::setUp();

        if ((new Git)->available() === false) {
            $this->markTestSkipped('git is not on PATH.');
        }

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stacktoast-git-'.bin2hex(random_bytes(6));
        $this->upstream = $this->root.DIRECTORY_SEPARATOR.'upstream';

        mkdir($this->upstream, 0777, true);
        $this->seedUpstream();
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);

        parent::tearDown();
    }

    public function test_it_finds_the_highest_version_tag(): void
    {
        // Lexically, v0.9.0 sorts above v0.10.0; by version it does not.
        $this->assertSame('v0.10.0', (new Git)->latestTag($this->upstream));
    }

    public function test_it_reports_a_repository_it_can_read(): void
    {
        $this->assertTrue((new Git)->canReach($this->upstream));
    }

    public function test_it_reports_a_repository_that_is_not_there(): void
    {
        $this->assertFalse((new Git)->canReach($this->root.DIRECTORY_SEPARATOR.'absent'));
    }

    public function test_it_clones_at_a_tag_with_the_history_an_upgrade_needs(): void
    {
        $git = new Git;
        $target = $this->root.DIRECTORY_SEPARATOR.'app';

        $this->assertTrue($git->cloneAtTag($this->upstream, 'v0.10.0', $target));
        $this->assertFileExists($target.DIRECTORY_SEPARATOR.'VERSION');
        $this->assertSame('v0.10.0', trim((string) file_get_contents($target.DIRECTORY_SEPARATOR.'VERSION')));

        // A shallow clone would leave nothing for a later tag to merge onto.
        $this->assertSame('3', $this->gitCommand($target, ['rev-list', '--count', 'HEAD']));
    }

    public function test_it_rewires_the_remotes_so_upgrades_and_your_own_repository_coexist(): void
    {
        $git = new Git;
        $target = $this->root.DIRECTORY_SEPARATOR.'app';
        $own = $this->root.DIRECTORY_SEPARATOR.'mine.git';

        $this->assertTrue($git->cloneAtTag($this->upstream, 'v0.10.0', $target));
        $this->assertTrue($git->renameRemote($target, 'origin', 'stacktoast'));
        $this->assertTrue($git->addRemote($target, 'origin', $own));

        $remotes = explode("\n", $this->gitCommand($target, ['remote']));

        $this->assertContains('stacktoast', $remotes);
        $this->assertContains('origin', $remotes);
        $this->assertSame($own, $this->gitCommand($target, ['remote', 'get-url', 'origin']));
    }

    public function test_a_clone_of_a_tag_that_does_not_exist_fails_rather_than_guessing(): void
    {
        $git = new Git;

        $this->assertFalse($git->cloneAtTag(
            $this->upstream,
            'v9.9.9',
            $this->root.DIRECTORY_SEPARATOR.'nope'
        ));
        $this->assertNotSame('', $git->lastError());
    }

    /**
     * Three commits, two tags, so version ordering and depth are both visible.
     */
    private function seedUpstream(): void
    {
        $this->gitCommand($this->upstream, ['init', '--quiet', '--initial-branch=main']);
        $this->gitCommand($this->upstream, ['config', 'user.email', 'test@example.com']);
        $this->gitCommand($this->upstream, ['config', 'user.name', 'Test']);

        foreach (['v0.1.0', 'v0.9.0', 'v0.10.0'] as $tag) {
            file_put_contents($this->upstream.DIRECTORY_SEPARATOR.'VERSION', $tag."\n");
            $this->gitCommand($this->upstream, ['add', 'VERSION']);
            $this->gitCommand($this->upstream, ['commit', '--quiet', '-m', $tag]);
            $this->gitCommand($this->upstream, ['tag', $tag]);
        }
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function gitCommand(string $directory, array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $directory, null, null, 120);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            'Fixture command failed: git '.implode(' ', $arguments).' — '.$process->getErrorOutput()
        );

        return trim($process->getOutput());
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($full)) {
                $this->remove($full);

                continue;
            }

            @chmod($full, 0666);
            @unlink($full);
        }

        @rmdir($path);
    }
}
