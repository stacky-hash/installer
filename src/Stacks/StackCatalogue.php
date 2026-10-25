<?php

declare(strict_types=1);

namespace StackToast\Installer\Stacks;

use RuntimeException;
use StackToast\Installer\Brand;

/**
 * The edition list, from the bundled copy or the published one.
 *
 * Both are public, so an edition is listed once it is available and not
 * before; there is no availability flag to carry.
 *
 * Every field is validated before use and nothing executable is read from it.
 * A repository name that does not match REPOSITORY is rejected rather than
 * corrected, because the name is half of a clone target.
 */
final class StackCatalogue
{
    /**
     * A git repository name. Deliberately narrow: no slashes, so a name
     * cannot redirect the clone to another account, and no leading dash, so
     * it cannot be read as an option.
     */
    private const REPOSITORY = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/';

    /**
     * A stack name, as typed after --stack.
     */
    private const NAME = '/^[a-z][a-z0-9_-]{0,31}$/';

    /**
     * @param  array<string, Stack>  $stacks
     */
    private function __construct(
        private readonly array $stacks,
        public readonly string $php,
        public readonly string $node,
    ) {}

    /**
     * The catalogue shipped inside this package.
     *
     * @throws RuntimeException When the bundled file is missing or malformed,
     *                          which is a packaging fault rather than a user one
     */
    public static function bundled(): self
    {
        $path = dirname(__DIR__, 2).'/stacks.json';
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException("The bundled catalogue is missing: {$path}");
        }

        $catalogue = self::fromJson($contents);

        if (! $catalogue instanceof self) {
            throw new RuntimeException("The bundled catalogue is malformed: {$path}");
        }

        return $catalogue;
    }

    /**
     * Build from JSON, or null when anything about it is unusable.
     *
     * Never throws on bad input: the caller is deciding whether to prefer a
     * remote copy, and unusable remote data means fall back, not fail.
     */
    public static function fromJson(string $json): ?self
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! isset($decoded['stacks']) || ! is_array($decoded['stacks'])) {
            return null;
        }

        $stacks = [];

        /** @var mixed $definition */
        foreach ($decoded['stacks'] as $name => $definition) {
            if (! is_string($name) || preg_match(self::NAME, $name) !== 1) {
                return null;
            }

            if (! is_array($definition)) {
                return null;
            }

            $repository = $definition['repo'] ?? null;

            if (! is_string($repository) || preg_match(self::REPOSITORY, $repository) !== 1) {
                return null;
            }

            $stacks[$name] = new Stack(
                name: $name,
                repository: $repository,
                title: is_string($definition['title'] ?? null) ? $definition['title'] : $name,
                description: is_string($definition['description'] ?? null) ? $definition['description'] : '',
                default: (bool) ($definition['default'] ?? false),
            );
        }

        if ($stacks === []) {
            return null;
        }

        return new self(
            stacks: $stacks,
            php: is_string($decoded['php'] ?? null) ? $decoded['php'] : Brand::APP_PHP,
            node: is_string($decoded['node'] ?? null) ? $decoded['node'] : Brand::APP_NODE,
        );
    }

    /**
     * @return array<string, Stack>
     */
    public function all(): array
    {
        return $this->stacks;
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->stacks);
    }

    public function has(string $name): bool
    {
        return isset($this->stacks[$name]);
    }

    public function get(string $name): ?Stack
    {
        return $this->stacks[$name] ?? null;
    }

    /**
     * The edition offered when the customer does not name one.
     */
    public function default(): Stack
    {
        foreach ($this->stacks as $stack) {
            if ($stack->default) {
                return $stack;
            }
        }

        return reset($this->stacks);
    }
}
