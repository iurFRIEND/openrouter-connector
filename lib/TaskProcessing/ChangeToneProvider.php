<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\TextToTextChangeTone;

class ChangeToneProvider extends AbstractRewriteProvider {
	private const TONES = [
		'less wordy', 'simpler', 'more convincing', 'less buzzwords', 'friendlier', 'more formal',
		'more urgent', 'funnier', 'more passionate', 'less emotional', 'more casual',
	];

	#[\Override]
	public function getTaskTypeId(): string {
		return TextToTextChangeTone::ID;
	}

	#[\Override]
	public function getInputShapeEnumValues(): array {
		return [
			'tone' => [
				new ShapeEnumValue($this->l->t('Less wordy'), 'less wordy'),
				new ShapeEnumValue($this->l->t('Simpler'), 'simpler'),
				new ShapeEnumValue($this->l->t('More convincing'), 'more convincing'),
				new ShapeEnumValue($this->l->t('Less buzzwords'), 'less buzzwords'),
				new ShapeEnumValue($this->l->t('Friendlier'), 'friendlier'),
				new ShapeEnumValue($this->l->t('More formal'), 'more formal'),
				new ShapeEnumValue($this->l->t('More urgent'), 'more urgent'),
				new ShapeEnumValue($this->l->t('Funnier'), 'funnier'),
				new ShapeEnumValue($this->l->t('More passionate'), 'more passionate'),
				new ShapeEnumValue($this->l->t('Less emotional'), 'less emotional'),
				new ShapeEnumValue($this->l->t('More casual'), 'more casual'),
			],
		];
	}

	#[\Override]
	public function getInputShapeDefaults(): array {
		return ['tone' => 'less wordy'];
	}

	#[\Override]
	protected function getSystemPrompt(array $input): string {
		$tone = is_string($input['tone'] ?? null) && in_array($input['tone'], self::TONES, true) ? $input['tone'] : 'less wordy';
		return 'Rewrite the text the user provides so that it sounds ' . $tone . ', in its original language and keeping its meaning. '
			. 'Return only the rewritten text, without any commentary.';
	}
}
