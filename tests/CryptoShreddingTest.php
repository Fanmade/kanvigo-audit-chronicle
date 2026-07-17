<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Kanvigo\Audit\Chronicle\ChronicleSink;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;

beforeEach(function (): void {
    // Turn on per-subject payload encryption (GDPR crypto-shredding).
    config()->set('chronicle.encryption.enabled', true);
    config()->set('chronicle.encryption.fields', ['metadata', 'context', 'diff']);
    config()->set('chronicle.encryption.kek.key', base64_encode(random_bytes(32)));
    config()->set('chronicle.encryption.kek.id', 'test-local');
});

it('erases a subject while the ledger still verifies', function (): void {
    app(ChronicleSink::class)->record(
        AuditEvent::make('commented', AuditCategory::Content)
            ->withActor(3)
            ->withSubject('task', 77)
            ->withMetadata(['body' => 'Personal note that is PII']),
    );

    $entry = Entry::query()->firstOrFail();

    // Before erasure the encrypted metadata decrypts back to the real content.
    expect($entry->erased())->toBeFalse()
        ->and($entry->decryptedMetadata()['body'])->toBe('Personal note that is PII');

    $this->artisan('chronicle:subject:erase', ['type' => 'task', 'id' => '77'])->assertSuccessful();

    $erased = Entry::query()->findOrFail($entry->id);

    // The subject's key is gone: the content is now an unreadable tombstone...
    expect($erased->erased())->toBeTrue()
        ->and($erased->decryptedMetadata())->toMatchArray(['_erased' => true]);

    // ...yet the chain still verifies byte-for-byte over the ciphertext.
    $this->artisan('chronicle:verify')->assertSuccessful();
});
