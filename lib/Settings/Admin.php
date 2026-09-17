<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Settings;

use OCA\OpenRouterConnector\AppInfo\Application;
use OCA\OpenRouterConnector\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

class Admin implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private SettingsService $settings,
		private IAppManager $appManager,
		private IURLGenerator $urlGenerator,
	) {
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		$config = $this->settings->getAdminConfig();
		$config['assistant_enabled'] = $this->appManager->isEnabledForUser('assistant');
		$config['instance_url'] = $this->urlGenerator->getAbsoluteURL('/');
		$this->initialState->provideInitialState('admin-config', $config);
		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	/**
	 * The server's "Artificial Intelligence" section
	 */
	#[\Override]
	public function getSection(): string {
		return 'ai';
	}

	#[\Override]
	public function getPriority(): int {
		return 20;
	}
}
