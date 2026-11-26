<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Controller;

use OCA\DAVC\Exception\ServiceNotFoundException;
use OCA\DAVC\Service\CoreService;
use OCA\DAVC\Service\HarmonizationService;
use OCA\DAVC\Service\ServicesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * @psalm-api
 */
class UserConfigurationController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CoreService $CoreService,
		private readonly HarmonizationService $HarmonizationService,
		private readonly ServicesService $ServicesService,
		private readonly LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * handles services list request
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/service/list')]
	public function serviceList(): DataResponse {

		// evaluate if user id is present
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		// retrieve services
		try {
			$rs = $this->ServicesService->fetchByUserId($this->userId);
			return new DataResponse($rs);
		} catch (\Throwable $th) {
			return $this->failure($th);
		}

	}

	/**
	 * handles connect click event
	 *
	 * @param array $service collection of configuration options
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/service/connect')]
	public function Connect(array $service): DataResponse {

		// evaluate if user id is present
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		// execute command
		try {
			$entity = $this->CoreService->connectAccount($this->userId, $service);
			return new DataResponse($entity);
		} catch (\InvalidArgumentException|\RuntimeException $th) {
			return new DataResponse($th->getMessage(), Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $th) {
			return $this->failure($th);
		}

	}

	/**
	 * handles disconnect click event
	 *
	 * @param int $sid Service id
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/service/disconnect')]
	public function Disconnect(int $sid): DataResponse {

		// evaluate if user id is present
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		// execute command
		try {
			$this->CoreService->disconnectAccount($this->userId, $sid);
			return new DataResponse('success');
		} catch (\Throwable $th) {
			return $this->failure($th);
		}

	}

	/**
	 * handles synchronize click event
	 *
	 * @param int $sid service id
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/service/harmonize')]
	public function Harmonize(int $sid): DataResponse {

		// evaluate if user id is present
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		// execute command
		try {
			$this->HarmonizationService->performHarmonization($this->userId, $sid, 'M');
			return new DataResponse('success');
		} catch (\Throwable $th) {
			return $this->failure($th);
		}

	}

	/**
	 * handles remote collections fetch requests
	 *
	 * @param int $sid service id
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/remote/collections/fetch')]
	public function remoteCollectionsFetch(int $sid): DataResponse {

		// evaluate if user id is present
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		// retrieve collections
		try {
			$rs = $this->CoreService->remoteCollectionsFetch($this->userId, $sid);
			return new DataResponse($rs);
		} catch (\Throwable $th) {
			return $this->failure($th);
		}
	}

	/**
	 * handles local collections fetch requests
	 *
	 * @param int $sid Service id
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/local/collections/fetch')]
	public function localCollectionsFetch(int $sid): DataResponse {

		// evaluate if user id is present
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		// retrieve collections
		try {
			$rs = $this->CoreService->localCollectionsFetch($this->userId, $sid);
			return new DataResponse($rs);
		} catch (\Throwable $th) {
			return $this->failure($th);
		}

	}

	/**
	 * handles save correlations requests
	 *
	 * @param array $values key/value pairs to save
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/local/collections/deposit')]
	public function localCollectionsDeposit(int $sid, array $ContactCorrelations, array $EventCorrelations): DataResponse {

		// evaluate if user id is present
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		// execute command
		try {
			$this->CoreService->localCollectionsDeposit($this->userId, $sid, $ContactCorrelations, $EventCorrelations);
			return $this->localCollectionsFetch($sid);
		} catch (\Throwable $th) {
			return $this->failure($th);
		}

	}

	/**
	 * Convert an exception to a response without exposing internal details
	 */
	private function failure(\Throwable $th): DataResponse {
		if ($th instanceof ServiceNotFoundException) {
			return new DataResponse('Service not found', Http::STATUS_NOT_FOUND);
		}
		$this->logger->error('Dav Connector request failed', ['app' => $this->appName, 'exception' => $th]);
		return new DataResponse('An internal error occurred', Http::STATUS_INTERNAL_SERVER_ERROR);
	}

}
