<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\TextToTextChat;

class TextToTextChatProvider extends AbstractTextProvider {
	#[\Override]
	public function getTaskTypeId(): string {
		return TextToTextChat::ID;
	}

	#[\Override]
	public function getOptionalInputShape(): array {
		return array_merge(parent::getOptionalInputShape(), [
			'memories' => new ShapeDescriptor(
				$this->l->t('Memories'),
				$this->l->t('Things the assistant remembers from earlier conversations with the user'),
				EShapeType::ListOfTexts,
			),
		]);
	}

	#[\Override]
	public function process(?string $userId, array $input, callable $reportProgress): array {
		$userPrompt = $this->requireString($input, 'input');
		$systemPrompt = $this->requireString($input, 'system_prompt');
		if (!isset($input['history']) || !is_array($input['history'])) {
			throw new ProcessingException('Invalid input: history must be a list');
		}
		$history = array_values(array_filter($input['history'], 'is_string'));
		$systemPrompt = self::appendMemories($systemPrompt, $input['memories'] ?? null);

		$messages = $this->messageBuilder->build($systemPrompt, $userPrompt, $history);
		$maxTokens = $this->optionalInt($input, 'max_tokens');
		$completion = $this->run(fn (): array => $this->api->createChatCompletion($this->model, $messages, $maxTokens));
		return ['output' => $this->ensureOutput($completion['content'])];
	}

	public static function appendMemories(string $systemPrompt, mixed $memories): string {
		if (!is_array($memories)) {
			return $systemPrompt;
		}
		$memories = array_values(array_filter($memories, static fn ($memory): bool => is_string($memory) && trim($memory) !== ''));
		if ($memories === []) {
			return $systemPrompt;
		}
		return $systemPrompt
			. "\n\nYou remember the following things from earlier conversations with the user. Use them as context where relevant, but do not repeat or list them:\n- "
			. implode("\n- ", $memories);
	}
}
