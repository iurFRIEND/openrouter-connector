<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCP\TaskProcessing\TaskTypes\TextToTextHeadline;

class HeadlineProvider extends AbstractTextProvider {
	private const SYSTEM_PROMPT = 'Write a short, concise headline for the text the user provides, in the same language as the text. '
		. 'Return only the headline, without quotes, a trailing period or any additional text.';

	#[\Override]
	public function getTaskTypeId(): string {
		return TextToTextHeadline::ID;
	}

	#[\Override]
	public function process(?string $userId, array $input, callable $reportProgress): array {
		$text = $this->requireString($input, 'input');
		// the beginning of a long text is enough to find a headline
		$text = $this->chunkService->split($text)[0];
		$output = $this->complete(self::SYSTEM_PROMPT, $text, $this->optionalInt($input, 'max_tokens'));
		return ['output' => $this->ensureOutput(trim($output, " \n\r\t\"'"))];
	}
}
