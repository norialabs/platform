<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

/**
 * A thing the product grants verbs over. Implemented by a backed enum in the
 * host, because the catalogue is the product: zana grants over meters and
 * tariffs, the CRM over deals and accounts.
 *
 * Extending BackedEnum rather than declaring `public string $value { get; }`:
 * the property form is an interface property, which is PHP 8.4, and this
 * package runs on 8.3. PHP forbids a userland class implementing BackedEnum,
 * so this says the same thing the docblock always said - implementers are
 * backed enums - and says it to the compiler.
 */
interface PermissionResource extends \BackedEnum
{
    public function label(): string;

    /** @return list<PermissionAction> the verbs this resource admits */
    public function actions(): array;

    public function supports(PermissionAction $action): bool;
}
