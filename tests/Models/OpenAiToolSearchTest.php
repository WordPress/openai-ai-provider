<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Tests\Models;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WordPress\AiClient\Tools\DTO\WebSearch;
use WordPress\OpenAiAiProvider\Models\OpenAiTextGenerationModel;

/**
 * Tests provider-owned policy, function annotations, and server-managed continuation.
 *
 * @since n.e.x.t
 */
class OpenAiToolSearchTest extends TestCase
{
    /**
     * Tests automatic defaults and request-level overrides without changing the config.
     *
     * @dataProvider policyProvider
     * @param string $modelId The model ID.
     * @param int $count The number of functions.
     * @param array<string, mixed> $options Provider options.
     * @param bool $expected Whether all functions should be deferred.
     */
    public function testPolicy(string $modelId, int $count, array $options, bool $expected): void
    {
        $model = $this->model($modelId, $count, $options);
        $original = $model->getConfig()->toArray();
        $params = $this->invoke($model, 'prepareGenerateTextParams', [$this->prompt()]);
        $this->assertSame($expected, in_array('tool_search', array_column($params['tools'], 'type'), true));
        $this->assertCount($count + ($expected ? 1 : 0), $params['tools']);
        foreach ($params['tools'] as $tool) {
            if ($tool['type'] === 'function') {
                $this->assertSame($expected, $tool['defer_loading'] ?? false);
            }
        }
        $this->assertArrayNotHasKey('openai_tool_search_threshold', $params);
        $this->assertArrayNotHasKey('deferredLoading', $params);
        $this->assertSame($original, $model->getConfig()->toArray());
    }

    /**
     * Provides automatic policy cases.
     *
     * @return array<string, array{string, int, array<string, mixed>, bool}> Test cases.
     */
    public static function policyProvider(): array
    {
        return [
            'zero' => ['gpt-5.4', 0, [], false],
            'ten eager' => ['gpt-5.4', 10, [], false],
            'eleven deferred' => ['gpt-5.4', 11, [], true],
            'snapshot' => ['gpt-5.4-2026-03-05', 11, [], true],
            'pro' => ['gpt-5.4-pro', 11, [], true],
            'old' => ['gpt-5.2', 11, [], false],
            'unknown' => ['custom-model', 11, [], false],
            'specialized' => ['gpt-5.4-codex', 11, [], false],
            'request opt out' => ['gpt-5.4', 11, ['deferredLoading' => false], false],
            'request opt in' => ['gpt-5.4', 1, ['deferredLoading' => true], true],
            'empty request opt in' => ['gpt-5.4', 0, ['deferredLoading' => true], false],
            'threshold opt out' => ['gpt-5.4', 11, ['openai_tool_search_threshold' => false], false],
            'raised threshold' => ['gpt-5.4', 11, ['openai_tool_search_threshold' => 20], false],
            'lowered threshold' => ['gpt-5.4', 1, ['openai_tool_search_threshold' => 0], true],
            'zero with zero threshold' => ['gpt-5.4', 0, ['openai_tool_search_threshold' => 0], false],
            'auto choice' => ['gpt-5.4', 11, ['tool_choice' => 'auto'], true],
            'none choice' => ['gpt-5.4', 11, ['tool_choice' => 'none'], false],
            'required choice' => ['gpt-5.4', 11, ['tool_choice' => 'required'], false],
            'forced choice' => ['gpt-5.4', 11, ['tool_choice' => ['type' => 'function', 'name' => 'tool_0']], false],
            'stateless storage' => ['gpt-5.4', 11, ['store' => false], false],
            'storage opt out beats opt in' => ['gpt-5.4', 1, ['store' => false, 'deferredLoading' => true], false],
        ];
    }

    /**
     * Tests per-function annotations take precedence over defaults, but not hard opt-outs.
     *
     * @dataProvider annotationsProvider
     * @param array<string, mixed> $options Request options.
     * @param list<array<string, mixed>> $metadata Function metadata.
     * @param list<bool> $expected Expected per-function deferral.
     */
    public function testAnnotations(array $options, array $metadata, array $expected): void
    {
        $model = $this->model('gpt-5.4', count($metadata), $options, $metadata);
        $before = $model->getConfig()->toArray();
        $params = $this->invoke($model, 'prepareGenerateTextParams', [$this->prompt()]);
        foreach ($expected as $index => $deferred) {
            $this->assertSame($deferred, $params['tools'][$index]['defer_loading'] ?? false);
            $this->assertArrayNotHasKey('metadata', $params['tools'][$index]);
            $this->assertArrayNotHasKey('deferredLoading', $params['tools'][$index]);
            $this->assertArrayNotHasKey('vendor', $params['tools'][$index]);
        }
        $this->assertSame(
            in_array(true, $expected, true),
            in_array('tool_search', array_column($params['tools'], 'type'), true)
        );
        $this->assertSame($before, $model->getConfig()->toArray());
    }

