<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;

class SummaryProvider extends AbstractTextProvider {
	/** Stop condensing once the summary fits in this many chunks */
	private const MAX_ROUNDS = 5;

	#[\Override]
	public function getTaskTypeId(): string {
		return TextToTextSummary::ID;
	}

	#[\Override]
	public function getOptionalInputShape(): array {
		return array_merge(parent::getOptionalInputShape(), [
			'format' => new ShapeDescriptor(
				$this->l->t('Format'),
				$this->l->t('The format of the summary'),
				EShapeType::Enum,
			),
			'complexity' => new ShapeDescriptor(
				$this->l->t('Complexity'),
				$this->l->t('The complexity of the summary'),
				EShapeType::Enum,
			),
		]);
	}

	#[\Override]
	public function getOptionalInputShapeEnumValues(): array {
		return [
			'format' => [
				new ShapeEnumValue($this->l->t('Auto'), 'auto'),
				new ShapeEnumValue($this->l->t('One sentence'), 'sentence'),
				new ShapeEnumValue($this->l->t('One paragraph'), 'paragraph'),
				new ShapeEnumValue($this->l->t('Bullet points'), 'bullet_points'),
			],
			'complexity' => [
				new ShapeEnumValue($this->l->t('Simple'), 'simple'),
				new ShapeEnumValue($this->l->t('Medium'), 'medium'),
				new ShapeEnumValue($this->l->t('Complex'), 'complex'),
			],
		];
	}

	#[\Override]
	public function getOptionalInputShapeDefaults(): array {
		return array_merge(parent::getOptionalInputShapeDefaults(), [
			'format' => 'auto',
			'complexity' => 'medium',
		]);
	}

	#[\Override]
	public function process(?string $userId, array $input, callable $reportProgress): array {
		$text = $this->requireString($input, 'input');
		$maxTokens = $this->optionalInt($input, 'max_tokens');
		$systemPrompt = self::buildSystemPrompt(
			is_string($input['format'] ?? null) ? $input['format'] : 'auto',
			is_string($input['complexity'] ?? null) ? $input['complexity'] : 'medium',
		);

		// Long texts are summarized chunk by chunk, and the partial summaries
		// are summarized again until the result fits into one chunk
		$chunks = $this->chunkService->split($text);
		$progress = 0.0;
		$this->reportProgress($reportProgress, $progress);
		for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
			$partials = [];
			$increase = (1.0 - $progress) / 2.0 / (float)count($chunks);
			foreach ($chunks as $chunk) {
				$partials[] = trim($this->complete($systemPrompt, $chunk, $maxTokens));
				$progress += $increase;
				$this->reportProgress($reportProgress, $progress);
			}
			$summary = implode("\n\n", $partials);
			if (count($chunks) === 1) {
				return ['output' => $this->ensureOutput($summary)];
			}
			$chunks = $this->chunkService->split($summary);
		}
		return ['output' => $this->ensureOutput($summary)];
	}

	public static function buildSystemPrompt(string $format, string $complexity): string {
		$prompt = 'You are a helpful assistant that summarizes text. Always write the summary in the same language as the text. '
			. 'Return only the summary, without any introduction, headline or additional commentary. ';
		$prompt .= match ($format) {
			'sentence' => 'Write the summary as a single sentence. ',
			'paragraph' => 'Write the summary as one paragraph. ',
			'bullet_points' => 'Write the summary as a list of bullet points. ',
			default => '',
		};
		$prompt .= match ($complexity) {
			'simple' => 'Use simple language and vocabulary that a child can understand. ',
			'complex' => 'Use precise, technical language appropriate for an expert on the subject. ',
			default => '',
		};
		return trim($prompt);
	}
}
