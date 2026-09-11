<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Tests\Models;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\OpenAiAiProvider\Models\OpenAiTextGenerationModel;

/**
 * Tests for the OpenAI text generation model.
 *
 * @since 1.1.0
 */
class OpenAiTextGenerationModelTest extends TestCase
{
    /**
     * Tests that native log-probability options are mapped to Responses API parameters.
     *
     * @since 1.1.0
     */
    public function testNativeLogProbabilityOptionsAreMapped(): void
    {
        $params = $this->prepareParams([
            'logprobs' => true,
            'topLogprobs' => 5,
        ]);

        $this->assertSame(['message.output_text.logprobs'], $params['include']);
        $this->assertSame(5, $params['top_logprobs']);
    }

    /**
     * Tests that explicitly disabling log probabilities does not request their output.
     *
     * @since 1.1.0
     */
    public function testFalseLogprobsDoesNotRequestLogProbabilityOutput(): void
    {
        $params = $this->prepareParams([
            'logprobs' => false,
        ]);

        $this->assertArrayNotHasKey('include', $params);
    }

    /**
     * Tests that sampling options combined with an explicit non-`none` reasoning effort are rejected.
     *
     * Models like `gpt-5.2` and `gpt-5.4` support sampling options in their default
     * `reasoning.effort: 'none'` mode, so the options are advertised in the model metadata.
     * When a request explicitly enables reasoning, the OpenAI API would reject the sampling
     * parameters, so the invalid combination must be caught before the request is sent.
     *
     * @dataProvider conflictingSamplingConfigProvider
     *
     * @param array<string, mixed> $configData The model configuration data.
     * @param string $expectedParam The sampling parameter expected to be reported.
     */
    public function testSamplingParamsWithExplicitReasoningEffortAreRejected(
        array $configData,
        string $expectedParam
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            '/"' . preg_quote($expectedParam, '/') . '".*reasoning effort/'
        );

