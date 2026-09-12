<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Codex;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

/**
 * Text generation model for ChatGPT Codex using the Codex Responses endpoint.
 *
 * @since n.e.x.t
 */
class CodexTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface
{
    private const REQUEST_TIMEOUT_FLOOR = 300.0;
    private const CONNECT_TIMEOUT_FLOOR = 120.0;

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $request = new Request(
            HttpMethodEnum::POST(),
            CodexProvider::url('responses'),
            ['Content-Type' => 'application/json'],
            $this->prepareGenerateTextParams($prompt),
            $this->getCodexRequestOptions()
        );

        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->shouldUseNativeCodexStreaming()
            ? $this->sendStreamingCodexRequest($request)
            : $this->getHttpTransporter()->send($request);
        $this->throwIfCodexResponseFailed($response);
        ResponseUtil::throwIfNotSuccessful($response);

        return $this->parseResponseToResult($response);
    }

    private function getCodexRequestOptions(): RequestOptions
    {
        $current = $this->getRequestOptions();
        $options = new RequestOptions();

        $timeout = $current ? $current->getTimeout() : null;
        $timeout = max($timeout ?? 0.0, self::REQUEST_TIMEOUT_FLOOR);
        $options->setTimeout($timeout);

        $connectTimeout = $current ? $current->getConnectTimeout() : null;
        $connectTimeoutFloor = min(self::CONNECT_TIMEOUT_FLOOR, $timeout);
        $options->setConnectTimeout(max($connectTimeout ?? 0.0, $connectTimeoutFloor));

        $maxRedirects = $current ? $current->getMaxRedirects() : null;
        if ($maxRedirects !== null) {
            $options->setMaxRedirects($maxRedirects);
        }

        return $options;
    }

    /**
     * Whether Codex should bypass the buffered php-ai-client transport for SSE.
     *
     * @since n.e.x.t
     *
     * @return bool True when native WordPress/runtime cURL streaming is available.
     */
    private function shouldUseNativeCodexStreaming(): bool
    {
        return defined('ABSPATH') && function_exists('curl_init');
    }

    /**
     * Sends a Codex request with cURL so required SSE responses are consumed incrementally.
     *
     * @since n.e.x.t
     *
     * @param Request $request Authenticated Codex HTTP request.
     * @return Response Buffered response containing the SSE frames received.
     *
     * @throws ResponseException When the cURL request fails before a Codex response is available.
     */
    private function sendStreamingCodexRequest(Request $request): Response
    {
        $curl = curl_init($request->getUri());
        if ($curl === false) {
            throw new ResponseException('Unable to initialize ChatGPT Codex streaming request.');
        }

        $body = (string) ($request->getBody() ?? '');
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = $name . ': ' . $value;
            }
        }

        /** @var array<string, list<string>> $responseHeaders */
        $responseHeaders = [];
        $responseBody = '';
        $receivedDone = false;
        $options = $request->getOptions() ?: $this->getCodexRequestOptions();

        $method = $request->getMethod()->value ?: 'POST';
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
        $caBundlePath = $this->getWordPressCaBundlePath();
        if ($caBundlePath !== null && $caBundlePath !== '') {
            curl_setopt($curl, CURLOPT_CAINFO, $caBundlePath);
        }
        curl_setopt(
            $curl,
            CURLOPT_WRITEFUNCTION,
            static function ($curlHandle, string $chunk) use (&$responseBody, &$receivedDone): int {
                $responseBody .= $chunk;
                if (strpos($responseBody, 'data: [DONE]') !== false) {
                    $receivedDone = true;
                    return 0;
                }

                return strlen($chunk);
            }
        );
        curl_setopt(
            $curl,
            CURLOPT_HEADERFUNCTION,
            static function ($curlHandle, string $headerLine) use (&$responseHeaders): int {
                $trimmed = trim($headerLine);
                if ($trimmed === '') {
                    return strlen($headerLine);
                }
                if (stripos($trimmed, 'HTTP/') === 0) {
                    $responseHeaders = [];
                    return strlen($headerLine);
                }

                $separator = strpos($trimmed, ':');
                if ($separator === false) {
                    return strlen($headerLine);
                }

                $name = substr($trimmed, 0, $separator);
                $value = trim(substr($trimmed, $separator + 1));
                if ($name !== '') {
                    $responseHeaders[$name][] = $value;
                }

                return strlen($headerLine);
            }
        );

        $timeout = $options->getTimeout();
        if ($timeout !== null) {
            curl_setopt($curl, CURLOPT_TIMEOUT, (int) ceil($timeout));
        }
        $connectTimeout = $options->getConnectTimeout();
        if ($connectTimeout !== null) {
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, (int) ceil($connectTimeout));
        }

        $success = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.curl_closeDeprecated

        if ($success === false && !$receivedDone) {
            throw new ResponseException(
                sprintf('ChatGPT Codex streaming request failed: cURL error %d: %s', $errno, $error)
            );
        }

        if ($statusCode < 100 || $statusCode >= 600) {
            throw new ResponseException('ChatGPT Codex streaming request did not return a valid HTTP response.');
        }

        $headers = [];
        foreach ($responseHeaders as $name => $values) {
            if (is_string($name)) {
                $headers[$name] = $values;
            }
        }

        return new Response($statusCode, $headers, $responseBody === '' ? null : $responseBody);
    }

    /**
     * Gets WordPress' bundled certificate authority file for sandboxed cURL runtimes.
     *
     * @since n.e.x.t
     *
     * @return string|null CA bundle path when available.
     */
    private function getWordPressCaBundlePath(): ?string
    {
        if (!defined('ABSPATH')) {
            return null;
        }

        $rawAbspath = constant('ABSPATH');
        $rawWpIncludes = defined('WPINC') ? constant('WPINC') : 'wp-includes';
        $abspath = is_scalar($rawAbspath) ? (string) $rawAbspath : '';
        $wpIncludes = is_scalar($rawWpIncludes) ? (string) $rawWpIncludes : 'wp-includes';
        $path = rtrim($abspath, '/\\') . '/' . $wpIncludes . '/certificates/ca-bundle.crt';

        return is_readable($path) ? $path : null;
    }

    /**
     * Prepares the request payload.
     *
     * @since n.e.x.t
     *
     * @param list<Message> $prompt Prompt messages.
     * @return array<string, mixed> Request payload.
     */
    private function prepareGenerateTextParams(array $prompt): array
    {
        $config = $this->getConfig();
        $params = [
            'model' => $this->metadata()->getId(),
            'input' => $this->prepareInput($prompt),
            'instructions' => $config->getSystemInstruction() ?: 'You are a concise coding assistant.',
            'store' => false,
            'stream' => true,
        ];

        $maxTokens = $config->getMaxTokens();
        if ($maxTokens !== null) {
            $params['max_output_tokens'] = $maxTokens;
        }

        $temperature = $config->getTemperature();
        if ($temperature !== null) {
            $params['temperature'] = $temperature;
        }

        $topP = $config->getTopP();
        if ($topP !== null) {
            $params['top_p'] = $topP;
        }

        if ($config->getOutputMimeType() === 'application/json' && $config->getOutputSchema()) {
            $params['text'] = [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'response_schema',
                    'schema' => $config->getOutputSchema(),
                    'strict' => true,
                ],
            ];
        }

        $functionDeclarations = $config->getFunctionDeclarations();
        if (is_array($functionDeclarations)) {
            $params['tools'] = $this->prepareToolsParam($functionDeclarations);
        }

        foreach ($config->getCustomOptions() as $key => $value) {
            if (isset($params[$key])) {
                throw new InvalidArgumentException(
                    sprintf('The custom option "%s" conflicts with an existing Codex request parameter.', $key)
                );
            }
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Converts prompt messages to Codex Responses input items.
     *
     * @since n.e.x.t
     *
     * @param list<Message> $messages Prompt messages.
     * @return list<array<string, mixed>> Input items.
     */
    private function prepareInput(array $messages): array
    {
        $this->validateMessages($messages);

        $input = [];
        foreach ($messages as $message) {
            $inputItem = $this->getMessageInputItem($message);
            if ($inputItem !== null) {
                $input[] = $inputItem;
            }
        }

        return $input;
    }

    /**
     * Validates Responses API message shape.
     *
     * @since n.e.x.t
     *
     * @param list<Message> $messages Prompt messages.
     * @return void
     */
    private function validateMessages(array $messages): void
    {
        foreach ($messages as $message) {
            $parts = $message->getParts();
            if (count($parts) <= 1) {
                continue;
            }

            foreach ($parts as $part) {
                $type = $part->getType();
                if ($type->isFunctionCall() || $type->isFunctionResponse()) {
                    throw new InvalidArgumentException(
                        'Function call and function response parts must be the only part in a message for the Codex '
                        . 'Responses API.'
                    );
                }
            }
        }
    }

    /**
     * Converts a message to a Codex Responses input item.
     *
     * @since n.e.x.t
     *
     * @param Message $message Prompt message.
     * @return array<string, mixed>|null Input item, or null for an empty message.
     */
    private function getMessageInputItem(Message $message): ?array
    {
        $parts = $message->getParts();
        if (empty($parts)) {
            return null;
        }

        $content = [];
        foreach ($parts as $part) {
            $partData = $this->getMessagePartData($part, $message->getRole());
            $partType = $partData['type'] ?? '';
            if ($partType === 'function_call' || $partType === 'function_call_output') {
                return $partData;
            }
            $content[] = $partData;
        }

        return [
            'role' => $this->roleToResponsesRole($message->getRole()),
            'content' => $content,
        ];
    }

    /**
     * Converts a message part to a Codex Responses input part.
     *
     * @since n.e.x.t
     *
     * @param MessagePart     $part Message part.
     * @param MessageRoleEnum $role Message role.
     * @return array<string, mixed> Input part.
     */
    private function getMessagePartData(MessagePart $part, MessageRoleEnum $role): array
    {
        $type = $part->getType();
        if ($type->isText()) {
            return [
                'type' => $role->isModel() ? 'output_text' : 'input_text',
                'text' => $part->getText(),
            ];
        }

        if ($type->isFunctionCall()) {
            $functionCall = $part->getFunctionCall();
            if (!$functionCall) {
                throw new InvalidArgumentException(
                    'The function_call typed message part must contain a function call.'
                );
            }

            return [
                'type' => 'function_call',
                'call_id' => $functionCall->getId(),
                'name' => $functionCall->getName(),
                'arguments' => json_encode($functionCall->getArgs()),
            ];
        }

        if ($type->isFunctionResponse()) {
            $functionResponse = $part->getFunctionResponse();
            if (!$functionResponse) {
                throw new InvalidArgumentException(
                    'The function_response typed message part must contain a function response.'
                );
            }

            return [
                'type' => 'function_call_output',
                'call_id' => $functionResponse->getId(),
                'output' => json_encode($functionResponse->getResponse()),
            ];
        }

        throw new InvalidArgumentException(
            'Codex text generation currently supports text and function message parts only.'
        );
    }

    /**
     * Prepares Codex Responses tool declarations.
     *
     * @since n.e.x.t
     *
     * @param list<FunctionDeclaration> $functionDeclarations Function declarations.
     * @return list<array<string, mixed>> Tool declarations.
     */
    private function prepareToolsParam(array $functionDeclarations): array
    {
        $tools = [];
        foreach ($functionDeclarations as $functionDeclaration) {
            $tools[] = [
                'type' => 'function',
                'name' => $functionDeclaration->getName(),
                'description' => $functionDeclaration->getDescription(),
                'parameters' => $functionDeclaration->getParameters(),
            ];
        }

        return $tools;
    }

    /**
     * Converts a client role to a Responses API role.
     *
     * @since n.e.x.t
     *
     * @param MessageRoleEnum $role Message role.
     * @return string Responses API role.
     */
    private function roleToResponsesRole(MessageRoleEnum $role): string
    {
        return $role->isModel() ? 'assistant' : 'user';
    }

    /**
     * Parses an API response into a result.
     *
     * @since n.e.x.t
     *
     * @param Response $response API response.
     * @return GenerativeAiResult Result.
     */
    private function parseResponseToResult(Response $response): GenerativeAiResult
    {
        $body = (string) $response->getBody();
        if (strpos($body, 'data: ') !== false) {
            $data = $this->parseSseResponse($body);
        } else {
            $data = $response->getData();
        }

        if (!is_array($data)) {
            $data = [];
        }

        $candidates = [];
        if (isset($data['output']) && is_array($data['output'])) {
            foreach ($data['output'] as $index => $outputItem) {
                if (!is_array($outputItem)) {
                    continue;
                }
                /** @var array<string, mixed> $outputItem */
                $candidate = $this->parseOutputItemToCandidate($outputItem, (int) $index);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        if (
            empty($candidates) &&
            isset($data['output_text']) &&
            is_string($data['output_text']) &&
            $data['output_text'] !== ''
        ) {
            $candidates[] = new Candidate(
                new ModelMessage([new MessagePart($data['output_text'])]),
                FinishReasonEnum::stop()
            );
        }

        if (empty($candidates)) {
            $failureMessage = $this->responseFailureMessage($data);
            if ($failureMessage !== null) {
                throw new ResponseException('Unexpected ChatGPT Codex API response: ' . $failureMessage);
            }

            throw ResponseException::fromMissingData('ChatGPT Codex', 'output');
        }

        $usage = isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : [];
        $inputTokens = $this->getIntegerValue($usage['input_tokens'] ?? null);
        $outputTokens = $this->getIntegerValue($usage['output_tokens'] ?? null);

        return new GenerativeAiResult(
            isset($data['id']) && is_string($data['id']) ? $data['id'] : '',
            $candidates,
            new TokenUsage(
                $inputTokens,
                $outputTokens,
                $this->getIntegerValue($usage['total_tokens'] ?? null, $inputTokens + $outputTokens)
            ),
            $this->providerMetadata(),
            $this->metadata(),
            $data
        );
    }

    /**
     * Parses one Codex Responses output item into a candidate.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $outputItem Output item.
     * @param int                  $index      Output index.
     * @return Candidate|null Candidate, or null for unsupported output items.
     */
    private function parseOutputItemToCandidate(array $outputItem, int $index): ?Candidate
    {
        $type = $outputItem['type'] ?? '';
        if ($type === 'function_call') {
            return $this->parseFunctionCallOutputToCandidate($outputItem, $index);
        }

        if ($type !== 'message') {
            return null;
        }

        $parts = [];
        if (isset($outputItem['content']) && is_array($outputItem['content'])) {
            foreach ($outputItem['content'] as $content) {
                if (!is_array($content)) {
                    continue;
                }
                if (
                    in_array(($content['type'] ?? ''), ['output_text', 'text'], true) &&
                    isset($content['text']) &&
                    is_string($content['text']) &&
                    $content['text'] !== ''
                ) {
                    $parts[] = new MessagePart($content['text']);
                }
            }
        }

        if (empty($parts)) {
            return null;
        }

        return new Candidate(new ModelMessage($parts), FinishReasonEnum::stop());
    }

    /**
     * Parses one Codex function_call output item into a tool-call candidate.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $outputItem Function call output item.
     * @param int                  $index      Output index.
     * @return Candidate Tool-call candidate.
     */
    private function parseFunctionCallOutputToCandidate(array $outputItem, int $index): Candidate
    {
        if (!isset($outputItem['call_id']) || !is_string($outputItem['call_id'])) {
            throw ResponseException::fromMissingData('ChatGPT Codex', "output[{$index}].call_id");
        }
        if (!isset($outputItem['name']) || !is_string($outputItem['name'])) {
            throw ResponseException::fromMissingData('ChatGPT Codex', "output[{$index}].name");
        }

        $args = null;
        if (isset($outputItem['arguments']) && is_string($outputItem['arguments'])) {
            $decoded = json_decode($outputItem['arguments'], true);
            if (is_array($decoded) && count($decoded) > 0) {
                $args = $decoded;
            }
        }

        $part = new MessagePart(
            new FunctionCall(
                $outputItem['call_id'],
                $outputItem['name'],
                $args
            )
        );

        return new Candidate(new ModelMessage([$part]), FinishReasonEnum::toolCalls());
    }

    /**
     * Parses a buffered text/event-stream response body.
     *
     * @since n.e.x.t
     *
     * @param string $body Response body.
     * @return array<string, mixed> Parsed response data.
     */
    private function parseSseResponse(string $body): array
    {
        $text = '';
        /** @var array<string, mixed> $completed */
        $completed = [];
        $outputItems = [];
        $eventData = '';
        $lines = preg_split("/\r\n|\n|\r/", $body);

        if ($lines === false) {
            $lines = [];
        }

        foreach ($lines as $line) {
            if (trim($line) === '') {
                $this->consumeSseEventData($eventData, $text, $completed, $outputItems);
                $eventData = '';
                continue;
            }

            if (substr($line, 0, 6) === 'data: ') {
                $eventData .= substr($line, 6);
            }
        }

        $this->consumeSseEventData($eventData, $text, $completed, $outputItems);

        if ($text !== '') {
            $completed['output_text'] = $text;
        }
        if (!empty($outputItems) && empty($completed['output'])) {
            $completed['output'] = array_values($outputItems);
        }

        return $completed;
    }

    /**
     * Consumes one SSE event data payload.
     *
     * @since n.e.x.t
     *
     * @param string $eventData Event data.
     * @param string $text Accumulated text.
     * @param array<string, mixed> $completed Completed response data.
     * @param array<int, array<string, mixed>> $outputItems Accumulated completed output items.
     * @return void
     */
    private function consumeSseEventData(string $eventData, string &$text, array &$completed, array &$outputItems): void
    {
        if ($eventData === '' || $eventData === '[DONE]') {
            return;
        }

        $data = json_decode($eventData, true);
        if (!is_array($data)) {
            return;
        }

        $rawType = $data['type'] ?? '';
        $type = is_scalar($rawType) ? (string) $rawType : '';
        if (
            $type === 'response.output_text.delta' &&
            isset($data['delta']) &&
            is_string($data['delta'])
        ) {
            $text .= $data['delta'];
        } elseif ($type === '' && isset($data['delta']) && is_string($data['delta'])) {
            $text .= $data['delta'];
        }

        if (
            isset($data['text']) &&
            is_string($data['text']) &&
            $data['text'] !== '' &&
            in_array($type, ['response.output_text.done', 'response.output_text.added'], true)
        ) {
            $text .= $data['text'];
        }

        if (
            isset($data['part']) &&
            is_array($data['part']) &&
            in_array($type, ['response.content_part.done', 'response.content_part.added'], true)
        ) {
            $part = $data['part'];
            if (
                in_array(($part['type'] ?? ''), ['output_text', 'text'], true) &&
                isset($part['text']) &&
                is_string($part['text']) &&
                $part['text'] !== ''
            ) {
                $text .= $part['text'];
            }
        }

        if (
            isset($data['response']) &&
            is_array($data['response']) &&
            in_array(
                $type,
                ['response.completed', 'response.failed', 'response.incomplete', 'response.cancelled'],
                true
            )
        ) {
            /** @var array<string, mixed> $response */
            $response = $data['response'];
            $completed = $response;
        }

        if (in_array($type, ['response.output_item.done', 'response.output_item.added'], true)) {
            $item = $data['item'] ?? $data['output_item'] ?? null;
            if (is_array($item)) {
                $index = $this->getIntegerValue($data['output_index'] ?? count($outputItems), count($outputItems));
                /** @var array<string, mixed> $item */
                $outputItems[$index] = $item;
            }
        }
    }

    /**
     * Gets an integer value with a fallback.
     *
     * @since n.e.x.t
     *
     * @param mixed $value Raw value.
     * @param int $fallback Fallback value.
     * @return int Integer value.
     */
    private function getIntegerValue($value, int $fallback = 0): int
    {
        if (!is_numeric($value)) {
            return $fallback;
        }

        return (int) $value;
    }

    /**
     * Throws a Codex-specific error that preserves bounded response details.
     *
     * @since n.e.x.t
     *
     * @param Response $response HTTP response.
     * @return void
     *
     * @throws ResponseException When the HTTP response is not successful.
     */
    private function throwIfCodexResponseFailed(Response $response): void
    {
        if ($response->isSuccessful()) {
            return;
        }

        $message = sprintf('HTTP %d', $response->getStatusCode());
        $data = $response->getData();
        if (is_array($data)) {
            $failureMessage = $this->responseFailureMessage($data);
            if ($failureMessage !== null) {
                $message .= ': ' . $failureMessage;
            }
        }

        $bodyPreview = $this->responseBodyPreview($response);
        if ($bodyPreview !== '') {
            $message .= ': ' . $bodyPreview;
        }

        throw new ResponseException('Unexpected ChatGPT Codex API response: ' . $message);
    }

    /**
     * Builds a bounded single-line response body preview for diagnostics.
     *
     * @since n.e.x.t
     *
     * @param Response $response HTTP response.
     * @return string Sanitized body preview, or empty string.
     */
    private function responseBodyPreview(Response $response): string
    {
        $body = (string) $response->getBody();
        if ($body === '') {
            return '';
        }

        $body = preg_replace('/\s+/', ' ', $body);
        $body = is_string($body) ? trim($body) : '';
        if ($body === '') {
            return '';
        }

        if (strlen($body) > 500) {
            $body = substr($body, 0, 500) . '...';
        }

        return $body;
    }

    /**
     * Builds a useful failure message from a terminal Codex Responses payload.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $data Parsed response data.
     * @return string|null Failure message, or null when the payload is not a failure.
     */
    private function responseFailureMessage(array $data): ?string
    {
        $error = $this->responseErrorData($data['error'] ?? null);
        if ($error !== null) {
            return $error;
        }

        $error = $this->responseErrorData($data['last_error'] ?? null);
        if ($error !== null) {
            return $error;
        }

        $status = isset($data['status']) && is_scalar($data['status']) ? (string) $data['status'] : '';
        if (!in_array($status, ['failed', 'incomplete', 'cancelled'], true)) {
            return null;
        }

        $details = [];
        if (isset($data['incomplete_details']) && is_array($data['incomplete_details'])) {
            foreach (['reason', 'message'] as $field) {
                if (isset($data['incomplete_details'][$field]) && is_scalar($data['incomplete_details'][$field])) {
                    $details[] = (string) $data['incomplete_details'][$field];
                }
            }
        }

        return sprintf(
            'Response finished with status "%s"%s.',
            $status,
            empty($details) ? '' : ': ' . implode('; ', $details)
        );
    }

    /**
     * Formats Codex error data without exposing the raw response body.
     *
     * @since n.e.x.t
     *
     * @param mixed $error Raw error payload.
     * @return string|null Error message, or null when absent.
     */
    private function responseErrorData($error): ?string
    {
        if (!is_array($error)) {
            return null;
        }

        $message = isset($error['message']) && is_scalar($error['message']) ? (string) $error['message'] : '';
        $code = isset($error['code']) && is_scalar($error['code']) ? (string) $error['code'] : '';
        if ($message === '' && $code === '') {
            return null;
        }

        if ($message === '') {
            return sprintf('Codex error "%s".', $code);
        }
        if ($code === '') {
            return $message;
        }

        return sprintf('%s (%s).', $message, $code);
    }
}
