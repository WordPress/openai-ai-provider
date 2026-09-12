<?php

/**
 * Plugin Name:       AI Provider for OpenAI
 * Plugin URI:        https://github.com/WordPress/ai-provider-for-openai
 * Description:       AI Provider for OpenAI for the WordPress AI Client.
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Version:           1.1.0
 * Author:            WordPress AI Team
 * Author URI:        https://make.wordpress.org/ai/
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       ai-provider-for-openai
 *
 * @package WordPress\OpenAiAiProvider
 */

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider;

use WordPress\AiClient\AiClient;
use WordPress\OpenAiAiProvider\Codex\CodexOAuthClient;
use WordPress\OpenAiAiProvider\Codex\CodexProvider;
use WordPress\OpenAiAiProvider\Codex\CodexRequestAuthentication;
use WordPress\OpenAiAiProvider\Codex\CodexTokenStore;
use WordPress\OpenAiAiProvider\Provider\OpenAiProvider;

if (!defined('ABSPATH')) {
    return;
}

require_once __DIR__ . '/src/autoload.php';

/**
 * Registers the AI Provider for OpenAI with the AI Client.
 *
 * @since 1.0.0
 *
 * @return void
 */
function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    $registry = AiClient::defaultRegistry();

    if (!$registry->hasProvider(OpenAiProvider::class)) {
        $registry->registerProvider(OpenAiProvider::class);
    }

    if (!$registry->hasProvider(CodexProvider::class)) {
        $registry->registerProvider(CodexProvider::class);
    }

    $codexTokenStore = new CodexTokenStore();
    $registry->setProviderRequestAuthentication(
        'codex',
        new CodexRequestAuthentication($codexTokenStore, new CodexOAuthClient($codexTokenStore))
    );
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);
