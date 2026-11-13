<?php

declare(strict_types=1);

namespace StackToast\Installer\Commands;

use StackToast\Installer\Brand;
use StackToast\Installer\Git;
use StackToast\Installer\Stacks\Stack;
use StackToast\Installer\Stacks\StackCatalogue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Create a new application from an edition.
 *
 * Clones at the newest tag, keeps upstream as a remote named `stacktoast`
 * and leaves `origin` free for the customer's own repository. That shape is
 * what makes `stacktoast:upgrade` able to merge a later tag, and what makes
 * one purchase usable for many projects.
 */
#[AsCommand(name: 'new', description: 'Create a new Stack Toast application')]
class NewCommand extends Command
{
    /**
     * A directory name, and nothing that could escape the chosen parent.
     */
    private const APP_NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    public function __construct(private readonly Git $git = new Git)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Directory to create, e.g. my-app')
            ->addOption('stack', null, InputOption::VALUE_REQUIRED, 'Edition to install: '.implode(', ', StackCatalogue::bundled()->names()))
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Where to create it. Defaults to the current directory')
            ->addOption('origin', null, InputOption::VALUE_REQUIRED, 'Your own repository, added as `origin`')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Install this tag rather than the newest')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Inside an existing repository: `subtree` adds Stack Toast to it, `standalone` creates a separate repository')
            ->addOption('no-configure', null, InputOption::VALUE_NONE, 'Clone only; do not run the setup wizard');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $catalogue = StackCatalogue::bundled();

        $name = (string) $input->getArgument('name');

        if (preg_match(self::APP_NAME, $name) !== 1) {
            $io->error('The name must be a plain directory name: letters, numbers, dot, dash or underscore.');

            return Command::INVALID;
        }

        $parent = $this->stringOption($input, 'path') ?? (getcwd() ?: '.');

        if (! is_dir($parent)) {
            $io->error("{$parent} does not exist.");

            return Command::INVALID;
        }

        $directory = rtrim($parent, '/\\').DIRECTORY_SEPARATOR.$name;

        if (is_dir($directory) && (scandir($directory) ?: []) !== ['.', '..']) {
            $io->error("{$directory} already exists and is not empty.");

            return Command::INVALID;
        }

        if (! $this->git->available()) {
            $io->error('git was not found on PATH. Run `stacktoast check` for the full list of requirements.');

            return Command::FAILURE;
        }

        // A clone inside a repository is a nested repository: the outer one
        // sees an untracked directory, or a gitlink with no .gitmodules, and
        // nothing says so. Both answers are legitimate, so it is asked.
        $enclosing = $this->git->topLevel($parent);
        $mode = $enclosing === null ? self::STANDALONE : $this->resolveMode($input, $io, $enclosing, $name);

        if ($mode === null) {
            return Command::INVALID;
        }

        $stack = $this->resolveStack($input, $io, $catalogue);

        if (! $stack instanceof Stack) {
            return Command::INVALID;
        }

        $io->newLine();
        $io->writeln(sprintf('  <fg=gray>Edition</>  %s', $stack->title));

        $url = $stack->cloneUrl();

        $io->writeln(sprintf('  <fg=gray>Source</>   %s', $stack->repositoryUrl()));
        $io->newLine();

        if (! $this->git->canReach($url)) {
            $this->renderNoAccess($io, $stack);

            return Command::FAILURE;
        }

        $tag = $this->stringOption($input, 'tag') ?? $this->git->latestTag($url);

        if ($tag === null) {
            $io->error(sprintf('%s has no release tag yet.', $stack->repository));

            return Command::FAILURE;
        }

        if ($mode === self::SUBTREE) {
            return $this->addToRepository($input, $io, $catalogue, $stack, $url, $tag, (string) $enclosing, $parent, $name, $directory);
        }

        $io->writeln(sprintf('  Cloning <options=bold>%s</> at <options=bold>%s</>...', $stack->repository, $tag));

        if (! $this->git->cloneAtTag($url, $tag, $directory)) {
            $io->newLine();
            $io->error('The clone failed.');
            $io->writeln('  <fg=gray>'.$this->git->lastError().'</>');

            return Command::FAILURE;
        }

        if (! $this->git->createBranch($directory, 'main')) {
            $io->warning('Could not create the `main` branch. Run `git switch -c main` in the project before your first commit.');
        }

        // Upstream is `stacktoast` so that `origin` stays the customer's own
        // repository; upgrades fetch tags from the former and never the latter.
        if (! $this->git->renameRemote($directory, 'origin', 'stacktoast')) {
            $io->warning('Could not rename the upstream remote to `stacktoast`. Upgrades expect that name.');
        } elseif ($catalogue->supportsReleaseRefs($tag)
            && ! ($this->git->moveTagsToReleaseNamespace($directory) && $this->git->configureReleaseFetch($directory, 'stacktoast'))) {
            // Release tags out of `refs/tags/`, so a tag the customer makes
            // for their own product is never read as a release.
            $io->warning('Could not move the release tags to refs/stacktoast/tags/. Upgrades still work; your own `v*` tags may be read as releases.');
        }

        $origin = $this->stringOption($input, 'origin');

        if ($origin !== null && ! $this->git->addRemote($directory, 'origin', $origin)) {
            $io->warning("Could not add {$origin} as `origin`.");
        }

        $io->newLine();
        $io->success(sprintf('%s created in %s', $stack->title, $directory));

        return $this->handOffToWizard($input, $io, $directory, $origin);
    }

    /**
     * Which of the two things `new` can mean inside an existing repository.
     *
     * Never guessed: the wrong guess is a nested repository nobody asked for,
     * or a release history merged into a repository that did not want it.
     */
    private function resolveMode(InputInterface $input, SymfonyStyle $io, string $enclosing, string $name): ?string
    {
        $requested = $this->stringOption($input, 'mode');

        if ($requested !== null) {
            if (in_array($requested, [self::SUBTREE, self::STANDALONE], true)) {
                return $requested;
            }

            $io->error(sprintf('Unknown mode "%s". Use --mode=%s or --mode=%s.', $requested, self::SUBTREE, self::STANDALONE));

            return null;
        }

        $choices = [
            self::SUBTREE => sprintf('Add Stack Toast to this repository, in %s/ (one repository)', $name),
            self::STANDALONE => sprintf('Create a separate repository in %s/ (nest it yourself)', $name),
        ];

        if (! $input->isInteractive()) {
            $io->error(sprintf('%s is already a git repository. Say which you want:', $enclosing));

            foreach ($choices as $mode => $meaning) {
                $io->writeln(sprintf('    --mode=%-11s %s', $mode, $meaning));
            }

            $io->newLine();

            return null;
        }

        $io->writeln(sprintf('  %s is already a git repository.', $enclosing));

        return (string) $io->choice('Which do you want?', $choices, self::SUBTREE);
    }

    /**
     * Add the edition to the enclosing repository, under the new directory.
     *
     * One merge commit on the current branch. No clone, no `main`, and
     * `origin` is not touched: the enclosing repository has all three.
     */
    private function addToRepository(
        InputInterface $input,
        SymfonyStyle $io,
        StackCatalogue $catalogue,
        Stack $stack,
        string $url,
        string $tag,
        string $enclosing,
        string $parent,
        string $name,
        string $directory,
    ): int {
        if (! $catalogue->supportsReleaseRefs($tag)) {
            $io->error(sprintf(
                '%s cannot be upgraded from inside a larger repository. That needs %s %s or newer.',
                $tag,
                Brand::NAME,
                $catalogue->releaseRefsSince ?? 'a release that supports it'
            ));

            return Command::FAILURE;
        }

        if (! $this->git->hasCommits($enclosing)) {
            $io->error(sprintf('%s has no commits yet. Make a first commit, so there is something to merge into.', $enclosing));

            return Command::FAILURE;
        }

        if (! $this->git->indexIsClean($enclosing)) {
            $io->error('There are staged changes in this repository. Commit or unstage them first: the install is a commit, and it would carry them with it.');

            return Command::FAILURE;
        }

        $remote = $this->releaseRemote($io, $enclosing, $url);

        if ($remote === null) {
            return Command::FAILURE;
        }

        $prefix = trim($this->git->prefix($parent).'/'.$name, '/');

        $io->writeln(sprintf('  Adding <options=bold>%s</> at <options=bold>%s</> in <options=bold>%s/</>...', $stack->repository, $tag, $prefix));

        if (! $this->git->configureReleaseFetch($enclosing, $remote) || ! $this->git->fetchReleases($enclosing, $remote)) {
            $io->newLine();
            $io->error('The fetch failed.');
            $io->writeln('  <fg=gray>'.$this->git->lastError().'</>');

            return Command::FAILURE;
        }

        $ref = $this->git->releaseRef($enclosing, $tag);

        if ($ref === null) {
            $io->error(sprintf('%s has no tag %s.', $stack->repository, $tag));

            return Command::FAILURE;
        }

        $message = sprintf('Add %s %s in %s/', $stack->title, $tag, $prefix);

        if (! $this->git->addUnderPrefix($enclosing, $prefix, $ref, $message)) {
            $io->newLine();
            $io->error('The install did not complete, and the repository was put back as it was.');
            $io->writeln('  <fg=gray>'.$this->git->lastError().'</>');

            return Command::FAILURE;
        }

        $io->newLine();
        $io->success(sprintf('%s %s added in %s/ (one commit on %s)', $stack->title, $tag, $prefix, $this->git->currentBranch($enclosing) ?? 'HEAD'));

        $this->prefixNotes = [
            sprintf('Run artisan from %s/. Release tags are under %s.', $prefix, Git::RELEASE_REFS),
            sprintf('The shipped .github/workflows/ are inert in %s/: GitHub reads workflows from the repository root only.', $prefix),
            sprintf('The agent rules in %s/ name paths relative to %s/. Say so in the rules file at the repository root.', $prefix, $prefix),
        ];
        $this->upstreamRemote = $remote;

        return $this->handOffToWizard($input, $io, $directory, null);
    }

    /**
     * The remote releases are fetched from, added when the repository has
     * none for this edition.
     */
    private function releaseRemote(SymfonyStyle $io, string $enclosing, string $url): ?string
    {
        $remotes = $this->git->remotes($enclosing);
        $existing = array_search($url, $remotes, true);

        if (is_string($existing)) {
            return $existing;
        }

        if (isset($remotes['stacktoast'])) {
            $io->error(sprintf(
                'This repository already has a remote named `stacktoast`, pointing at %s. That is another edition; one repository holds one.',
                $remotes['stacktoast']
            ));

            return null;
        }

        if (! $this->git->addRemote($enclosing, 'stacktoast', $url)) {
            $io->error('Could not add the `stacktoast` remote: '.$this->git->lastError());

            return null;
        }

        return 'stacktoast';
    }

    private const SUBTREE = 'subtree';

    private const STANDALONE = 'standalone';

    /**
     * Set by a subtree install, which leaves `origin` alone and has things to
     * say that a fresh clone does not.
     *
     * @var array<int, string>
     */
    private array $prefixNotes = [];

    private ?string $upstreamRemote = null;

    /**
     * Run the setup wizard, or say how to.
     *
     * The wizard is interactive. Symfony Process cannot hand it a terminal on
     * Windows, so there it is printed rather than run — a prompt nobody can
     * answer looks like a hang.
     */
    private function handOffToWizard(InputInterface $input, SymfonyStyle $io, string $directory, ?string $origin): int
    {
        $wizard = $directory.DIRECTORY_SEPARATOR.'configure-stacktoast.php';

        $io->writeln('  <options=bold>Next</>');
        $io->writeln(sprintf('    cd %s', $directory));

        if ($input->getOption('no-configure') || ! is_file($wizard)) {
            $io->writeln('    php configure-stacktoast.php');
            $io->newLine();
            $this->renderRemotes($io, $origin);

            return Command::SUCCESS;
        }

        if (! $input->isInteractive() || ! Process::isTtySupported()) {
            $io->writeln('    php configure-stacktoast.php   <fg=gray>(installs dependencies, writes .env, migrates, builds)</>');
            $io->newLine();
            $this->renderRemotes($io, $origin);

            return Command::SUCCESS;
        }

        if (! $io->confirm('Run the setup wizard now?', true)) {
            $io->writeln('    php configure-stacktoast.php');
            $io->newLine();
            $this->renderRemotes($io, $origin);

            return Command::SUCCESS;
        }

        $process = new Process([PHP_BINARY, 'configure-stacktoast.php'], $directory, null, null, null);
        $process->setTty(true);
        $process->run();

        $this->renderRemotes($io, $origin);

        return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }

    private function renderRemotes(SymfonyStyle $io, ?string $origin): void
    {
        if ($this->upstreamRemote !== null) {
            $io->writeln('  <options=bold>Remotes</>');
            $io->writeln(sprintf('    <fg=gray>%s</>  upstream; `php artisan stacktoast:upgrade` fetches releases from here', $this->upstreamRemote));
            $io->writeln('    <fg=gray>origin</>      unchanged');
            $io->newLine();

            foreach ($this->prefixNotes as $note) {
                $io->writeln('  '.$note);
            }

            $io->newLine();

            return;
        }

        $io->writeln('  <options=bold>Remotes</>');
        $io->writeln('    <fg=gray>stacktoast</>  upstream; `php artisan stacktoast:upgrade` fetches tags from here');
        $io->writeln($origin === null
            ? '    <fg=gray>origin</>      not set. Add your own repository: git remote add origin <url> && git push -u origin main'
            : sprintf('    <fg=gray>origin</>      %s', $origin));
        $io->newLine();
    }

    private function resolveStack(InputInterface $input, SymfonyStyle $io, StackCatalogue $catalogue): ?Stack
    {
        $requested = $this->stringOption($input, 'stack');

        if ($requested !== null) {
            $stack = $catalogue->get($requested);

            if (! $stack instanceof Stack) {
                $io->error(sprintf(
                    'Unknown edition "%s". Available: %s',
                    $requested,
                    implode(', ', $catalogue->names())
                ));

                return null;
            }

            return $stack;
        }

        $editions = $catalogue->all();

        if (! $input->isInteractive() || count($editions) === 1) {
            return $catalogue->default();
        }

        $choices = [];

        foreach ($editions as $stack) {
            $choices[$stack->name] = $stack->description;
        }

        $chosen = (string) $io->choice('Which edition?', $choices, $catalogue->default()->name);

        return $catalogue->get($chosen);
    }

    /**
     * The message a non-customer sees. The reason this package is public.
     */
    private function renderNoAccess(SymfonyStyle $io, Stack $stack): void
    {
        $io->writeln(sprintf('  <fg=red>x</> No access to %s/%s', Brand::OWNER, $stack->repository));
        $io->newLine();
        // The default edition's title is the product name, so naming both
        // reads as "Stack Toast is part of Stack Toast".
        $io->writeln($stack->title === Brand::NAME
            ? sprintf('  %s is a paid product.', Brand::NAME)
            : sprintf('  %s is one of the %s editions.', $stack->title, Brand::NAME));
        $io->writeln(sprintf('  Get access: <fg=cyan>%s</>', Brand::SITE_URL));
        $io->newLine();
        $io->writeln(sprintf('  Bought already? Accept your repository invitation: <fg=cyan>%s</>', Brand::INVITES_URL));
        $io->writeln('  Then run `stacktoast whoami` to confirm.');
        $io->newLine();
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
