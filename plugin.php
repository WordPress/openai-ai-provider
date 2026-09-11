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
use WordPress\OpenAiAiProvider\Provider\OpenAiProvider;

if (!defined('ABSPATH')) {
    return;
}

// Preserve the path beneath WP_PLUGIN_DIR when WordPress loads the plugin through a symlink.
if (!defined('AI_PROVIDER_FOR_OPENAI_PLUGIN_DIR')) {
    define(
        'AI_PROVIDER_FOR_OPENAI_PLUGIN_DIR',
        WP_PLUGIN_DIR . '/' . dirname(plugin_basename(__FILE__))
    );
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

    if ($registry->hasProvider(OpenAiProvider::class)) {
        return;
    }

    $registry->registerProvider(OpenAiProvider::class);
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);
