=== AI Provider for OpenAI ===
Contributors:      wordpressdotorg
Tags:              ai, openai, gpt, artificial-intelligence, connector
Requires at least: 6.9
Tested up to:      7.1
Stable tag:        1.1.0
Requires PHP:      7.4
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

AI Provider for OpenAI for the PHP AI Client SDK.

== Description ==

This plugin provides OpenAI integration for the PHP AI Client SDK. It enables WordPress sites to use OpenAI's GPT models for text generation, DALL-E for image generation, and other AI capabilities.

**Features:**

* Text generation with GPT models
* Image generation with DALL-E models
* Optional ChatGPT subscription-backed Codex provider
* Function calling support
* Web search support
* Automatic provider registration

Available models are dynamically discovered from the OpenAI API, including GPT models for text generation, DALL-E and GPT Image models for image generation, and TTS models for text-to-speech.

**Requirements:**

* PHP 7.4 or higher
* For WordPress 6.9, the [wordpress/php-ai-client](https://github.com/WordPress/php-ai-client) package must be installed
* For WordPress 7.0 and above, no additional changes are required
* OpenAI API key
* Optional Codex OAuth refresh token for the Codex provider

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ai-provider-for-openai/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure your OpenAI API key via the `OPENAI_API_KEY` environment variable or constant
4. Optionally configure Codex OAuth token data with the `ai_provider_openai_codex_oauth_tokens` option or filter

== Frequently Asked Questions ==

= How do I get an OpenAI API key? =

Visit the [OpenAI Platform](https://platform.openai.com/) to create an account and generate an API key.

= Does this plugin work without the PHP AI Client? =

No, this plugin requires the PHP AI Client plugin to be installed and activated. It provides the OpenAI-specific implementation that the PHP AI Client uses.

== Changelog ==

= 1.1.0 - 2026-08-17 =

**Added**

* Support for OpenAI embedding models, including batch inputs, custom dimensions, token usage, and result metadata ([#34](https://github.com/WordPress/ai-provider-for-openai/pull/34)).
* Support for fine-tuned OpenAI models by deriving capabilities from their underlying base models ([#17](https://github.com/WordPress/ai-provider-for-openai/pull/17)).
* Support for editing and refining generated images using reference images and OpenAI’s image-editing endpoint ([#29](https://github.com/WordPress/ai-provider-for-openai/pull/29)).
* A `TokenLimitReachedException` when OpenAI responses are incomplete because the maximum output-token limit was reached ([#10](https://github.com/WordPress/ai-provider-for-openai/pull/10)).

**Changed**

* Made sampling-option capabilities model-aware for reasoning models, added Responses API support for log probabilities, and reject incompatible sampling and reasoning configurations before sending a request ([#40](https://github.com/WordPress/ai-provider-for-openai/pull/40)).
* Bumped WordPress tested-up-to version 7.1 ([#43](https://github.com/WordPress/ai-provider-for-openai/pull/43)).

**Fixed**

* Function-call name handling for names that do not meet OpenAI’s naming requirements, while preserving the original PHP AI Client function names returned to callers ([#31](https://github.com/WordPress/ai-provider-for-openai/pull/31)).

= 1.0.3 =

* Add a provider logo to the metadata if the client version > 1.3.0 ([#19](https://github.com/WordPress/ai-provider-for-openai/pull/19)).
* Fix mapping of models that support multimodal inputs ([#22](https://github.com/WordPress/ai-provider-for-openai/pull/22)).

= 1.0.2 =

* Add plugin directory assets by @shaunandrews in https://github.com/WordPress/ai-provider-for-openai/pull/7
* Update tags in readme.txt by @jeffpaul in https://github.com/WordPress/ai-provider-for-openai/pull/9
* Fix missing input and output modality combinations. by @felixarntz in https://github.com/WordPress/ai-provider-for-openai/pull/11
* Add provider description by @felixarntz in https://github.com/WordPress/ai-provider-for-openai/pull/12

= 1.0.1 =

* Initial release of the plugin
* Support for GPT text generation models
* Support for DALL-E image generation models
* Function calling support
* Web search support

= 1.0.0 =

* Initial release of the Composer package
