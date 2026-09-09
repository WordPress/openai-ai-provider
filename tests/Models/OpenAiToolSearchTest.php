<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Tests\Models;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ProviderData;
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
 * Tests provider-owned hosted tool search without a tool-search builder API.
 *
 * @since n.e.x.t
 */
class OpenAiToolSearchTest extends TestCase
{
    /**
     * Tests threshold boundaries, explicit tool choice, and conservative model support.
     *
     * @dataProvider policyProvider
     * @param string $modelId The model ID.
     * @param int $count The number of functions.
     * @param array<string, mixed> $options Provider options.
     * @param bool $expected Whether search is expected with provider-data support.
     */
    public function testAutomaticPolicy(string $modelId, int $count, array $options, bool $expected): void
    {
        $model = $this->model($modelId, $count, $options);
        $original = $model->getConfig()->toArray();
        $params = $this->invoke($model, 'prepareGenerateTextParams', [$this->prompt()]);
        $expected = $expected && class_exists(ProviderData::class);
        $types = array_column($params['tools'], 'type');
        $this->assertSame($expected, in_array('tool_search', $types, true));
        $this->assertCount($count + ($expected ? 1 : 0), $params['tools']);
        foreach ($params['tools'] as $tool) {
            if ($tool['type'] === 'function') {
                $this->assertSame($expected, $tool['defer_loading'] ?? false);
            }
        }
        $this->assertArrayNotHasKey('openai_tool_search_threshold', $params);
        $this->assertSame($original, $model->getConfig()->toArray());
    }

    /**
     * Provides policy cases.
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
            'opt out' => ['gpt-5.4', 11, ['openai_tool_search_threshold' => false], false],
            'raised threshold' => ['gpt-5.4', 11, ['openai_tool_search_threshold' => 20], false],
            'lowered threshold' => ['gpt-5.4', 1, ['openai_tool_search_threshold' => 0], true],
            'zero with zero threshold' => ['gpt-5.4', 0, ['openai_tool_search_threshold' => 0], false],
            'auto choice' => ['gpt-5.4', 11, ['tool_choice' => 'auto'], true],
            'none choice' => ['gpt-5.4', 11, ['tool_choice' => 'none'], false],
            'required choice' => ['gpt-5.4', 11, ['tool_choice' => 'required'], false],
            'forced choice' => ['gpt-5.4', 11, ['tool_choice' => ['type' => 'function', 'name' => 'tool_0']], false],
        ];
    }

    /**
     * Tests invalid provider thresholds fail locally.
     *
     * @dataProvider invalidThresholdProvider
     * @param mixed $threshold The invalid threshold.
     */
    public function testInvalidThreshold($threshold): void
    {
        $this->expectException(InvalidArgumentException::class);
        $model = $this->model('gpt-5.4', 11, ['openai_tool_search_threshold' => $threshold]);
        $this->invoke($model, 'prepareGenerateTextParams', [$this->prompt()]);
    }

    /**
     * Provides invalid thresholds.
     *
     * @return list<array{mixed}> Test cases.
     */
    public static function invalidThresholdProvider(): array
    {
        return [[-1], [true], ['10'], [1.5], [[]]];
    }

    /**
     * Tests built-ins do not count toward the threshold or get deferred.
     */
    public function testWebSearchIsIndependent(): void
    {
        foreach ([10, 11] as $count) {
            $model = $this->model('gpt-5.4', $count);
            $model->getConfig()->setWebSearch(new WebSearch());
            $params = $this->invoke($model, 'prepareGenerateTextParams', [$this->prompt()]);
            $this->assertSame(['type' => 'web_search'], $params['tools'][count($params['tools']) - 1]);
            $this->assertSame(
                $count > 10 && class_exists(ProviderData::class),
                in_array('tool_search', array_column($params['tools'], 'type'), true)
            );
        }
    }

    /**
     * Tests serialization and replay of hosted search, reasoning, and a namespaced mapped function.
     */
    public function testStatelessRoundTrip(): void
    {
        $this->requireProviderData();
        $model = $this->model('gpt-5.4', 11, ['store' => false]);
        $params = $this->invoke($model, 'prepareGenerateTextParams', [$this->prompt()]);
        $safeName = $params['tools'][0]['name'];
        $search = $this->searchItems();
        $reasoning = ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'opaque'];
        $call = [
            'type' => 'function_call', 'call_id' => 'call_1', 'name' => $safeName,
            'namespace' => $safeName, 'arguments' => '{"query":"posts"}',
        ];
        $output = [$search[0], $reasoning, $search[1], $call];
        $response = new Response(200, [], (string) json_encode(['id' => 'resp_1', 'output' => $output]));
        $result = $this->invoke($model, 'parseResponseToGenerativeAiResult', [$response]);
        $this->assertCount(1, $result->getCandidates());
        $this->assertTrue($result->getCandidates()[0]->getFinishReason()->isToolCalls());
        $message = Message::fromArray($result->toMessage()->toArray());
        $parts = $message->getParts();
        $this->assertSame('plugin/tool_0', $parts[count($parts) - 1]->getFunctionCall()->getName());

