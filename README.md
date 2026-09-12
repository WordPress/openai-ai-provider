# AI Provider for OpenAI

An AI Provider for OpenAI for the [PHP AI Client](https://github.com/WordPress/php-ai-client) SDK. Works as both a Composer package and a WordPress plugin.

## Requirements

- PHP 7.4 or higher
- When using with WordPress, requires WordPress 7.0 or higher
    - If using an older WordPress release, the [wordpress/php-ai-client](https://github.com/WordPress/php-ai-client) package must be installed

## Installation

### As a Composer Package

```bash
composer require wordpress/ai-provider-for-openai
```

### As a WordPress Plugin

1. Download the plugin files
2. Upload to `/wp-content/plugins/ai-provider-for-openai/`
3. Ensure the PHP AI Client plugin is installed and activated
4. Activate the plugin through the WordPress admin

## Usage

### With WordPress

The provider automatically registers itself with the PHP AI Client on the `init` hook. Simply ensure both plugins are active and configure your API key:

```php
// Set your OpenAI API key (or use the OPENAI_API_KEY environment variable)
putenv('OPENAI_API_KEY=your-api-key');

// Use the provider
$result = AiClient::prompt('Hello, world!')
    ->usingProvider('openai')
    ->generateTextResult();
```

### As a Standalone Package

```php
use WordPress\AiClient\AiClient;
use WordPress\OpenAiAiProvider\Provider\OpenAiProvider;

// Register the provider
$registry = AiClient::defaultRegistry();
$registry->registerProvider(OpenAiProvider::class);

// Set your API key
putenv('OPENAI_API_KEY=your-api-key');

// Generate text
$result = AiClient::prompt('Explain quantum computing')
    ->usingProvider('openai')
    ->generateTextResult();

echo $result->toText();
```

## Supported Models

Available models are dynamically discovered from the OpenAI API. This includes GPT models for text generation, DALL-E and GPT Image models for image generation, and TTS models for text-to-speech. See the [OpenAI documentation](https://platform.openai.com/docs/models) for the full list of available models.

## Configuration

The provider uses the `OPENAI_API_KEY` environment variable for authentication. You can set this in your environment or via PHP:

```php
putenv('OPENAI_API_KEY=your-api-key');
```

### Codex OAuth

The plugin also registers a `codex` provider for ChatGPT subscription-backed Codex access. Codex uses OAuth token data instead of an OpenAI Platform API key.

In WordPress, token data can be supplied with the `ai_provider_openai_codex_oauth_tokens` option or filter. The expected shape is:

```php
[
    'refresh_token' => '...',
    'access_token'  => '...', // Optional; refreshed automatically when expired.
    'expires_at'    => time() + 3600,
    'account_id'    => '...', // Optional ChatGPT workspace/account ID.
    'fedramp'       => false, // Optional.
]
```

Constants are also supported for local configuration:

```php
define('AI_PROVIDER_OPENAI_CODEX_REFRESH_TOKEN', '...');
define('AI_PROVIDER_OPENAI_CODEX_ACCOUNT_ID', '...');
```

Codex models are available through the `codex` provider, for example `gpt-5.5`, `gpt-5`, and `codex-mini-latest`.

## License

GPL-2.0-or-later
