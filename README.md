# norialabs/platform

[![CI](https://github.com/norialabs/platform/actions/workflows/ci.yml/badge.svg)](https://github.com/norialabs/platform/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/norialabs/platform)](https://packagist.org/packages/norialabs/platform)

The chassis every Noria Laravel product sits on. Built for our own products and pinned hard to
PHP 8.5 and Laravel 13 because we control every consumer - but public, and MIT, so nothing here
is a secret you have to take on trust.

Extracted from zana and the CRM, which had been solving the same eight problems twice. Where the
two had diverged, the better implementation won and the other one's extras were folded in.

## Install

```bash
composer require norialabs/platform
php artisan vendor:publish --tag=noria-config
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

**Every setting has to be declared in `noria.tenancy.gucs`**, because `clear()` resets exactly
that list and pushing one that is not on it throws. Two of the settings widen what a connection
can see rather than narrowing it, and the unwind is allowed to fail - so a pooled connection still
holding `staff_read` would read every workspace for every request after. A setting `clear()` does
not know about is a setting that outlives the request that set it.

The host implements `WorkspaceResolver` and the middleware does the rest. `terminate()` is not
optional.

Models use `BelongsToWorkspace`, which stamps the workspace as a row is created. Without it every
insert has to name its own, and the one that forgets is refused by the policy's `with check` - or,
on a nullable column, writes an orphan nobody can read again.

`php artisan noria:tenancy-check` reports every way one workspace could read another: a role
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
variants an exact list misses. A product adds its own through `noria.log.*`, however it spells
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

### Identity

`Destination` is an email address or a phone number normalised to the one spelling everything else
stores and hashes. Every path that identifies a person by a channel goes through it, because two
spellings of one address that hash differently are two accounts for one person. `Phone` puts a
number in E.164 against a dialling code from config, so a new market is a config line.

`KeyedHash` is keyed SHA-256, for a value the system must look up and must never read back. The
key is what makes it unguessable - a plain digest of a Kenyan mobile is 10^8, seconds of work -
and it is not in the database.

Both a destination and a token are stored hashed, with a masked hint beside them: enough to
recognise which of your addresses was used, never enough to reconstruct one you have not seen.

### Auth

Issuing and checking a one-time code, and the mechanics of a provider round trip. No user lookup,
no mail, no session, no routes: those differ per product and these do not.

Neither the code nor the address it went to is kept in clear, so a dump of the table signs nobody
in and names nobody. Attempts are counted on the row rather than in the cache, issuing cancels
whatever was outstanding so the newest message is always the one that works, and a resend inside
`noria.auth.otp.throttle` throws `OtpThrottled` carrying the wait.

`SocialState` mints and claims the nonce that binds a provider round trip to the browser that
started it - stateless Socialite sends no state parameter at all - and records who began it,
because that is the only moment the intent is known: begun by nobody is a sign-in, begun by
somebody is a link. Single use, keyed by hash. `ProviderProfile` normalises what came back and
keeps no provider token; `email_verified` and `verified_email` are the same answer and absent
means no.

### Tokens

`PersonalAccessToken` is Sanctum's, carrying a real tenant column. The workspace is parsed out of
the abilities on the way in, and **a token that names none never reaches the table** - enforced on
the model rather than at the caller, so no controller, job or command can mint a token spanning
every workspace by forgetting a line. A token naming two workspaces is as unscoped as one naming
none.

`TokenAbilities` is the vocabulary: one `workspace:{uuid}`, then `{resource}:{action}` pairs the
catalogue supports, or `*`. `TokenCeiling` reads those abilities as a `Permissions` document and
is the `PermissionCeiling` most products want - a token narrows a role and can never widen one.

Register it yourself with `Sanctum::usePersonalAccessTokenModel()`; the package does not, because
a product may not use Sanctum at all. Sanctum is a suggested dependency, not a required one.

### Invitations

One open invitation per destination, a hashed single-use token, a deadline, and the answer that
the person accepting is the person invited - a leaked link must not become an account in somebody
else's workspace.

What a role means and who becomes a member stay with the product: `accept()` marks the invitation
used inside its workspace and hands it back for the caller to write the membership from. Delivery
is the `Courier` contract, because the wording, the template and the provider are the product's.

A token is read through a policy keyed on `noria.invitations.token_guc` rather than through
tenancy, because somebody accepting has not joined a workspace yet:

```php
Rls::allowLookupByGuc('invitations', 'token_hash', 'app.invitation_token');
```

### Db

`noria:backup`, `noria:restore`, `noria:rebuild`, and a dumper per driver. `register()`
takes a product's own.

**Backups need a role that bypasses row level security.** `pg_dump` run as an RLS-constrained role
writes a file that looks entirely normal and holds no rows, and nobody finds out until a restore.
The preflight refuses rather than letting that file exist, so point `noria.db.admin_connection`
at a role created with `bypassrls`.

Two tiers, not one retention number: an hourly dump answers the mistake somebody made this
morning, a daily one answers the corruption nobody noticed for a fortnight, and keeping a
fortnight of hourlies to get the second costs fourteen times the storage. The first dump after
`tiers.daily.hour` is promoted; the rest of the day stays hourly. A sweep that fails is logged and
does not fail the run - the dump is already safe, and turning a storage bill into a missing backup
would be the worse trade. Uploads and listings retry, because on object storage a connection
failure and a rejection look identical once the disk swallows the reason.

`restore --database=` restores beside the live database rather than over it, creating it and
granting the application role in: a rehearsal that proves the dump before anybody bets on it.

`noria:rebuild` exists because migrations edited in place rather than added to leave a
long-lived database behind for good - `migrate` sees every file already run, and the gap only
surfaces as a missing relation somewhere deep inside a request. It runs the migrations against an
empty probe database first and compares: a table or column the migrations no longer define is
dropped when it is empty and **refuses to proceed** when it still holds rows, so nothing is lost
quietly. Then it copies, dumps, rebuilds from the migrations, reloads, and verifies every table
row for row against the copy before dropping it. Constraints added `NOT VALID` come off for the
reload and go back on afterwards, still not validated, because reloading grandfathered rows
through them would fail. Nothing is deleted, and if any step fails the error says how to swap the
copy back.

`noria:scheduler-heartbeat` on the schedule and `noria:scheduler-healthy` as the container
healthcheck: a scheduler that is running but never firing looks identical to a healthy one from
outside.

### Http

`SecurityHeaders` is config-driven, because a product that embeds a widget needs to relax one
directive without dropping the middleware. `TrustedProxies` returns null by default rather than
`*`: a container reached only through its own proxy should say so, and one that is not should not
believe forwarded headers at all.

### Errors

`noria:build-error-pages` renders the gateway views to static files the edge can serve. A 502
or a 504 means the application is not answering, so the page for it cannot be rendered by the
application and its stylesheet cannot be fetched either - both are baked in ahead of time.

### Timestamps

`noria.timestamps` is `tz` by default, so the package's columns are `timestamptz`.

That needs the connection to agree with the application. Eloquent writes a naive
`Y-m-d H:i:s`, and Postgres reads it into a `timestamptz` using the **session** timezone - so a
server sitting on `Africa/Nairobi` under an application on UTC stores every moment three hours
early. Nothing errors; a sign-in code is simply born expired, and it only reproduces on the one
machine whose server has that default.

```php
// config/database.php
'timezone' => env('DB_TIMEZONE', env('APP_TIMEZONE', 'UTC')),
```

The migration refuses without it, and `noria:tenancy-check` reports it afterwards, because a
connection added later would otherwise go unnoticed until the next migration. Set
`noria.timestamps` to `plain` for a host whose other tables are not timezone aware.

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
| Database | `noria.connection` |
| Table names | `noria.table_prefix`, or `noria.tables.<name>` for one |
| Models | `Platform::useAuditLogModel(...)`, `useOtpChallengeModel(...)`, `useInvitationModel(...)` |
| Hash key, country, dialling codes | `noria.identity.*` |
| Invitation deadline and lookup setting | `noria.invitations.*` |
| Tenant column and settings | `noria.tenancy.column`, `.workspace_guc`, `.gucs` |
| Tables outside tenancy | `noria.tenancy.unscoped_tables` |
| RBAC catalogue | `noria.rbac.resources`, `.actions` |
| CSP directives | `noria.http.security_headers.directives` |
| Currency and minor units | `noria.money.*` |
| Backup disk, tiers, retention, retries | `noria.db.*` |
| The role dumps and restores run as | `noria.db.admin_connection` |
| Tables a rebuild does not carry | `noria.db.rebuild.unrestored` |
| Checks a rebuild ends on | `noria.db.rebuild.verify_commands` |
| OTP length, TTL, attempts, resend wait | `noria.auth.otp.*` |
| Static error page codes, view, stylesheet | `noria.errors.*` |
| Extra redaction keys | `noria.log.*` |
| Trusted proxies | `noria.http.trusted_proxies` + the `TrustProxies` middleware |
| Scheduler heartbeat window | `noria.scheduler.heartbeat_ttl` |

`actor_id` on the trail is a string, not a uuid: the package cannot know the host's user model,
and a product still keyed on bigint would have every write refused. The tenant column is read from
`noria.tenancy.column` in the package's own migration too, so renaming it renames it everywhere.

Turn a module off with `noria.tenancy.enabled` or `noria.audit.enabled`. Publish and edit the
migrations with `--tag=noria-migrations`, then set `NORIA_LOAD_MIGRATIONS=false` or every
table is created twice.

## Testing against it

The isolation tests need Postgres **and** a role that cannot ignore a policy. Without one they skip
rather than passing against tables that are wide open:

```bash
psql postgres -c "create role platform_test login password 'platform_test' nosuperuser nobypassrls"
psql postgres -c "create database platform_test owner platform_test"

NORIA_TEST_PG="pgsql://platform_test:platform_test@127.0.0.1:5432/platform_test" vendor/bin/pest
```

The dump and restore tests need a second role as well, one that may bypass row level security,
and they skip without it rather than passing against a file that would have come back empty:

```bash
psql postgres -c "create role platform_admin login password 'platform_admin' nosuperuser bypassrls createdb in role platform_test"

NORIA_TEST_PG_ADMIN="pgsql://platform_admin:platform_admin@127.0.0.1:5432/platform_test" vendor/bin/pest
```

Without `NORIA_TEST_PG` the suite runs on SQLite and everything Postgres-only skips.
