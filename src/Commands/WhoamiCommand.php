<?php

declare(strict_types=1);

namespace StackToast\Installer\Commands;

use StackToast\Installer\Brand;
use StackToast\Installer\Git;
use StackToast\Installer\Stacks\StackCatalogue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Which editions this machine's git credentials can reach.
 *
 * Derived entirely from git access, so there is no licence server, no key to
 * mislay and nothing to answer when it is down. It reports what the account
 * can clone, which is the same question `new` asks.
 */
#[AsCommand(name: 'whoami', description: 'Show which editions your git credentials can reach')]
class WhoamiCommand extends Command
{
    public function __construct(private readonly Git $git = new Git)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (! $this->git->available()) {
            $io->error('git was not found on PATH. Run `stacktoast check` for the full list.');

            return Command::FAILURE;
        }

        $catalogue = StackCatalogue::bundled();
        $reachable = [];

        foreach ($catalogue->all() as $stack) {
            $reachable[$stack->name] = $this->git->canReach($stack->cloneUrl());
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($reachable, JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        $io->newLine();

        foreach ($catalogue->all() as $stack) {
            $io->writeln(sprintf(
                '  %s  %s%s',
                $reachable[$stack->name] ? '<fg=green>ok</>' : '<fg=gray>-</>',
                $stack->name,
                $reachable[$stack->name] ? '' : ' <fg=gray>no access</>'
            ));
        }

        $io->newLine();

        if (! in_array(true, $reachable, true)) {
            $io->writeln('  No editions are reachable with this machine\'s credentials.');
            $io->writeln(sprintf('  Bought already? Accept the repository invitation: <fg=cyan>%s</>', Brand::INVITES_URL));
            $io->writeln(sprintf('  Not yet? <fg=cyan>%s</>', Brand::SITE_URL));
            $io->newLine();
        }

        return Command::SUCCESS;
    }
}
