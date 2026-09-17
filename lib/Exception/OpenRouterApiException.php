<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Exception;

use OCP\TaskProcessing\Exception\UserFacingProcessingException;

/**
 * A failed request to the OpenRouter API.
 *
 * It is a task processing exception so that providers can let it propagate
 * as-is: the message is logged by the server and the user-facing message is
 * shown to the user who scheduled the task.
 */
class OpenRouterApiException extends UserFacingProcessingException {
	public function __construct(
		string $message = '',
		int $code = 0,
		?\Throwable $previous = null,
		?string $userFacingMessage = null,
		private int $statusCode = 0,
	) {
		parent::__construct($message, $code, $previous, $userFacingMessage);
	}

	/**
	 * The HTTP status code of the OpenRouter response, 0 if there was none
	 */
	public function getStatusCode(): int {
		return $this->statusCode;
	}
}
