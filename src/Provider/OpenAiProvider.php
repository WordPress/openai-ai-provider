<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Provider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface;
use WordPress\OpenAiAiProvider\Metadata\OpenAiModelMetadataDirectory;
use WordPress\OpenAiAiProvider\Models\OpenAiEmbeddingGenerationModel;
use WordPress\OpenAiAiProvider\Models\OpenAiImageGenerationModel;
use WordPress\OpenAiAiProvider\Models\OpenAiTextGenerationModel;
use WordPress\OpenAiAiProvider\Models\OpenAiTextToSpeechConversionModel;

/**
 * Class for the AI Provider for OpenAI.
 *
 * @since 1.0.0
 */
class OpenAiProvider extends AbstractApiProvider
{
    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function baseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        $capabilities = $modelMetadata->getSupportedCapabilities();
        foreach ($capabilities as $capability) {
            if ($capability->isTextGeneration()) {
                return new OpenAiTextGenerationModel($modelMetadata, $providerMetadata);
            }
            if ($capability->isImageGeneration()) {
                return new OpenAiImageGenerationModel($modelMetadata, $providerMetadata);
            }
            // Embedding generation support was added in 1.4.0.
            if (
                $capability->isEmbeddingGeneration() &&
                interface_exists(EmbeddingGenerationModelInterface::class)
            ) {
                return new OpenAiEmbeddingGenerationModel($modelMetadata, $providerMetadata);
            }
            if ($capability->isTextToSpeechConversion()) {
                return new OpenAiTextToSpeechConversionModel($modelMetadata, $providerMetadata);
            }
        }

        throw new RuntimeException(
            'Unsupported model capabilities: ' . implode(', ', $capabilities)
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $providerMetadataArgs = [
            'openai',
            'OpenAI',
            ProviderTypeEnum::cloud(),
            'https://platform.openai.com/api-keys',
            RequestAuthenticationMethod::apiKey()
        ];
        // Provider description support was added in 1.2.0.
        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            // For WordPress, we should translate the description.
            if (function_exists('__')) {
                // phpcs:ignore Generic.Files.LineLength.TooLong
                $providerMetadataArgs[] = __('Text, image, embedding and speech generation with GPT and Dall-E.', 'ai-provider-for-openai');
            } else {
                $providerMetadataArgs[] = 'Text, image, embedding and speech generation with GPT and Dall-E.';
            }
        }
        // Provider logoPath support was added in 1.3.0.
        if (version_compare(AiClient::VERSION, '1.3.0', '>=')) {
            $pluginDir = dirname(__DIR__, 2);
            if (defined('AI_PROVIDER_FOR_OPENAI_PLUGIN_DIR')) {
                $definedPluginDir = constant('AI_PROVIDER_FOR_OPENAI_PLUGIN_DIR');
                if (is_string($definedPluginDir)) {
                    $pluginDir = $definedPluginDir;
                }
            }
            $providerMetadataArgs[] = $pluginDir . '/assets/images/openai.svg';
        }
        return new ProviderMetadata(...$providerMetadataArgs);
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        // Check valid API access by attempting to list models.
        return new ListModelsApiBasedProviderAvailability(
            static::modelMetadataDirectory()
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new OpenAiModelMetadataDirectory();
    }
}
