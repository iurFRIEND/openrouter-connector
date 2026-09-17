<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],
		['name' => 'config#setApiKey', 'url' => '/admin-config/api-key', 'verb' => 'PUT'],
		['name' => 'config#refreshModelMetadata', 'url' => '/admin-config/refresh-metadata', 'verb' => 'POST'],
		['name' => 'openRouter#getModels', 'url' => '/models/{modality}', 'verb' => 'GET'],
		['name' => 'openRouter#getKeyInfo', 'url' => '/key-info', 'verb' => 'GET'],
	],
];
