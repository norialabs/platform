<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use NoriaLabs\Platform\Auth\TokenAbilities;
use NoriaLabs\Platform\Auth\TokenCeiling;
use NoriaLabs\Platform\PlatformServiceProvider;
use NoriaLabs\Platform\Rbac\Catalog;
use NoriaLabs\Platform\Rbac\PermissionResolver;
use NoriaLabs\Platform\Rbac\Permissions;
use NoriaLabs\Platform\Tests\Fixtures\Action;
use NoriaLabs\Platform\Tests\Fixtures\Resource;
use NoriaLabs\Platform\Tests\Fixtures\SidedResource;
use NoriaLabs\Platform\Tests\Fixtures\StubCeiling;
use NoriaLabs\Platform\Tests\Fixtures\StubPrincipal;
use NoriaLabs\Platform\Tests\Fixtures\StubPrincipals;
use NoriaLabs\Platform\Tests\Fixtures\StubRoles;
use NoriaLabs\Platform\Tests\Fixtures\TokenHolder;

beforeEach(function (): void {
    StubPrincipals::$principal = null;
    StubPrincipals::$calls = 0;
    StubRoles::$grants = [];
    StubRoles::$calls = 0;
    StubCeiling::$ceiling = null;
});

describe('a permission document', function (): void {
    it('grants what it lists and nothing else', function (): void {
        $permissions = Permissions::fromArray(['invoice' => ['view']]);

        expect($permissions->has(Resource::Invoice, Action::View))->toBeTrue();
        expect($permissions->has(Resource::Invoice, Action::Delete))->toBeFalse();
        expect($permissions->has(Resource::Report, Action::View))->toBeFalse();
    });

    it('lets a system role grant everything with one wildcard', function (): void {
        expect(Permissions::all()->has(Resource::Report, Action::View))->toBeTrue();
        expect(Permissions::all()->grantsEverything())->toBeTrue();
    });

    it('refuses a resource the product does not have', function (): void {
        Permissions::fromArray(['spaceship' => ['view']]);
    })->throws(InvalidArgumentException::class, 'spaceship');

    it('refuses a verb the product does not have', function (): void {
        Permissions::fromArray(['invoice' => ['teleport']]);
    })->throws(InvalidArgumentException::class, 'teleport');

    /*
     * The check that stops a settings screen offering a delete button for
     * something nothing can delete.
     */
    it('refuses a verb the resource does not admit', function (): void {
        Permissions::fromArray(['report' => ['delete']]);
    })->throws(InvalidArgumentException::class, 'does not support');

    it('combines two roles into everything either one grants', function (): void {
        $combined = Permissions::fromArray(['invoice' => ['view']])
            ->merge(Permissions::fromArray(['invoice' => ['create'], 'report' => ['view']]));

        expect($combined->has(Resource::Invoice, Action::Create))->toBeTrue();
        expect($combined->has(Resource::Report, Action::View))->toBeTrue();
    });

    it('trims a grant down to what the ceiling also allows', function (): void {
        $trimmed = Permissions::fromArray(['invoice' => ['view', 'delete'], 'report' => ['view']])
            ->withinCeiling(Permissions::fromArray(['invoice' => ['view']]));

        expect($trimmed->has(Resource::Invoice, Action::View))->toBeTrue();
        expect($trimmed->has(Resource::Invoice, Action::Delete))->toBeFalse();
        expect($trimmed->has(Resource::Report, Action::View))->toBeFalse();
    });

    it('leaves a grant alone when nothing caps it', function (): void {
        $permissions = Permissions::fromArray(['invoice' => ['delete']]);

        expect($permissions->withinCeiling(null)->has(Resource::Invoice, Action::Delete))->toBeTrue();
    });

    it('renders a settings catalogue from the enums, so the screen cannot drift from the gate', function (): void {
        $catalog = Permissions::fromArray(['invoice' => ['view']])->catalog();

        expect($catalog)->toHaveCount(2);
        expect($catalog[0]['resource'])->toBe('invoice');
        expect($catalog[0]['actions'][0])->toBe(['action' => 'view', 'label' => 'View', 'granted' => true]);
        expect($catalog[0]['actions'][2]['granted'])->toBeFalse();
    });

    it('survives a round trip through json', function (): void {
        $permissions = Permissions::fromArray(['invoice' => ['view', 'create']]);
        $decoded = Permissions::fromArray((array) json_decode((string) json_encode($permissions), true));

        expect($decoded->toArray())->toBe($permissions->toArray());
    });
});

