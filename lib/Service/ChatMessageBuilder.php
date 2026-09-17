<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Service;

use OCP\Files\File;
use OCP\IL10N;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;

/**
 * Turns task inputs into the message list of an OpenAI compatible chat completion request
 */
class ChatMessageBuilder {
	public const IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
	public const DOCUMENT_MIME_TYPES = ['application/pdf'];

	public function __construct(
		private IL10N $l,
	) {
	}

	/**
	 * @param list<string>|null $history the previous messages as the task processing API delivers them: one JSON object per message
	 * @param string|null $toolMessage the results of the tool calls of the last turn, a JSON list of messages
	 * @param list<array<string, mixed>> $userContentParts content parts (files) attached to the user message
	 * @return list<array<string, mixed>>
	 * @throws UserFacingProcessingException
	 */
	public function build(
		?string $systemPrompt,
		?string $userPrompt,
		?array $history = null,
		?string $toolMessage = null,
		array $userContentParts = [],
	): array {
		$messages = [];
		if ($systemPrompt !== null && $systemPrompt !== '') {
			$messages[] = ['role' => 'system', 'content' => $systemPrompt];
		}
		foreach ($history ?? [] as $entry) {
			$message = $this->normalizeHistoryEntry($entry);
			if ($message !== null) {
				$messages[] = $message;
			}
		}
		if ($userContentParts !== []) {
			$content = [];
			if ($userPrompt !== null && $userPrompt !== '') {
				$content[] = ['type' => 'text', 'text' => $userPrompt];
			}
			array_push($content, ...$userContentParts);
			$messages[] = ['role' => 'user', 'content' => $content];
		} elseif ($userPrompt !== null && $userPrompt !== '') {
			$messages[] = ['role' => 'user', 'content' => $userPrompt];
		}
		if ($toolMessage !== null && $toolMessage !== '') {
			array_push($messages, ...$this->normalizeToolMessages($toolMessage));
		}
		if ($messages === []) {
			throw new UserFacingProcessingException('Empty prompt', 0, null, $this->l->t('The prompt must not be empty'));
		}
		return $messages;
	}

	/**
	 * A content part carrying a file, as image or document
	 *
	 * @return array<string, mixed>
	 * @throws UserFacingProcessingException
	 */
	public function buildFilePart(File $file): array {
		$mimeType = strtolower($file->getMimeType());
		$content = $file->getContent();
		if (!is_string($content)) {
			throw new UserFacingProcessingException('Could not read file ' . $file->getName(), 0, null, $this->l->t('The file %s could not be read', [$file->getName()]));
		}
		$dataUrl = 'data:' . $mimeType . ';base64,' . base64_encode($content);
		if (in_array($mimeType, self::IMAGE_MIME_TYPES, true)) {
			return [
				'type' => 'image_url',
				'image_url' => ['url' => $dataUrl],
			];
		}
		if (in_array($mimeType, self::DOCUMENT_MIME_TYPES, true)) {
			return [
				'type' => 'file',
				'file' => [
					'filename' => $file->getName(),
					'file_data' => $dataUrl,
				],
			];
		}
		throw new UserFacingProcessingException(
			'Unsupported file type ' . $mimeType,
			0,
			null,
			$this->l->t('The file type %s is not supported. Supported are PNG, JPEG, WebP, GIF and PDF.', [$mimeType]),
		);
	}

	/**
	 * Converts one history message from the task processing format to the API format
	 *
	 * @return array<string, mixed>|null
	 * @throws UserFacingProcessingException
	 */
	public function normalizeHistoryEntry(string $entry): ?array {
		$message = json_decode($entry, true);
		if (!is_array($message) || !isset($message['role']) || !is_string($message['role'])) {
			throw new UserFacingProcessingException('Invalid history entry', 0, null, $this->l->t('The chat history is invalid'));
		}
		$role = $message['role'] === 'human' ? 'user' : $message['role'];
		if (!in_array($role, ['user', 'assistant', 'system', 'tool'], true)) {
			return null;
		}
		$normalized = ['role' => $role];

		$content = $message['content'] ?? '';
		if (is_array($content)) {
			// Only text parts can be replayed, attachments of earlier turns are not available anymore
			$texts = [];
			foreach ($content as $part) {
				if (is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
					$texts[] = $part['text'];
				}
			}
			$content = implode("\n", $texts);
		} elseif (!is_string($content)) {
			$content = is_scalar($content) ? (string)$content : '';
		}
		$normalized['content'] = $content;

		if ($role === 'tool' && isset($message['tool_call_id']) && is_string($message['tool_call_id'])) {
			$normalized['tool_call_id'] = $message['tool_call_id'];
		}
		if ($role === 'assistant' && isset($message['tool_calls']) && is_array($message['tool_calls'])) {
			$toolCalls = [];
			foreach ($message['tool_calls'] as $toolCall) {
				if (!is_array($toolCall)) {
					continue;
				}
				$args = $toolCall['args'] ?? [];
				$arguments = json_encode($args === [] ? new \stdClass() : $args);
				$toolCalls[] = [
					'id' => (string)($toolCall['id'] ?? ''),
					'type' => 'function',
					'function' => [
						'name' => (string)($toolCall['name'] ?? ''),
						'arguments' => $arguments === false ? '{}' : $arguments,
					],
				];
			}
			if ($toolCalls !== []) {
				$normalized['tool_calls'] = $toolCalls;
				if ($normalized['content'] === '') {
					$normalized['content'] = null;
				}
			}
		}
		return $normalized;
	}

	/**
	 * @return list<array<string, mixed>>
	 * @throws UserFacingProcessingException
	 */
	private function normalizeToolMessages(string $toolMessage): array {
		$decoded = json_decode($toolMessage, true);
		if (!is_array($decoded)) {
			throw new UserFacingProcessingException('Invalid tool message', 0, null, $this->l->t('The tool message is invalid'));
		}
		// a single message is accepted as well as a list of them
		if (isset($decoded['tool_call_id']) || isset($decoded['content'])) {
			$decoded = [$decoded];
		}
		$messages = [];
		foreach ($decoded as $message) {
			if (!is_array($message)) {
				continue;
			}
			$content = $message['content'] ?? '';
			if (!is_string($content)) {
				$encoded = json_encode($content);
				$content = $encoded === false ? '' : $encoded;
			}
			$normalized = ['role' => 'tool', 'content' => $content];
			if (isset($message['tool_call_id']) && is_string($message['tool_call_id'])) {
				$normalized['tool_call_id'] = $message['tool_call_id'];
			}
			$messages[] = $normalized;
		}
		return $messages;
	}
}
