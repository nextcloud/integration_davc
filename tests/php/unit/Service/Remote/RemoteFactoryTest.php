<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Service\Remote;

use OCA\DAVC\Service\ConfigurationService;
use OCA\DAVC\Service\Remote\RemoteClient;
use OCA\DAVC\Service\Remote\RemoteFactory;
use OCA\DAVC\Store\Local\ServiceEntity;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RemoteFactoryTest extends TestCase {
	private ConfigurationService&MockObject $configurationService;
	private RemoteFactory $factory;

	protected function setUp(): void {
		parent::setUp();
		$this->configurationService = $this->createMock(ConfigurationService::class);
		$this->factory = new RemoteFactory(
			$this->createMock(IClientService::class),
			$this->createMock(IConfig::class),
			$this->configurationService,
		);
	}

	private function service(string $protocol): ServiceEntity {
		$service = new ServiceEntity();
		$service->setLocationProtocol($protocol);
		$service->setLocationHost('dav.example.com');
		$service->setLocationPort($protocol === 'http' ? 80 : 443);
		$service->setLocationPath('/');
		return $service;
	}

	public function testFreshClientRejectsServiceWhenTransportNotPermitted(): void {
		$this->configurationService->expects($this->once())
			->method('isTransportPermitted')
			->with('http')
			->willReturn(false);

		$this->expectException(\RuntimeException::class);
		$this->factory->freshClient($this->service('http'));
	}

	public function testFreshClientCreatesClientWhenTransportPermitted(): void {
		$this->configurationService->expects($this->once())
			->method('isTransportPermitted')
			->with('https')
			->willReturn(true);

		$this->assertInstanceOf(RemoteClient::class, $this->factory->freshClient($this->service('https')));
	}

}
