<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

/**
 * A thing the product grants verbs over. Implemented by a backed enum in the
 * host, because the catalogue is the product: zana grants over meters and
 * tariffs, the CRM over deals and accounts.
 */
interface PermissionResource
{
    public string $value { get; }

    public function label(): string;

    /** @return list<PermissionAction> the verbs this resource admits */
    public function actions(): array;

    public function supports(PermissionAction $action): bool;
}
