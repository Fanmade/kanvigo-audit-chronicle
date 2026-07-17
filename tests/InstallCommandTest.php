<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('registers the sink in an existing config/audit.php', function (): void {
    $path = app()->configPath('audit.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, <<<'PHP'
        <?php

        return [
            'sinks' => [
                App\Audit\Sinks\ActivityLogSink::class,
            ],
        ];
        PHP);

    $this->artisan('audit:chronicle:install', ['--no-migrate' => true])->assertSuccessful();

    expect(File::get($path))->toContain('Kanvigo\Audit\Chronicle\ChronicleSink::class');

    File::delete($path);
});

it('warns and prints manual instructions when config/audit.php is absent', function (): void {
    $path = app()->configPath('audit.php');
    File::delete($path);

    $this->artisan('audit:chronicle:install', ['--no-migrate' => true])
        ->expectsOutputToContain('config/audit.php was not found')
        ->assertSuccessful();
});

it('points to the anchor-s3 package when it is not installed', function (): void {
    File::delete(app()->configPath('audit.php'));

    $this->artisan('audit:chronicle:install', ['--no-migrate' => true])
        ->expectsOutputToContain('laravel-chronicle/anchor-s3')
        ->assertSuccessful();
});

it('does not register the sink twice', function (): void {
    $path = app()->configPath('audit.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, <<<'PHP'
        <?php

        return [
            'sinks' => [
                Kanvigo\Audit\Chronicle\ChronicleSink::class,
            ],
        ];
        PHP);

    $this->artisan('audit:chronicle:install', ['--no-migrate' => true])
        ->expectsOutputToContain('already registered')
        ->assertSuccessful();

    expect(substr_count(File::get($path), 'ChronicleSink'))->toBe(1);

    File::delete($path);
});