        $this->prepareParams($configData);
    }

    /**
     * Data provider for testSamplingParamsWithExplicitReasoningEffortAreRejected().
     *
     * @return array<string, array{array<string, mixed>, string}> Test cases.
     */
    public static function conflictingSamplingConfigProvider(): array
    {
        return [
            'temperature with high effort' => [
                [
                    'temperature' => 0.7,
                    'customOptions' => ['reasoning' => ['effort' => 'high']],
                ],
                'temperature',
            ],
            'top_p with medium effort' => [
                [
                    'topP' => 0.9,
                    'customOptions' => ['reasoning' => ['effort' => 'medium']],
                ],
                'top_p',
            ],
            'logprobs with low effort' => [
                [
                    'logprobs' => true,
                    'customOptions' => ['reasoning' => ['effort' => 'low']],
                ],
                'logprobs',
            ],
            'top_logprobs with high effort' => [
                [
                    'topLogprobs' => 5,
                    'customOptions' => ['reasoning' => ['effort' => 'high']],
                ],
                'top_logprobs',
            ],
        ];
    }

    /**
     * Tests that sampling options are allowed when the reasoning effort is explicitly `none`.
     */
    public function testSamplingParamsWithReasoningEffortNoneAreAllowed(): void
    {
        $params = $this->prepareParams([
            'temperature' => 0.7,
            'topP' => 0.9,
            'logprobs' => true,
            'topLogprobs' => 5,
            'customOptions' => ['reasoning' => ['effort' => 'none']],
        ]);

        $this->assertSame(0.7, $params['temperature']);
        $this->assertSame(0.9, $params['top_p']);
        $this->assertSame(['message.output_text.logprobs'], $params['include']);
        $this->assertSame(5, $params['top_logprobs']);
        $this->assertSame(['effort' => 'none'], $params['reasoning']);
    }

    /**
     * Tests that sampling options are allowed when no reasoning effort is configured.
     */
    public function testSamplingParamsWithoutReasoningEffortAreAllowed(): void
    {
        $params = $this->prepareParams([
            'temperature' => 0.7,
        ]);

        $this->assertSame(0.7, $params['temperature']);
        $this->assertArrayNotHasKey('reasoning', $params);
    }

    /**
     * Tests that an explicit reasoning effort without sampling options is allowed.
     */
    public function testReasoningEffortWithoutSamplingParamsIsAllowed(): void
    {
        $params = $this->prepareParams([
            'customOptions' => ['reasoning' => ['effort' => 'high']],
        ]);

        $this->assertSame(['effort' => 'high'], $params['reasoning']);
        $this->assertArrayNotHasKey('temperature', $params);
        $this->assertArrayNotHasKey('top_p', $params);
    }

    /**
     * Tests that thought-channel parts round-trip as top-level Responses API reasoning items.
     */
    public function testThoughtOnlyMessageCreatesOnlyReasoningInputItem(): void
    {
        $model = $this->createTextGenerationModel();
        $method = new ReflectionMethod($model, 'prepareInputParam');
        $method->setAccessible(true);

        $signature = (string) json_encode([
            'id' => 'rs_123',
            'encrypted_content' => 'encrypted-reasoning',
            'summary' => [],
        ]);
        $message = new Message(
            MessageRoleEnum::model(),
            [new MessagePart('', MessagePartChannelEnum::thought(), $signature)]
        );

        $input = $method->invoke($model, [$message]);

        $this->assertSame([
            [
                'type' => 'reasoning',
                'id' => 'rs_123',
                'encrypted_content' => 'encrypted-reasoning',
                'summary' => [],
            ],
        ], $input);
    }

    /**
     * Tests that malformed reasoning signatures are not sent to the API.
     */
    public function testReasoningInputItemsWithoutValidIdsAreSkipped(): void
    {
        $model = $this->createTextGenerationModel();
        $method = new ReflectionMethod($model, 'prepareInputParam');
        $method->setAccessible(true);

        $message = new Message(
            MessageRoleEnum::model(),
            [
                new MessagePart('', MessagePartChannelEnum::thought(), 'encrypted-reasoning'),
                new MessagePart(
                    '',
                    MessagePartChannelEnum::thought(),
                    (string) json_encode(['encrypted_content' => 'encrypted-reasoning'])
                ),
            ]
        );

        $this->assertSame([], $method->invoke($model, [$message]));
    }

    /**
     * Tests that reasoning output is preserved as a thought-channel message part.
     */
    public function testReasoningOutputIsPrependedToCandidateMessageParts(): void
    {
        $model = $this->createTextGenerationModel();
        $method = new ReflectionMethod($model, 'parseResponseToGenerativeAiResult');
        $method->setAccessible(true);

        $response = new Response(200, [], (string) json_encode([
            'id' => 'resp_123',
            'status' => 'completed',
            'output' => [
                [
                    'type' => 'reasoning',
                    'id' => 'rs_123',
                    'encrypted_content' => 'encrypted-reasoning',
                    'summary' => [
                        ['type' => 'summary_text', 'text' => 'First reasoning summary.'],
                        ['type' => 'summary_text', 'text' => 'Second reasoning summary.'],
                    ],
                ],
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'output_text', 'text' => 'Final answer.'],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 1,
                'output_tokens' => 2,
                'total_tokens' => 3,
                'output_tokens_details' => ['reasoning_tokens' => 4],
            ],
        ]));

        $result = $method->invoke($model, $response);
        $parts = $result->getCandidates()[0]->getMessage()->getParts();

        $this->assertCount(2, $parts);
        $this->assertTrue($parts[0]->getChannel()->isThought());
        $this->assertSame("First reasoning summary.\nSecond reasoning summary.", $parts[0]->getText());
        $this->assertSame('Final answer.', $parts[1]->getText());
        $this->assertSame(4, $result->getTokenUsage()->getThoughtTokens());
    }

    /**
     * Tests that reasoning output without an ID is ignored.
     */
    public function testReasoningOutputWithoutIdIsIgnored(): void
    {
        $result = $this->parseResponse([
            [
                'type' => 'reasoning',
                'encrypted_content' => 'encrypted-reasoning',
                'summary' => [],
            ],
            [
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'output_text', 'text' => 'Final answer.'],
                ],
            ],
        ]);

        $parts = $result->getCandidates()[0]->getMessage()->getParts();

        $this->assertCount(1, $parts);
        $this->assertSame('Final answer.', $parts[0]->getText());
    }

    /**
     * Tests that reasoning does not cross an unsupported output item boundary.
     */
    public function testReasoningIsClearedAfterUnsupportedOutputItem(): void
    {
        $result = $this->parseResponse([
            [
                'type' => 'reasoning',
                'id' => 'rs_123',
                'encrypted_content' => 'encrypted-reasoning',
                'summary' => [],
            ],
            [
                'type' => 'web_search_call',
                'id' => 'ws_123',
            ],
            [
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'output_text', 'text' => 'Final answer.'],
                ],
            ],
        ]);

        $parts = $result->getCandidates()[0]->getMessage()->getParts();

        $this->assertCount(1, $parts);
        $this->assertSame('Final answer.', $parts[0]->getText());
    }

    /**
     * Parses a Responses API output list into a generative AI result.
     *
     * @param list<array<string, mixed>> $output The response output items.
     */
    private function parseResponse(array $output): \WordPress\AiClient\Results\DTO\GenerativeAiResult
    {
        $model = $this->createTextGenerationModel();
        $method = new ReflectionMethod($model, 'parseResponseToGenerativeAiResult');
        $method->setAccessible(true);

        $response = new Response(200, [], (string) json_encode([
            'id' => 'resp_123',
            'status' => 'completed',
            'output' => $output,
        ]));

        return $method->invoke($model, $response);
    }

    /**
     * Prepares request parameters for a `gpt-5.2` model with the given configuration.
     *
     * @param array<string, mixed> $configData The model configuration data.
     * @return array<string, mixed> The prepared request parameters.
     */
    private function prepareParams(array $configData): array
    {
        $model = $this->createTextGenerationModel();
        $model->setConfig(ModelConfig::fromArray($configData));

        $method = new ReflectionMethod($model, 'prepareGenerateTextParams');
        $method->setAccessible(true);

        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];

        /** @var array<string, mixed> $params */
        $params = $method->invoke($model, $prompt);

        return $params;
    }

    /**
     * Creates the text generation model under test.
     */
    private function createTextGenerationModel(): OpenAiTextGenerationModel
    {
        return new OpenAiTextGenerationModel(
            new ModelMetadata('gpt-5.2', 'gpt-5.2', [CapabilityEnum::textGeneration()], []),
            new ProviderMetadata('openai', 'OpenAI', ProviderTypeEnum::cloud())
        );
    }
}
