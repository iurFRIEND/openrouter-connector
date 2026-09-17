<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCA\OpenRouterConnector\AppInfo\Application;
use OCA\OpenRouterConnector\Service\ChatMessageBuilder;
use OCA\OpenRouterConnector\Service\ChunkService;
use OCA\OpenRouterConnector\Service\OpenRouterApiService;
use OCA\OpenRouterConnector\Service\SettingsService;
use OCP\IL10N;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\ISynchronousProvider;
use Psr\Log\LoggerInterface;

/**
 * What every provider of this app shares: it serves one task type with one
 * OpenRouter model selected by the admin.
 */
abstract class AbstractProvider implements ISynchronousProvider {
	public function __construct(
		protected OpenRouterApiService $api,
		protected SettingsService $settings,
		protected ChunkService $chunkService,
		protected ChatMessageBuilder $messageBuilder,
		protected IL10N $l,
		protected LoggerInterface $logger,
		protected string $model,
	) {
	}

	/**
	 * The modality whose runtime estimate this provider feeds and reads
	 */
	abstract protected function getModality(): string;

	/**
	 * The ID is derived from the model and the task type alone, so that the
	 * preferences the admin configured in the AI settings survive restarts and
	 * updates. Model IDs contain slashes and colons, which are replaced.
	 */
	#[\Override]
	public function getId(): string {
		return Application::APP_ID . '-' . self::slugifyModel($this->model) . '-' . self::slugifyTaskType($this->getTaskTypeId());
	}

	/**
	 * Providers are named after their model, so the admin can tell them apart
	 */
	#[\Override]
	public function getName(): string {
		// TRANSLATORS %s is the name of an AI model, for example "OpenAI: GPT-5 Mini"
		return $this->l->t('%s (OpenRouter)', [$this->settings->getModelName($this->model)]);
	}

	public function getModel(): string {
		return $this->model;
	}

	#[\Override]
	public function getExpectedRuntime(): int {
		return $this->settings->getExpectedRuntime($this->getModality());
	}

	#[\Override]
	public function getInputShapeEnumValues(): array {
		return [];
	}

	#[\Override]
	public function getInputShapeDefaults(): array {
		return [];
	}

	#[\Override]
	public function getOptionalInputShape(): array {
		return [];
	}

	#[\Override]
	public function getOptionalInputShapeEnumValues(): array {
		return [];
	}

	#[\Override]
	public function getOptionalInputShapeDefaults(): array {
		return [];
	}

	#[\Override]
	public function getOutputShapeEnumValues(): array {
		return [];
	}

	#[\Override]
	public function getOptionalOutputShape(): array {
		return [];
	}

	#[\Override]
	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	public static function slugifyModel(string $model): string {
		return preg_replace('/[^A-Za-z0-9._-]/', '_', $model) ?? $model;
	}

	/**
	 * The "core:" prefix of the server's task types is dropped, other prefixes
	 * are kept to avoid collisions between task types of different apps
	 */
	public static function slugifyTaskType(string $taskTypeId): string {
		return str_starts_with($taskTypeId, 'core:') ? substr($taskTypeId, 5) : $taskTypeId;
	}

	/**
	 * Reports the progress and stops when the task was cancelled
	 *
	 * @throws ProcessingException
	 */
	protected function reportProgress(callable $reportProgress, float $progress): void {
		if ($reportProgress(min(1.0, max(0.0, $progress))) === false) {
			throw new ProcessingException('The task was cancelled');
		}
	}

	/**
	 * Feeds the duration of a finished request into the runtime estimate
	 */
	protected function recordRuntime(float $startTime): void {
		$this->settings->updateExpectedRuntime($this->getModality(), (int)round(microtime(true) - $startTime));
	}

	/**
	 * @param array<string, mixed> $input
	 * @throws ProcessingException
	 */
	protected function requireString(array $input, string $key): string {
		if (!isset($input[$key]) || !is_string($input[$key])) {
			throw new ProcessingException('Invalid input: ' . $key . ' must be a string');
		}
		return $input[$key];
	}

	/**
	 * @param array<string, mixed> $input
	 */
	protected function optionalInt(array $input, string $key): ?int {
		$value = $input[$key] ?? null;
		if (is_int($value)) {
			return $value;
		}
		if (is_float($value) || (is_string($value) && is_numeric($value))) {
			return (int)$value;
		}
		return null;
	}
}
