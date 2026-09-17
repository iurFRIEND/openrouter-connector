<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCP\TaskProcessing\TaskTypes\TextToText;

/**
 * Free prompt
 */
class TextToTextProvider extends AbstractTextProvider {
	#[\Override]
	public function getTaskTypeId(): string {
		return TextToText::ID;
	}

	#[\Override]
	public function process(?string $userId, array $input, callable $reportProgress): array {
		$prompt = $this->requireString($input, 'input');
		$output = $this->complete(null, $prompt, $this->optionalInt($input, 'max_tokens'));
		return ['output' => $this->ensureOutput($output)];
	}
}
