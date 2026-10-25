<?php

declare(strict_types=1);

namespace StackToast\Installer\Environment;

/**
 * One requirement and what was found.
 */
final readonly class Result
{
    public function __construct(
        public string $label,
        public Outcome $outcome,
        public string $found = '',
        public string $remedy = '',
    ) {}

    public static function pass(string $label, string $found = ''): self
    {
        return new self($label, Outcome::Pass, $found);
    }

    public static function fail(string $label, string $found, string $remedy): self
    {
        return new self($label, Outcome::Fail, $found, $remedy);
    }

    public static function warn(string $label, string $found, string $remedy): self
    {
        return new self($label, Outcome::Warn, $found, $remedy);
    }

    public static function skip(string $label, string $reason): self
    {
        return new self($label, Outcome::Skip, $reason);
    }
}
