<?php

declare(strict_types=1);

namespace StackToast\Installer\Commands;

use StackToast\Installer\Git;
use StackToast\Installer\Stacks\Stack;
use StackToast\Installer\Stacks\StackCatalogue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Give an install the shared history an upgrade merges from.
 *
 * For a tree that holds a release's files and no link to the release: a zip
 * download, or an install whose branch was squashed or rebased on its way to
 * the default branch. One `ours` merge of the release the manifest names
 * records it as an ancestor and changes no file, and every later release is
 * then an ordinary merge.
 */
#[AsCommand(name: 'link', description: 'Reconnect an install to its releases, so it can be upgraded')]
class LinkCommand extends Command
{
    private const MANIFEST = 'stacktoast-manifest.json';

    public function __construct(private readonly Git $git = new Git)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The install. Defaults to the current directory')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Fetch the releases and report, without making the commit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $catalogue = StackCatalogue::bundled();

        $path = $input->getOption('path');
        $directory = is_string($path) && $path !== '' ? $path : (getcwd() ?: '.');

        $manifest = $this->manifest($directory);

        if ($manifest === null) {
            $io->error(sprintf('No %s here naming a version. Run this from the root of a Stack Toast install.', self::MANIFEST));

            return Command::INVALID;
        }

        $stack = $catalogue->get($manifest['stack']);

        if (! $stack instanceof Stack) {
            $io->error(sprintf('The manifest names the edition "%s", which this installer does not know. Update it: composer global update stacktoast/installer', $manifest['stack']));

            return Command::INVALID;
        }

        if (! $this->git->available()) {
            $io->error('git was not found on PATH. Run `stacktoast check` for the full list of requirements.');

            return Command::FAILURE;
        }

        $tag = 'v'.$manifest['version'];
        $refusal = $this->refusal($catalogue, $directory, $tag);

        if ($refusal !== null) {
            $io->error($refusal);

            return Command::FAILURE;
        }

        $url = $stack->cloneUrl();

        if (! $this->git->canReach($url)) {
            $io->error(sprintf('No access to %s. Run `stacktoast whoami` to see which editions this machine can reach.', $stack->repositoryUrl()));

            return Command::FAILURE;
        }

        $remote = $this->releaseRemote($io, $directory, $url);

        if ($remote === null) {
            return Command::FAILURE;
        }

        if (! $this->git->configureReleaseFetch($directory, $remote) || ! $this->git->fetchReleases($directory, $remote)) {
            $io->error('The fetch failed.');
            $io->writeln('  <fg=gray>'.$this->git->lastError().'</>');

            return Command::FAILURE;
        }

        $ref = $this->git->releaseRef($directory, $tag);

        if ($ref === null) {
            $io->error(sprintf('%s has no tag %s, which is the version the manifest names.', $stack->repository, $tag));

            return Command::FAILURE;
        }

        if ($this->git->isAncestor($directory, $ref)) {
            $io->success(sprintf('Already linked to %s %s. Nothing to do.', $stack->title, $tag));

            return Command::SUCCESS;
        }

        $this->renderDifferences($io, $this->git->changedFromRelease($directory, $ref), $tag);

        if ($input->getOption('dry-run')) {
            $io->writeln(sprintf('  Would run: git merge -s ours --allow-unrelated-histories %s', $ref));
            $io->newLine();
            $io->writeln('  Dry run. No commit was made.');
            $io->newLine();

            return Command::SUCCESS;
        }

        if (! $this->git->linkTo($directory, $ref, sprintf('Link to %s %s', $stack->title, $tag))) {
            $io->error('The link did not complete.');
            $io->writeln('  <fg=gray>'.$this->git->lastError().'</>');

            return Command::FAILURE;
        }

        $io->success(sprintf('Linked to %s %s. No file was changed.', $stack->title, $tag));
        $io->writeln('  <options=bold>Next</>');
        $io->writeln('    php artisan stacktoast:upgrade');
        $io->newLine();
        $io->writeln('  Keep this commit a merge commit. A squash or a rebase drops the link again.');
        $io->newLine();

