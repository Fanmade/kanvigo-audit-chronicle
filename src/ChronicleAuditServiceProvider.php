<?php

declare(strict_types=1);

namespace Kanvigo\Audit\Chronicle;

use Chronicle\Contracts\ReferenceResolver;
use Illuminate\Support\ServiceProvider;
use Kanvigo\Audit\Chronicle\Console\InstallCommand;

/**
 * Wires the Chronicle audit bridge into the host application.
 *
 * Deliberately unintrusive: it registers the install command and the reference
 * resolver the sink needs, but it does NOT activate the ledger. The
 * {@see ChronicleSink} only starts recording once it is added to the host's
 * config/audit.php `sinks` — which `audit:chronicle:install` does for the
 * operator. Installing the package therefore changes no behaviour until opted in.
 */
final class ChronicleAuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/audit-chronicle.php', 'audit-chronicle');
    }

    public function boot(): void
    {
        // Teach Chronicle's resolver to accept the bridge's pre-resolved
        // (type, id) references. Done in boot() via extend() so it wraps
        // whatever Chronicle bound in register(), regardless of provider order.
        $this->app->extend(
            ReferenceResolver::class,
            static fn (ReferenceResolver $resolver): ReferenceResolver => new BridgeReferenceResolver($resolver),
        );

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/audit-chronicle.php' => $this->app->configPath('audit-chronicle.php'),
        ], 'audit-chronicle-config');
    }
}
