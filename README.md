# norialabs/platform

The chassis every Noria Laravel product sits on. Internal - not published, and pinned hard to
PHP 8.5 and Laravel 13 because we control every consumer.

Extracted from zana and the CRM, which had been solving the same eight problems twice. Where the
two had diverged, the better implementation won and the other one's extras were folded in.

## Install

```bash
composer require norialabs/platform
php artisan vendor:publish --tag=platform-config
php artisan migrate
```

Add to the root `composer.json` while developing:

```json
{ "repositories": [{ "type": "path", "url": "../packages/laravel/norialabs-platform" }] }
```

## Modules

Every module is off unless the product wires it, and each is independent. The marketing site can
take `Http` and `Db` without inheriting a Postgres dependency it has no use for.

### Tenancy

Isolation in the database, not in a global scope: a forgotten `where`, a raw query or a console
command cannot step around a policy.

```php
// in a migration
Rls::protect('invoices', staffRead: true);
Rls::protectAllowingGlobal('templates');   // workspace rows plus platform-wide ones
Rls::protectTrail('auth_events');          // written before a workspace is known
Rls::appendOnly('audit_logs');             // a trigger, not a policy
```

```php
$tenancy->run($workspaceId, fn () => $this->work());
$tenancy->asStaff(fn () => Workspace::count());     // an operator screen
$tenancy->asPlatform(fn () => $seeder->run());      // the sanctioned platform write
```

**Every setting has to be declared in `platform.tenancy.gucs`**, because `clear()` resets exactly
that list and pushing one that is not on it throws. Two of the settings widen what a connection
can see rather than narrowing it, and the unwind is allowed to fail - so a pooled connection still
holding `staff_read` would read every workspace for every request after. A setting `clear()` does
not know about is a setting that outlives the request that set it.

The host implements `WorkspaceResolver` and the middleware does the rest. `terminate()` is not
optional.

`php artisan platform:tenancy-check` reports every way one workspace could read another: a role
that is superuser or holds BYPASSRLS, a tenant table with no policy, a policy the table owner is
still exempt from. Run it at boot, on a clock, and in CI.

### Rbac

One gate for the whole application. The catalogue is the product's own enums - zana grants over
meters and tariffs, the CRM over deals and accounts - so the package validates against them and
never ships them.

```php
enum Resource: string implements PermissionResource { /* value, label(), actions(), supports() */ }
enum Action: string implements PermissionAction { /* value, label() */ }
```

```php
Gate::authorize(PlatformServiceProvider::GATE, [Resource::Invoice, Action::Delete]);
```

`Permissions` is a value object, not an array: the document was written by a request body or by an
older version of the class, and both are untrusted on the way in. It refuses a resource the product
does not have, a verb it does not have, and a verb the resource does not admit.

The ceiling is applied after the roles are merged and never before: a role granting more than the
token allows is not an error, it is a role being exercised through a narrower door.

The host binds `PrincipalResolver` and `RoleRepository`, and optionally `PermissionCeiling`. The
gate is registered only when the first two are bound, so a product that has not adopted RBAC gets
its own failure rather than one from inside this package.

### Audit

Append-only, outside tenancy, and the trail outlives the workspace it describes. One
`AuditRecorder`, one shared request id so a row and a log line written seconds apart join up.

### Auth

Issuing and checking a one-time code, and nothing else: no user lookup, no mail, no session,
because those answers differ per product and this does not. Codes are hashed, attempts are counted
on the row rather than in the cache, and issuing cancels whatever was outstanding so the newest
mail is always the one that works.

### Db

`platform:backup`, `platform:restore`, and a dumper per driver. `register()` takes a product's own.

### Http

`SecurityHeaders` is config-driven, because a product that embeds a widget needs to relax one
directive without dropping the middleware. `TrustedProxies` returns null by default rather than
`*`: a container reached only through its own proxy should say so, and one that is not should not
believe forwarded headers at all.

### Money

Integers throughout: a float cannot hold a third of a shilling and a sum of floats does not
reconcile. Mixing currencies throws. `checkedMultiply` refuses an overflow before it happens,
because an int product past `PHP_INT_MAX` silently becomes a float and returns a wrong amount.

### Csv

`Reader` handles the file somebody actually has - a BOM from Excel, semicolons from an older
export, padded and duplicated headers - and yields rows numbered the way the spreadsheet shows.
`Writer` streams, and defuses a cell a spreadsheet would run as a formula.

## Customising

| Knob | How |
|---|---|
| Database | `platform.connection` |
| Table names | `platform.table_prefix`, or `platform.tables.<name>` for one |
| Models | `Platform::useAuditLogModel(...)` |
| Tenant column and settings | `platform.tenancy.column`, `.workspace_guc`, `.gucs` |
| Tables outside tenancy | `platform.tenancy.unscoped_tables` |
| RBAC catalogue | `platform.rbac.resources`, `.actions` |
| CSP directives | `platform.http.security_headers.directives` |
| Currency and minor units | `platform.money.*` |
| Backup disk, retention | `platform.db.*` |
| OTP length, TTL, attempts | `platform.auth.otp.*` |

Turn a module off with `platform.tenancy.enabled` or `platform.audit.enabled`. Publish and edit the
migrations with `--tag=platform-migrations`, then set `PLATFORM_LOAD_MIGRATIONS=false` or every
table is created twice.

## Testing against it

The isolation tests need Postgres **and** a role that cannot ignore a policy. Without one they skip
rather than passing against tables that are wide open:

```bash
psql postgres -c "create role platform_test login password 'platform_test' nosuperuser nobypassrls"
psql postgres -c "create database platform_test owner platform_test"

PLATFORM_TEST_PG="pgsql://platform_test:platform_test@127.0.0.1:5432/platform_test" vendor/bin/pest
```

Without `PLATFORM_TEST_PG` the suite runs on SQLite and everything Postgres-only skips.
