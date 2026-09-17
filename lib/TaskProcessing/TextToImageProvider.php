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
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\TextToImage;

/**
 * Image generation with OpenRouter's dedicated image API
 */
class TextToImageProvider extends AbstractProvider {
	#[\Override]
	protected function getModality(): string {
		return Application::MODALITY_IMAGE;
	}

	#[\Override]
	public function getTaskTypeId(): string {
		return TextToImage::ID;
	}

	#[\Override]
	public function getInputShapeDefaults(): array {
		return ['numberOfImages' => 1];
	}

	#[\Override]
	public function getOptionalInputShape(): array {
		return [
			'aspect_ratio' => new ShapeDescriptor(
				$this->l->t('Aspect ratio'),
				$this->l->t('The aspect ratio of the generated images. "Auto" lets the model provider choose.'),
				EShapeType::Enum,
			),
			'quality' => new ShapeDescriptor(
				$this->l->t('Quality'),
				$this->l->t('The quality of the generated images. Ignored by models without a quality setting.'),
				EShapeType::Enum,
			),
		];
	}

	#[\Override]
	public function getOptionalInputShapeEnumValues(): array {
		return [
			'aspect_ratio' => array_map(
				fn (string $ratio): ShapeEnumValue => new ShapeEnumValue($ratio === 'auto' ? $this->l->t('Auto') : $ratio, $ratio),
				Application::IMAGE_ASPECT_RATIOS,
			),
			'quality' => [
				new ShapeEnumValue($this->l->t('Auto'), 'auto'),
				new ShapeEnumValue($this->l->t('Low'), 'low'),
				new ShapeEnumValue($this->l->t('Medium'), 'medium'),
				new ShapeEnumValue($this->l->t('High'), 'high'),
			],
		];
	}

	#[\Override]
	public function getOptionalInputShapeDefaults(): array {
		return [
			'aspect_ratio' => 'auto',
			'quality' => 'auto',
		];
	}

	#[\Override]
	public function process(?string $userId, array $input, callable $reportProgress): array {
		$prompt = $this->requireString($input, 'input');
		$numberOfImages = $this->optionalInt($input, 'numberOfImages') ?? 1;
		if ($numberOfImages < 1) {
			throw new UserFacingProcessingException('numberOfImages is out of bounds', 0, null, $this->l->t('At least one image has to be generated'));
		}
		if ($numberOfImages > Application::MAX_IMAGES_PER_REQUEST) {
			throw new UserFacingProcessingException('numberOfImages is out of bounds', 0, null, $this->l->t('Cannot generate more than %d images at once', [Application::MAX_IMAGES_PER_REQUEST]));
		}
		$options = [];
		$aspectRatio = $input['aspect_ratio'] ?? 'auto';
		if (is_string($aspectRatio) && $aspectRatio !== 'auto' && in_array($aspectRatio, Application::IMAGE_ASPECT_RATIOS, true)) {
			$options['aspect_ratio'] = $aspectRatio;
		}
		$quality = $input['quality'] ?? 'auto';
		if (is_string($quality) && $quality !== 'auto' && in_array($quality, Application::IMAGE_QUALITIES, true)) {
			$options['quality'] = $quality;
		}

		$this->reportProgress($reportProgress, 0.0);
		$startTime = microtime(true);
		try {
			$images = $this->api->generateImages($this->model, $prompt, $numberOfImages, $options);
		} catch (UserFacingProcessingException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->warning('OpenRouter image generation failed: ' . $e->getMessage(), ['exception' => $e]);
			throw new ProcessingException('OpenRouter image generation failed: ' . $e->getMessage(), 0, $e);
		}
		$this->recordRuntime($startTime);
		return ['images' => array_column($images, 'data')];
	}
}
