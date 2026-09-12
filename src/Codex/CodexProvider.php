<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Codex;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Provider for ChatGPT Codex subscription-backed access.
 *
 * @since n.e.x.t
 */
class CodexProvider extends AbstractApiProvider
{
    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    protected static function baseUrl(): string
    {
        return 'https://chatgpt.com/backend-api/codex';
    }

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    protected static function createModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        foreach ($modelMetadata->getSupportedCapabilities() as $capability) {
            if ($capability->isTextGeneration()) {
                return new CodexTextGenerationModel($modelMetadata, $providerMetadata);
            }
        }

        throw new RuntimeException('Unsupported Codex model capabilities.');
    }

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $providerMetadataArgs = [
            'codex',
            'ChatGPT Codex',
            ProviderTypeEnum::cloud(),
            'https://chatgpt.com',
            RequestAuthenticationMethod::apiKey(),
        ];

        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            if (function_exists('__')) {
                $providerMetadataArgs[] = __('ChatGPT subscription-backed Codex access.', 'ai-provider-for-openai');
            } else {
                $providerMetadataArgs[] = 'ChatGPT subscription-backed Codex access.';
            }
        }

        return new ProviderMetadata(...$providerMetadataArgs);
    }

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new CodexProviderAvailability(new CodexTokenStore());
    }

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new CodexModelMetadataDirectory();
    }
}
