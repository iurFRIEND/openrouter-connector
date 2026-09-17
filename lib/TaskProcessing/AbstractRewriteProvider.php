<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

/**
 * A task that rewrites the input text chunk by chunk with a fixed instruction
 */
abstract class AbstractRewriteProvider extends AbstractTextProvider {
	/**
	 * The instruction for the model
	 *
	 * @param array<string, mixed> $input the task input, for tasks with additional parameters
	 */
	abstract protected function getSystemPrompt(array $input): string;

	#[\Override]
	public function process(?string $userId, array $input, callable $reportProgress): array {
		$text = $this->requireString($input, 'input');
		$output = $this->completeChunked(
			$text,
			$this->getSystemPrompt($input),
			static fn (string $chunk): string => $chunk,
			$this->optionalInt($input, 'max_tokens'),
			$reportProgress,
		);
		return ['output' => $this->ensureOutput($output)];
	}
}
