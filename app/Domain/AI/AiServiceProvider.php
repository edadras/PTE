<?php

declare(strict_types=1);

namespace App\Domain\AI;

use App\Domain\AI\Services\AiGateway;
use App\Domain\AI\Services\CircuitBreaker;
use App\Domain\AI\Services\CostMeter;
use App\Domain\AI\Services\FallbackChain;
use App\Domain\AI\Services\PromptCache;
use App\Domain\AI\Services\PromptRenderer;
use App\Domain\AI\Services\ProviderRegistry;
use App\Domain\AI\Services\ProviderResolver;
use App\Domain\AI\Services\ResponseValidator;
use App\Domain\AI\Services\RubricEngine;
use App\Domain\AI\Support\AudioAnalyzer;
use App\Domain\AI\Support\TextComparator;
use Illuminate\Support\ServiceProvider;

/**
 * Register in bootstrap/providers.php.
 *
 * Everything here is constructor-injectable and would auto-resolve; the
 * singletons exist so that one request re-uses one provider client, one circuit
 * breaker view and one cached catalogue rather than rebuilding them per call.
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([
            ProviderRegistry::class,
            ProviderResolver::class,
            PromptRenderer::class,
            ResponseValidator::class,
            RubricEngine::class,
            CostMeter::class,
            CircuitBreaker::class,
            PromptCache::class,
            FallbackChain::class,
            AiGateway::class,
            AudioAnalyzer::class,
            TextComparator::class,
        ] as $service) {
            $this->app->singleton($service);
        }
    }
}
