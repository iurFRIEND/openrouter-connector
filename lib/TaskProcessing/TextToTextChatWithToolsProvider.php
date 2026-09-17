<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\TaskTypes\TextToTextChatWithTools;

/**
 * Chat with tool calling (function calling), used by the Assistant's agent mode
 */
class TextToTextChatWithToolsProvider extends AbstractTextProvider {
	#[\Override]
	public function getTaskTypeId(): string {
		return TextToTextChatWithTools::ID;
	}

	#[\Override]
	public function process(?string $userId, array $input, callable $reportProgress): array {
		$userPrompt = $this->requireString($input, 'input');
		$systemPrompt = $this->requireString($input, 'system_prompt');
		$toolMessage = $this->requireString($input, 'tool_message');
		$toolsJson = $this->requireString($input, 'tools');
		if (!isset($input['history']) || !is_array($input['history'])) {
			throw new ProcessingException('Invalid input: history must be a list');
		}
		$history = array_values(array_filter($input['history'], 'is_string'));

		$tools = json_decode($toolsJson, true);
		if (!is_array($tools) || !array_is_list($tools)) {
			throw new ProcessingException('Invalid input: tools must be a JSON list');
		}

		$messages = $this->messageBuilder->build(
			$systemPrompt,
			$userPrompt !== '' ? $userPrompt : null,
			$history,
			$toolMessage !== '' ? $toolMessage : null,
		);
		$extraParams = $tools !== [] ? ['tools' => $tools] : [];
		$maxTokens = $this->optionalInt($input, 'max_tokens');
		$completion = $this->run(fn (): array => $this->api->createChatCompletion($this->model, $messages, $maxTokens, $extraParams));

		if ($completion['content'] === '' && $completion['tool_calls'] === []) {
			throw new ProcessingException('Empty completion from OpenRouter: neither content nor tool calls');
		}
		return [
			'output' => $completion['content'],
			'tool_calls' => self::encodeToolCalls($completion['tool_calls']),
		];
	}

	/**
	 * The tool calls in the format the task type expects: a JSON list of
	 * objects with name, id and args, an empty string when there are none
	 *
	 * @param list<array{id: string, name: string, args: array<array-key, mixed>}> $toolCalls
	 */
	public static function encodeToolCalls(array $toolCalls): string {
		if ($toolCalls === []) {
			return '';
		}
		$encoded = json_encode(array_map(static fn (array $toolCall): array => [
			'name' => $toolCall['name'],
			'id' => $toolCall['id'],
			'args' => $toolCall['args'] === [] ? new \stdClass() : $toolCall['args'],
		], $toolCalls));
		return $encoded === false ? '' : $encoded;
	}
}
