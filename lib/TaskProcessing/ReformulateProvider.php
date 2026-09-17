<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCP\TaskProcessing\TaskTypes\TextToTextReformulation;

class ReformulateProvider extends AbstractRewriteProvider {
	#[\Override]
	public function getTaskTypeId(): string {
		return TextToTextReformulation::ID;
	}

	#[\Override]
	protected function getSystemPrompt(array $input): string {
		return 'Reformulate the text the user provides in its original language, keeping its meaning, tone and formatting. '
			. 'Return only the reformulated text, without any commentary.';
	}
}
