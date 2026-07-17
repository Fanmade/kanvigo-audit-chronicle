# Kanvigo Audit — Chronicle bridge

You run Kanvigo yourself, and someone — an auditor, a customer's security team, a
regulation — now needs you to prove what happened on your board and prove the
record wasn't edited after the fact. Kanvigo's built-in activity feed shows recent
changes, but it's a mutable table: fine for "who moved this task", useless as
evidence.

This package turns that requirement into an opt-in install. It writes every audit
event into a [Chronicle](https://github.com/laravel-chronicle/core) compliance
ledger — append-only, hash-chained and tamper-evident, with signed checkpoints,
optional WORM storage and per-person GDPR erasure. If you don't have this
requirement, you never install it and never carry its weight.

## Is this for you?

Reach for it when you need to answer "yes" to any of these:

- **You have to prove integrity.** A hash chain plus signed checkpoints lets you
  demonstrate to a third party that no entry was altered or removed — not just
  assert it.
- **You have a retention mandate.** WORM anchoring (S3 Object Lock) makes the
  record physically un-deletable for a fixed period, even by an admin with root.
- **You operate under GDPR and still need the log.** Per-subject crypto-shredding
  erases one person's data on request while the chain around it still verifies.
- **You can't afford a silent gap.** In fail-closed mode, if the ledger can't
  record an action, the action doesn't happen.

If none of that applies, the default Kanvigo activity feed is the right tool and
you can skip this entirely.

## What you're signing up for

Compliance-grade guarantees cost something, and it's fairer to name it up front:

- **Every audited mutation must run inside a database transaction.** Fail-closed
  writes join that transaction so a failed record rolls the action back; an event
  emitted outside a transaction throws. This is the price of "no record → no
  action". If that's too strict, `sync` mode relaxes to a best-effort write after
  commit.
- **Chronicle is young.** You're taking on a dependency whose crypto you should
  review before you rely on it (see [Before you trust it](#before-you-trust-it)).
  It's pinned to an exact version for that reason.
- **New moving parts in production.** Signing keys, scheduled integrity checks,
  and — if you want them — S3 and KMS. The install command hands you the checklist.

Core Kanvigo never depends on this package. All of Chronicle's baggage
(`ext-sodium` / `ext-openssl` / AWS) lives here, behind the stable
[`kanvigo/audit-contracts`](https://github.com/Fanmade/kanvigo-audit-contracts)
`AuditSink` interface — so nothing in your audited code knows or cares that
Chronicle is the backend, and you can swap it out later without touching a call
site.

## Get it running

```bash
composer require kanvigo/audit-chronicle
php artisan audit:chronicle:install
```

The install command publishes config, runs Chronicle's own installer, mints a
signing key, registers the sink in your `config/audit.php`, and prints the
production-hardening checklist. Put the printed key material in your `.env` and
you're recording.

## Configuration

Most of what you'll touch lives in `config/audit-chronicle.php`:

- **`policy`** — `fail-closed` (default) or `sync`. This is the big decision; see
  the trade-off above.
- **`categories`** — which `AuditCategory` values reach the ledger (default: all).
  Narrow it if you only need certain events on the record.
- **`actor_type`** — the reference type stamped for the actor (default `user`; set
  it to your `User` model class to enable Chronicle reference hydration).
- **`system_subject`** — the stand-in subject for events that have none.

Chronicle's own chain, signing, encryption and anchoring settings live in
`config/chronicle.php`.

## Hardening for production

The install command prints these; the short version:

- **Integrity** — schedule `chronicle:checkpoint` and
  `chronicle:verify --since-last-checkpoint` so drift is caught early, not at audit
  time.
- **WORM anchoring** — `composer require laravel-chronicle/anchor-s3`, configure S3
  Object Lock (Governance mode, 7-year retention), set
  `CHRONICLE_ANCHORING_ENABLED=true`.
- **GDPR erasure** — enable per-subject encryption
  (`CHRONICLE_ENCRYPTION_ENABLED=true` plus a dedicated `CHRONICLE_ENCRYPTION_KEY`)
  so `chronicle:subject:erase {type} {id}` crypto-shreds one person: the ciphertext
  stays, the chain still verifies, the content is gone.

## Before you trust it

Chronicle is young enough that you should review the crypto rather than take it on
faith. The parts that matter:

- hash-chain construction and the canonical-payload serialization,
- checkpoint/export signature generation and verification,
- XChaCha20-Poly1305 nonce handling in the per-subject encryption,
- signing- and encryption-key storage — prefer
  [`laravel-chronicle/kms-aws`](https://github.com/laravel-chronicle) to keep key
  material out of the app environment.

And the escape hatch, worth knowing before you commit: because the sink sits behind
the `AuditSink` interface, if this package is ever abandoned you can replace the
backend without changing any audited code.

## How events land in the ledger

If you're integrating or debugging, this is how each `AuditEvent` maps onto a
Chronicle entry:

| Audit event | Chronicle entry |
| --- | --- |
| `action` (namespaced by category) | `content.status_changed`, `authn.login`, … (dot-notation, as Chronicle requires); the raw action is kept in metadata's `_audit` envelope |
| `category` | added as a tag, and recorded in `_audit` |
| `actorId` (or none) | actor reference `(actor_type, id)`, or Chronicle's `system` actor |
| `subjectType` / `subjectId` | subject reference; an event with no subject falls back to the actor, then to a synthetic `system` subject (Chronicle requires a subject) |
| `metadata`, `tags`, `context` | passed through; `context` carries source/IP/user-agent/token name |
| `occurredAt`, `idempotencyKey` | recorded in the `_audit` metadata envelope |

The bridge passes pre-resolved `(type, id)` references — it never hydrates a model
per write, so it keeps working even after the subject has been deleted.

## Testing

```bash
composer test          # Pint + PHPStan (level 6) + Pest
```

The suite runs on SQLite by default; set `DB_CONNECTION=pgsql` (plus the usual
`DB_*` vars) to exercise the append-only + hash-chain behaviour against PostgreSQL.

## License

MIT.
