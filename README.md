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

## Automatic hosted tool search (experimental)

When more than **10 application functions** are declared, this provider enables
OpenAI-hosted tool search and defers their parameter schemas. No new prompt-builder
method or per-function flag is needed:

```php
// $functions is an ordinary list of SDK FunctionDeclaration objects.
$result = AiClient::prompt('Find the relevant content')
    ->usingModel(OpenAiProvider::model('gpt-5.4'))
    ->usingFunctionDeclarations(...$functions)
    ->generateTextResult();
```

The initial model allowlist is `gpt-5.4`, `gpt-5.4-pro`, and their dated snapshots.
Other models retain eager loading, even if their names look newer. The optimization
also requires the generic `ProviderData` message support proposed in
[php-ai-client #282](https://github.com/WordPress/php-ai-client/pull/282).
Older SDK versions continue to work with eager tools. This development branch uses
that SDK PR as a **development-only** dependency for tests/static analysis; replace
the branch constraint with a released version once the SDK change is available.

Web search is independent and does not count toward the threshold. Explicit
`tool_choice` values other than `auto` keep functions eager so that forced or
restricted tool choice does not accidentally become a discovery call.

### Adjusting or disabling the policy

Use existing model configuration, not a new core API:

```php
$config = new \WordPress\AiClient\Providers\Models\DTO\ModelConfig();
$config->setCustomOption('openai_tool_search_threshold', 20); // Enable above 20 functions.
// $config->setCustomOption('openai_tool_search_threshold', false); // Disable.

$result = AiClient::prompt('Find the relevant content')
    ->usingModel(OpenAiProvider::model('gpt-5.4'))
    ->usingModelConfig($config)
    ->usingFunctionDeclarations(...$functions)
    ->generateTextResult();
```

The threshold accepts a non-negative integer or `false`. `0` enables search for any
non-empty function list on an eligible model. This provider-only option is consumed
locally and never sent to OpenAI. Other custom options retain their existing behavior.

### Conversation history and execution

Hosted search runs at OpenAI; applications execute only ordinary returned function
calls, never `tool_search_call`. Preserve **all** returned messages and parts (use
`$result->toMessages()` for history) when continuing a conversation. The provider
round-trips search call/output items, reasoning, and function-call namespace metadata
through SDK array/JSON serialization. It validates ownership and supported item shapes
before replay. Client-executed discovery is not supported by this experiment.

For `store: false`, retain reasoning encrypted content where needed through the
existing `include: ['reasoning.encrypted_content']` custom option. Alternatively,
use `previous_response_id` with only new input; do not also replay the already-stored
history. The provider does not infer or cache response IDs on your behalf.

Tool discovery is not authorization. Validate tool names, arguments, permissions,
and approvals against the application's current tool registry before executing them,
especially when replaying history containing previously loaded tools.

### Evidence and limitations

- [OpenAI](https://developers.openai.com/api/docs/guides/tools-tool-search) documents
  hosted search for GPT-5.4 and later compatible Responses models. Deferred flat
  functions still expose their names/descriptions; the main saving is parameter
  schemas, not request-upload size. Namespaces can save more but require meaningful
  grouping metadata, so this provider does not invent them.
- [Vercel AI SDK v7](https://ai-sdk.dev/providers/ai-sdk-providers/openai) exposes
  `openai.tools.toolSearch()` and per-tool `providerOptions.openai.deferLoading`.
  Its documented API is explicit rather than count-triggered.
- [Vercel's Anthropic provider](https://ai-sdk.dev/providers/ai-sdk-providers/anthropic)
  similarly uses provider-defined search and deferred-loading options.

Research checked 2026-09-09. The `>10` threshold is a tunable experiment, not an OpenAI
requirement or a measured performance guarantee. Compare eager vs automatic behavior
on the same tasks at 10, 11, 20, and 50 functions, including small and large schemas.
Measure task success, wrong/missed tool selections, total input/output tokens, cache
hits, and end-to-end latency across multiple turns. Hosted search can add latency and
extra model work; keep an opt-out and collect evidence before generalizing the policy.
Unit tests verify wire construction and replay, not real-world quality or savings.
This model currently exposes non-streaming generation; streaming support is outside
this change.

## Configuration

The provider uses the `OPENAI_API_KEY` environment variable for authentication. You can set this in your environment or via PHP:

```php
putenv('OPENAI_API_KEY=your-api-key');
```

## License

GPL-2.0-or-later
