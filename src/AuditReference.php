<?php

declare(strict_types=1);

namespace Kanvigo\Audit\Chronicle;

/**
 * A pre-resolved (type, id) reference for a Chronicle actor or subject.
 *
 * The audit contract carries actors and subjects as scalar ids with a separate
 * type string, not as Eloquent models — and Chronicle's default resolver only
 * understands models (it rejects scalars). Passing one of these to the builder,
 * together with the {@see BridgeReferenceResolver} bound by the service
 * provider, lets the bridge stamp the exact (type, id) pair without hydrating a
 * model per write — which also keeps working when the subject has since been
 * deleted.
 */
final readonly class AuditReference
{
    public function __construct(
        public string $type,
        public string $id,
    ) {}
}
