<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCP\TaskProcessing\TaskTypes\TextToTextTopics;

class TopicsProvider extends AbstractTextProvider {
	private const SYSTEM_PROMPT = 'Extract the main topics of the text the user provides as a comma-separated list of short keywords, '
		. 'in the same language as the text. Return only the comma-separated list, without numbering or additional text.';

	#[\Override]
	public function getTaskTypeId(): string {
		return TextToTextTopics::ID;
	}

	#[\Override]
	public function process(?string $userId, array $input, callable $reportProgress): array {
		$text = $this->requireString($input, 'input');
		$maxTokens = $this->optionalInt($input, 'max_tokens');
		$chunks = $this->chunkService->split($text);
		$topics = [];
		$this->reportProgress($reportProgress, 0.0);
		foreach ($chunks as $index => $chunk) {
			foreach (explode(',', $this->complete(self::SYSTEM_PROMPT, $chunk, $maxTokens)) as $topic) {
				$topic = trim($topic, " \n\r\t.\"'");
				if ($topic !== '' && !in_array(mb_strtolower($topic), array_map('mb_strtolower', $topics), true)) {
					$topics[] = $topic;
				}
			}
			$this->reportProgress($reportProgress, ($index + 1) / count($chunks));
		}
		return ['output' => $this->ensureOutput(implode(', ', $topics))];
	}
}
