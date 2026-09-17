<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCA\OpenRouterConnector\AppInfo\Application;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use OCP\TaskProcessing\ISynchronousWatermarkingProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\TextToSpeech;

/**
 * Speech generation with OpenRouter's dedicated text-to-speech API
 */
class TextToSpeechProvider extends AbstractProvider implements ISynchronousWatermarkingProvider {
	#[\Override]
	protected function getModality(): string {
		return Application::MODALITY_TTS;
	}

	#[\Override]
	public function getTaskTypeId(): string {
		return TextToSpeech::ID;
	}

	#[\Override]
	public function getOptionalInputShape(): array {
		return [
			'voice' => new ShapeDescriptor(
				$this->l->t('Voice'),
				$this->l->t('The voice to use'),
				$this->getVoices() !== [] ? EShapeType::Enum : EShapeType::Text,
			),
			'speed' => new ShapeDescriptor(
				$this->l->t('Speed'),
				$this->l->t('Speech speed modifier, 1 is normal speed. Ignored by models without a speed setting.'),
				EShapeType::Number,
			),
		];
	}

	#[\Override]
	public function getOptionalInputShapeEnumValues(): array {
		$voices = $this->getVoices();
		if ($voices === []) {
			return [];
		}
		return [
			'voice' => array_map(static fn (string $voice): ShapeEnumValue => new ShapeEnumValue($voice, $voice), $voices),
		];
	}

	#[\Override]
	public function getOptionalInputShapeDefaults(): array {
		return [
			'voice' => $this->getDefaultVoice(),
			'speed' => 1,
		];
	}

	#[\Override]
	public function process(?string $userId, array $input, callable $reportProgress, bool $includeWatermark = true): array {
		$text = $this->requireString($input, 'input');
		if (trim($text) === '') {
			throw new UserFacingProcessingException('Empty input', 0, null, $this->l->t('The text to read must not be empty'));
		}
		if ($includeWatermark) {
			$text .= "\n\n" . $this->l->t('This was generated using Artificial Intelligence.');
		}
		$voice = is_string($input['voice'] ?? null) ? trim($input['voice']) : '';
		if ($voice === '') {
			$voice = $this->getDefaultVoice();
		}
		$speed = 1.0;
		if (isset($input['speed']) && is_numeric($input['speed'])) {
			$speed = max(0.25, min(4.0, (float)$input['speed']));
		}

		$this->reportProgress($reportProgress, 0.0);
		$startTime = microtime(true);
		try {
			$speech = $this->api->createSpeech($this->model, $text, $voice !== '' ? $voice : null, $speed);
		} catch (UserFacingProcessingException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->warning('OpenRouter speech generation failed: ' . $e->getMessage(), ['exception' => $e]);
			throw new ProcessingException('OpenRouter speech generation failed: ' . $e->getMessage(), 0, $e);
		}
		$this->recordRuntime($startTime);
		return ['speech' => $speech['body']];
	}

	/**
	 * The voices the model offers, as far as the catalog knows
	 *
	 * @return list<string>
	 */
	private function getVoices(): array {
		return $this->settings->getModelVoices($this->model);
	}

	/**
	 * The configured default voice if the model offers it (or the catalog
	 * does not know the model's voices), otherwise the model's first voice
	 */
	private function getDefaultVoice(): string {
		$voices = $this->getVoices();
		$configured = $this->settings->getTtsVoice();
		if ($voices === [] || in_array($configured, $voices, true)) {
			return $configured;
		}
		return $voices[0];
	}
}
