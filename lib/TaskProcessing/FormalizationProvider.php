<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCP\TaskProcessing\TaskTypes\TextToTextFormalization;

class FormalizationProvider extends AbstractRewriteProvider {
	#[\Override]
	public function getTaskTypeId(): string {
		return TextToTextFormalization::ID;
	}

	#[\Override]
	protected function getSystemPrompt(array $input): string {
		return 'Rewrite the text the user provides in a formal, professional register, in its original language and keeping its meaning. '
			. 'Return only the rewritten text, without any commentary.';
	}
}
