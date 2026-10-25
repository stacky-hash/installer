<?php

declare(strict_types=1);

namespace StackToast\Installer\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StackToast\Installer\Brand;
use StackToast\Installer\Stacks\StackCatalogue;

/**
 * What the catalogue refuses.
 *
 * The published catalogue is data this installer fetches over the network, so
 * the one rule it has to keep is that nothing in it can point a clone
 * somewhere else. Anyone who can serve that file would otherwise be running
 * `git clone` of their choosing on every customer's machine.
 */
class CatalogueValidationTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function hostileCatalogues(): array
    {
        return [
            'another account' => ['{"stacks":{"evil":{"repo":"attacker/payload"}}}'],
            'an absolute url' => ['{"stacks":{"evil":{"repo":"https://attacker.example/payload"}}}'],
            'a parent traversal' => ['{"stacks":{"evil":{"repo":"../../payload"}}}'],
            'an option' => ['{"stacks":{"evil":{"repo":"--upload-pack=sh"}}}'],
            'a shell break' => ['{"stacks":{"evil":{"repo":"repo;rm -rf ."}}}'],
            'an ssh url' => ['{"stacks":{"evil":{"repo":"git@attacker.example:payload"}}}'],
            'a name with a slash' => ['{"stacks":{"ev/il":{"repo":"stacktoast"}}}'],
            'no repository' => ['{"stacks":{"evil":{}}}'],
            'no editions' => ['{"stacks":{}}'],
            'not an object' => ['[]'],
            'not json' => ['nonsense'],
        ];
    }

    #[DataProvider('hostileCatalogues')]
    public function test_a_catalogue_that_could_redirect_a_clone_is_rejected(string $json): void
    {
        $this->assertNull(
            StackCatalogue::fromJson($json),
            'A repository name that is not a plain name must be refused, not corrected.'
        );
    }

    public function test_a_well_formed_catalogue_is_accepted(): void
    {
        // So that refusing everything cannot pass for validation.
        $catalogue = StackCatalogue::fromJson(
            '{"php":"8.5.0","node":">=22.0.0","stacks":{"mine":{"repo":"my-repo","title":"Mine","default":true}}}'
        );

        $this->assertNotNull($catalogue);
        $this->assertSame('my-repo', $catalogue->get('mine')?->repository);
        $this->assertSame('8.5.0', $catalogue->php);
    }

    public function test_a_clone_target_is_assembled_from_the_compiled_in_account(): void
    {
        // The other half of the same property: the catalogue supplies a name,
        // never a URL.
        $catalogue = StackCatalogue::fromJson('{"stacks":{"mine":{"repo":"my-repo"}}}');

        $this->assertSame(
            'https://'.Brand::HOST.'/'.Brand::OWNER.'/my-repo.git',
            $catalogue?->get('mine')?->cloneUrl()
        );
    }
}
