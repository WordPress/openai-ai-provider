<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Codex;

use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;

/**
 * Authenticates requests to the ChatGPT Codex backend.
 *
 * @since n.e.x.t
 */
class CodexRequestAuthentication extends ApiKeyRequestAuthentication
{
    /**
     * @var CodexTokenStore Token store.
     */
    private CodexTokenStore $tokenStore;

    /**
     * @var CodexOAuthClient OAuth client.
     */
    private CodexOAuthClient $oauthClient;

    /**
     * Constructor.
     *
     * @since n.e.x.t
     *
     * @param CodexTokenStore $tokenStore Token store.
     * @param CodexOAuthClient $oauthClient OAuth client.
     */
    public function __construct(CodexTokenStore $tokenStore, CodexOAuthClient $oauthClient)
    {
        parent::__construct('');

        $this->tokenStore = $tokenStore;
        $this->oauthClient = $oauthClient;
    }

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    public function authenticateRequest(Request $request): Request
    {
        $request = $request->withHeader('Authorization', 'Bearer ' . $this->oauthClient->getAccessToken());

        $accountId = $this->tokenStore->getAccountId();
        if ($accountId !== null && $accountId !== '') {
            $request = $request->withHeader('ChatGPT-Account-ID', $accountId);
        }

        if ($this->tokenStore->isFedramp()) {
            $request = $request->withHeader('X-OpenAI-Fedramp', 'true');
        }

        return $request;
    }

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    public static function getJsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }
}
