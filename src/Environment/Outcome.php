<?php

declare(strict_types=1);

namespace StackToast\Installer\Environment;

/**
 * How one environment requirement came out.
 */
enum Outcome: string
{
    /**
     * Met.
     */
    case Pass = 'pass';

    /**
     * Not met, and the application will not run without it. The only outcome
     * that makes `check` exit non-zero.
     */
    case Fail = 'fail';

    /**
     * Not met, and something will be harder than it needs to be. Not fatal.
     */
    case Warn = 'warn';

    /**
     * Not tested, because testing it needs something the caller did not give.
     */
    case Skip = 'skip';

    public function symbol(): string
    {
        return match ($this) {
            self::Pass => '<fg=green>ok</>',
            self::Fail => '<fg=red>x</>',
            self::Warn => '<fg=yellow>!</>',
            self::Skip => '<fg=gray>-</>',
        };
    }
}
