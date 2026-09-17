<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCA\OpenRouterConnector\AppInfo\Application;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;

class TranslateProvider extends AbstractTextProvider {
	public const DETECT_LANGUAGE = 'detect_language';

	#[\Override]
	public function getTaskTypeId(): string {
		return TextToTextTranslate::ID;
	}

	#[\Override]
	public function getInputShapeEnumValues(): array {
		$languages = array_map(
			static fn (array $language): ShapeEnumValue => new ShapeEnumValue($language[1], $language[0]),
			Application::LANGUAGE_CODES_AND_ENDONYMS,
		);
		return [
			'origin_language' => array_merge([new ShapeEnumValue($this->l->t('Detect language'), self::DETECT_LANGUAGE)], $languages),
			'target_language' => $languages,
		];
	}

	#[\Override]
	public function getInputShapeDefaults(): array {
		return ['origin_language' => self::DETECT_LANGUAGE];
	}

	#[\Override]
	public function process(?string $userId, array $input, callable $reportProgress): array {
		$text = $this->requireString($input, 'input');
		$targetLanguage = $this->requireString($input, 'target_language');
		$originLanguage = is_string($input['origin_language'] ?? null) ? $input['origin_language'] : self::DETECT_LANGUAGE;
		$systemPrompt = self::buildSystemPrompt($originLanguage, $targetLanguage);
		$output = $this->completeChunked(
			$text,
			$systemPrompt,
			static fn (string $chunk): string => $chunk,
			$this->optionalInt($input, 'max_tokens'),
			$reportProgress,
		);
		return ['output' => $this->ensureOutput($output)];
	}

	public static function buildSystemPrompt(string $originLanguage, string $targetLanguage): string {
		$names = array_column(Application::LANGUAGE_CODES_AND_ENDONYMS, 1, 0);
		$target = $names[$targetLanguage] ?? $targetLanguage;
		$origin = $originLanguage === self::DETECT_LANGUAGE ? null : ($names[$originLanguage] ?? $originLanguage);
		return 'You are a professional translator. Translate the text the user provides '
			. ($origin !== null ? 'from ' . $origin . ' ' : '')
			. 'into ' . $target . '. Preserve the meaning, tone, formatting, names and numbers. '
			. 'Return only the translation, without any explanation or additional text.';
	}
}
