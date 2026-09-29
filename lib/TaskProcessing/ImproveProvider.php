<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCP\TaskProcessing\TaskTypes\TextToTextImprove;

/**
 * Improves a text following the user's instructions. The task type exists
 * since Nextcloud 35, whose Assistant offers it instead of reformulation,
 * formalization and simplification.
 */
class ImproveProvider extends AbstractRewriteProvider {
	/** Used when the user leaves the instructions empty */
	private const DEFAULT_INSTRUCTIONS = 'Correct spelling, grammar and punctuation, and make the text clearer and easier to read.';

	#[\Override]
	public function getTaskTypeId(): string {
		return TextToTextImprove::ID;
	}

	#[\Override]
	protected function getSystemPrompt(array $input): string {
		$instructions = trim($this->requireString($input, 'instructions'));
		if ($instructions === '') {
			$instructions = self::DEFAULT_INSTRUCTIONS;
		}
		return 'Improve the text the user provides according to the following instructions. '
			. 'Keep the language of the text unless the instructions ask for another one. '
			. 'Return only the improved text, without any commentary.'
			. "\n\nInstructions:\n" . $instructions;
	}
}
