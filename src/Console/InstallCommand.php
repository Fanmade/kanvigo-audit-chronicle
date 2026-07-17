<?php

declare(strict_types=1);

namespace Kanvigo\Audit\Chronicle\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Kanvigo\Audit\Chronicle\ChronicleSink;

/**
 * One-command setup for the Chronicle audit bridge: publishes the bridge and
 * Chronicle config, runs Chronicle's own installer, generates a signing key,
 * registers the sink in config/audit.php, and prints the recommended production
 * hardening (encryption, scheduled verification, WORM anchoring when available).
 *
 * The goal is "composer require + one command".
 */
final class InstallCommand extends Command
{
    protected $signature = 'audit:chronicle:install
        {--force : Overwrite any published config that already exists}
        {--no-migrate : Publish migrations without running them}';

    protected $description = 'Install and enable the Chronicle compliance-ledger audit sink';

    public function handle(): int
    {
        $this->components->info('Installing the Chronicle audit bridge.');

        $this->call('vendor:publish', [
            '--tag' => 'audit-chronicle-config',
            '--force' => (bool) $this->option('force'),
        ]);

        $chronicleOptions = [
            '--no-interaction' => true,
            '--force' => (bool) $this->option('force'),
        ];

        if (! $this->option('no-migrate')) {
            $chronicleOptions['--migrate'] = true;
        }

        $this->call('chronicle:install', $chronicleOptions);

        $this->generateSigningKey();

        $this->registerSink();

        $this->printNextSteps();

        return self::SUCCESS;
    }

    /**
     * Mint a fresh Ed25519 signing keypair. Chronicle prints the key material
     * for the operator to place in .env — the bridge never persists it.
     */
    private function generateSigningKey(): void
    {
        $this->newLine();
        $this->components->info('Generating a Chronicle signing key. Copy the printed values into your .env.');

        $this->call('chronicle:key:generate');
    }

    /**
     * Add the sink to config/audit.php so events start flowing to the ledger.
     * Best-effort: when the file is missing or already lists the sink, fall back
     * to printed instructions rather than editing blindly.
     */
    private function registerSink(): void
    {
        $path = $this->laravel->configPath('audit.php');

        if (! File::exists($path)) {
            $this->manualSinkInstructions('config/audit.php was not found');

            return;
        }

        $contents = File::get($path);

        if (str_contains($contents, 'ChronicleSink')) {
            $this->components->info('ChronicleSink is already registered in config/audit.php.');

            return;
        }

        $updated = preg_replace(
            "/('sinks'\s*=>\s*\[)/",
            "$1\n        \\Kanvigo\\Audit\\Chronicle\\ChronicleSink::class,",
            $contents,
            1,
            $count,
        );

        if ($updated === null || $count === 0) {
            $this->manualSinkInstructions('could not locate the sinks array');

            return;
        }

        File::put($path, $updated);

        $this->components->info('Registered ChronicleSink in config/audit.php.');
    }

    private function manualSinkInstructions(string $reason): void
    {
        $this->components->warn("Could not register the sink automatically ({$reason}).");
        $this->line('  Add it to the <options=bold>sinks</> array in config/audit.php:');
        $this->line('      '.ChronicleSink::class.'::class,');
    }

    private function printNextSteps(): void
    {
        $this->newLine();
        $this->components->info('Next steps');

        $steps = [
            'Set CHRONICLE_PRIVATE_KEY / CHRONICLE_PUBLIC_KEY (and CHRONICLE_ACTIVE_KEY) from the key printed above.',
            'Enable per-subject encryption for GDPR crypto-shredding: set CHRONICLE_ENCRYPTION_ENABLED=true and a dedicated CHRONICLE_ENCRYPTION_KEY.',
            'Schedule integrity checks: run "chronicle:checkpoint" and "chronicle:verify --since-last-checkpoint" on a cadence.',
            'The sink is fail-closed by default — every audited mutation must run inside a DB transaction, or emitting throws. Set AUDIT_CHRONICLE_POLICY=sync to relax this.',
        ];

        if ($this->anchorS3Installed()) {
            $steps[] = 'WORM anchoring: configure the S3 Object Lock anchor (Governance mode, 7-year retention) and set CHRONICLE_ANCHORING_ENABLED=true.';
        } else {
            $steps[] = 'For off-box WORM anchoring, add "composer require laravel-chronicle/anchor-s3" (S3 Object Lock), then re-run this command.';
        }

        foreach ($steps as $step) {
            $this->components->bulletList([$step]);
        }
    }

    private function anchorS3Installed(): bool
    {
        // Referenced as a string: anchor-s3 is an optional (suggested) dependency.
        return class_exists('Chronicle\\AnchorS3\\S3ObjectLockAnchor');
    }
}
