<?php

declare(strict_types=1);

namespace StackToast\Installer\Stacks;

use StackToast\Installer\Brand;

/**
 * One SKU in the catalogue.
 */
final readonly class Stack
{
    public function __construct(
        public string $name,
        public string $repository,
        public string $title,
        public string $description,
        public bool $default = false,
    ) {}

    public function cloneUrl(): string
    {
        return Brand::cloneUrl($this->repository);
    }

    public function repositoryUrl(): string
    {
        return Brand::repositoryUrl($this->repository);
    }
}
