<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Kanvigo\Audit\Chronicle\ChronicleSink;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditContext;
use Kanvigo\Audit\Contracts\AuditEvent;
use Kanvigo\Audit\Contracts\AuditSource;
use Kanvigo\Audit\Contracts\DispatchMode;
use Kanvigo\Audit\Contracts\FailureMode;

function sink(): ChronicleSink
{
    return app(ChronicleSink::class);
}

it('accepts every category by default', function (): void {
    foreach (AuditCategory::cases() as $category) {
        expect(sink()->accepts(AuditEvent::make('x', $category)))->toBeTrue();
    }
});

it('accepts only the configured categories', function (): void {
    config()->set('audit-chronicle.categories', [AuditCategory::Authn, AuditCategory::Token]);

    expect(sink()->accepts(AuditEvent::make('login', AuditCategory::Authn)))->toBeTrue()
        ->and(sink()->accepts(AuditEvent::make('created', AuditCategory::Content)))->toBeFalse();
});

it('is fail-closed and synchronous by default', function (): void {
    $policy = sink()->policy();

    expect($policy->isFailClosed())->toBeTrue()
        ->and($policy->dispatch)->toBe(DispatchMode::Sync)
        ->and($policy->failure)->toBe(FailureMode::FailClosed);
});

it('can be relaxed to a fail-open sync policy', function (): void {
    config()->set('audit-chronicle.policy', 'sync');

    expect(sink()->policy()->isFailClosed())->toBeFalse()
        ->and(sink()->policy()->dispatch)->toBe(DispatchMode::Sync);
});

it('records an accepted event as a Chronicle entry', function (): void {
    $event = AuditEvent::make('status_changed', AuditCategory::Content)
        ->withActor(7)
        ->withSubject('task', 42)
        ->withMetadata(['field' => 'status', 'old' => 'todo', 'new' => 'done'])
        ->withTags('board')
        ->withContext(new AuditContext(AuditSource::Ui, ip: '203.0.113.9', userAgent: 'Firefox', tokenName: null));

    sink()->record($event);

    $entry = Entry::query()->firstOrFail();

    expect($entry->action)->toBe('content.status_changed')
        ->and($entry->actor_type)->toBe('user')
        ->and($entry->actor_id)->toBe('7')
        ->and($entry->subject_type)->toBe('task')
        ->and($entry->subject_id)->toBe('42')
        ->and($entry->metadata['field'])->toBe('status')
        ->and($entry->metadata['_audit']['action'])->toBe('status_changed')
        ->and($entry->metadata['_audit']['category'])->toBe('content')
        ->and($entry->context['ip'])->toBe('203.0.113.9')
        ->and($entry->tags)->toContain('content')
        ->and($entry->tags)->toContain('board');
});

it('maps a null actor to the system actor', function (): void {
    sink()->record(AuditEvent::make('auto_archived', AuditCategory::Content)->withSubject('task', 5));

    $entry = Entry::query()->firstOrFail();

    expect($entry->actor_type)->toBe('system')
        ->and($entry->actor_id)->toBe('system');
});

it('falls back to the actor as subject when the event has none', function (): void {
    sink()->record(AuditEvent::make('login', AuditCategory::Authn)->withActor(9));

    $entry = Entry::query()->firstOrFail();

    expect($entry->subject_type)->toBe('user')
        ->and($entry->subject_id)->toBe('9');
});

it('falls back to the system subject when there is no actor or subject', function (): void {
    sink()->record(AuditEvent::make('failed_login', AuditCategory::Authn));

    $entry = Entry::query()->firstOrFail();

    expect($entry->subject_type)->toBe('system')
        ->and($entry->subject_id)->toBe('system');
});
