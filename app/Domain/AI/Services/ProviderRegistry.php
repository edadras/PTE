<?php

declare(strict_types=1);

namespace App\Domain\AI\Services;

use App\Domain\AI\Contracts\AiProviderClient;
use App\Domain\AI\Contracts\TranscriptionClient;
use App\Domain\AI\Enums\AiProvider;
use App\Domain\AI\Exceptions\ProviderUnavailableException;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Turns an AiProvider case into a live client.
 *
 * The mapping itself lives on the enum, so this class — like the rest of the
 * application outside Providers/ — never spells a vendor's name.
 */
final class ProviderRegistry
{
    /** @var array<string, object> */
    private array $instances = [];

    public function __construct(private readonly Container $container) {}

    public function completionClient(AiProvider $provider): AiProviderClient
    {
        $client = $this->client($provider);

        if (! $client instanceof AiProviderClient) {
            throw new RuntimeException("Provider [{$provider->value}] does not offer completions.");
        }

        return $client;
    }

    public function transcriptionClient(AiProvider $provider): TranscriptionClient
    {
        $client = $this->client($provider);

        if (! $client instanceof TranscriptionClient) {
            throw new RuntimeException("Provider [{$provider->value}] does not offer transcription.");
        }

        return $client;
    }

    /** Sanity check used by the resolver: does this provider actually serve that model? */
    public function supports(AiProvider $provider, string $modelKey): bool
    {
        $client = $this->client($provider);

        return method_exists($client, 'supports') && $client->supports($modelKey);
    }

    /**
     * @return array<int, AiProvider>
     */
    public function availableCompletionProviders(): array
    {
        return array_values(array_filter(
            AiProvider::completionProviders(),
            fn (AiProvider $provider): bool => filled(config("pte.ai.providers.{$provider->configKey()}.api_key")),
        ));
    }

    public function assertConfigured(AiProvider $provider): void
    {
        if (blank(config("pte.ai.providers.{$provider->configKey()}.api_key"))) {
            throw ProviderUnavailableException::notConfigured($provider);
        }
    }

    private function client(AiProvider $provider): object
    {
        return $this->instances[$provider->value] ??= $this->container->make($provider->clientClass());
    }
}