describe('the catalogue', function (): void {
    it('reads the product enums the host named', function (): void {
        expect(Catalog::resources())->toBe(Resource::cases());
        expect(Catalog::action('view'))->toBe(Action::View);
        expect(Catalog::resource('nothing'))->toBeNull();
    });

    it('says so plainly when a product has not named its enums', function (): void {
        config(['noria.rbac.resources' => null]);

        Catalog::resources();
    })->throws(RuntimeException::class, 'noria.rbac.resources');
});

describe('resolving what a caller may do', function (): void {
    it('refuses everything to somebody with no principal', function (): void {
        expect(app(PermissionResolver::class)->allows(new User, Resource::Invoice, Action::View))->toBeFalse();
    });

    it('grants what the caller roles add up to', function (): void {
        StubPrincipals::$principal = new StubPrincipal('tenant', ['reader', 'biller']);
        StubRoles::$grants = ['reader' => ['report' => ['view']], 'biller' => ['invoice' => ['create']]];

        $resolver = app(PermissionResolver::class);

        expect($resolver->allows(new User, Resource::Report, Action::View))->toBeTrue();
        expect($resolver->allows(new User, Resource::Invoice, Action::Create))->toBeTrue();
        expect($resolver->allows(new User, Resource::Invoice, Action::Delete))->toBeFalse();
    });

    /*
     * A token narrower than the person holding it. The role is not wrong,
     * it is being exercised through a smaller door.
     */
    it('caps a role at what the credential allows', function (): void {
        StubPrincipals::$principal = new StubPrincipal('tenant', ['admin']);
        StubRoles::$grants = ['admin' => ['invoice' => ['view', 'delete']]];
        StubCeiling::$ceiling = Permissions::fromArray(['invoice' => ['view']]);

        $resolver = app(PermissionResolver::class);

        expect($resolver->allows(new User, Resource::Invoice, Action::View))->toBeTrue();
        expect($resolver->allows(new User, Resource::Invoice, Action::Delete))->toBeFalse();
    });

    it('reads the roles once however many gates a request checks', function (): void {
        StubPrincipals::$principal = new StubPrincipal('tenant', ['reader']);
        StubRoles::$grants = ['reader' => ['invoice' => ['view']]];

        $resolver = app(PermissionResolver::class);
        $user = new User;

        foreach (range(1, 5) as $ignored) {
            $resolver->allows($user, Resource::Invoice, Action::View);
        }

        expect(StubRoles::$calls)->toBe(1);
    });

    it('answers through one gate for the whole application', function (): void {
        StubPrincipals::$principal = new StubPrincipal('tenant', ['reader']);
        StubRoles::$grants = ['reader' => ['invoice' => ['view']]];

        $user = new User;

        expect(Gate::forUser($user)->allows(PlatformServiceProvider::GATE, [Resource::Invoice, Action::View]))->toBeTrue();
        expect(Gate::forUser($user)->allows(PlatformServiceProvider::GATE, [Resource::Invoice, Action::Delete]))->toBeFalse();
    });
});

