<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Controller;

use OCA\OpenRouterConnector\Service\ModelCatalogService;
use OCA\OpenRouterConnector\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The admin settings endpoints; every method requires an admin
 */
class ConfigController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private SettingsService $settings,
		private ModelCatalogService $modelCatalog,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Stores the non-secret settings
	 *
	 * @param array<string, mixed> $values key/value pairs to store
	 */
	public function setAdminConfig(array $values): DataResponse {
		try {
			$selectionChanged = $this->settings->setAdminConfig($values);
		} catch (\Throwable $e) {
			$this->logger->error('Could not store the OpenRouter settings: ' . $e->getMessage(), ['exception' => $e]);
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		if ($selectionChanged) {
			// the names and capabilities of newly selected models are looked up once, here
			$this->modelCatalog->refreshSelectedModelMetadata();
		}
		return new DataResponse($this->settings->getAdminConfig());
	}

	/**
	 * Stores the API key; an empty key removes it
	 */
	#[PasswordConfirmationRequired]
	public function setApiKey(string $apiKey): DataResponse {
		$this->settings->setApiKey($apiKey);
		return new DataResponse($this->settings->getAdminConfig());
	}

	/**
	 * Looks up the names and capabilities of the selected models again
	 */
	public function refreshModelMetadata(): DataResponse {
		$this->modelCatalog->refreshSelectedModelMetadata();
		return new DataResponse($this->settings->getAdminConfig());
	}
}
