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

/**
 * A provider that sends text prompts to a chat model
 */
abstract class AbstractTextProvider extends AbstractProvider {
	#[\Override]
	protected function getModality(): string {
		return Application::MODALITY_TEXT;
	}

	#[\Override]
	public function getOptionalInputShape(): array {
		return [
			'max_tokens' => new ShapeDescriptor(
				$this->l->t('Maximum output words'),
				$this->l->t('The maximum number of words/tokens that can be generated in the completion.'),
				EShapeType::Number,
			),
		];
	}

	#[\Override]
	public function getOptionalInputShapeDefaults(): array {
		return [
			'max_tokens' => $this->settings->getMaxTokens(),
		];
	}

	/**
	 * One completion of a single prompt
	 *
	 * @param array<string, mixed> $extraParams
	 * @throws ProcessingException
	 */
	protected function complete(?string $systemPrompt, string $userPrompt, ?int $maxTokens, array $extraParams = []): string {
		$messages = $this->messageBuilder->build($systemPrompt, $userPrompt);
		return $this->run(fn (): string => $this->api->createChatCompletion($this->model, $messages, $maxTokens, $extraParams)['content']);
	}

	/**
	 * Runs one prompt per chunk of a long text and joins the outputs. Meant
	 * for tasks whose output is about as long as the input, like rewriting.
	 *
	 * @param callable(string): string $userPromptFactory builds the user prompt of a chunk
	 * @throws ProcessingException
	 */
	protected function completeChunked(string $text, ?string $systemPrompt, callable $userPromptFactory, ?int $maxTokens, callable $reportProgress, string $separator = "\n\n"): string {
		$chunks = $this->chunkService->split($text, true, $maxTokens);
		$outputs = [];
		$count = count($chunks);
		$this->reportProgress($reportProgress, 0.0);
		foreach ($chunks as $index => $chunk) {
			$outputs[] = trim($this->complete($systemPrompt, $userPromptFactory($chunk), $maxTokens));
			$this->reportProgress($reportProgress, ($index + 1) / $count);
		}
		return implode($separator, $outputs);
	}

	/**
	 * Wraps an API call: measures its runtime and turns unexpected errors
	 * into processing exceptions
	 *
	 * @template T
	 * @param callable(): T $call
	 * @return T
	 * @throws ProcessingException
	 */
	protected function run(callable $call) {
		$startTime = microtime(true);
		try {
			$result = $call();
		} catch (UserFacingProcessingException $e) {
			throw $e;
		} catch (ProcessingException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->warning('OpenRouter request failed: ' . $e->getMessage(), ['exception' => $e]);
			throw new ProcessingException('OpenRouter request failed: ' . $e->getMessage(), 0, $e);
		}
		$this->recordRuntime($startTime);
		return $result;
	}

	/**
	 * Fails on empty output, which some models return instead of an error
	 *
	 * @throws ProcessingException
	 */
	protected function ensureOutput(string $output): string {
		if (trim($output) === '') {
			throw new UserFacingProcessingException('Empty completion from OpenRouter', 0, null, $this->l->t('The model returned an empty response'));
		}
		return $output;
	}
}
