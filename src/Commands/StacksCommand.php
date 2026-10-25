<?php

declare(strict_types=1);

namespace StackToast\Installer\Commands;

use StackToast\Installer\Brand;
use StackToast\Installer\Stacks\Stack;
use StackToast\Installer\Stacks\StackCatalogue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * List the editions. Needs no access to anything.
 */
#[AsCommand(name: 'stacks', description: 'List the available editions')]
class StacksCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stacks = StackCatalogue::bundled()->all();

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(
                array_map(static fn (Stack $stack): array => [
                    'name' => $stack->name,
                    'title' => $stack->title,
                    'description' => $stack->description,
                ], array_values($stacks)),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->newLine();

        foreach ($stacks as $stack) {
            $io->writeln(sprintf('  <options=bold>%s</>', $stack->name));
            $io->writeln(sprintf('  <fg=gray>%s</>', $stack->description));
            $io->newLine();
        }

        $io->writeln(sprintf('  <fg=gray>%s</>', Brand::SITE_URL));
        $io->newLine();

        return Command::SUCCESS;
    }
}
