<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Controller;

use OCA\DAVC\BackgroundJob\MigrationJob;
use OCA\DAVC\Service\MigrationService;
use OCA\DAVC\Service\Native\NativeContactsService;
use OCA\DAVC\Service\Native\NativeEventsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;

class MigrationController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MigrationService $migrationService,
		private readonly NativeEventsService $nativeEventsService,
		private readonly NativeContactsService $nativeContactsService,
		private readonly IJobList $jobList,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * handles requests to list a user's native calendars/address books, as
	 * migration source/target candidates
	 *
	 * @param string $resource 'event' or 'contact'
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/migration/native/collections')]
	public function nativeCollections(string $resource): DataResponse {

		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		try {
			$collections = $resource === MigrationService::RESOURCE_CONTACT
				? $this->nativeContactsService->collectionList($this->userId)
				: $this->nativeEventsService->collectionList($this->userId);

			$data = array_map(static fn (array $collection) => [
				'id' => $collection['id'],
				'label' => $collection['label'],
				'readOnly' => $collection['readOnly'],
			], $collections);

			return new DataResponse($data);
		} catch (\Throwable $th) {
			return new DataResponse($th->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}

	/**
	 * handles migration start requests
	 *
	 * @param int $sid service id
	 * @param string $resource 'event' or 'contact'
	 * @param string $direction 'externalToInternal' or 'internalToExternal'
	 * @param string $source
	 * @param string $target
	 * @param bool $overwrite whether to overwrite existing objects with matching uid
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/migration/start')]
	public function start(int $sid, string $resource, string $direction, string $source, string $target, bool $overwrite = false): DataResponse {

		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$job = $this->findJobForService($sid);
		if ($job !== null) {
			$state = $job->getArgument();
			if ($state['status'] === MigrationService::STATUS_RUNNING) {
				return new DataResponse('a migration is already running for this service', Http::STATUS_CONFLICT);
			}
			// if a finished migration for this service is still lingering; clear it before starting a new one
			$this->jobList->removeById($job->getId());
		}

		try {
			$state = $this->migrationService->start($this->userId, $sid, $resource, $direction, $source, $target, $overwrite);
			$this->jobList->add(MigrationJob::class, $state);
			return new DataResponse($state);
		} catch (\Throwable $th) {
			return new DataResponse($th->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}

	/**
	 * handles migration status requests
	 *
	 * @param int $sid service id
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/migration/status')]
	public function status(int $sid): DataResponse {

		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$job = $this->findJobForService($sid);
		if ($job === null) {
			return new DataResponse(null);
		}

		return new DataResponse($job->getArgument());
	}

	/**
	 * handles migration cancellation requests
	 *
	 * @param int $sid service id
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/migration/cancel')]
	public function cancel(int $sid): DataResponse {

		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$job = $this->findJobForService($sid);
		if ($job === null) {
			return new DataResponse('migration not found', Http::STATUS_NOT_FOUND);
		}

		$state = $job->getArgument();
		if ($state['status'] === MigrationService::STATUS_RUNNING) {
			$this->jobList->removeById($job->getId());
			$state['status'] = MigrationService::STATUS_CANCELLED;
			$state['updatedAt'] = time();
			$this->jobList->add(MigrationJob::class, $state);
		}

		return new DataResponse($state);
	}

	/**
	 * handles migration dismissal requests, clearing a finished migration's state
	 *
	 * @param int $sid service id
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/migration/dismiss')]
	public function dismiss(int $sid): DataResponse {

		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$job = $this->findJobForService($sid);
		if ($job !== null) {
			$this->jobList->removeById($job->getId());
		}

		return new DataResponse('success');
	}

	private function findJobForService(int $sid): ?IJob {
		foreach ($this->jobList->getJobsIterator(MigrationJob::class, null, 0) as $job) {
			$state = $job->getArgument();
			if (is_array($state) && ($state['sid'] ?? null) === $sid && ($state['uid'] ?? null) === $this->userId) {
				return $job;
			}
		}

		return null;
	}

}