        return Command::SUCCESS;
    }

    /**
     * Why this install cannot be linked as it stands, or null when it can.
     */
    private function refusal(StackCatalogue $catalogue, string $directory, string $tag): ?string
    {
        if ($this->git->topLevel($directory) === null) {
            return 'This is not a git repository. Run `git init`, commit the files, and run this again.';
        }

        if (! $this->git->hasCommits($directory)) {
            return 'This repository has no commits yet. Commit the files first, so there is something to link.';
        }

        if ($this->git->currentBranch($directory) === null) {
            return 'HEAD is detached. Check out a branch first, so the link has somewhere to live.';
        }

        if (! $this->git->isClean($directory) || ! $this->git->indexIsClean($directory)) {
            return 'There are uncommitted changes. Commit or stash them first: the link is a commit, and it should hold nothing else.';
        }

        if ($this->git->prefix($directory) !== '' && ! $catalogue->supportsReleaseRefs($tag)) {
            return sprintf(
                'This install is in a subdirectory of its repository, and %s cannot be upgraded from one. That needs %s or newer.',
                $tag,
                $catalogue->releaseRefsSince ?? 'a newer release'
            );
        }

        return null;
    }

    /**
     * @return array{stack: string, version: string}|null
     */
    private function manifest(string $directory): ?array
    {
        $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.self::MANIFEST;

        if (! is_file($path)) {
            return null;
        }

        // The release build writes a BOM on Windows, which json_decode reads
        // as a syntax error.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($path)) ?? '';

        /** @var mixed $data */
        $data = json_decode($contents, true);

        if (! is_array($data)
            || ! is_string($data['stack'] ?? null)
            || ! is_string($data['version'] ?? null)
            || preg_match('/^\d+\.\d+\.\d+$/', $data['version']) !== 1) {
            return null;
        }

        return ['stack' => $data['stack'], 'version' => $data['version']];
    }

    private function releaseRemote(SymfonyStyle $io, string $directory, string $url): ?string
    {
        $remotes = $this->git->remotes($directory);
        $existing = array_search($url, $remotes, true);

        if (is_string($existing)) {
            return $existing;
        }

        if (isset($remotes['stacktoast'])) {
            $io->error(sprintf(
                'The `stacktoast` remote points at %s, which is not the edition the manifest names (%s).',
                $remotes['stacktoast'],
                $url
            ));

            return null;
        }

        if (! $this->git->addRemote($directory, 'stacktoast', $url)) {
            $io->error('Could not add the `stacktoast` remote: '.$this->git->lastError());

            return null;
        }

        return 'stacktoast';
    }

    /**
     * Shipped files that are not as the release has them.
     *
     * Information, not a blocker: once linked these are ordinary edits, and
     * merge as such.
     *
     * @param  array<string, string>  $changed
     */
    private function renderDifferences(SymfonyStyle $io, array $changed, string $tag): void
    {
        if ($changed === []) {
            $io->writeln(sprintf('  Every shipped file matches %s.', $tag));
            $io->newLine();

            return;
        }

        $modified = array_keys($changed, 'M', true);
        $missing = array_keys($changed, 'D', true);

        $io->writeln(sprintf(
            '  %d shipped file%s differ from %s: %d modified, %d missing.',
            count($changed),
            count($changed) === 1 ? '' : 's',
            $tag,
            count($modified),
            count($missing)
        ));

        foreach (array_slice($modified, 0, 10) as $path) {
            $io->writeln('    <fg=yellow>modified</> '.$path);
        }

        foreach (array_slice($missing, 0, 10) as $path) {
            $io->writeln('    <fg=red>missing</>  '.$path);
        }

        if (count($modified) > 10 || count($missing) > 10) {
            $io->writeln('    <fg=gray>... and more. `php artisan stacktoast:upgrade` lists them all once linked.</>');
        }

        $io->writeln('  <fg=gray>These are kept as they are. An upgrade treats them as your edits.</>');
        $io->newLine();
    }
}
