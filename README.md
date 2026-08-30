# Safe SQL MCP

Read-only, de-identified access to your Laravel application's database for AI
agents — served from inside your own app, under your own OAuth and your own
permissions.

```
SELECT id, name, email, status FROM customers LIMIT 2

id  name              email                status
1   [name:15cd0e0f]   [email:51e84a2b]     active
2   [name:2b194dbb]   [email:f1100654]     churned
```

## Why this exists

Three things have to be true at once before you can point an agent at a
production database, and no existing tool does all three.

| | Overlaps on | Gap |
|---|---|---|
| Laravel Boost | `Database Query`, `Database Schema` tools | `--dev` only, stdio, local machine. No PII layer, no remote HTTP/OAuth. Built for a coding agent on your laptop. |
| The telescope-mcp packages | Telescope access | Compete on coverage — one advertises 19 tools. Each call risks dumping 10k+ tokens. |
| `mcp-server-mysql`, `mcp-read-only-sql` | Read-only SQL guardrails | External Node/Python processes holding their own DB credentials. No Laravel awareness, no app permissions, no anonymization. |
| The Laravel anonymization packages | PII column maps | All anonymize *at rest* or mask *Eloquent serialization*. None handle arbitrary result rows from free-form SQL. |
| `mcp-server-conceal` | In-flight pseudonymization | Generic proxy, extra network hop, no Laravel or schema awareness. |

**The combination is the product:** read-only SQL over a real database,
pseudonymization of arbitrary result sets, served from inside the Laravel app
under its own auth.

## Installation

```bash
composer require afiqsazlan/laravel-mcp-safe-sql
php artisan vendor:publish --tag=safe-sql-config
```

## Quick start

**1. Define a profile** in `config/safe-sql.php`:

```php
'profiles' => [
    'research' => [
        'connection' => 'mysql_replica',
        'anonymize'  => true,
        'tools'      => ['sql', 'schema'],
        'instructions' => resource_path('mcp/research.md'),
    ],
],
```

**2. Declare a server** — a subclass per profile, and nothing else:

```php
namespace App\Mcp\Servers;

use Afiqsazlan\SafeSql\Servers\SqlMcpServer;

class ResearchServer extends SqlMcpServer
{
    protected string $profile = 'research';
}
```

**3. Register the route**, with your own middleware:

```php
use Afiqsazlan\SafeSql\Http\OAuthRoutes;

OAuthRoutes::register();

Route::middleware(['auth:oauth', 'scope:mcp:use', 'can:access-research'])
    ->group(fn () => Mcp::web('mcp/research', ResearchServer::class));
```

The package enforces read-only SQL and pseudonymization. It does not decide
who may connect — that stays your application's job.

### Use one endpoint per sensitivity tier

Give each profile its own endpoint rather than merging them. Anonymized
production data and literal staging data need to be grantable independently:
nobody debugging staging should acquire production access as a side effect.
Server instructions also stay resident for the entire session, so a merged
endpoint makes every conversation pay for both instruction sets.

## What "safe" means

Precision matters more than reassurance here, so this section says what is
guaranteed and what is not.

### Read-only enforcement

Only `SELECT`, `WITH` and plain `EXPLAIN` reach the database. Writes, schema
changes, `SET`, procedures, filesystem access (`LOAD_FILE`, `INTO OUTFILE`)
and stacked statements are refused. `EXPLAIN ANALYZE` and
`EXPLAIN FOR CONNECTION` are refused because they execute the statement.

The validator mirrors MySQL's own comment rules rather than approximating
them, because every divergence is a bypass. It rejects `/*! … */` executable
comments outright, and treats `--` as a comment only when followed by
whitespace, as MySQL does.

**This is defense in depth, not a sandbox.** Give the connection a
genuinely read-only database user. A validator is a parser, and parsers can
be wrong; database grants cannot be argued with.

### Pseudonymization

Fail-closed: a value is returned raw only when positively established as
safe. Decisions run in this order, first match wins.

| | Rule | Result |
|---|---|---|
| 1 | `null`, boolean, empty | raw |
| 2 | Label is known PII | pseudonymized |
| 3 | **Value matches a PII shape** | pseudonymized |
| 4 | Value is numeric | raw |
| 5 | Label is known safe | raw |
| 6 | Long free text | pseudonymized |
| 7 | Anything else | **pseudonymized** |

Step 3 is why this is not just a column list. The anonymizer only ever sees
the *result column label*, and labels are chosen by whoever wrote the query,
so trusting them alone fails in both directions:

```sql
SELECT email AS e  FROM users   -- defeats a label denylist
SELECT email AS id FROM users   -- defeats a label allowlist
```

Value-shape detection catches both, because the value still looks like an
address whatever it is called.

### What pseudonymization does *not* cover

- **Values with no recognisable shape.** A bare first name in an unmapped
  column is caught by step 7 only if it is a string; if your schema stores an
  identifier as an integer under an unrecognised label, it passes as a number.
  Add such columns to `pii_columns`.
- **No provenance resolution.** Result labels are not mapped back to source
  columns via `information_schema`. Detection is by label and by value shape.
