<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\TaskTypes\ImageToTextOpticalCharacterRecognition;

/**
 * Extracts the text of images and PDF documents with a vision model
 */
class ImageToTextOcrProvider extends AnalyzeImagesProvider {
	private const SYSTEM_PROMPT = 'Extract all text visible in the file the user provides. Preserve the reading order, line breaks and paragraphs. '
		. 'Return only the extracted text, without any commentary. If the file contains no text, return an empty response.';

	#[\Override]
	public function getTaskTypeId(): string {
		return ImageToTextOpticalCharacterRecognition::ID;
	}

	#[\Override]
	public function process(?string $userId, array $input, callable $reportProgress): array {
		if (!isset($input['input']) || !is_array($input['input']) || $input['input'] === []) {
			throw new ProcessingException('Invalid input: input must be a non-empty list of files');
		}
		$files = array_values($input['input']);
		$maxTokens = $this->optionalInt($input, 'max_tokens');
		$outputs = [];
		$this->reportProgress($reportProgress, 0.0);
		foreach ($files as $index => $file) {
			$parts = $this->buildFileParts([$file]);
			$messages = $this->messageBuilder->build(self::SYSTEM_PROMPT, 'Extract all text from this file.', null, null, $parts);
			$completion = $this->run(fn (): array => $this->api->createChatCompletion($this->model, $messages, $maxTokens));
			$outputs[] = trim($completion['content']);
			$this->reportProgress($reportProgress, ($index + 1) / count($files));
		}
		return ['output' => $outputs];
	}
}
