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
OpenAI-hosted tool search on eligible requests and defers their parameter schemas.
No new prompt-builder method is needed:

```php
// $functions is an ordinary list of SDK FunctionDeclaration objects.
$result = AiClient::prompt('Find the relevant content')
    ->usingModel(OpenAiProvider::model('gpt-5.4'))
    ->usingFunctionDeclarations(...$functions)
    ->generateTextResult();
```

The initial model allowlist is `gpt-5.4`, `gpt-5.4-pro`, and their dated snapshots.
Other models retain eager loading, even if their names look newer. This experiment
uses **OpenAI-managed conversation state**, not stateless message replay. Requests
with `store: false`, replayed model messages/reasoning, or function results without
a `previous_response_id` keep functions eager. The provider never changes `store`
or manages response IDs on the application's behalf.

Web search is independent and does not count toward the threshold. Explicit
`tool_choice` values other than `auto` keep functions eager so that forced or
restricted tool choice does not accidentally become a discovery call.

### Function annotations and request options

Generic function metadata can opt individual functions in or keep them eager:

```php
$weather = new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
    'get_weather',
    'Gets the weather',
    null,
    ['deferredLoading' => true]
);
```

Use existing model configuration for the request-wide default:

```php
$config = new \WordPress\AiClient\Providers\Models\DTO\ModelConfig();
$config->setCustomOptions(['deferredLoading' => true]);
// $config->setCustomOptions(['deferredLoading' => false]); // Disable the optimization.
// $config->setCustomOption('openai_tool_search_threshold', 20); // Tune automatic activation.

$result = AiClient::prompt('Find the relevant content')
    ->usingModel(OpenAiProvider::model('gpt-5.4'))
    ->usingModelConfig($config)
    ->usingFunctionDeclarations(...$functions)
    ->generateTextResult();
```

Precedence on an eligible model/request:

1. Request `deferredLoading: false` or threshold `false` disables all deferral,
   including per-function opt-ins.
2. Per-function `deferredLoading: false` keeps that function eager;
   `true` makes it searchable even below the threshold.
3. Unannotated functions use request `deferredLoading: true`, if set; otherwise
   they are deferred only when the function count exceeds the threshold (default 10).

The threshold accepts a non-negative integer or `false`. `0` defers any non-empty
inventory unless an individual function opts out. Search is added only if at least
one function is deferred. `deferredLoading` values must be booleans. Unknown metadata
is ignored, not copied into OpenAI tools or parameter schemas. Both policy options
are consumed locally and never sent to OpenAI.

Per-function annotations require the generic `FunctionDeclaration::getMetadata()`
extension in [php-ai-client #282](https://github.com/WordPress/php-ai-client/pull/282).
Automatic and request-level policy also work on older SDKs without that accessor.
This development branch follows the SDK PR as a **development-only** dependency to
test annotations; replace its branch constraint with a released version before
releasing this experiment. No new core message types or model options are required.

### Conversation history and execution

Hosted search runs at OpenAI; applications execute only ordinary returned function
calls, never `tool_search_call`. OpenAI retains search results and namespace data.
The provider validates hosted search output, exposes the response ID as usual, and
sets `$result->getAdditionalData()['tool_search']['continuation']` to
`previous_response_id` when search was used. Discovery items are **not** retained by
`toMessages()` or serialized message history. Client-executed discovery is rejected.

Continue with the existing custom option and **only new input or function results**:

```php
$config->setCustomOption('previous_response_id', $result->getId());

// $functionResponse contains the authorized function's result and its call ID.
$nextResult = AiClient::prompt()
    ->withFunctionResponse($functionResponse)
    ->usingModel(OpenAiProvider::model('gpt-5.4'))
    ->usingModelConfig($config)
    ->usingFunctionDeclarations(...$functions)
    ->generateTextResult();
```

Do not include the previous model response or reasoning in this request: the server
already has it. Keep passing current declarations for name mapping and discovery.
Use the latest response ID on each subsequent turn. A search-only response carries
an empty candidate (no executable function) and the continuation hint; applications
can inspect it and decide whether to continue within their existing turn budget.
An application with a no-storage policy must keep `store: false` and eager loading.

The eager fallback prevents new discovery in stateless requests; it cannot reconstruct
discovery context from an earlier searched response. Once search has been used,
continue that conversation through its response ID, or start a new conversation.

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
Unit tests verify wire construction and server-continuation plumbing, not real-world
quality or savings. Stateless replay of discovery remains outside this experiment.
This model currently exposes non-streaming generation; streaming support is outside
this change.

## Configuration

The provider uses the `OPENAI_API_KEY` environment variable for authentication. You can set this in your environment or via PHP:

```php
putenv('OPENAI_API_KEY=your-api-key');
```

## License

GPL-2.0-or-later
