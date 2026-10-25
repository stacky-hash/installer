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

        $io->writeln(sprintf('  Cloning <options=bold>%s</> at <options=bold>%s</>...', $stack->repository, $tag));

        if (! $this->git->cloneAtTag($url, $tag, $directory)) {
            $io->newLine();
            $io->error('The clone failed.');
            $io->writeln('  <fg=gray>'.$this->git->lastError().'</>');

            return Command::FAILURE;
        }

        // Upstream is `stacktoast` so that `origin` stays the customer's own
        // repository; upgrades fetch tags from the former and never the latter.
        if (! $this->git->renameRemote($directory, 'origin', 'stacktoast')) {
            $io->warning('Could not rename the upstream remote to `stacktoast`. Upgrades expect that name.');
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
        $io->writeln('  <options=bold>Remotes</>');
        $io->writeln('    <fg=gray>stacktoast</>  upstream; `php artisan stacktoast:upgrade` fetches tags from here');
        $io->writeln($origin === null
            ? '    <fg=gray>origin</>      not set. Add your own repository: git remote add origin <url>'
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
