<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCP\TaskProcessing\TaskTypes\TextToTextReformatParagraphs;

class ReformatParagraphsProvider extends AbstractRewriteProvider {
	#[\Override]
	public function getTaskTypeId(): string {
		return TextToTextReformatParagraphs::ID;
	}

	#[\Override]
	protected function getSystemPrompt(array $input): string {
		return 'Reformat the text the user provides into well-structured paragraphs. Insert paragraph breaks where the topic changes '
			. 'and add punctuation and capitalization where missing, but do not change, add or remove any words. '
			. 'Return only the reformatted text, without any commentary.';
	}
}
