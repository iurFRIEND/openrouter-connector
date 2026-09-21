<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Controller;

use OCA\OpenRouterConnector\AppInfo\Application;
use OCA\OpenRouterConnector\Exception\OpenRouterApiException;
use OCA\OpenRouterConnector\Service\ModelCatalogService;
use OCA\OpenRouterConnector\Service\OpenRouterApiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Read access to the OpenRouter API for the admin settings; every method requires an admin
 */
class OpenRouterController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ModelCatalogService $modelCatalog,
		private OpenRouterApiService $api,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The models available for a modality, together with the filters that
	 * narrowed the list down, so the settings can say why a model is missing
	 *
	 * @param string $modality one of text, image, stt, tts
	 * @param bool $refresh whether to bypass the cache
	 */
	public function getModels(string $modality, bool $refresh = false): DataResponse {
		if (!in_array($modality, Application::MODALITIES, true)) {
			return new DataResponse(['error' => 'Unknown modality'], Http::STATUS_BAD_REQUEST);
		}
		try {
			return new DataResponse($this->modelCatalog->getCatalog($modality, $refresh));
		} catch (OpenRouterApiException $e) {
			return new DataResponse(['error' => $e->getUserFacingMessage() ?? $e->getMessage()], $this->errorStatus($e));
		} catch (\Throwable $e) {
			$this->logger->error('Could not load the OpenRouter model catalog: ' . $e->getMessage(), ['exception' => $e]);
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Label, limits and usage of the configured API key; doubles as connection test
	 */
	public function getKeyInfo(): DataResponse {
		try {
			return new DataResponse($this->api->getKeyInfo());
		} catch (OpenRouterApiException $e) {
			return new DataResponse(['error' => $e->getUserFacingMessage() ?? $e->getMessage()], $this->errorStatus($e));
		} catch (\Throwable $e) {
			$this->logger->error('Could not load the OpenRouter key info: ' . $e->getMessage(), ['exception' => $e]);
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Upstream client errors are passed on as such, everything else is a gateway problem
	 */
	private function errorStatus(OpenRouterApiException $e): int {
		$status = $e->getStatusCode();
		if ($status >= 400 && $status < 500) {
			return $status;
		}
		return Http::STATUS_BAD_GATEWAY;
	}
}
