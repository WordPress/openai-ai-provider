<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Codex;

/**
 * Stores ChatGPT/Codex OAuth tokens.
 *
 * @since n.e.x.t
 *
 * @phpstan-type CodexTokens array{
 *     access_token?: string,
 *     refresh_token?: string,
 *     expires_at?: int,
 *     account_id?: string,
 *     fedramp?: bool
 * }
 */
class CodexTokenStore
{
    private const OPTION_NAME = 'ai_provider_openai_codex_oauth_tokens';

    /**
     * Gets stored token data.
     *
     * @since n.e.x.t
     *
     * @return CodexTokens Token data.
     */
    public function getTokens(): array
    {
        $tokens = [];
        if (function_exists('get_option')) {
            $tokens = get_option(self::OPTION_NAME, []);
        }

        if (!is_array($tokens)) {
            $tokens = [];
        }

        /** @var array<string, mixed> $tokenData */
        $tokenData = $tokens;
        $tokens = $this->addEnvironmentTokens($tokenData);
        $tokens = $this->addConstantTokens($tokens);

        if (function_exists('apply_filters')) {
            /**
             * Filters the Codex OAuth tokens used by the OpenAI provider.
             *
             * @since n.e.x.t
             *
             * @param array<string, mixed> $tokens Token data.
             */
            $tokens = apply_filters('ai_provider_openai_codex_oauth_tokens', $tokens);
        }

        if (!is_array($tokens)) {
            return [];
        }

        /** @var array<string, mixed> $tokens */
        return $this->sanitizeTokens($tokens);
    }

    /**
     * Updates stored token data.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $tokens Token data.
     * @return void
     */
    public function updateTokens(array $tokens): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        update_option(self::OPTION_NAME, $this->sanitizeTokens($tokens), false);
    }

    /**
     * Checks whether a refresh token is configured.
     *
     * @since n.e.x.t
     *
     * @return bool True if configured.
     */
    public function hasRefreshToken(): bool
    {
        $tokens = $this->getTokens();
        return !empty($tokens['refresh_token']);
    }

    /**
     * Gets a non-expired access token if available.
     *
     * @since n.e.x.t
     *
     * @return string|null Access token, or null if unavailable or expired.
     */
    public function getAccessToken(): ?string
    {
        $tokens = $this->getTokens();
        $accessToken = $tokens['access_token'] ?? '';
        $expiresAt = $tokens['expires_at'] ?? 0;

        if ($accessToken === '' || $expiresAt <= time() + 60) {
            return null;
        }

        return $accessToken;
    }

    /**
     * Gets the ChatGPT account ID if available.
     *
     * @since n.e.x.t
     *
     * @return string|null Account ID, or null if unavailable.
     */
    public function getAccountId(): ?string
    {
        $tokens = $this->getTokens();
        return $tokens['account_id'] ?? null;
    }

    /**
     * Checks whether the account uses FedRAMP headers.
     *
     * @since n.e.x.t
     *
     * @return bool True if FedRAMP mode is enabled.
     */
    public function isFedramp(): bool
    {
        $tokens = $this->getTokens();
        return true === ($tokens['fedramp'] ?? false);
    }

    /**
     * Adds token data from environment variables when present.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $tokens Token data.
     * @return array<string, mixed> Token data.
     */
    private function addEnvironmentTokens(array $tokens): array
    {
        foreach ($this->tokenSourceMap() as $name => $tokenKey) {
            $value = getenv($name);
            if ($value !== false) {
                $tokens[$tokenKey] = $value;
            }
        }

        return $tokens;
    }

    /**
     * Adds token data from constants when present.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $tokens Token data.
     * @return array<string, mixed> Token data.
     */
    private function addConstantTokens(array $tokens): array
    {
        foreach ($this->tokenSourceMap() as $constantName => $tokenKey) {
            if (defined($constantName)) {
                $tokens[$tokenKey] = constant($constantName);
            }
        }

        return $tokens;
    }

    /**
     * Maps environment/constant names to token keys.
     *
     * @since n.e.x.t
     *
     * @return array<string, string>
     */
    private function tokenSourceMap(): array
    {
        return [
            'AI_PROVIDER_OPENAI_CODEX_ACCESS_TOKEN' => 'access_token',
            'AI_PROVIDER_OPENAI_CODEX_REFRESH_TOKEN' => 'refresh_token',
            'AI_PROVIDER_OPENAI_CODEX_EXPIRES_AT' => 'expires_at',
            'AI_PROVIDER_OPENAI_CODEX_ACCOUNT_ID' => 'account_id',
            'AI_PROVIDER_OPENAI_CODEX_FEDRAMP' => 'fedramp',
        ];
    }

    /**
     * Sanitizes token data.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $tokens Raw token data.
     * @return CodexTokens Sanitized token data.
     */
    private function sanitizeTokens(array $tokens): array
    {
        /** @var CodexTokens $sanitized */
        $sanitized = [];
        foreach (['access_token', 'refresh_token', 'account_id'] as $key) {
            if (isset($tokens[$key]) && is_scalar($tokens[$key])) {
                $sanitized[$key] = trim((string) $tokens[$key]);
            }
        }

        if (isset($tokens['expires_at']) && is_numeric($tokens['expires_at'])) {
            $sanitized['expires_at'] = (int) $tokens['expires_at'];
        }

        if (isset($tokens['fedramp'])) {
            $sanitized['fedramp'] = filter_var($tokens['fedramp'], FILTER_VALIDATE_BOOLEAN);
        }

        return $sanitized;
    }
}
