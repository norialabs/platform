<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

/**
 * Who is asking, reduced to the three things authorisation needs: which side
 * of the product they are on, which roles they hold there, and the workspace
 * the roles apply to.
 *
 * A member, an operator, a portal visitor and a partner are all principals;
 * what differs is where their roles come from, which is the host's business.
 */
interface Principal
{
    /** Tenant, platform, portal - whatever sides this product has. */
    public function scope(): string;

    /** @return list<string> */
    public function roleSlugs(): array;

    public function contextId(): ?string;
}
