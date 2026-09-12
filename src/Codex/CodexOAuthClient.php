<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Codex;

use RuntimeException;

/**
 * Refreshes ChatGPT/Codex OAuth access tokens.
 *
 * @since n.e.x.t
 */
class CodexOAuthClient
{
    private const TOKEN_URL = 'https://auth.openai.com/oauth/token';
    private const CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';

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
     * Gets a fresh access token.
     *
     * @since n.e.x.t
     *
     * @return string Access token.
     * @throws RuntimeException If OAuth refresh fails.
     */
    public function getAccessToken(): string
    {
        $accessToken = $this->tokenStore->getAccessToken();
        if ($accessToken !== null) {
            return $accessToken;
        }

        $tokens = $this->tokenStore->getTokens();
        $refreshToken = $tokens['refresh_token'] ?? '';
        if ($refreshToken === '') {
            throw new RuntimeException('Codex OAuth refresh token is not configured.');
        }

        $data = $this->refreshAccessToken($refreshToken);
        if (empty($data['access_token']) || !is_scalar($data['access_token'])) {
            throw new RuntimeException('Codex OAuth refresh returned an invalid response.');
        }

        $updated = array_merge(
            $tokens,
            [
                'access_token' => (string) $data['access_token'],
                'expires_at' => time() + $this->getIntegerValue($data['expires_in'] ?? null, 3600),
            ]
        );

        if (!empty($data['refresh_token']) && is_scalar($data['refresh_token'])) {
            $updated['refresh_token'] = (string) $data['refresh_token'];
        }

        $this->tokenStore->updateTokens($updated);
        return (string) $data['access_token'];
    }

    /**
     * Refreshes an access token.
     *
     * @since n.e.x.t
     *
     * @param string $refreshToken Refresh token.
     * @return array<string, mixed> Response data.
     * @throws RuntimeException If the request fails.
     */
    private function refreshAccessToken(string $refreshToken): array
    {
        $body = [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $refreshToken,
        ];

        if (function_exists('wp_remote_post')) {
            return $this->refreshAccessTokenWithWordPress($body);
        }

        $context = stream_context_create(
            [
                    'http' => [
                        'method' => 'POST',
                        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                        'content' => http_build_query($body),
                        'ignore_errors' => true,
                        'timeout' => 20,
                    ],
                ]
        );
        $responseBody = file_get_contents(self::TOKEN_URL, false, $context);
        if ($responseBody === false) {
            throw new RuntimeException('Codex OAuth refresh failed.');
        }

        $data = json_decode($responseBody, true);
        if (!is_array($data)) {
            throw new RuntimeException('Codex OAuth refresh returned an invalid response.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Refreshes an access token using the WordPress HTTP API.
     *
     * @since n.e.x.t
     *
     * @param array<string, string> $body Request body.
     * @return array<string, mixed> Response data.
     * @throws RuntimeException If the request fails.
     */
    private function refreshAccessTokenWithWordPress(array $body): array
    {
        $wpRemotePost = 'wp_remote_post';
        // @phpstan-ignore-next-line WordPress HTTP API is available at runtime when function_exists() passes.
        $response = $wpRemotePost(self::TOKEN_URL, [
            'body' => $body,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'timeout' => 20,
        ]);

        $isWpError = 'is_wp_error';
        // @phpstan-ignore-next-line WordPress error helper is available at runtime when function_exists() passes.
        if (function_exists('is_wp_error') && $isWpError($response)) {
            $rawMessage = is_object($response) && method_exists($response, 'get_error_message')
                ? $response->get_error_message()
                : 'WordPress HTTP API error';
            $message = is_scalar($rawMessage) ? (string) $rawMessage : 'WordPress HTTP API error';
            throw new RuntimeException('Codex OAuth refresh failed: ' . $this->sanitizeErrorPreview($message));
        }

        $wpRemoteRetrieveResponseCode = 'wp_remote_retrieve_response_code';
        $rawStatusCode = 0;
        if (function_exists('wp_remote_retrieve_response_code')) {
            // @phpstan-ignore-next-line WordPress HTTP helper is available at runtime when function_exists() passes.
            $rawStatusCode = $wpRemoteRetrieveResponseCode($response);
        }
        $statusCode = is_numeric($rawStatusCode) ? (int) $rawStatusCode : 0;
        $wpRemoteRetrieveBody = 'wp_remote_retrieve_body';
        $rawResponseBody = '';
        if (function_exists('wp_remote_retrieve_body')) {
            // @phpstan-ignore-next-line WordPress HTTP helper is available at runtime when function_exists() passes.
            $rawResponseBody = $wpRemoteRetrieveBody($response);
        }
        $responseBody = is_scalar($rawResponseBody) ? (string) $rawResponseBody : '';
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(
                sprintf(
                    'Codex OAuth refresh failed with HTTP %d: %s',
                    $statusCode,
                    $this->sanitizeErrorPreview($responseBody)
                )
            );
        }

        $data = json_decode($responseBody, true);
        if (!is_array($data)) {
            throw new RuntimeException(
                'Codex OAuth refresh returned an invalid response: ' . $this->sanitizeErrorPreview($responseBody)
            );
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Gets an integer value with a fallback.
     *
     * @since n.e.x.t
     *
     * @param mixed $value Raw value.
     * @param int $fallback Fallback value.
     * @return int Integer value.
     */
    private function getIntegerValue($value, int $fallback): int
    {
        if (!is_numeric($value)) {
            return $fallback;
        }

        return (int) $value;
    }

    /**
     * Builds a bounded single-line OAuth error preview without exposing token material.
     *
     * @since n.e.x.t
     *
     * @param string $message Raw error message or body.
     * @return string Sanitized preview.
     */
    private function sanitizeErrorPreview(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', $message);
        $message = is_string($message) ? trim($message) : '';
        if ($message === '') {
            return 'empty response';
        }

        if (strlen($message) > 500) {
            $message = substr($message, 0, 500) . '...';
        }

        return $message;
    }
}
