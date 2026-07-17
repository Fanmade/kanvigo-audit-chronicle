# Kanvigo Audit — Chronicle bridge

The first official optional sink for [Kanvigo](https://github.com/Fanmade/Kanvigo)'s
pluggable audit layer. It records every audit event into a
[Chronicle](https://github.com/laravel-chronicle/core) compliance ledger — an
append-only, hash-chained, tamper-evident store with signed checkpoints, optional
WORM anchoring and per-subject GDPR crypto-shredding.

**Core Kanvigo never depends on this package.** The default install keeps only the
activity-feed sink; a self-hoster who wants a compliance ledger opts in with a
`composer require` and one command. Chronicle and its `ext-sodium` / `ext-openssl`
/ AWS baggage land only here, behind the stable
[`kanvigo/audit-contracts`](https://github.com/Fanmade/kanvigo-audit-contracts)
`AuditSink` interface, so the ledger backend can be swapped without touching a
single audited call site.

## Installation

```bash
composer require kanvigo/audit-chronicle
php artisan audit:chronicle:install
```

`audit:chronicle:install` publishes the bridge and Chronicle config, runs
Chronicle's installer, mints a signing key, registers `ChronicleSink` in your
`config/audit.php`, and prints the production-hardening checklist. Then set the
printed key material in your `.env` and you are recording.

## How it maps

Each `AuditEvent` becomes a Chronicle entry:

| Audit event | Chronicle entry |
| --- | --- |
| `action` (namespaced by category) | `content.status_changed`, `authn.login`, … (dot-notation as Chronicle requires); the raw action is kept in metadata's `_audit` envelope |
| `category` | added as a tag, and recorded in `_audit` |
| `actorId` (or none) | actor reference `(actor_type, id)`, or Chronicle's `system` actor |
| `subjectType` / `subjectId` | subject reference; an event with no subject falls back to the actor, then to a synthetic `system` subject (Chronicle requires a subject) |
| `metadata`, `tags`, `context` | passed through; `context` carries source/IP/user-agent/token name |
| `occurredAt`, `idempotencyKey` | recorded in the `_audit` metadata envelope |

The bridge passes pre-resolved `(type, id)` references — it never hydrates a model
per write, so it keeps working when the subject has since been deleted.

## Configuration

`config/audit-chronicle.php`:

- **`categories`** — which `AuditCategory` values the ledger records (default: all).
- **`policy`** — `fail-closed` (default) or `sync`.
- **`actor_type`** — the reference type stamped for the actor (default `user`; set
  it to your `User` model class to enable Chronicle reference hydration).
- **`system_subject`** — the synthetic subject for events that have none.

Chronicle's own chain, signing, encryption and anchoring live in
`config/chronicle.php`.

### Fail-closed

By default the sink is **fail-closed**: the ledger write runs synchronously inside
the audited action's database transaction, so a failed write rolls the action back
("no guaranteed record → no action"). This means **every audited mutation must run
inside a transaction** — the audit manager throws if one emits a fail-closed event
outside a transaction. Set `AUDIT_CHRONICLE_POLICY=sync` to relax to a best-effort,
post-commit write.

## Hardening (WORM, integrity, erasure)

The install command prints these; in short:

- **Integrity** — schedule `chronicle:checkpoint` and
  `chronicle:verify --since-last-checkpoint`.
- **WORM anchoring** — `composer require laravel-chronicle/anchor-s3`, then
  configure S3 Object Lock (Governance mode, 7-year retention) and set
  `CHRONICLE_ANCHORING_ENABLED=true`.
- **GDPR erasure** — enable per-subject encryption
  (`CHRONICLE_ENCRYPTION_ENABLED=true` + a dedicated `CHRONICLE_ENCRYPTION_KEY`) so
  `chronicle:subject:erase {type} {id}` crypto-shreds a subject: the ciphertext
  stays, the chain still verifies, the content is gone.

## Security review

Chronicle is young. Pin it (this package requires an exact version) and review the
crypto before you rely on it in production:

- hash-chain construction and the canonical-payload serialization,
- checkpoint/export signature generation and verification,
- XChaCha20-Poly1305 nonce handling in the per-subject encryption,
- signing- and encryption-key storage (prefer `laravel-chronicle/kms-aws` to keep
  key material out of the app environment).

The `AuditSink` seam means that if the package is ever abandoned, the backend can
be replaced without changing any call site.

## Testing

```bash
composer test          # Pint + PHPStan (level 6) + Pest
```

The suite runs on SQLite by default; set `DB_CONNECTION=pgsql` (plus the usual
`DB_*` vars) to run the append-only + hash-chain behaviour against PostgreSQL.

## License

MIT.
