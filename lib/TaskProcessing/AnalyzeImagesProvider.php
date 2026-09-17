<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCA\OpenRouterConnector\AppInfo\Application;
use OCP\Files\File;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use OCP\TaskProcessing\TaskTypes\AnalyzeImages;

/**
 * Answers a question about images with a vision model
 */
class AnalyzeImagesProvider extends AbstractTextProvider {
	private const SYSTEM_PROMPT = 'Answer the user\'s question based on the provided images. Answer in the language of the question.';

	#[\Override]
	public function getTaskTypeId(): string {
		return AnalyzeImages::ID;
	}

	#[\Override]
	public function process(?string $userId, array $input, callable $reportProgress): array {
		$prompt = $this->requireString($input, 'input');
		if (!isset($input['images']) || !is_array($input['images']) || $input['images'] === []) {
			throw new ProcessingException('Invalid input: images must be a non-empty list of files');
		}
		$parts = $this->buildFileParts($input['images']);
		$messages = $this->messageBuilder->build(self::SYSTEM_PROMPT, $prompt, null, null, $parts);
		$maxTokens = $this->optionalInt($input, 'max_tokens');
		$completion = $this->run(fn (): array => $this->api->createChatCompletion($this->model, $messages, $maxTokens));
		return ['output' => $this->ensureOutput($completion['content'])];
	}

	/**
	 * @param array<mixed> $files
	 * @return list<array<string, mixed>>
	 * @throws ProcessingException
	 */
	protected function buildFileParts(array $files): array {
		$totalSize = 0;
		$parts = [];
		foreach ($files as $file) {
			if (!$file instanceof File || !$file->isReadable()) {
				throw new ProcessingException('Invalid input: not a readable file');
			}
			$totalSize += (int)$file->getSize();
			if ($totalSize > Application::MAX_INPUT_FILE_SIZE) {
				throw new UserFacingProcessingException(
					'Input files too large',
					0,
					null,
					$this->l->t('The total size of the input files is too large. A maximum of 50 MB is allowed.'),
				);
			}
			$parts[] = $this->messageBuilder->buildFilePart($file);
		}
		return $parts;
	}
}
