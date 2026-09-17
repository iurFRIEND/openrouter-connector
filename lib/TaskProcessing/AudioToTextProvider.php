<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCA\OpenRouterConnector\AppInfo\Application;
use OCP\Files\File;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\AudioToText;

/**
 * Transcription with OpenRouter's dedicated speech-to-text API
 */
class AudioToTextProvider extends AbstractProvider {
	public const DETECT_LANGUAGE = 'detect_language';

	/** The audio formats the API accepts, by MIME type */
	public const FORMATS_BY_MIME_TYPE = [
		'audio/mpeg' => 'mp3',
		'audio/mp3' => 'mp3',
		'audio/wav' => 'wav',
		'audio/x-wav' => 'wav',
		'audio/wave' => 'wav',
		'audio/flac' => 'flac',
		'audio/x-flac' => 'flac',
		'audio/mp4' => 'm4a',
		'audio/x-m4a' => 'm4a',
		'audio/m4a' => 'm4a',
		'audio/ogg' => 'ogg',
		'audio/webm' => 'webm',
		'video/webm' => 'webm',
		'audio/aac' => 'aac',
		'audio/x-aac' => 'aac',
	];
	public const FORMATS_BY_EXTENSION = ['mp3', 'wav', 'flac', 'm4a', 'ogg', 'webm', 'aac', 'mp4' => 'm4a', 'oga' => 'ogg'];

	#[\Override]
	protected function getModality(): string {
		return Application::MODALITY_STT;
	}

	#[\Override]
	public function getTaskTypeId(): string {
		return AudioToText::ID;
	}

	#[\Override]
	public function getOptionalInputShape(): array {
		return [
			'language' => new ShapeDescriptor(
				$this->l->t('Language'),
				$this->l->t('The language spoken in the audio file'),
				EShapeType::Enum,
			),
		];
	}

	#[\Override]
	public function getOptionalInputShapeEnumValues(): array {
		$languages = array_map(
			static fn (array $language): ShapeEnumValue => new ShapeEnumValue($language[1], $language[0]),
			Application::LANGUAGE_CODES_AND_ENDONYMS,
		);
		return [
			'language' => array_merge([new ShapeEnumValue($this->l->t('Detect language'), self::DETECT_LANGUAGE)], $languages),
		];
	}

	#[\Override]
	public function getOptionalInputShapeDefaults(): array {
		return ['language' => self::DETECT_LANGUAGE];
	}

	#[\Override]
	public function process(?string $userId, array $input, callable $reportProgress): array {
		$file = $input['input'] ?? null;
		if (!$file instanceof File || !$file->isReadable()) {
			throw new ProcessingException('Invalid input: input must be a readable audio file');
		}
		if ((int)$file->getSize() > Application::MAX_INPUT_FILE_SIZE) {
			throw new UserFacingProcessingException('Audio file too large', 0, null, $this->l->t('The audio file is too large. A maximum of 50 MB is allowed.'));
		}
		$format = self::detectFormat($file->getMimeType(), $file->getExtension());
		if ($format === null) {
			throw new UserFacingProcessingException(
				'Unsupported audio type ' . $file->getMimeType(),
				0,
				null,
				$this->l->t('The audio format %s is not supported. Supported are MP3, WAV, FLAC, M4A, OGG, WebM and AAC.', [$file->getMimeType()]),
			);
		}
		$audio = $file->getContent();
		if (!is_string($audio) || $audio === '') {
			throw new ProcessingException('Could not read the audio file');
		}
		$language = $input['language'] ?? self::DETECT_LANGUAGE;
		$language = is_string($language) && $language !== self::DETECT_LANGUAGE && $language !== '' ? $language : null;

		$this->reportProgress($reportProgress, 0.0);
		$startTime = microtime(true);
		try {
			$text = $this->api->transcribe($this->model, $audio, $format, $language);
		} catch (UserFacingProcessingException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->warning('OpenRouter transcription failed: ' . $e->getMessage(), ['exception' => $e]);
			throw new ProcessingException('OpenRouter transcription failed: ' . $e->getMessage(), 0, $e);
		}
		$this->recordRuntime($startTime);
		return ['output' => $text];
	}

	/**
	 * The format name the API expects, null for unsupported files
	 */
	public static function detectFormat(string $mimeType, string $extension): ?string {
		$mimeType = strtolower(explode(';', $mimeType)[0]);
		if (isset(self::FORMATS_BY_MIME_TYPE[$mimeType])) {
			return self::FORMATS_BY_MIME_TYPE[$mimeType];
		}
		$extension = strtolower($extension);
		if (isset(self::FORMATS_BY_EXTENSION[$extension])) {
			return self::FORMATS_BY_EXTENSION[$extension];
		}
		if (in_array($extension, self::FORMATS_BY_EXTENSION, true)) {
			return $extension;
		}
		return null;
	}
}
