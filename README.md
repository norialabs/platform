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

Models use `BelongsToWorkspace`, which stamps the workspace as a row is created. Without it every
insert has to name its own, and the one that forgets is refused by the policy's `with check` - or,
on a nullable column, writes an orphan nobody can read again.

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

### Log

`Logger::app/auth/backup/exception` with the context scrubbed first. Keys are normalised before
matching, so `Api-Key`, `api_key` and `apikey` are one key, and suffixes catch the prefixed
variants an exact list misses. A product adds its own through `platform.log.*`, however it spells
them, and never loses the defaults.

Masked rather than dropped: a support ticket saying the token ended `9f` is answerable, one saying
`[redacted]` is not.

This is the front of the pipe, not the transport. It scrubs and hands off to whatever channel the
product configured, so it composes with `thekiharani/laravel-cwl` rather than replacing it:

```
Logger::auth('otp issued', ['phone' => '254712345678'])   scrubbed here
        v
Log::channel('auth')                                      Laravel
        v
'auth' => ['driver' => 'cloudwatch', ...]                 laravel-cwl ships it
```

Do not write a CloudWatch channel of your own - `laravel-cwl` is that, and this package
deliberately carries no handler, no driver and no AWS SDK, because every product would then pull
it to get a redacted log line. A channel this package names that the product has not defined falls
back to the default one, so `auth` and `backup` are optional.

### Audit

Append-only, outside tenancy, and the trail outlives the workspace it describes. One
`AuditRecorder`, one shared request id so a row and a log line written seconds apart join up.

### Auth

Issuing and checking a one-time code, and the mechanics of a provider round trip. No user lookup,
no mail, no session, no routes: those differ per product and these do not.

Codes are hashed, attempts are counted on the row rather than in the cache, issuing cancels
whatever was outstanding so the newest mail is always the one that works, and a resend inside
`platform.auth.otp.throttle` throws `OtpThrottled` carrying the wait.

`SocialState` mints and claims the nonce that binds a provider round trip to the browser that
started it - stateless Socialite sends no state parameter at all - and records who began it,
because that is the only moment the intent is known: begun by nobody is a sign-in, begun by
somebody is a link. Single use, keyed by hash. `ProviderProfile` normalises what came back and
keeps no provider token; `email_verified` and `verified_email` are the same answer and absent
means no.

### Db

`platform:backup`, `platform:restore`, and a dumper per driver. `register()` takes a product's own.

`platform:scheduler-heartbeat` on the schedule and `platform:scheduler-healthy` as the container
healthcheck: a scheduler that is running but never firing looks identical to a healthy one from
outside.

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
| Models | `Platform::useAuditLogModel(...)`, `useOtpChallengeModel(...)` |
| Tenant column and settings | `platform.tenancy.column`, `.workspace_guc`, `.gucs` |
| Tables outside tenancy | `platform.tenancy.unscoped_tables` |
| RBAC catalogue | `platform.rbac.resources`, `.actions` |
| CSP directives | `platform.http.security_headers.directives` |
| Currency and minor units | `platform.money.*` |
| Backup disk, retention | `platform.db.*` |
| OTP length, TTL, attempts, resend wait | `platform.auth.otp.*` |
| Extra redaction keys | `platform.log.*` |
| Trusted proxies | `platform.http.trusted_proxies` + the `TrustProxies` middleware |
| Scheduler heartbeat window | `platform.scheduler.heartbeat_ttl` |

`actor_id` on the trail is a string, not a uuid: the package cannot know the host's user model,
and a product still keyed on bigint would have every write refused. The tenant column is read from
`platform.tenancy.column` in the package's own migration too, so renaming it renames it everywhere.

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
