<?php

declare(strict_types=1);

namespace StackToast\Installer;

/**
 * The handful of values that identify this product.
 *
 * Compiled in rather than read from the catalogue: a clone target assembled
 * from remote data is a remote-code-execution path, so the host and the
 * account are fixed here and only the repository name comes from data.
 */
final class Brand
{
    public const NAME = 'Stack Toast';

    /**
     * The git host the SKU repositories live on.
     */
    public const HOST = 'github.com';

    /**
     * The account holding the SKU repositories. Customers are added to these
     * as collaborators; access to them is the licence.
     */
    public const OWNER = 'stacky-hash';

    /**
     * The product site, and where anyone without access is sent.
     */
    public const SITE_URL = 'https://stacktoast.com';

    /**
     * Where a customer accepts the repository invitation.
     */
    public const INVITES_URL = 'https://github.com/notifications';

    /**
     * The published catalogue. Absent, unreachable or malformed, the bundled
     * copy is used instead.
     */
    public const CATALOGUE_URL = 'https://raw.githubusercontent.com/stacky-hash/installer/main/stacks.json';

    /**
     * The lowest PHP the application itself runs on. Separate from this
     * package's own floor, which only has to be high enough to run the CLI.
     */
    public const APP_PHP = '8.4.0';

    /**
     * The Node range the application's build tooling requires, as declared by
     * the Vite release it pins.
     */
    public const APP_NODE = '^20.19.0 || >=22.12.0';

    /**
     * An HTTPS clone URL for a repository in the product account.
     *
     * @param  string  $repository  A validated repository name
     */
    public static function cloneUrl(string $repository): string
    {
        return sprintf('https://%s/%s/%s.git', self::HOST, self::OWNER, $repository);
    }

    /**
     * The repository's web page, for a message a human reads.
     *
     * @param  string  $repository  A validated repository name
     */
    public static function repositoryUrl(string $repository): string
    {
        return sprintf('https://%s/%s/%s', self::HOST, self::OWNER, $repository);
    }
}
