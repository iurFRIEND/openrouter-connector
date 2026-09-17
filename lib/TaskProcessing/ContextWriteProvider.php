<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCP\TaskProcessing\TaskTypes\ContextWrite;

/**
 * Writes about the source material in the style of an example text
 */
class ContextWriteProvider extends AbstractTextProvider {
	private const SYSTEM_PROMPT = 'You are a professional copywriter. The user gives you a style example and source material. '
		. 'Write a new text that conveys the content of the source material while exactly imitating the writing style of the example: '
		. 'its tone, sentence structure, vocabulary and formatting. Write in the language of the source material. '
		. 'Return only the resulting text.';

	#[\Override]
	public function getTaskTypeId(): string {
		return ContextWrite::ID;
	}

	#[\Override]
	public function process(?string $userId, array $input, callable $reportProgress): array {
		$style = $this->requireString($input, 'style_input');
		$source = $this->requireString($input, 'source_input');
		$maxTokens = $this->optionalInt($input, 'max_tokens');
		$output = $this->completeChunked(
			$source,
			self::SYSTEM_PROMPT,
			static fn (string $chunk): string => "Style example:\n\n" . $style . "\n\nSource material:\n\n" . $chunk,
			$maxTokens,
			$reportProgress,
		);
		return ['output' => $this->ensureOutput($output)];
	}
}
