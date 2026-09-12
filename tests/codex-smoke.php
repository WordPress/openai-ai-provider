<?php

declare(strict_types=1);

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\OpenAiAiProvider\Codex\CodexOAuthClient;
use WordPress\OpenAiAiProvider\Codex\CodexProvider;
use WordPress\OpenAiAiProvider\Codex\CodexRequestAuthentication;
use WordPress\OpenAiAiProvider\Codex\CodexTokenStore;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/autoload.php';

$codexSmokeTokens = [
    'access_token' => 'expired-access-token',
    'refresh_token' => 'test-refresh-token',
    'expires_at' => time() - 3600,
    'account_id' => 'test-account-id',
    'fedramp' => true,
];
$codexSmokeRefreshCalls = 0;
$codexSmokeUpdatedTokens = null;
$codexSmokeAutoload = null;
$codexSmokeRequestTimeouts = [];
$codexSmokeRequestConnectTimeouts = [];

function get_option(string $option, $default = false)
{
    global $codexSmokeTokens;

    if ($option !== 'ai_provider_openai_codex_oauth_tokens') {
        return $default;
    }

    return $codexSmokeTokens;
}

function update_option(string $option, $value, $autoload = null): bool
{
    global $codexSmokeAutoload, $codexSmokeTokens, $codexSmokeUpdatedTokens;

    assert($option === 'ai_provider_openai_codex_oauth_tokens');
    $codexSmokeTokens = $value;
    $codexSmokeUpdatedTokens = $value;
    $codexSmokeAutoload = $autoload;

    return true;
}

function wp_remote_post(string $url, array $args = [])
{
    global $codexSmokeRefreshCalls;

    ++$codexSmokeRefreshCalls;

    assert($url === 'https://auth.openai.com/oauth/token');
    assert(($args['body']['grant_type'] ?? null) === 'refresh_token');
    assert(($args['body']['client_id'] ?? null) === 'app_EMoamEEZ73f0CkXaXp7hrann');
    assert(($args['body']['refresh_token'] ?? null) === 'test-refresh-token');

    return [
        'response' => ['code' => 200],
        'body' => json_encode(
            [
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'fresh-refresh-token',
                'expires_in' => 3600,
            ]
        ),
    ];
}

function wp_remote_retrieve_response_code($response): int
{
    return (int) ($response['response']['code'] ?? 0);
}

function wp_remote_retrieve_body($response): string
{
    return (string) ($response['body'] ?? '');
}

function is_wp_error($response): bool
{
    return false;
}