- **Aggregates leak shape, not values.** `COUNT(*) WHERE email = '…'` returns
  a real number. Pseudonymization protects values in result rows; it does not
  stop a determined operator inferring facts through repeated queries.
- **It is not access control.** Anything the connection can read, the agent
  can ask about. Scope the database user, and use `excluded_tables`.
- **Free text is best-effort.** Long text is pseudonymized wholesale, but
  inline redaction inside shorter strings only catches configured patterns.

Pseudonymization reduces exposure. It is not anonymization in the regulatory
sense, and you should not describe it that way to your compliance team.

### Token stability

Tokens are `[type:hash]`, where the hash is keyed by a salt.

| `salt.lifetime` | Same value → same token | Use when |
|---|---|---|
| `session` (default) | within one MCP session | Normal. Multi-query analysis works; tokens die with the session. |
| `request` | within one tool call | Maximum privacy, and cross-query correlation breaks silently. |
| `config` | forever, across sessions and deploys | Long-running analysis. This is a durable pseudonym for a real person — treat the secret accordingly. |

Under HTTP transport each request is a separate PHP process, so session-stable
tokens are *derived* from the MCP session id rather than held in memory.

### The ergonomic cost, up front

Fail-closed means unrecognised short strings are pseudonymized, including
harmless ones:

```
payment_method  'fpx'       →  [value:221a3546]
```

The tool cannot tell `'fpx'` from a first name. Add such columns to
`safe_columns` — `safe_column_patterns` already covers `*_at`, `*_id`,
`is_*` and similar. Expect to spend your first hour classifying columns. The
default errs toward tokenizing something readable rather than leaking
something sensitive, and loosening it is a one-line config change.

## Telescope

One tool, not nineteen. The entry point is a uuid pasted from a Telescope URL,
and it returns a digest of the whole batch — request line, exceptions with
their top frames, the SQL that ran, log entries — for a few hundred tokens
instead of the tens of thousands a full response body costs.

Heavy fields are opt-in:

```
telescope uuid=9f2a…                      compact digest
telescope uuid=9f2a… include=payload      add the request payload
telescope uuid=9f2a… include=trace        add the full stack trace
```

Everything that can carry user data is pseudonymized, and credential-bearing
headers (`Authorization`, `Cookie`, `X-API-Key`, …) are removed outright and
cannot be retrieved. A denylist is sound for headers, unlike for result
columns: header names are fixed by the protocol and cannot be aliased.

Free text is redacted *inline* so it stays debuggable:

```
Duplicate entry [email:51e84a2b] for key users_email_unique
```

The tools compose: digest a batch, pull the one heavy field you still need,
then verify what was actually written with SQL. Telescope tells you what the
request did; SQL tells you what survived it.

## Instructions

Server instructions are composed per profile and shipped with the package. A
profile that does not pseudonymize does not carry the pseudonymization rules;
one without the telescope tool does not carry its workflow.

Your own instructions are **appended**, not substituted:

```php
'instructions' => resource_path('mcp/research.md'),
```

This matters. An agent that does not know `[email:51e84a2b]` is a pseudonym
will write `WHERE email = '[email:51e84a2b]'`, get zero rows, and report that
the customer does not exist. That is a wrong answer, not a worse
conversation — which is why it lives in instructions rather than a skill, and
why supplying your own cannot silently drop it.

## OAuth

`OAuthRoutes::register()` serves discovery metadata **without Dynamic Client
Registration**. Laravel MCP's built-in `Mcp::oauthRoutes()` also exposes
`POST /oauth/register` and advertises a `registration_endpoint`, with no way
to opt out.

DCR lets anything that can reach the endpoint mint its own OAuth client.
Reasonable for a public MCP service; wrong in front of a production database,
where the legitimate clients are few and known:

```bash
php artisan passport:client --public --name="Claude"
```

Use `Mcp::oauthRoutes()` instead if you actually want open registration.

## Configuration

| Key | Purpose |
|---|---|
| `profiles.*` | connection, `anonymize`, `tools`, `instructions`, `descriptions` |
| `limits` | row cap, response cap, timeout, cell length, Telescope caps |
| `anonymizer.salt` | `lifetime` and `secret` |
| `anonymizer.pii_columns` | label → token type |
| `anonymizer.safe_columns` / `safe_column_patterns` | labels that may pass raw |
| `anonymizer.value_patterns` | shape detection, applied regardless of label |
| `schema.core_tables` | described in full. Empty means index-only, the cheap default |
| `schema.excluded_tables` | omitted from the digest |
| `telescope.header_denylist` | headers removed outright |
| `uri_scheme` | scheme for resource URIs |

## Requirements

- PHP 8.2+
- Laravel 12
- `laravel/mcp` ^0.9

**`laravel/mcp` is pre-1.0, and that is this package's largest maintenance
risk.** The constraint is deliberately tight. Expect a package release to be
needed for each `laravel/mcp` minor bump.

MySQL is the primary target. Query validation is written against MySQL's
grammar; schema introspection uses Laravel's driver-agnostic schema builder
and is not MySQL-specific.

## Testing

```bash
composer test
```

## License

MIT.
