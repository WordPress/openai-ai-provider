<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Codex;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

/**
 * Availability checker for the Codex provider.
 *
 * @since n.e.x.t
 */
class CodexProviderAvailability implements ProviderAvailabilityInterface
{
    /**
     * @var CodexTokenStore Token store.
     */
    private CodexTokenStore $tokenStore;

    /**
     * Constructor.
     *
     * @since n.e.x.t
     *
     * @param CodexTokenStore $tokenStore Token store.
     */
    public function __construct(CodexTokenStore $tokenStore)
    {
        $this->tokenStore = $tokenStore;
    }

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    public function isConfigured(): bool
    {
        return $this->tokenStore->hasRefreshToken();
    }
}
