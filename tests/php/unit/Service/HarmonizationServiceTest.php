<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Service;

use OCA\DAVC\Exception\ServiceNotFoundException;
use OCA\DAVC\Service\ConfigurationService;
use OCA\DAVC\Service\ContactsService;
use OCA\DAVC\Service\EventsService;
use OCA\DAVC\Service\HarmonizationService;
use OCA\DAVC\Service\Remote\RemoteFactory;
use OCA\DAVC\Service\ServicesService;
use OCA\DAVC\Store\Local\ServiceEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HarmonizationServiceTest extends TestCase {
	private ConfigurationService&MockObject $configurationService;
	private ServicesService&MockObject $servicesService;
	private EventsService&MockObject $eventsService;
	private ContactsService&MockObject $contactsService;
	private RemoteFactory&MockObject $remoteFactory;
	private HarmonizationService $harmonizationService;

	protected function setUp(): void {
		parent::setUp();
		$this->configurationService = $this->createMock(ConfigurationService::class);
		$this->servicesService = $this->createMock(ServicesService::class);
		$this->eventsService = $this->createMock(EventsService::class);
		$this->contactsService = $this->createMock(ContactsService::class);
		$this->remoteFactory = $this->createMock(RemoteFactory::class);
		$this->harmonizationService = new HarmonizationService(
			$this->createMock(LoggerInterface::class),
			$this->configurationService,
			$this->servicesService,
			$this->contactsService,
			$this->eventsService,
			$this->remoteFactory,
		);
	}

	public function testPerformHarmonizationForServiceSkipsServiceWhenTransportNotPermitted(): void {
		$service = new ServiceEntity();
		$service->setId(3);
		$service->setUid('user1');
		$service->setEnabled(true);
		$service->setConnected(true);
		$service->setLocationProtocol('http');

		$this->configurationService->method('isTransportPermitted')->with('http')->willReturn(false);
		$this->remoteFactory->expects($this->never())->method('freshClient');
		$this->servicesService->expects($this->never())->method('deposit');
		$this->eventsService->expects($this->never())->method('harmonize');
		$this->contactsService->expects($this->never())->method('harmonize');

		$this->harmonizationService->performHarmonizationForService($service);
	}

	public function testPerformHarmonizationThrowsWhenServiceIsNotTheUsers(): void {
		$this->servicesService->method('fetchByUserIdAndServiceId')->with('user1', 3)->willReturn(null);
		$this->remoteFactory->expects($this->never())->method('freshClient');

		$this->expectException(ServiceNotFoundException::class);
		$this->harmonizationService->performHarmonization('user1', 3);
	}

}
