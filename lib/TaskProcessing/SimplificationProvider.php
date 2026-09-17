<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCP\TaskProcessing\TaskTypes\TextToTextSimplification;

class SimplificationProvider extends AbstractRewriteProvider {
	#[\Override]
	public function getTaskTypeId(): string {
		return TextToTextSimplification::ID;
	}

	#[\Override]
	protected function getSystemPrompt(array $input): string {
		return 'Rewrite the text the user provides in plain, simple language that is easy to understand, in its original language '
			. 'and keeping its meaning. Use short sentences and common words. Return only the rewritten text, without any commentary.';
	}
}
