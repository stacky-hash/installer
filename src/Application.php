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
    /**
     * 1.x from the first public release on purpose. `composer global require`
     * writes a caret constraint, and on a 0.x version a caret stops at the
     * next minor, so `composer global update` could never cross to 0.2.0.
     */
    public const VERSION = '1.0.0';

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
