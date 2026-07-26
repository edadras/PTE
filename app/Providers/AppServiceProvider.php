<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Telegram\Support\TokenRedactor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureFactories();
        $this->configureLogRedaction();
        $this->configureUrls();
    }

    private function configureModels(): void
    {
        // Fail loudly in development when a relation is used without eager
        // loading or a non-existent attribute is written — both are silent
        // performance and correctness bugs in production otherwise.
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::unguard(false);
    }

    /**
     * Domain models live under app/Domain/{Context}/Models, which Laravel's
     * default factory guesser cannot map to Database\Factories. Teach it the
     * mapping once instead of overriding newFactory() on every model.
     */
    private function configureFactories(): void
    {
        Factory::guessFactoryNamesUsing(static function (string $modelName): string {
            $base = class_basename($modelName);

            return 'Database\\Factories\\'.$base.'Factory';
        });

        Factory::guessModelNamesUsing(static function (Factory $factory): string {
            $base = Str::replaceLast('Factory', '', class_basename($factory));

            foreach ([
                'Tenancy', 'Identity', 'Telegram', 'Learning',
                'Assessment', 'AI', 'Commerce', 'Support',
            ] as $context) {
                $candidate = "App\\Domain\\{$context}\\Models\\{$base}";

                if (class_exists($candidate)) {
                    return $candidate;
                }
            }

            return "App\\Models\\{$base}";
        });
    }

    /**
     * Last line of defence for bot tokens. Every other layer (encrypted casts,
     * $hidden, password inputs) can be bypassed by an exception message or a
     * dd() left in a job; this catches the string on its way to any log.
     *
     * @see docs/12-security-and-compliance.md §3
     */
    private function configureLogRedaction(): void
    {
        if (! class_exists(TokenRedactor::class)) {
            return;
        }

        Event::listen(function (MessageLogged $event): void {
            $event->message = TokenRedactor::redact($event->message);

            if ($event->context !== []) {
                $event->context = TokenRedactor::redactArray($event->context);
            }
        });
    }

    private function configureUrls(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
