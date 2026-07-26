<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\BackgroundJob;

use OCA\DAVC\AppInfo\Application;
use OCA\DAVC\Service\MigrationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

class MigrationJob extends QueuedJob {

	private const TIME_BUDGET_SECONDS = 300;

	public function __construct(
		ITimeFactory $time,
		private readonly IJobList $jobList,
		private readonly MigrationService $migrationService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param array<string, mixed> $argument
	 */
	#[\Override]
	protected function run($argument): void {
		if (!is_array($argument) || !isset($argument['sid'])) {
			$this->logger->warning(Application::APP_TAG . ' - MigrationJob invoked without a valid state argument');
			return;
		}

		try {
			$state = $this->migrationService->process($argument, self::TIME_BUDGET_SECONDS);
		} catch (Throwable $e) {
			$this->logger->error(Application::APP_TAG . ' - Migration for service ' . $argument['sid'] . ' crashed: ' . $e->getMessage(), ['exception' => $e]);
			$argument['status'] = MigrationService::STATUS_FAILED;
			$argument['lastError'] = $e->getMessage();
			$state = $argument;
		}

		$this->jobList->add(self::class, $state);
	}

}
