<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCP\TaskProcessing\TaskTypes\GenerateEmoji;

class EmojiProvider extends AbstractTextProvider {
	private const SYSTEM_PROMPT = 'Reply with a single emoji that best represents the text the user provides. Output only the emoji, nothing else.';

	#[\Override]
	public function getTaskTypeId(): string {
		return GenerateEmoji::ID;
	}

	#[\Override]
	public function process(?string $userId, array $input, callable $reportProgress): array {
		$text = $this->chunkService->split($this->requireString($input, 'input'))[0];
		$output = $this->complete(self::SYSTEM_PROMPT, $text, $this->optionalInt($input, 'max_tokens'));
		return ['output' => $this->ensureOutput(trim($output))];
	}
}
