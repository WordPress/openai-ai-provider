<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Codex;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Model metadata directory for ChatGPT Codex models.
 *
 * @since n.e.x.t
 */
class CodexModelMetadataDirectory implements ModelMetadataDirectoryInterface
{
    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    public function listModelMetadata(): array
    {
        return array_values($this->getModelMap());
    }

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    public function hasModelMetadata(string $modelId): bool
    {
        return isset($this->getModelMap()[$modelId]);
    }

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    public function getModelMetadata(string $modelId): ModelMetadata
    {
        $models = $this->getModelMap();
        if (!isset($models[$modelId])) {
            throw new InvalidArgumentException('No Codex model with the requested ID was found.');
        }

        return $models[$modelId];
    }

    /**
     * Gets the supported Codex model map.
     *
     * @since n.e.x.t
     *
     * @return array<string, ModelMetadata> Model metadata keyed by ID.
     */
    private function getModelMap(): array
    {
        $options = [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']),
            new SupportedOption(OptionEnum::outputSchema()),
            new SupportedOption(OptionEnum::customOptions()),
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
        ];

        $models = [];
        foreach (['gpt-5.5', 'gpt-5', 'codex-mini-latest'] as $modelId) {
            $models[$modelId] = new ModelMetadata(
                $modelId,
                $modelId,
                [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()],
                $options
            );
        }

        return $models;
    }
}