$registry = new ProviderRegistry();
$registry->setHttpTransporter(
    new class implements HttpTransporterInterface {
        public function send(Request $request, ?RequestOptions $options = null): Response
        {
            global $codexSmokeRequestConnectTimeouts, $codexSmokeRequestTimeouts;

            $requestOptions = $request->getOptions();

            assert($request->getUri() === 'https://chatgpt.com/backend-api/codex/responses');
            assert(
                in_array(
                    $request->getHeaderAsString('Authorization'),
                    ['Bearer fresh-access-token', 'Bearer env-access-token'],
                    true
                )
            );
            assert(
                in_array(
                    $request->getHeaderAsString('ChatGPT-Account-ID'),
                    ['test-account-id', 'env-account-id'],
                    true
                )
            );
            assert($request->getHeaderAsString('X-OpenAI-Fedramp') === 'true');
            assert($requestOptions instanceof RequestOptions);
            $codexSmokeRequestTimeouts[] = $requestOptions->getTimeout();
            $codexSmokeRequestConnectTimeouts[] = $requestOptions->getConnectTimeout();

            $data = $request->getData();
            assert(is_array($data));
            assert(($data['model'] ?? null) === 'gpt-5.5');
            assert(($data['store'] ?? null) === false);
            assert(($data['stream'] ?? null) === true);
            assert(isset($data['instructions']) && is_string($data['instructions']));

            $inputText = '';
            foreach (($data['input'] ?? []) as $inputItem) {
                foreach (($inputItem['content'] ?? []) as $contentItem) {
                    if (is_array($contentItem) && isset($contentItem['text']) && is_string($contentItem['text'])) {
                        $inputText .= $contentItem['text'];
                    }
                }
            }

            if (strpos($inputText, 'completed text item') !== false) {
                $body = implode(
                    "\n\n",
                    [
                        'data: ' . json_encode(
                            [
                                'type' => 'response.completed',
                                'response' => [
                                    'id' => 'resp_text_item',
                                    'output' => [
                                        [
                                            'type' => 'message',
                                            'content' => [
                                                [
                                                    'type' => 'text',
                                                    'text' => 'completed text normalized',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ]
                        ),
                        'data: [DONE]',
                    ]
                );

                return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
            }

            if (strpos($inputText, 'done text event') !== false) {
                $body = implode(
                    "\n\n",
                    [
                        'data: ' . json_encode(
                            [
                                'type' => 'response.output_text.done',
                                'text' => 'done text normalized',
                            ]
                        ),
                        'data: ' . json_encode(
                            ['type' => 'response.completed', 'response' => ['id' => 'resp_done_text']]
                        ),
                        'data: [DONE]',
                    ]
                );

                return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
            }

            if (strpos($inputText, 'content part event') !== false) {
                $body = implode(
                    "\n\n",
                    [
                        'data: ' . json_encode(
                            [
                                'type' => 'response.content_part.done',
                                'part' => [
                                    'type' => 'output_text',
                                    'text' => 'content part normalized',
                                ],
                            ]
                        ),
                        'data: ' . json_encode(
                            ['type' => 'response.completed', 'response' => ['id' => 'resp_content_part']]
                        ),
                        'data: [DONE]',
                    ]
                );

                return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
            }

            if (strpos($inputText, 'failed response') !== false) {
                $body = implode(
                    "\n\n",
                    [
                        'data: ' . json_encode(
                            [
                                'type' => 'response.failed',
                                'response' => [
                                    'id' => 'resp_failed',
                                    'status' => 'failed',
                                    'error' => [
                                        'code' => 'codex_failed',
                                        'message' => 'Codex failed while generating a response',
                                    ],
                                ],
                            ]
                        ),
                        'data: [DONE]',
                    ]
                );

                return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
            }

            if (strpos($inputText, 'bad request body') !== false) {
                return new Response(
                    400,
                    ['Content-Type' => 'text/plain'],
                    "Codex says the request payload is invalid\n"
                );
            }

            if (isset($data['tools'])) {
                assert(is_array($data['tools']));
                assert(($data['tools'][0]['type'] ?? null) === 'function');
                assert(($data['tools'][0]['name'] ?? null) === 'workspace_read');
                assert(
                    ($data['tools'][0]['parameters']['properties']['path']['type'] ?? null) === 'string'
                );

                $response = [
                    'id' => 'resp_tool',
                    'output' => [
                        [
                            'type' => 'function_call',
                            'call_id' => 'call_read',
                            'name' => 'workspace_read',
                            'arguments' => '{"path":"README.md"}',
                        ],
                    ],
                    'usage' => [
                        'input_tokens' => 4,
                        'output_tokens' => 5,
                        'total_tokens' => 9,
                    ],
                ];

                $body = strpos($inputText, 'streamed tool item') !== false
                    ? implode(
                        "\n\n",
                        [
                            'data: ' . json_encode(
                                [
                                    'type' => 'response.output_item.done',
                                    'output_index' => 0,
                                    'item' => $response['output'][0],
                                ]
                            ),
                            'data: ' . json_encode(
                                ['type' => 'response.completed', 'response' => ['id' => 'resp_tool_streamed']]
                            ),
                            'data: [DONE]',
                        ]
                    )
                    : implode(
                        "\n\n",
                        [
                            'data: ' . json_encode(['type' => 'response.completed', 'response' => $response]),
                            'data: [DONE]',
                        ]
                    );

                return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
            }

            $body = implode(
                "\n\n",
                [
                    'data: {"type":"response.output_text.delta","delta":"codex "}',
                    'data: {"delta":"smoke"}',
                    'data: ' . json_encode(
                        [
                            'type' => 'response.completed',
                            'response' => [
                                'id' => 'resp_test',
                                'usage' => [
                                    'input_tokens' => 1,
                                    'output_tokens' => 2,
                                    'total_tokens' => 3,
                                ],
                            ],
                        ]
                    ),
                    'data: [DONE]',
                ]
            );

            return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
        }
    }
);

$registry->registerProvider(CodexProvider::class);
$codexTokenStore = new CodexTokenStore();
$registry->setProviderRequestAuthentication(
    'codex',
    new CodexRequestAuthentication($codexTokenStore, new CodexOAuthClient($codexTokenStore))
);

assert($registry->isProviderConfigured('codex') === true);

$model = $registry->getProviderModel('codex', 'gpt-5.5');
$result = $model->generateTextResult([new UserMessage([new MessagePart('hello')])]);

assert($result->toText() === 'codex smoke');
assert($codexSmokeRequestTimeouts[0] === 300.0);
assert($codexSmokeRequestConnectTimeouts[0] === 120.0);
assert($codexSmokeRefreshCalls === 1);
assert(is_array($codexSmokeUpdatedTokens));
assert(($codexSmokeUpdatedTokens['access_token'] ?? null) === 'fresh-access-token');
assert(($codexSmokeUpdatedTokens['refresh_token'] ?? null) === 'fresh-refresh-token');
assert($codexSmokeAutoload === false);

echo "Codex smoke passed.\n";

$toolConfig = new ModelConfig();
$toolConfig->setFunctionDeclarations([
    new FunctionDeclaration(
        'workspace_read',
        'Read a workspace file.',
        [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
            ],
            'required' => ['path'],
        ]
    ),
]);
$model->setConfig($toolConfig);
$toolResult = $model->generateTextResult([new UserMessage([new MessagePart('read README')])]);
$toolCandidate = $toolResult->getCandidates()[0];
$toolCall = $toolCandidate->getMessage()->getParts()[0]->getFunctionCall();

assert($toolCall !== null);
assert($toolCall->getId() === 'call_read');
assert($toolCall->getName() === 'workspace_read');
assert($toolCall->getArgs() === ['path' => 'README.md']);
assert($toolCandidate->getFinishReason()->isToolCalls());

echo "Codex tool call smoke passed.\n";

$textItemResult = $model->generateTextResult([new UserMessage([new MessagePart('completed text item')])]);
assert($textItemResult->toText() === 'completed text normalized');

echo "Codex completed text item smoke passed.\n";

$doneTextResult = $model->generateTextResult([new UserMessage([new MessagePart('done text event')])]);
assert($doneTextResult->toText() === 'done text normalized');

echo "Codex done text event smoke passed.\n";

$contentPartResult = $model->generateTextResult([new UserMessage([new MessagePart('content part event')])]);
assert($contentPartResult->toText() === 'content part normalized');

echo "Codex content part event smoke passed.\n";

$failedResponseMessage = '';
try {
    $model->generateTextResult([new UserMessage([new MessagePart('failed response')])]);
} catch (ResponseException $exception) {
    $failedResponseMessage = $exception->getMessage();
}
assert(strpos($failedResponseMessage, 'Codex failed while generating a response') !== false);
assert(strpos($failedResponseMessage, 'codex_failed') !== false);

echo "Codex failed response smoke passed.\n";

$badRequestMessage = '';
try {
    $model->generateTextResult([new UserMessage([new MessagePart('bad request body')])]);
} catch (ResponseException $exception) {
    $badRequestMessage = $exception->getMessage();
}
assert(strpos($badRequestMessage, 'HTTP 400') !== false);
assert(strpos($badRequestMessage, 'Codex says the request payload is invalid') !== false);

echo "Codex bad request body smoke passed.\n";

$streamedToolResult = $model->generateTextResult([new UserMessage([new MessagePart('streamed tool item')])]);
$streamedToolCall = $streamedToolResult->getCandidates()[0]->getMessage()->getParts()[0]->getFunctionCall();
assert($streamedToolCall !== null);
assert($streamedToolCall->getId() === 'call_read');
assert($streamedToolCall->getName() === 'workspace_read');
assert($streamedToolCall->getArgs() === ['path' => 'README.md']);

echo "Codex streamed tool item smoke passed.\n";

$model->setConfig(new ModelConfig());

$codexSmokeTokens = [];
putenv('AI_PROVIDER_OPENAI_CODEX_ACCESS_TOKEN=env-access-token');
putenv('AI_PROVIDER_OPENAI_CODEX_REFRESH_TOKEN=env-refresh-token');
putenv('AI_PROVIDER_OPENAI_CODEX_EXPIRES_AT=' . (time() + 3600));
putenv('AI_PROVIDER_OPENAI_CODEX_ACCOUNT_ID=env-account-id');
putenv('AI_PROVIDER_OPENAI_CODEX_FEDRAMP=true');

$envTokenStore = new WordPress\OpenAiAiProvider\Codex\CodexTokenStore();
$envTokens = $envTokenStore->getTokens();

assert(($envTokens['access_token'] ?? null) === 'env-access-token');
assert(($envTokens['refresh_token'] ?? null) === 'env-refresh-token');
assert(($envTokens['account_id'] ?? null) === 'env-account-id');
assert(($envTokens['fedramp'] ?? null) === true);

echo "Codex env token smoke passed.\n";

$shortOptions = new RequestOptions();
$shortOptions->setTimeout(15.0);
$shortOptions->setConnectTimeout(15.0);
$model->setRequestOptions($shortOptions);
$result = $model->generateTextResult([new UserMessage([new MessagePart('hello again')])]);

assert($result->toText() === 'codex smoke');
assert($codexSmokeRequestTimeouts[1] === 300.0);
assert($codexSmokeRequestConnectTimeouts[1] === 120.0);

echo "Codex request timeout floor smoke passed.\n";
