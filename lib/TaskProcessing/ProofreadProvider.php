<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCP\TaskProcessing\TaskTypes\TextToTextProofread;

class ProofreadProvider extends AbstractRewriteProvider {
	#[\Override]
	public function getTaskTypeId(): string {
		return TextToTextProofread::ID;
	}

	#[\Override]
	protected function getSystemPrompt(array $input): string {
		return 'Proofread the text the user provides. List every spelling, grammar and punctuation mistake as a bullet point '
			. 'that quotes the mistake and gives the correction, in the language of the text. '
			. 'If the text has no mistakes, say so in one sentence. Return only this list, without repeating the whole text.';
	}
}