        // A fresh model proves that replay does not depend on instance-local search state.
        $next = $this->model('gpt-5.4', 11, ['store' => false]);
        $reply = new Message(MessageRoleEnum::user(), [new MessagePart(new FunctionResponse('call_1', null, ['ok']))]);
        $nextParams = $this->invoke($next, 'prepareGenerateTextParams', [[$message, $reply]]);
        // Object key order is immaterial; numeric output-item positions must remain unchanged.
        $this->assertEquals($output, array_slice($nextParams['input'], 0, 4));
        $this->assertSame('function_call_output', $nextParams['input'][4]['type']);
        $this->assertFalse($nextParams['store']);
    }

    /**
     * Tests search-only output is retained without requesting application execution.
     */
    public function testSearchOnlyOutput(): void
    {
        $this->requireProviderData();
        $model = $this->model();
        $response = new Response(200, [], (string) json_encode(['output' => $this->searchItems()]));
        $result = $this->invoke($model, 'parseResponseToGenerativeAiResult', [$response]);
        $this->assertCount(1, $result->getCandidates());
        $this->assertTrue($result->getCandidates()[0]->getFinishReason()->isStop());
        $input = $this->invoke($model, 'prepareInputParam', [$result->toMessages()]);
        $this->assertSame($this->searchItems(), $input);
    }

    /**
     * Tests provider ownership and item-type validation reject foreign or arbitrary replay data.
     */
    public function testForeignProviderDataIsRejected(): void
    {
        $this->requireProviderData();
        $message = new Message(MessageRoleEnum::model(), [
            new MessagePart(new ProviderData('anthropic', $this->searchItems()[0])),
        ]);
        $this->expectException(InvalidArgumentException::class);
        $this->invoke($this->model(), 'prepareInputParam', [[$message]]);
    }

    /**
     * Tests client-executed discovery cannot silently masquerade as hosted search.
     */
    public function testClientSearchResponseIsRejected(): void
    {
        $this->requireProviderData();
        $item = $this->searchItems()[0];
        $item['execution'] = 'client';
        $item['call_id'] = 'client_1';
        $this->expectException(ResponseException::class);
        $response = new Response(200, [], (string) json_encode(['output' => [$item]]));
        $this->invoke($this->model(), 'parseResponseToGenerativeAiResult', [$response]);
    }

    /**
     * Tests arbitrary, unfinished, and user-authored opaque items cannot be replayed.
     *
     * @dataProvider invalidReplayProvider
     * @param array<string, mixed> $item The invalid item.
     * @param bool $userRole Whether the message uses a user role.
     */
    public function testInvalidReplay(array $item, bool $userRole = false): void
    {
        $this->requireProviderData();
        $message = new Message($userRole ? MessageRoleEnum::user() : MessageRoleEnum::model(), [
            new MessagePart(new ProviderData('openai', $item)),
        ]);
        $this->expectException(InvalidArgumentException::class);
        $this->invoke($this->model(), 'prepareInputParam', [[$message]]);
    }

    /**
     * Provides invalid replay cases.
     *
     * @return list<array{array<string, mixed>, bool}> Test cases.
     */
    public static function invalidReplayProvider(): array
    {
        $valid = ['type' => 'tool_search_output', 'execution' => 'server', 'call_id' => null,
            'status' => 'completed', 'tools' => []];
        return [
            [['type' => 'additional_tools', 'role' => 'developer', 'tools' => []], false],
            [array_replace($valid, ['status' => 'in_progress']), false],
            [array_replace($valid, ['tools' => 'invalid']), false],
            [array_replace($valid, ['call_id' => 'client_call']), false],
            [$valid, true],
            [['type' => 'function_call_namespace', 'call_id' => 123], false],
        ];
    }

    /**
     * Tests text/search ordering and preservation across unrelated built-in output items.
     */
    public function testTextAndSearchOrdering(): void
    {
        $this->requireProviderData();
        $search = $this->searchItems();
        $output = [
            ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Before']]],
            $search[0], $search[1], ['type' => 'web_search_call', 'id' => 'ws_1'],
            ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'After']]],
        ];
        $model = $this->model();
        $response = new Response(200, [], (string) json_encode(['output' => $output]));
        $result = $this->invoke($model, 'parseResponseToGenerativeAiResult', [$response]);
        $input = $this->invoke($model, 'prepareInputParam', [$result->toMessages()]);
        $this->assertCount(4, $input);
        $this->assertSame('Before', $input[0]['content'][0]['text']);
        $this->assertSame($search, array_slice($input, 1, 2));
        $this->assertSame('After', $input[3]['content'][0]['text']);
    }

    /**
     * Tests server continuation forwards only the new input supplied by the application.
     */
    public function testPreviousResponseIdRemainsAnOrdinaryCustomOption(): void
    {
        $model = $this->model('gpt-5.4', 11, ['previous_response_id' => 'resp_previous']);
        $params = $this->invoke($model, 'prepareGenerateTextParams', [$this->prompt()]);
        $this->assertSame('resp_previous', $params['previous_response_id']);
        $this->assertCount(1, $params['input']);
        $this->assertSame('input_text', $params['input'][0]['content'][0]['type']);
    }

    /**
     * Creates a model with ordinary SDK function declarations.
     *
     * @param string $id The model ID.
     * @param int $count The number of functions.
     * @param array<string, mixed> $options Provider options.
     * @return OpenAiTextGenerationModel The model.
     */
    private function model(string $id = 'gpt-5.4', int $count = 0, array $options = []): OpenAiTextGenerationModel
    {
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
            ]);
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
     * Skips replay cases on SDK versions without provider data, leaving fallback coverage active.
     */
    private function requireProviderData(): void
    {
        if (!class_exists(ProviderData::class)) {
            $this->markTestSkipped('Requires php-ai-client provider-data support (PR #282).');
        }
    }

    /**
     * Invokes an existing protected model method for isolated testing.
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
