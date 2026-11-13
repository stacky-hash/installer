<?php

declare(strict_types=1);

namespace StackToast\Installer\Tests;

use PHPUnit\Framework\TestCase;
use StackToast\Installer\Application;
use StackToast\Installer\Brand;
use StackToast\Installer\Commands\LinkCommand;
use StackToast\Installer\Commands\NewCommand;
use StackToast\Installer\Git;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * `new` inside an existing repository, and `link`, against real repositories.
 *
 * The edition is a repository on disk with three releases: one older than the
 * first that can live in a subdirectory, and two newer. The only thing doubled
 * is the clone URL, which is rewritten to that path; every git call is real,
 * because what is being pinned is the history git ends up with.
 */
class ExistingRepositoryTest extends TestCase
{
    private string $root;

    private string $upstream;

    private string $monorepo;

    protected function setUp(): void
    {
        parent::setUp();

        if ((new Git)->available() === false) {
            $this->markTestSkipped('git is not on PATH.');
        }

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stacktoast-existing-'.bin2hex(random_bytes(6));
        $this->upstream = $this->root.DIRECTORY_SEPARATOR.'upstream';
        $this->monorepo = $this->root.DIRECTORY_SEPARATOR.'monorepo';

        mkdir($this->upstream, 0777, true);
        mkdir($this->monorepo, 0777, true);

        $this->seedUpstream();
        $this->seedMonorepo();
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // new, inside a repository
    // ---------------------------------------------------------------

    public function test_new_inside_a_repository_asks_rather_than_nesting_one(): void
    {
        $tester = $this->runNew(['name' => 'backend']);

        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('--mode=subtree', $tester->getDisplay());
        $this->assertStringContainsString('--mode=standalone', $tester->getDisplay());
        $this->assertDirectoryDoesNotExist($this->monorepo.DIRECTORY_SEPARATOR.'backend');
    }

    public function test_subtree_mode_adds_the_edition_as_one_merge_of_the_release(): void
    {
        $before = $this->git($this->monorepo, ['rev-parse', 'HEAD']);

        $tester = $this->runNew(['name' => 'backend', '--mode' => 'subtree', '--tag' => 'v1.2.0']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFileExists($this->monorepo.'/backend/app/StackToast/Service.php');
        $this->assertFileDoesNotExist($this->monorepo.'/backend/.git');

        // One commit, on the branch that was already there, with the release
        // as its second parent.
        $this->assertSame($before, $this->git($this->monorepo, ['rev-parse', 'HEAD^1']));
        $this->assertSame(
            $this->git($this->monorepo, ['rev-parse', Git::RELEASE_REFS.'v1.2.0^{commit}']),
            $this->git($this->monorepo, ['merge-base', 'HEAD', Git::RELEASE_REFS.'v1.3.0'])
        );
        $this->assertSame('trunk', $this->git($this->monorepo, ['branch', '--show-current']));

        // The repository's own tag, remote and files are as they were.
        $this->assertSame('v9.0.0', $this->git($this->monorepo, ['tag', '--list']));
        $this->assertSame('https://example.test/mine.git', $this->git($this->monorepo, ['remote', 'get-url', 'origin']));
        $this->assertSame("spa\n", file_get_contents($this->monorepo.'/app/index.js'));
        $this->assertSame('', $this->git($this->monorepo, ['status', '--porcelain']));
    }

    public function test_a_later_release_merges_into_the_prefix_and_nowhere_else(): void
    {
        $this->runNew(['name' => 'backend', '--mode' => 'subtree', '--tag' => 'v1.2.0']);

        file_put_contents($this->monorepo.'/backend/app/StackToast/Service.php', "v1.2.0\nmine\n");
        $this->git($this->monorepo, ['commit', '--quiet', '-am', 'Customer edit']);

        $this->git($this->monorepo, ['merge', '--quiet', '--no-ff', '-X', 'subtree=backend', '-m', 'Upgrade', Git::RELEASE_REFS.'v1.3.0']);

        $this->assertFileExists($this->monorepo.'/backend/app/StackToast/Added.php');
        $this->assertFileDoesNotExist($this->monorepo.'/app/StackToast/Added.php');
        $this->assertStringContainsString('mine', (string) file_get_contents($this->monorepo.'/backend/app/StackToast/Service.php'));
    }

    public function test_subtree_mode_refuses_a_release_that_cannot_upgrade_from_a_subdirectory(): void
    {
        $tester = $this->runNew(['name' => 'backend', '--mode' => 'subtree', '--tag' => 'v1.1.0']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('cannot be upgraded from inside a larger repository', $tester->getDisplay());
        $this->assertDirectoryDoesNotExist($this->monorepo.DIRECTORY_SEPARATOR.'backend');
    }

    public function test_subtree_mode_refuses_while_something_is_staged(): void
    {
        file_put_contents($this->monorepo.'/app/index.js', "edited\n");
        $this->git($this->monorepo, ['add', 'app/index.js']);

        $tester = $this->runNew(['name' => 'backend', '--mode' => 'subtree', '--tag' => 'v1.2.0']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('staged changes', $tester->getDisplay());
    }

    public function test_standalone_mode_keeps_release_tags_out_of_the_projects_own(): void
    {
        $tester = $this->runNew(['name' => 'backend', '--mode' => 'standalone']);
        $project = $this->monorepo.DIRECTORY_SEPARATOR.'backend';

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertDirectoryExists($project.DIRECTORY_SEPARATOR.'.git');
        $this->assertSame('', $this->git($project, ['tag', '--list']));
        $this->assertNotNull((new Git)->releaseRef($project, 'v1.3.0'));

        // And a later plain fetch leaves them there.
        $this->release('v1.4.0', 'Later.php');
        $this->git($project, ['fetch', '--quiet', 'stacktoast']);

        $this->assertSame('', $this->git($project, ['tag', '--list']));
        $this->assertNotNull((new Git)->releaseRef($project, 'v1.4.0'));
    }

    // ---------------------------------------------------------------
    // link
    // ---------------------------------------------------------------

    public function test_link_makes_a_zip_install_upgradeable_and_keeps_its_edits(): void
    {
        $install = $this->unzipped('v1.2.0');

        file_put_contents($install.'/app/StackToast/Service.php', "v1.2.0\nmine\n");
        $this->git($install, ['commit', '--quiet', '-am', 'Customer edit']);

        $tester = $this->runLink($install);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('1 modified', $tester->getDisplay());
        $this->assertTrue((new Git)->isAncestor($install, Git::RELEASE_REFS.'v1.2.0'));
        $this->assertSame('', $this->git($install, ['status', '--porcelain']));

        $this->git($install, ['merge', '--quiet', '--no-ff', '-m', 'Upgrade', Git::RELEASE_REFS.'v1.3.0']);

        $this->assertFileExists($install.'/app/StackToast/Added.php');
        $this->assertStringContainsString('mine', (string) file_get_contents($install.'/app/StackToast/Service.php'));
    }

    public function test_link_dry_run_makes_no_commit(): void
    {
        $install = $this->unzipped('v1.2.0');
        $before = $this->git($install, ['rev-parse', 'HEAD']);

        $tester = $this->runLink($install, ['--dry-run' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame($before, $this->git($install, ['rev-parse', 'HEAD']));
    }

    public function test_link_has_nothing_to_do_for_an_install_that_is_already_linked(): void
    {
        $install = $this->unzipped('v1.2.0');

        $this->runLink($install);
        $linked = $this->git($install, ['rev-parse', 'HEAD']);

        $tester = $this->runLink($install);

        $this->assertStringContainsString('Already linked', $tester->getDisplay());
        $this->assertSame($linked, $this->git($install, ['rev-parse', 'HEAD']));
    }

    public function test_link_refuses_a_tree_with_uncommitted_changes_or_no_manifest(): void
    {
        $install = $this->unzipped('v1.2.0');
        file_put_contents($install.'/app/StackToast/Service.php', "uncommitted\n");

        $this->assertStringContainsString('uncommitted changes', $this->runLink($install)->getDisplay());
        $this->assertSame(2, $this->runLink($this->monorepo)->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function runNew(array $arguments): CommandTester
    {
        $application = new Application;
        $application->add(new NewCommand($this->localGit()));

        $tester = new CommandTester($application->find('new'));
        $tester->execute(
            $arguments + ['--path' => $this->monorepo, '--stack' => 'livewire', '--no-configure' => true],
            ['interactive' => false]
        );

        return $tester;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function runLink(string $install, array $arguments = []): CommandTester
    {
        $application = new Application;
        $application->add(new LinkCommand($this->localGit()));

        $tester = new CommandTester($application->find('link'));
        $tester->execute($arguments + ['--path' => $install], ['interactive' => false]);

        return $tester;
    }

    /**
     * Real git, with the edition's clone URL pointed at the fixture.
     */
    private function localGit(): Git
    {
        $upstream = $this->upstream;

        return new class($upstream) extends Git
        {
            public function __construct(private readonly string $upstream) {}

            public function remotes(string $directory): array
            {
                return array_map(
                    fn (string $url): string => $url === $this->upstream ? Brand::cloneUrl('stacktoast') : $url,
                    parent::remotes($directory)
                );
            }

            protected function run(array $arguments, ?string $directory = null): ?Process
            {
                return parent::run(array_map(
                    fn (string $argument): string => $argument === Brand::cloneUrl('stacktoast') ? $this->upstream : $argument,
                    $arguments
                ), $directory);
            }
        };
    }

    /**
     * A release's files committed with no link to the release: a zip download.
     */
    private function unzipped(string $tag): string
    {
        $install = $this->root.DIRECTORY_SEPARATOR.'zip-'.bin2hex(random_bytes(3));
        mkdir($install.'/app/StackToast', 0777, true);

        foreach (['app/StackToast/Service.php', 'stacktoast-manifest.json'] as $path) {
            file_put_contents($install.'/'.$path, $this->git($this->upstream, ['show', "{$tag}:{$path}"])."\n");
        }

        $this->init($install, 'main');
        $this->git($install, ['add', '-A']);
        $this->git($install, ['commit', '--quiet', '-m', 'Unzipped']);

        return $install;
    }

    private function seedUpstream(): void
    {
        $this->init($this->upstream, 'main');
        mkdir($this->upstream.'/app/StackToast', 0777, true);

        foreach (['v1.1.0', 'v1.2.0'] as $tag) {
            file_put_contents($this->upstream.'/app/StackToast/Service.php', ltrim($tag, 'v') === '1.1.0' ? "v1.1.0\n" : "v1.2.0\n");
            $this->commitRelease($tag);
        }

        $this->release('v1.3.0', 'Added.php');
    }

    private function release(string $tag, string $file): void
    {
        file_put_contents($this->upstream.'/app/StackToast/'.$file, $tag."\n");
        $this->commitRelease($tag);
    }

    private function commitRelease(string $tag): void
    {
        file_put_contents(
            $this->upstream.'/stacktoast-manifest.json',
            json_encode(['stack' => 'livewire', 'version' => ltrim($tag, 'v')])."\n"
        );

        $this->git($this->upstream, ['add', '-A']);
        $this->git($this->upstream, ['commit', '--quiet', '-m', $tag]);
        $this->git($this->upstream, ['tag', $tag]);
    }

    private function seedMonorepo(): void
    {
        $this->init($this->monorepo, 'trunk');
        mkdir($this->monorepo.'/app', 0777, true);
        file_put_contents($this->monorepo.'/app/index.js', "spa\n");

        $this->git($this->monorepo, ['add', '-A']);
        $this->git($this->monorepo, ['commit', '--quiet', '-m', 'The product']);
        $this->git($this->monorepo, ['tag', 'v9.0.0']);
        $this->git($this->monorepo, ['remote', 'add', 'origin', 'https://example.test/mine.git']);
    }

    private function init(string $directory, string $branch): void
    {
        $this->git($directory, ['init', '--quiet', '--initial-branch='.$branch]);
        $this->git($directory, ['config', 'user.email', 'test@example.com']);
        $this->git($directory, ['config', 'user.name', 'Test']);
        $this->git($directory, ['config', 'core.autocrlf', 'false']);
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function git(string $directory, array $arguments): string
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
