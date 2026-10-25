<?php

declare(strict_types=1);

namespace StackToast\Installer\Commands;

use StackToast\Installer\Environment\Doctor;
use StackToast\Installer\Environment\Outcome;
use StackToast\Installer\Environment\Result;
use StackToast\Installer\Stacks\StackCatalogue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Report whether this machine can run the application.
 *
 * Needs no purchase and no access to anything private, which is why this
 * package is public: anyone can run it before deciding.
 *
 * Exit code is 1 when a requirement is unmet, so it can gate a CI job.
 */
#[AsCommand(name: 'check', description: 'Check this machine can run a Stack Toast application')]
class CheckCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('db', null, InputOption::VALUE_REQUIRED, 'A PDO DSN to test, e.g. mysql:host=127.0.0.1;dbname=stacktoast')
            ->addOption('db-username', null, InputOption::VALUE_REQUIRED, 'Username for --db')
            ->addOption('db-password', null, InputOption::VALUE_REQUIRED, 'Password for --db')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $catalogue = StackCatalogue::bundled();
        $doctor = new Doctor;

        $results = $doctor->run([
            'php' => $catalogue->php,
            'node' => $catalogue->node,
            'dsn' => $this->stringOption($input, 'db'),
            'username' => $this->stringOption($input, 'db-username'),
            'password' => $this->stringOption($input, 'db-password'),
        ]);

        if ($input->getOption('json')) {
            $output->writeln($this->json($results));

            return $doctor->failed($results) ? Command::FAILURE : Command::SUCCESS;
        }

        $this->render($io, $results);

        return $doctor->failed($results) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  array<int, Result>  $results
     */
    private function render(SymfonyStyle $io, array $results): void
    {
        $io->newLine();

        foreach ($results as $result) {
            $io->writeln(sprintf(
                '  %s  %s%s',
                $result->outcome->symbol(),
                $result->label,
                $result->found === '' ? '' : sprintf('  <fg=gray>%s</>', $result->found)
            ));
        }

        $remedies = array_values(array_filter(
            $results,
            static fn (Result $result): bool => $result->remedy !== ''
                && in_array($result->outcome, [Outcome::Fail, Outcome::Warn], true)
        ));

        if ($remedies !== []) {
            $io->newLine();

            foreach ($remedies as $result) {
                $io->writeln(sprintf('  <fg=gray>%s:</> %s', $result->label, $result->remedy));
            }
        }

        $io->newLine();

        $failures = array_filter($results, static fn (Result $r): bool => $r->outcome === Outcome::Fail);

        if ($failures !== []) {
            $io->error(sprintf('%d requirement%s unmet.', count($failures), count($failures) === 1 ? '' : 's'));

            return;
        }

        $io->success('This machine is ready.');
    }

    /**
     * @param  array<int, Result>  $results
     */
    private function json(array $results): string
    {
        $payload = [
            'ready' => ! (new Doctor)->failed($results),
            'checks' => array_map(static fn (Result $result): array => [
                'label' => $result->label,
                'outcome' => $result->outcome->value,
                'found' => $result->found,
                'remedy' => $result->remedy,
            ], $results),
        ];

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
