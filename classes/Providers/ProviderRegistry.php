<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers;

/**
 * Every provider a transport plugin registered, collected once per request.
 *
 * The Email plugin fires `onEmailProviders` with one of these in it, each
 * transport plugin adds itself, and everything downstream asks this rather than
 * carrying a list of provider names. That is the whole point of the exercise:
 * adding a provider is writing a plugin, and nothing else on the site changes.
 *
 * ## Two providers cannot claim one engine
 *
 * That is refused, loudly, with an exception naming both. It is not a
 * preference: a site where two plugins both answer for `ses` has one of them
 * quietly winning, and which one depends on plugin load order, which depends on
 * a directory listing. A merchant would see delivery events being verified with
 * the wrong key and nothing anywhere saying why. Better to fail at boot with a
 * sentence naming the two plugins to choose between.
 *
 * A duplicate *key* is refused for the same reason: a key is what a route and a
 * config block are addressed by, and two providers sharing one would be two
 * providers reading each other's credentials.
 */
final class ProviderRegistry
{
    /** @var array<string, Provider> keyed by provider key */
    private array $providers = [];

    /** @var array<string, string> engine to the key of the provider that claimed it */
    private array $engines = [];

    /**
     * Add a provider.
     *
     * @throws \RuntimeException when another provider already claims this
     *         provider's key or one of its engines
     */
    public function add(Provider $provider): void
    {
        $key = strtolower(trim($provider->key()));

        if ($key === '') {
            throw new \RuntimeException(sprintf(
                'The email provider %s answered an empty key(). A provider needs a short lowercase key, '
                . 'because that is what its routes and its configuration block are addressed by.',
                $provider::class
            ));
        }

        if (isset($this->providers[$key]) && $this->providers[$key] !== $provider) {
            throw new \RuntimeException(sprintf(
                'Two email providers both call themselves "%s": %s and %s. A key addresses a provider\'s '
                . 'routes and its configuration, so only one plugin can use it. Disable one of the two plugins, '
                . 'or ask its author to choose a different key.',
                $key,
                $this->providers[$key]::class,
                $provider::class
            ));
        }

        $engines = [];
        foreach ($provider->engines() as $engine) {
            $engine = strtolower(trim((string)$engine));
            if ($engine === '') {
                continue;
            }

            $owner = $this->engines[$engine] ?? null;
            if ($owner !== null && $owner !== $key) {
                throw new \RuntimeException(sprintf(
                    'Two email providers both answer for the "%s" engine: %s and %s. Only one of them can, '
                    . 'because the store has to know whose key to verify a delivery webhook with. Disable one '
                    . 'of the two plugins, or ask its author to register a different engine name.',
                    $engine,
                    $this->providers[$owner]::class,
                    $provider::class
                ));
            }

            $engines[] = $engine;
        }

        $this->providers[$key] = $provider;
        foreach ($engines as $engine) {
            $this->engines[$engine] = $key;
        }
    }

    /**
     * Every registered provider, keyed by its own key.
     *
     * @return array<string, Provider>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /** The provider that answers for an engine, or null when nothing does. */
    public function forEngine(string $engine): ?Provider
    {
        $key = $this->engines[strtolower(trim($engine))] ?? null;

        return $key === null ? null : ($this->providers[$key] ?? null);
    }

    /** The provider with this key, or null. */
    public function byKey(string $key): ?Provider
    {
        return $this->providers[strtolower(trim($key))] ?? null;
    }

    /**
     * Every engine that has a provider, in the order they were claimed.
     *
     * @return list<string>
     */
    public function engines(): array
    {
        return array_keys($this->engines);
    }

    /**
     * Every provider key.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    public function isEmpty(): bool
    {
        return $this->providers === [];
    }
}
