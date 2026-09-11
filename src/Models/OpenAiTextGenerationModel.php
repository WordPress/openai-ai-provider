<?php

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Common\Exception\TokenLimitReachedException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
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
use WordPress\AiClient\Tools\DTO\WebSearch;
use WordPress\OpenAiAiProvider\Provider\OpenAiProvider;

/**
 * Class for an OpenAI text generation model using the Responses API.
 *
 * @since 1.0.0
 *
 * @phpstan-type OutputContentData array{
 *     type: string,
 *     text?: string,
 *     call_id?: string,
 *     name?: string,
 *     arguments?: string
 * }
 * @phpstan-type OutputItemData array{
 *     type: string,
 *     id?: string,
 *     encrypted_content?: string,
 *     summary?: list<array<string, mixed>>,
 *     role?: string,
 *     status?: string,
 *     content?: list<OutputContentData>
 * }
 * @phpstan-type OutputTokenDetailsData array{reasoning_tokens?: int}
 * @phpstan-type UsageData array{
 *     input_tokens?: int,
 *     output_tokens?: int,
 *     total_tokens?: int,
 *     output_tokens_details?: OutputTokenDetailsData
 * }
 * @phpstan-type IncompleteDetailsData array{reason?: string}
 * @phpstan-type ResponseData array{
 *     id?: string,
 *     status?: string,
 *     output?: list<OutputItemData>,
 *     output_text?: string,
 *     usage?: UsageData,
 *     incomplete_details?: IncompleteDetailsData|null
 * }
 */
class OpenAiTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface
{
    /**
     * Maps OpenAI-safe function names back to the original client tool names.
     *
     * @var array<string, string>
     */
    private array $openAiFunctionNameMap = [];

    /**
     * Maps original client tool names to OpenAI-safe function names.
     *
     * @var array<string, string>
     */
    private array $clientFunctionNameMap = [];

    private const OPENAI_FUNCTION_NAME_MAX_LENGTH = 64;
    private const OPENAI_FUNCTION_NAME_HASH_LENGTH = 8;

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    final public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $httpTransporter = $this->getHttpTransporter();

        $params = $this->prepareGenerateTextParams($prompt);

        $request = new Request(
            HttpMethodEnum::POST(),
            OpenAiProvider::url('responses'),
            ['Content-Type' => 'application/json'],
            $params,
            $this->getRequestOptions()
        );

        // Add authentication credentials to the request.
        $request = $this->getRequestAuthentication()->authenticateRequest($request);