    /**
     * Provides annotation precedence cases.
     *
     * @return list<array{array<string, mixed>, list<array<string, mixed>>, list<bool>}> Test cases.
     */
    public static function annotationsProvider(): array
    {
        return [
            [[], [['deferredLoading' => true], []], [true, false]],
            [['deferredLoading' => true], [['deferredLoading' => false], []], [false, true]],
            [['deferredLoading' => false], [['deferredLoading' => true], []], [false, false]],
            [['openai_tool_search_threshold' => 0], [['deferredLoading' => false], []], [false, true]],
            [['deferredLoading' => true], [['deferredLoading' => false]], [false]],
            [['openai_tool_search_threshold' => false], [['deferredLoading' => true]], [false]],
            [[], [['vendor' => ['arbitrary' => true]]], [false]],
            [['store' => false], [['deferredLoading' => true]], [false]],
        ];
    }

    /**
     * Tests invalid policy values fail locally rather than leaking into the wire request.
     *
     * @dataProvider invalidPolicyProvider
     * @param array<string, mixed> $options Request options.
     * @param array<string, mixed> $metadata Function metadata.
     */
    public function testInvalidPolicy(array $options, array $metadata): void
    {
        $this->expectException(InvalidArgumentException::class);
        $model = $this->model('gpt-5.4', 1, $options, [$metadata]);
        $this->invoke($model, 'prepareGenerateTextParams', [$this->prompt()]);
    }

    /**
     * Provides invalid request and annotation values.
     *
     * @return list<array{array<string, mixed>, array<string, mixed>}> Test cases.
     */
    public static function invalidPolicyProvider(): array
    {
        return [
            [['openai_tool_search_threshold' => -1], []],
            [['openai_tool_search_threshold' => true], []],
            [['openai_tool_search_threshold' => '10'], []],
            [['openai_tool_search_threshold' => null], []],
            [['deferredLoading' => 'true'], []],
            [['deferredLoading' => null], []],
            [[], ['deferredLoading' => 1]],
            [[], ['deferredLoading' => null]],
        ];
    }

    /**
     * Tests web search stays eager and does not count toward the function threshold.
     */
    public function testWebSearchIsIndependent(): void
    {
        $model = $this->model('gpt-5.4', 10);
        $model->getConfig()->setWebSearch(new WebSearch());
        $params = $this->invoke($model, 'prepareGenerateTextParams', [$this->prompt()]);
        $this->assertCount(11, $params['tools']);
        $this->assertSame(['type' => 'web_search'], $params['tools'][10]);
    }

    /**
     * Tests discovery stays on the server while a fresh model sends only a new function result.
     */
    public function testServerManagedContinuation(): void
    {
        $model = $this->model('gpt-5.4', 1, ['deferredLoading' => true]);
        $params = $this->invoke($model, 'prepareGenerateTextParams', [$this->prompt()]);
        $safeName = $params['tools'][0]['name'];
        $output = $this->searchItems();
        $output[] = ['type' => 'function_call', 'call_id' => 'call_1', 'name' => $safeName,
            'namespace' => $safeName, 'arguments' => '{"query":"posts"}'];
        $response = new Response(200, [], (string) json_encode(['id' => 'resp_1', 'output' => $output]));
        $result = $this->invoke($model, 'parseResponseToGenerativeAiResult', [$response]);
        $this->assertCount(1, $result->getCandidates());
        $this->assertTrue($result->getCandidates()[0]->getFinishReason()->isToolCalls());
        $parts = $result->toMessage()->getParts();
        $this->assertCount(1, $parts);
        $this->assertSame('plugin/tool_0', $parts[0]->getFunctionCall()->getName());
        $this->assertSame(['continuation' => 'previous_response_id'], $result->getAdditionalData()['tool_search']);

        $next = $this->model('gpt-5.4', 1, ['deferredLoading' => true, 'previous_response_id' => $result->getId()]);
        $reply = new Message(MessageRoleEnum::user(), [new MessagePart(new FunctionResponse('call_1', null, ['ok']))]);
        $nextParams = $this->invoke($next, 'prepareGenerateTextParams', [[$reply]]);
        $this->assertSame('resp_1', $nextParams['previous_response_id']);
        $this->assertCount(1, $nextParams['input']);
        $this->assertSame('function_call_output', $nextParams['input'][0]['type']);
        $this->assertSame('call_1', $nextParams['input'][0]['call_id']);
        $this->assertSame(['type' => 'tool_search'], $nextParams['tools'][1]);
        $this->assertArrayNotHasKey('store', $nextParams); // Never override the application's storage choice.
    }

