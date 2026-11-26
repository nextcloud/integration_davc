<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Service;

use OCA\DAVC\Service\Remote\RemoteFactory;
use OCA\DAVC\Store\Local\ServiceEntity;
use Psr\Log\LoggerInterface;

class HarmonizationService {
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ConfigurationService $configurationService,
		private readonly ServicesService $servicesService,
		private readonly ContactsService $contactsService,
		private readonly EventsService $eventsService,
		private readonly RemoteFactory $remoteFactory,
	) {
	}

	/**
	 * perform harmonization for all or specific services of a user
	 *
	 * @param string $uid user id
	 * @param int $sid service id
	 *
	 * @return void
	 */
	public function performHarmonization(string $uid, ?int $sid = null): void {

		if ($sid !== null) {
			// retrieve service
			$services[] = $this->servicesService->fetchByUserIdAndServiceId($uid, $sid);
		} else {
			// retrieve all services
			$services = $this->servicesService->fetchByUserId($uid);
		}

		foreach ($services as $service) {
			$this->performHarmonizationForService($service);
		}

	}

	/**
	 * perform harmonization for all modules of a specific service
	 */
	public function performHarmonizationForService(ServiceEntity $service): void {

		// determine if we should run harmonization
		if (!$service->getEnabled() || !$service->getConnected()) {
			return;
		}
		if (!$this->configurationService->isTransportPermitted($service->getLocationProtocol())) {
			$this->logger->warning('Skipped Harmonization of service ' . $service->getId() . ' for ' . $service->getUid() . ', insecure (http) connections are not permitted by the administrator');
			return;
		}
		// update harmonization state and start time
		$service->setHarmonizationState(true);
		$service->setHarmonizationStart(time());
		$service = $this->servicesService->deposit($service->getUid(), $service);
		// initialize store(s)
		$remoteStore = $this->remoteFactory->freshClient($service);

		// events
		if ($this->configurationService->isCalendarAppAvailable()) {
			$this->logger->info('Started Harmonization of Events for ' . $service->getUid());
			// assign configuration, data stores and harmonize
			$this->eventsService->harmonize($service->getUid(), $service, $remoteStore);

			$this->logger->info('Finished Harmonization of Events for ' . $service->getUid());
		}

		// contacts
		if ($this->configurationService->isContactsAppAvailable()) {
			$this->logger->info('Started Harmonization of Contacts for ' . $service->getUid());
			// assign configuration, data stores and harmonize
			$this->contactsService->harmonize($service->getUid(), $service, $remoteStore);

			$this->logger->info('Finished Harmonization of Contacts for ' . $service->getUid());
		}

		// update harmonization state and end time
		$service->setHarmonizationState(false);
		$service->setHarmonizationEnd(time());
		$service = $this->servicesService->deposit($service->getUid(), $service);

		$this->logger->info('Finished Harmonization of Collections for ' . $service->getUid());

	}

}
