<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Illuminate\Support\Facades\DB;
use Kanvigo\Audit\Chronicle\ChronicleSink;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;

function recordSample(string $action = 'created'): void
{
    app(ChronicleSink::class)->record(
        AuditEvent::make($action, AuditCategory::Content)->withActor(1)->withSubject('task', 1),
    );
}

it('produces a ledger that verifies', function (): void {
    recordSample('created');
    recordSample('status_changed');

    $this->artisan('chronicle:verify')->assertSuccessful();
});

it('detects a tampered entry', function (): void {
    recordSample('created');

    // Rewrite a hashed column straight in the database, bypassing the immutable
    // Eloquent model — the hash chain no longer matches.
    DB::table((new Entry)->getTable())
        ->where('id', Entry::query()->value('id'))
        ->update(['action' => 'content.something_else']);

    $this->artisan('chronicle:verify')->assertFailed();
});
