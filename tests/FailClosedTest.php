<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Illuminate\Support\Facades\Schema;
use Kanvigo\Audit\Chronicle\ChronicleSink;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;
use Kanvigo\Audit\Contracts\DispatchMode;
use Kanvigo\Audit\Contracts\FailureMode;

it('declares a synchronous, fail-closed policy so a failed write can abort the action', function (): void {
    $policy = app(ChronicleSink::class)->policy();

    expect($policy->dispatch)->toBe(DispatchMode::Sync)
        ->and($policy->failure)->toBe(FailureMode::FailClosed);
});

it('propagates a ledger-write failure rather than swallowing it', function (): void {
    // Simulate the ledger being unwritable. Because the sink never catches, the
    // exception propagates out of record(); the audit manager runs a fail-closed
    // sink inside the domain transaction, so a propagated throw rolls the whole
    // audited action back — "no guaranteed record → no action".
    Schema::drop((new Entry)->getTable());

    $record = fn () => app(ChronicleSink::class)->record(
        AuditEvent::make('created', AuditCategory::Content)->withActor(1)->withSubject('task', 1),
    );

    expect($record)->toThrow(Exception::class);
});