    /**
     * Tests stateless model history or orphan function results keep functions eager.
     */
    public function testStatelessInputKeepsFunctionsEager(): void
    {
        $model = $this->model('gpt-5.4', 11);
        $messages = [
            new Message(MessageRoleEnum::model(), [new MessagePart('Previous answer')]),
            new Message(MessageRoleEnum::user(), [new MessagePart(new FunctionResponse('call_1', null, ['ok']))]),
        ];
        foreach ($messages as $message) {
            $params = $this->invoke($model, 'prepareGenerateTextParams', [[$message]]);
            $this->assertCount(11, $params['tools']);
            foreach ($params['tools'] as $tool) {
                $this->assertArrayNotHasKey('defer_loading', $tool);
            }
        }
    }

    /**
     * Tests search-only responses expose an ID and no executable search call.
     */
    public function testSearchOnlyResponse(): void
    {
        $response = new Response(200, [], (string) json_encode(['id' => 'resp_1', 'output' => $this->searchItems()]));
        $result = $this->invoke($this->model(), 'parseResponseToGenerativeAiResult', [$response]);
        $this->assertSame('resp_1', $result->getId());
        $this->assertSame([], $result->toMessage()->getParts());
        $this->assertTrue($result->getCandidates()[0]->getFinishReason()->isStop());
    }

    /**
     * Tests client-executed discovery and missing continuation IDs are rejected.
     */
    public function testClientSearchResponseIsRejected(): void
    {
        $items = $this->searchItems();
        $items[0]['execution'] = 'client';
        $items[0]['call_id'] = 'call_search';
        $response = new Response(200, [], (string) json_encode(['id' => 'resp_1', 'output' => $items]));
        $this->expectException(ResponseException::class);
        $this->invoke($this->model(), 'parseResponseToGenerativeAiResult', [$response]);
    }

    /**
     * Tests search results without a response ID cannot silently lose continuation state.
     */
    public function testMissingResponseIdIsRejected(): void
    {
        $response = new Response(200, [], (string) json_encode(['output' => $this->searchItems()]));
        $this->expectException(ResponseException::class);
        $this->invoke($this->model(), 'parseResponseToGenerativeAiResult', [$response]);
    }

    /**
     * Creates a model using ordinary declarations with optional generic metadata.
     *
     * @param string $id The model ID.
     * @param int $count The number of functions.
     * @param array<string, mixed> $options Provider options.
     * @param list<array<string, mixed>> $metadata Per-function metadata.
     * @return OpenAiTextGenerationModel The model.
     */
    private function model(
        string $id = 'gpt-5.4',
        int $count = 0,
        array $options = [],
        array $metadata = []
    ): OpenAiTextGenerationModel {
        $model = new OpenAiTextGenerationModel(
            new ModelMetadata($id, $id, [CapabilityEnum::textGeneration()], []),
            new ProviderMetadata('openai', 'OpenAI', ProviderTypeEnum::cloud())
        );
        $config = new ModelConfig();
        $functions = [];
        for ($i = 0; $i < $count; $i++) {
            $functions[] = new FunctionDeclaration('plugin/tool_' . $i, 'Search content ' . $i, [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']],
                'required' => ['query'], 'additionalProperties' => false,
            ], $metadata[$i] ?? []);
        }
        $config->setFunctionDeclarations($functions);
        $config->setCustomOptions($options);
        $model->setConfig($config);
        return $model;
    }

    /**
     * Provides a simple prompt.
     *
     * @return list<Message> The prompt.
     */
    private function prompt(): array
    {
        return [new Message(MessageRoleEnum::user(), [new MessagePart('Find posts')])];
    }

    /**
     * Provides documented hosted search item shapes.
     *
     * @return list<array<string, mixed>> The search items.
     */
    private function searchItems(): array
    {
        return [
            ['type' => 'tool_search_call', 'execution' => 'server', 'call_id' => null,
                'status' => 'completed', 'arguments' => ['paths' => ['posts']]],
            ['type' => 'tool_search_output', 'execution' => 'server', 'call_id' => null,
                'status' => 'completed', 'tools' => []],
        ];
    }

    /**
     * Invokes an existing protected method for isolated testing.
     *
     * @param OpenAiTextGenerationModel $model The model.
     * @param string $name The method name.
     * @param list<mixed> $args The arguments.
     * @return mixed The method result.
     */
    private function invoke(OpenAiTextGenerationModel $model, string $name, array $args)
    {
        $method = new ReflectionMethod($model, $name);
        $method->setAccessible(true);
        return $method->invokeArgs($model, $args);
    }
}