        // Send and process the request.
        $response = $httpTransporter->send($request);
        ResponseUtil::throwIfNotSuccessful($response);
        return $this->parseResponseToGenerativeAiResult($response);
    }

    /**
     * Prepares the given prompt and the model configuration into parameters for the API request.
     *
     * @since 1.0.0
     *
     * @param list<Message> $prompt The prompt to generate text for. Either a single message or a list of messages
     *                              from a chat.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $config = $this->getConfig();

        $params = [
            'model' => $this->metadata()->getId(),
            'input' => $this->prepareInputParam($prompt),
        ];

        $systemInstruction = $config->getSystemInstruction();
        if ($systemInstruction) {
            $params['instructions'] = $systemInstruction;
        }

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

        $logprobs = $config->getLogprobs();
        if ($logprobs === true) {
            $params['include'] = ['message.output_text.logprobs'];
        }

        $topLogprobs = $config->getTopLogprobs();
        if ($topLogprobs !== null) {
            $params['top_logprobs'] = $topLogprobs;
        }

        // Note: OpenAI does not support top_k parameter.

        $outputMimeType = $config->getOutputMimeType();
        $outputSchema = $config->getOutputSchema();
        if ($outputMimeType === 'application/json' && $outputSchema) {
            $params['text'] = [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'response_schema',
                    'schema' => $outputSchema,
                    'strict' => true,
                ],
            ];
        }

        $functionDeclarations = $config->getFunctionDeclarations();
        $webSearch = $config->getWebSearch();
        $customOptions = $config->getCustomOptions();

        if (is_array($functionDeclarations) || $webSearch) {
            $params['tools'] = $this->prepareToolsParam(
                $functionDeclarations,
                $webSearch
            );
        }

        /*
         * Any custom options are added to the parameters as well.
         * This allows developers to pass other options that may be more niche or not yet supported by the SDK.
         */
        foreach ($customOptions as $key => $value) {
            if (isset($params[$key])) {
                throw new InvalidArgumentException(
                    sprintf(
                        'The custom option "%s" conflicts with an existing parameter.',
                        $key
                    )
                );
            }
            $params[$key] = $value;
        }

        $this->validateSamplingParamsForReasoningEffort($params);

        return $params;
    }

    /**
     * Validates that sampling parameters are not combined with an explicitly enabled reasoning effort.
     *
     * The model metadata advertises support for `temperature`, `top_p`, `logprobs`, and
     * `top_logprobs` for reasoning models that run with `reasoning.effort` set to `none` by
     * default (e.g. `gpt-5.2` and `gpt-5.4`), because the options apply in that default mode.
     * The OpenAI API rejects these parameters as soon as reasoning is enabled, which the
     * metadata cannot express conditionally. Since a reasoning effort can only be requested
     * via the `reasoning` custom option, that combination is caught here before the API request
     * is sent.
     *
     * @since 1.1.0
     *
     * @param array<string, mixed> $params The prepared parameters for the API request.
     * @return void
     * @throws InvalidArgumentException If sampling parameters are combined with an enabled reasoning effort.
     */
    protected function validateSamplingParamsForReasoningEffort(array $params): void
    {
        $reasoning = $params['reasoning'] ?? null;
        if (!is_array($reasoning)) {
            return;
        }

        $effort = $reasoning['effort'] ?? null;
        if (!is_string($effort) || $effort === 'none') {
            return;
        }

        $samplingParams = array_values(
            array_intersect(['temperature', 'top_p', 'top_logprobs'], array_keys($params))
        );
        $include = $params['include'] ?? null;
        if (
            is_array($include)
            && in_array('message.output_text.logprobs', $include, true)
        ) {
            $samplingParams[] = 'logprobs';
        }
        if (!$samplingParams) {
            return;
        }

        throw new InvalidArgumentException(
            sprintf(
                'The parameter(s) "%s" cannot be combined with reasoning effort "%s" for model "%s". '
                    . 'OpenAI Responses only support these sampling options when the reasoning effort is "none".',
                implode('", "', $samplingParams),
                $effort,
                $this->metadata()->getId()
            )
        );
    }

    /**
     * Prepares the input parameter for the API request.
     *
     * @since 1.0.0
     *
     * @param list<Message> $messages The messages to prepare.
     * @return list<array<string, mixed>> The prepared input parameter.
     */
    protected function prepareInputParam(array $messages): array
    {
        $this->validateMessages($messages);

        $input = [];
        foreach ($messages as $message) {
            foreach ($this->getReasoningInputItems($message) as $reasoningItem) {
                $input[] = $reasoningItem;
            }
            $inputItem = $this->getMessageInputItem($message);
            if ($inputItem !== null) {
                $input[] = $inputItem;
            }
        }
        return $input;
    }

    /**
     * Extracts top-level reasoning items from a message's thought-channel parts.
     *
     * @since n.e.x.t
     *
     * @param Message $message The message to inspect.
     * @return list<array<string, mixed>> Reasoning items to send as top-level input.
     */
    protected function getReasoningInputItems(Message $message): array
    {
        $items = [];
        foreach ($message->getParts() as $part) {
            if (!$part->getChannel()->isThought()) {
                continue;
            }
            $signature = $part->getThoughtSignature();
            if (!is_string($signature) || $signature === '') {
                continue;
            }

            $decoded = json_decode($signature, true);
            if (
                !is_array($decoded)
                || !isset($decoded['id'])
                || !is_string($decoded['id'])
                || $decoded['id'] === ''
            ) {
                continue;
            }

            $item = [
                'type' => 'reasoning',
                'id' => $decoded['id'],
            ];
            if (isset($decoded['encrypted_content']) && is_string($decoded['encrypted_content'])) {
                $item['encrypted_content'] = $decoded['encrypted_content'];
            }
            if (isset($decoded['summary']) && is_array($decoded['summary'])) {
                $item['summary'] = $decoded['summary'];
            } else {
                $item['summary'] = [];
            }
            $items[] = $item;
        }
        return $items;
    }

    /**
     * Validates that the messages are appropriate for the OpenAI Responses API.
     *
     * The OpenAI Responses API requires function calls and function responses to be
     * sent as top-level input items rather than nested in message content. As such,
     * they must be the only part in a message.
     *
     * Thought-channel parts are sent as separate top-level `reasoning` items
     * (see {@see self::getReasoningInputItems()}) and skipped here.
     *
     * @since 1.0.0
     *
     * @param list<Message> $messages The messages to validate.
     * @return void
     * @throws InvalidArgumentException If validation fails.
     */
    protected function validateMessages(array $messages): void
    {
        foreach ($messages as $message) {
            $contentParts = [];
            foreach ($message->getParts() as $part) {
                if ($part->getChannel()->isThought()) {
                    continue;
                }
                $contentParts[] = $part;
            }

            if (count($contentParts) <= 1) {
                continue;
            }

            foreach ($contentParts as $part) {
                $type = $part->getType();

                if ($type->isFunctionCall()) {
                    throw new InvalidArgumentException(
                        'Function call parts must be the only part in a message for the OpenAI Responses API.'
                    );
                }

                if ($type->isFunctionResponse()) {
                    throw new InvalidArgumentException(
                        'Function response parts must be the only part in a message for the OpenAI Responses API.'
                    );
                }
            }
        }
    }

    /**
     * Converts a Message object to a Responses API input item.
     *
     * @since 1.0.0
     *
     * @param Message $message The message to convert.
     * @return array<string, mixed>|null The input item, or null if the message is empty.
     */
    protected function getMessageInputItem(Message $message): ?array
    {
        $parts = $message->getParts();

        if (empty($parts)) {
            return null;
        }

        $role = $message->getRole();
        $content = [];
        foreach ($parts as $part) {
            if ($part->getChannel()->isThought()) {
                continue;
            }
            $partData = $this->getMessagePartData($part, $role);

            // Function calls and responses are top-level items, not wrapped in a message.
            // validateMessages() ensures these are the only part in a message.
            $partType = $partData['type'] ?? '';
            if ($partType === 'function_call' || $partType === 'function_call_output') {
                return $partData;
            }

            $content[] = $partData;
        }

        if (empty($content)) {
            return null;
        }

        return [
            'role' => $this->getMessageRoleString($role),
            'content' => $content,
        ];
    }

    /**
     * Returns the OpenAI API specific role string for the given message role.
     *
     * @since 1.0.0
     *
     * @param MessageRoleEnum $role The message role.
     * @return string The role for the API request.
     */
    protected function getMessageRoleString(MessageRoleEnum $role): string
    {
        if ($role === MessageRoleEnum::model()) {
            return 'assistant';
        }
        return 'user';
    }

    /**
     * Returns the OpenAI API specific data for a message part.
     *
     * @since 1.0.0
     *
     * @param MessagePart $part The message part to get the data for.
     * @param MessageRoleEnum $role The role of the message containing the part.
     * @return array<string, mixed> The data for the message part.
     * @throws InvalidArgumentException If the message part type or data is unsupported.
     */
    protected function getMessagePartData(MessagePart $part, MessageRoleEnum $role): array
    {
        $type = $part->getType();
        if ($type->isText()) {
            return [
                'type' => $role->isModel() ? 'output_text' : 'input_text',
                'text' => $part->getText(),
            ];
        }
        if ($type->isFile()) {
            $file = $part->getFile();
            if (!$file) {
                // This should be impossible due to class internals, but still needs to be checked.
                throw new RuntimeException(
                    'The file typed message part must contain a file.'
                );
            }
            if ($file->isRemote()) {
                $fileUrl = $file->getUrl();
                if (!$fileUrl) {
                    // This should be impossible due to class internals, but still needs to be checked.
                    throw new RuntimeException(
                        'The remote file must contain a URL.'
                    );
                }
                if ($file->isImage()) {
                    return [
                        'type' => 'input_image',
                        'image_url' => $fileUrl,
                    ];
                }
                // For other file types, use input_file with URL.
                return [
                    'type' => 'input_file',
                    'file_url' => $fileUrl,
                ];
            }
            // Else, it is an inline file.
            $dataUri = $file->getDataUri();
            if (!$dataUri) {
                // This should be impossible due to class internals, but still needs to be checked.
                throw new RuntimeException(
                    'The inline file must contain base64 data.'
                );
            }
            if ($file->isImage()) {
                return [
                    'type' => 'input_image',
                    'image_url' => $dataUri,
                ];
            }
            // For other file types (like PDF), use input_file.
            return [
                'type' => 'input_file',
                'filename' => 'file',
                'file_data' => $dataUri,
            ];
        }
        if ($type->isFunctionCall()) {
            $functionCall = $part->getFunctionCall();
            if (!$functionCall) {
                throw new RuntimeException(
                    'The function_call typed message part must contain a function call.'
                );
            }
            $functionName = $functionCall->getName();
            if ($functionName === null) {
                throw new RuntimeException(
                    'The function_call typed message part must contain a function name.'
                );
            }
            return [
                'type' => 'function_call',
                'call_id' => $functionCall->getId(),
                'name' => $this->openAiFunctionName($functionName),
                'arguments' => json_encode($functionCall->getArgs()),
            ];
        }
        if ($type->isFunctionResponse()) {
            $functionResponse = $part->getFunctionResponse();
            if (!$functionResponse) {
                throw new RuntimeException(
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
            sprintf(
                'Unsupported message part type "%s".',
                $type
            )
        );
    }

    /**
     * Prepares the tools parameter for the API request.
     *
     * @since 1.0.0
     *
     * @param list<FunctionDeclaration>|null $functionDeclarations The function declarations, or null if none.
     * @param WebSearch|null $webSearch The web search config, or null if none.
     * @return list<array<string, mixed>> The prepared tools parameter.
     */
    protected function prepareToolsParam(
        ?array $functionDeclarations,
        ?WebSearch $webSearch
    ): array {
        $tools = [];
        $this->openAiFunctionNameMap = [];
        $this->clientFunctionNameMap = [];

        if (is_array($functionDeclarations)) {
            foreach ($functionDeclarations as $functionDeclaration) {
                $openAiName = $this->openAiFunctionName($functionDeclaration->getName());
                $tools[] = [
                    'type' => 'function',
                    'name' => $openAiName,
                    'description' => $functionDeclaration->getDescription(),
                    'parameters' => $functionDeclaration->getParameters(),
                ];
            }
        }

        if ($webSearch) {
            $webSearchTool = ['type' => 'web_search'];
            // Note: The OpenAI Responses API web_search tool may have different filtering options.
            // For now, we use the basic form.
            $tools[] = $webSearchTool;
        }

        return $tools;
    }

    /**
     * Parses the response from the API endpoint to a generative AI result.
     *
     * @since 1.0.0
     *
     * @param Response $response The response from the API endpoint.
     * @return GenerativeAiResult The parsed generative AI result.
     */
    protected function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        /** @var ResponseData $responseData */
        $responseData = $response->getData();

        // Check for token limit before processing output. When max_output_tokens is reached, OpenAI returns
        // status 'incomplete' with incomplete_details.reason 'max_output_tokens'. The output array may be
        // empty in this case, so this check must happen before the output validation below.
        $status = $responseData['status'] ?? 'completed';
        if ($status === 'incomplete') {
            $incompleteDetails = $responseData['incomplete_details'] ?? null;
            $reason = is_array($incompleteDetails) ? ($incompleteDetails['reason'] ?? '') : '';
            if ($reason === 'max_output_tokens') {
                $maxTokens = $this->getConfig()->getMaxTokens();
                throw new TokenLimitReachedException(
                    sprintf(
                        'Generation stopped due to token limit with reason "%s".',
                        $reason
                    ),
                    $maxTokens
                );
            }
        }

        if (!isset($responseData['output']) || !$responseData['output']) {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'output');
        }
        if (!is_array($responseData['output']) || !array_is_list($responseData['output'])) {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'output',
                'The value must be an indexed array.'
            );
        }

        $candidates = [];
        $pendingReasoningParts = [];
        foreach ($responseData['output'] as $index => $outputItem) {
            if (!is_array($outputItem) || array_is_list($outputItem)) {
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    "output[{$index}]",
                    'The value must be an associative array.'
                );
            }

            if (($outputItem['type'] ?? '') === 'reasoning') {
                $reasoningPart = $this->parseReasoningOutputToPart($outputItem);
                if ($reasoningPart !== null) {
                    $pendingReasoningParts[] = $reasoningPart;
                }
                continue;
            }

            $candidate = $this->parseOutputItemToCandidate(
                $outputItem,
                $index,
                $responseData['status'] ?? 'completed',
                $pendingReasoningParts
            );
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
            $pendingReasoningParts = [];
        }

        $id = isset($responseData['id']) && is_string($responseData['id']) ? $responseData['id'] : '';

        if (isset($responseData['usage']) && is_array($responseData['usage'])) {
            $usage = $responseData['usage'];
            $tokenUsage = $this->buildTokenUsage($usage);
        } else {
            $tokenUsage = $this->buildTokenUsage([]);
        }

        // Use any other data from the response as provider-specific response metadata.
        $additionalData = $responseData;
        unset($additionalData['id'], $additionalData['output'], $additionalData['usage']);

        return new GenerativeAiResult(
            $id,
            $candidates,
            $tokenUsage,
            $this->providerMetadata(),
            $this->metadata(),
            $additionalData
        );
    }

    /**
     * Parses a single output item from the API response into a Candidate object.
     *
     * @since 1.0.0
     *
     * @param OutputItemData $outputItem The output item data from the API response.
     * @param int $index The index of the output item in the output array.
     * @param string $responseStatus The overall response status.
     * @param list<MessagePart> $reasoningParts Buffered thought-channel parts to attach to this candidate.
     * @return Candidate|null The parsed candidate, or null if the output item should be skipped.
     */
    protected function parseOutputItemToCandidate(
        array $outputItem,
        int $index,
        string $responseStatus,
        array $reasoningParts = []
    ): ?Candidate {
        $type = $outputItem['type'] ?? '';

        // Handle message output type.
        if ($type === 'message') {
            return $this->parseMessageOutputToCandidate($outputItem, $index, $responseStatus, $reasoningParts);
        }

        // Handle function_call output type (top-level function call).
        if ($type === 'function_call') {
            return $this->parseFunctionCallOutputToCandidate($outputItem, $index, $reasoningParts);
        }

        // Skip other output types for now (e.g., image_generation_call is handled in image model).
        return null;
    }

    /**
     * Parses a reasoning output item into a thought-channel MessagePart.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $outputItem The reasoning output item from the API response.
     * @return MessagePart|null The reasoning part, or null if there is nothing to round-trip.
     */
    protected function parseReasoningOutputToPart(array $outputItem): ?MessagePart
    {
        if (
            !isset($outputItem['id'])
            || !is_string($outputItem['id'])
            || $outputItem['id'] === ''
        ) {
            return null;
        }

        $summary = isset($outputItem['summary']) && is_array($outputItem['summary'])
            ? $outputItem['summary']
            : [];

        $signaturePayload = [
            'id' => $outputItem['id'],
            'summary' => $summary,
        ];
        if (isset($outputItem['encrypted_content']) && is_string($outputItem['encrypted_content'])) {
            $signaturePayload['encrypted_content'] = $outputItem['encrypted_content'];
        }

        $signature = json_encode($signaturePayload);
        if ($signature === false) {
            return null;
        }

        $summaryTexts = [];
        foreach ($summary as $summaryItem) {
            if (is_array($summaryItem) && isset($summaryItem['text']) && is_string($summaryItem['text'])) {
                $summaryTexts[] = $summaryItem['text'];
            }
        }

        return new MessagePart(implode("\n", $summaryTexts), MessagePartChannelEnum::thought(), $signature);
    }

    /**
     * Builds a TokenUsage DTO from the API usage block.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $usage The usage block from the API response.
     * @return TokenUsage The token usage DTO.
     */
    protected function buildTokenUsage(array $usage): TokenUsage
    {
        $inputTokens = is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : 0;
        $outputTokens = is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : 0;
        $totalTokens = is_int($usage['total_tokens'] ?? null) ? $usage['total_tokens'] : $inputTokens + $outputTokens;

        $thoughtTokens = null;
        $details = $usage['output_tokens_details'] ?? null;
        if (is_array($details) && isset($details['reasoning_tokens']) && is_int($details['reasoning_tokens'])) {
            $thoughtTokens = $details['reasoning_tokens'];
        }

        return new TokenUsage($inputTokens, $outputTokens, $totalTokens, $thoughtTokens);
    }

    /**
     * Parses a message output item into a Candidate object.
     *
     * @since 1.0.0
     *
     * @param OutputItemData $outputItem The output item data.
     * @param int $index The index of the output item.
     * @param string $responseStatus The overall response status.
     * @param list<MessagePart> $reasoningParts Buffered thought-channel parts to prepend to the candidate message.
     * @return Candidate The parsed candidate.
     */
    protected function parseMessageOutputToCandidate(
        array $outputItem,
        int $index,
        string $responseStatus,
        array $reasoningParts = []
    ): Candidate {
        $role = isset($outputItem['role']) && $outputItem['role'] === 'user'
            ? MessageRoleEnum::user()
            : MessageRoleEnum::model();

        $parts = $reasoningParts;
        $hasFunctionCalls = false;

        if (isset($outputItem['content']) && is_array($outputItem['content'])) {
            foreach ($outputItem['content'] as $contentIndex => $contentItem) {
                try {
                    $part = $this->parseOutputContentToPart($contentItem);
                    if ($part !== null) {
                        $parts[] = $part;
                        if ($part->getType()->isFunctionCall()) {
                            $hasFunctionCalls = true;
                        }
                    }
                } catch (InvalidArgumentException $e) {
                    throw ResponseException::fromInvalidData(
                        $this->providerMetadata()->getName(),
                        "output[{$index}].content[{$contentIndex}]",
                        $e->getMessage()
                    );
                }
            }
        }

        $message = new Message($role, $parts);
        $finishReason = $this->parseStatusToFinishReason($responseStatus, $hasFunctionCalls);

        return new Candidate($message, $finishReason);
    }

    /**
     * Parses a function_call output item into a Candidate object.
     *
     * @since 1.0.0
     *
     * @param OutputItemData $outputItem The output item data.
     * @param int $index The index of the output item.
     * @param list<MessagePart> $reasoningParts Buffered thought-channel parts to prepend to the candidate message.
     * @return Candidate The parsed candidate.
     */
    protected function parseFunctionCallOutputToCandidate(
        array $outputItem,
        int $index,
        array $reasoningParts = []
    ): Candidate {
        if (!isset($outputItem['call_id']) || !is_string($outputItem['call_id'])) {
            throw ResponseException::fromMissingData(
                $this->providerMetadata()->getName(),
                "output[{$index}].call_id"
            );
        }
        if (!isset($outputItem['name']) || !is_string($outputItem['name'])) {
            throw ResponseException::fromMissingData(
                $this->providerMetadata()->getName(),
                "output[{$index}].name"
            );
        }

        /*
         * Parse and normalize function arguments.
         * OpenAI returns arguments as a JSON string. An empty object "{}"
         * decodes to an empty array, which semantically means "no arguments"
         * and should be normalized to null.
         */
        $args = null;
        if (isset($outputItem['arguments']) && is_string($outputItem['arguments'])) {
            $decoded = json_decode($outputItem['arguments'], true);
            if (is_array($decoded) && count($decoded) > 0) {
                $args = $decoded;
            }
        }

        $functionCall = new FunctionCall(
            $outputItem['call_id'],
            $this->originalFunctionName($outputItem['name']),
            $args
        );

        $part = new MessagePart($functionCall);
        $parts = $reasoningParts;
        $parts[] = $part;
        $message = new Message(MessageRoleEnum::model(), $parts);

        return new Candidate($message, FinishReasonEnum::toolCalls());
    }

    /**
     * Converts a PHP AI Client function name to an OpenAI-safe function name.
     *
     * @since 1.0.0
     *
     * @param string $name Original function name.
     * @return string OpenAI-safe function name.
     */
    private function openAiFunctionName(string $name): string
    {
        if (isset($this->clientFunctionNameMap[$name])) {
            return $this->clientFunctionNameMap[$name];
        }

        $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $name) ?? '';
        $safe = trim($safe, '_-');
        if ($safe === '') {
            $safe = 'tool';
        }

        if (
            $safe !== $name ||
            strlen($safe) > self::OPENAI_FUNCTION_NAME_MAX_LENGTH ||
            isset($this->openAiFunctionNameMap[$safe])
        ) {
            $prefixLength = self::OPENAI_FUNCTION_NAME_MAX_LENGTH - self::OPENAI_FUNCTION_NAME_HASH_LENGTH - 1;
            $safe = substr($safe, 0, $prefixLength)
                . '_'
                . substr(sha1($name), 0, self::OPENAI_FUNCTION_NAME_HASH_LENGTH);
        }

        $this->clientFunctionNameMap[$name] = $safe;
        $this->openAiFunctionNameMap[$safe] = $name;

        return $safe;
    }

    /**
     * Restores the original PHP AI Client function name from an OpenAI function call.
     *
     * @since 1.0.0
     *
     * @param string $name OpenAI function name.
     * @return string Original function name when known.
     */
    private function originalFunctionName(string $name): string
    {
        return $this->openAiFunctionNameMap[$name] ?? $name;
    }

    /**
     * Parses an output content item into a MessagePart.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $contentItem The content item data.
     * @return MessagePart|null The parsed message part, or null to skip.
     */
    protected function parseOutputContentToPart(array $contentItem): ?MessagePart
    {
        $type = $contentItem['type'] ?? '';

        if ($type === 'output_text') {
            if (!isset($contentItem['text']) || !is_string($contentItem['text'])) {
                throw new InvalidArgumentException('Content has an invalid output_text shape.');
            }
            return new MessagePart($contentItem['text']);
        }

        if ($type === 'function_call') {
            if (
                !isset($contentItem['call_id']) ||
                !is_string($contentItem['call_id']) ||
                !isset($contentItem['name']) ||
                !is_string($contentItem['name'])
            ) {
                throw new InvalidArgumentException('Content has an invalid function_call shape.');
            }

            /*
             * Parse and normalize function arguments.
             * OpenAI returns arguments as a JSON string. An empty object "{}"
             * decodes to an empty array, which semantically means "no arguments"
             * and should be normalized to null.
             */
            $args = null;
            if (isset($contentItem['arguments']) && is_string($contentItem['arguments'])) {
                $decoded = json_decode($contentItem['arguments'], true);
                if (is_array($decoded) && count($decoded) > 0) {
                    $args = $decoded;
                }
            }

            return new MessagePart(
                new FunctionCall(
                    $contentItem['call_id'],
                    $this->originalFunctionName($contentItem['name']),
                    $args
                )
            );
        }

        // Skip unknown content types.
        return null;
    }

    /**
     * Parses the response status to a finish reason.
     *
     * @since 1.0.0
     *
     * @param string $status The response status.
     * @param bool $hasFunctionCalls Whether the response contains function calls.
     * @return FinishReasonEnum The finish reason.
     */
    protected function parseStatusToFinishReason(string $status, bool $hasFunctionCalls): FinishReasonEnum
    {
        switch ($status) {
            case 'completed':
                return $hasFunctionCalls ? FinishReasonEnum::toolCalls() : FinishReasonEnum::stop();
            case 'incomplete':
                return FinishReasonEnum::length();
            case 'failed':
            case 'cancelled':
                return FinishReasonEnum::error();
            default:
                // Default to stop for unknown statuses.
                return FinishReasonEnum::stop();
        }
    }
}