describe('a token as a ceiling', function (): void {
    /*
     * A token narrows a role and can never widen one, so the abilities are
     * read as a permission document and applied over whatever the roles
     * granted.
     */
    it('allows only what the abilities name', function (): void {
        $user = new TokenHolder(['workspace:01a0b000-0000-7000-8000-00000000000a', 'invoice:view']);

        $ceiling = (new TokenCeiling)->for($user);

        expect($ceiling?->has(Resource::Invoice, Action::View))->toBeTrue();
        expect($ceiling?->has(Resource::Invoice, Action::Delete))->toBeFalse();
    });

    it('allows everything for a token that says so', function (): void {
        expect((new TokenCeiling)->for(new TokenHolder(['*']))?->grantsEverything())->toBeTrue();
    });

    /* A person at a keyboard is bounded by their roles alone. */
    it('imposes no ceiling on somebody who arrived by session', function (): void {
        expect((new TokenCeiling)->for(new TokenHolder(null)))->toBeNull();
    });

    it('ignores the workspace scope, which is not a permission', function (): void {
        $ceiling = (new TokenCeiling)->for(new TokenHolder(['workspace:01a0b000-0000-7000-8000-00000000000a']));

        expect($ceiling?->isEmpty())->toBeTrue();
    });

    it('ignores an ability naming something the product does not have', function (): void {
        $ceiling = (new TokenCeiling)->for(new TokenHolder(['spaceship:launch', 'invoice:view']));

        expect($ceiling?->has(Resource::Invoice, Action::View))->toBeTrue();
        expect($ceiling?->toArray())->toBe(['invoice' => ['view']]);
    });
});

describe('the token vocabulary', function (): void {
    it('reads the one workspace a token is for', function (): void {
        expect(TokenAbilities::workspaceIn(['workspace:01A0B000-0000-7000-8000-00000000000A', 'invoice:view']))
            ->toBe('01a0b000-0000-7000-8000-00000000000a');
    });

    /* A token naming two workspaces is as unscoped as one naming none. */
    it('reads no workspace from a token that names two', function (): void {
        expect(TokenAbilities::workspaceIn([
            'workspace:01a0b000-0000-7000-8000-00000000000a',
            'workspace:01a0b000-0000-7000-8000-00000000000b',
        ]))->toBeNull();
    });

    it('reads no workspace from a token that names none', function (): void {
        expect(TokenAbilities::workspaceIn(['invoice:view']))->toBeNull();
    });

    it('reads no workspace from something that is not one', function (): void {
        expect(TokenAbilities::workspaceIn(['workspace:not-a-uuid']))->toBeNull();
    });

    it('accepts an ability the catalogue supports and refuses one it does not', function (): void {
        expect(TokenAbilities::isValid('invoice:view'))->toBeTrue();
        expect(TokenAbilities::isValid('*'))->toBeTrue();
        expect(TokenAbilities::isValid('workspace:01a0b000-0000-7000-8000-00000000000a'))->toBeTrue();

        expect(TokenAbilities::isValid('report:delete'))->toBeFalse();
        expect(TokenAbilities::isValid('spaceship:launch'))->toBeFalse();
        expect(TokenAbilities::isValid('nonsense'))->toBeFalse();
    });

    it('writes an ability the catalogue will accept back', function (): void {
        $ability = TokenAbilities::permission(Resource::Invoice, Action::View);

        expect($ability)->toBe('invoice:view');
        expect(TokenAbilities::isValid($ability))->toBeTrue();
    });
});

describe('a catalogue with more than one side', function (): void {
    beforeEach(fn () => config(['noria.rbac.resources' => SidedResource::class]));

    /*
     * A settings screen that lists every resource offers a tenant admin
     * the platform ledger. Scope is what stops that.
     */
    it('offers only the resources belonging to that side', function (): void {
        expect(array_map(fn ($r) => $r->value, Catalog::forScope('tenant')))
            ->toBe(['invoice', 'report']);
        expect(array_map(fn ($r) => $r->value, Catalog::forScope('platform')))
            ->toBe(['ledger']);
    });

    it('renders a settings catalogue for one side only', function (): void {
        $catalog = Permissions::fromArray(['invoice' => ['view']])->catalog('tenant');

        expect(array_column($catalog, 'resource'))->toBe(['invoice', 'report']);
    });

    it('renders every side when the caller names none', function (): void {
        expect(Permissions::none()->catalog())->toHaveCount(3);
    });

    /* A product with one side has nothing to filter. */
    it('returns everything when the resources say nothing about scope', function (): void {
        config(['noria.rbac.resources' => Resource::class]);

        expect(Catalog::forScope('tenant'))->toBe(Resource::cases());
    });
});
