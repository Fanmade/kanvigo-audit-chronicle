<?php

declare(strict_types=1);

namespace Kanvigo\Audit\Chronicle;

use Chronicle\Facades\Chronicle;
use Illuminate\Contracts\Config\Repository;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;
use Kanvigo\Audit\Contracts\AuditSink;
use Kanvigo\Audit\Contracts\SinkPolicy;

/**
 * A compliance-ledger {@see AuditSink} backed by laravel-chronicle/core: every
 * accepted audit event becomes an immutable, hash-chained Chronicle entry, with
 * tamper-evidence, signed checkpoints, optional WORM anchoring and per-subject
 * crypto-shredding provided by Chronicle itself.
 *
 * Registered fail-closed by default (see the 'policy' config): the ledger write
 * runs synchronously inside the audited action's transaction, so a failed write
 * aborts the action rather than leaving it unrecorded.
 */
final class ChronicleSink implements AuditSink
{
    public function __construct(private readonly Repository $config) {}

    /**
     * A compliance ledger records everything by default; a configured subset of
     * {@see AuditCategory} narrows it.
     */
    public function accepts(AuditEvent $event): bool
    {
        $categories = $this->config->get('audit-chronicle.categories');

        if ($categories === null) {
            return true;
        }

        foreach ($categories as $category) {
            $value = $category instanceof AuditCategory ? $category : AuditCategory::from((string) $category);

            if ($value === $event->category) {
                return true;
            }
        }

        return false;
    }

    public function record(AuditEvent $event): void
    {
        $builder = Chronicle::record()
            ->actor($this->actor($event))
            ->action($this->action($event))
            ->subject($this->subject($event))
            ->metadata($this->metadata($event))
            ->tags($this->tags($event));

        $context = $event->context?->toArray();

        if ($context !== null) {
            $builder->context($context);
        }

        $builder->commit();
    }

    public function policy(): SinkPolicy
    {
        return $this->config->get('audit-chronicle.policy') === 'sync'
            ? SinkPolicy::sync()
            : SinkPolicy::failClosed();
    }

    /**
     * Chronicle requires dot-notation actions (domain.event). The audit
     * category is the domain, so an event's action becomes "{category}.{action}"
     * — e.g. "content.status_changed", "authn.login". The action segment is
     * sanitised (whitespace and dots collapse to underscores) so any raw action
     * is valid; the verbatim original is preserved in metadata's _audit envelope.
     */
    private function action(AuditEvent $event): string
    {
        $segment = trim((string) preg_replace('/[\s.]+/', '_', trim($event->action)), '_');

        if ($segment === '') {
            $segment = 'event';
        }

        return $event->category->value.'.'.$segment;
    }

    /**
     * The event's actor, as a (type, id) reference. A null actor (a scheduler or
     * queue-worker action) becomes Chronicle's "system" actor.
     */
    private function actor(AuditEvent $event): AuditReference|string
    {
        if ($event->actorId === null) {
            return 'system';
        }

        return new AuditReference(
            (string) $this->config->get('audit-chronicle.actor_type', 'user'),
            (string) $event->actorId,
        );
    }

    /**
     * The event's subject. Chronicle requires one, so an event without its own
     * subject (e.g. a failed login) falls back to the actor, then to the
     * configured synthetic system subject.
     */
    private function subject(AuditEvent $event): AuditReference
    {
        if ($event->subjectType !== null && $event->subjectId !== null) {
            return new AuditReference($event->subjectType, (string) $event->subjectId);
        }

        if ($event->actorId !== null) {
            return new AuditReference(
                (string) $this->config->get('audit-chronicle.actor_type', 'user'),
                (string) $event->actorId,
            );
        }

        /** @var array{type: string, id: string} $system */
        $system = $this->config->get('audit-chronicle.system_subject', ['type' => 'system', 'id' => 'system']);

        return new AuditReference($system['type'], $system['id']);
    }

    /**
     * The event's own metadata, plus a reserved envelope carrying the audit
     * category, schema version and the fields Chronicle's builder does not model
     * (the true occurrence time and the idempotency key). Nested under a
     * reserved key so it can never collide with an event's own metadata.
     *
     * @return array<string, mixed>
     */
    private function metadata(AuditEvent $event): array
    {
        return array_merge($event->metadata, [
            '_audit' => array_filter([
                'v' => AuditEvent::SCHEMA_VERSION,
                'action' => $event->action,
                'category' => $event->category->value,
                'occurred_at' => $event->occurredAt?->format(DATE_RFC3339_EXTENDED),
                'idempotency_key' => $event->idempotencyKey,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    /**
     * The event's tags plus its category, so the ledger is filterable by both.
     *
     * @return list<string>
     */
    private function tags(AuditEvent $event): array
    {
        return array_values(array_unique([...$event->tags, $event->category->value]));
    }
}
