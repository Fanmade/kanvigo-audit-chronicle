<?php

declare(strict_types=1);

namespace Kanvigo\Audit\Chronicle;

use Chronicle\Contracts\ReferenceResolver;
use Chronicle\Support\Reference;

/**
 * Decorates Chronicle's reference resolver so the bridge can hand the builder a
 * pre-resolved {@see AuditReference} — a (type, id) pair taken straight from the
 * audit event — instead of an Eloquent model. Everything else (models, objects
 * with an id, the "system" actor) falls through to the wrapped default resolver
 * unchanged, so Chronicle's own usage (e.g. the HasChronicle trait) is
 * untouched.
 */
final readonly class BridgeReferenceResolver implements ReferenceResolver
{
    public function __construct(private ReferenceResolver $inner) {}

    public function resolve(mixed $value): Reference
    {
        if ($value instanceof AuditReference) {
            return new Reference($value->type, $value->id);
        }

        return $this->inner->resolve($value);
    }
}
