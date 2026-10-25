<?php

declare(strict_types=1);

namespace StackToast\Installer;

use StackToast\Installer\Commands\CheckCommand;
use StackToast\Installer\Commands\NewCommand;
use StackToast\Installer\Commands\StacksCommand;
use StackToast\Installer\Commands\WhoamiCommand;
use Symfony\Component\Console\Application as Console;

/**
 * The `stacktoast` command line application.
 */
final class Application extends Console
{
    public const VERSION = '0.1.0';

    public function __construct()
    {
        parent::__construct(Brand::NAME, self::VERSION);

        $this->addCommands([
            new NewCommand,
            new CheckCommand,
            new StacksCommand,
            new WhoamiCommand,
        ]);
    }
}
