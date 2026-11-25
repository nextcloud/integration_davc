<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Service;

use OCA\DAVC\Constants;
use OCA\DAVC\Models\Calendars\Collection;
use OCA\DAVC\Service\ConfigurationService;
use OCA\DAVC\Service\CoreService;
use OCA\DAVC\Service\Local\LocalFactory;
use OCA\DAVC\Service\Remote\RemoteClient;
use OCA\DAVC\Service\Remote\RemoteEventsService;
use OCA\DAVC\Service\Remote\RemoteFactory;
use OCA\DAVC\Service\ServicesService;
use OCA\DAVC\Service\ServicesTemplateService;
use OCA\DAVC\Store\Local\CollectionEntity;
use OCA\DAVC\Store\Local\EventStore;
use OCA\DAVC\Store\Local\ServiceEntity;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class CoreServiceTest extends TestCase {
	private LoggerInterface&MockObject $logger;

	private IJobList&MockObject $jobList;

	private ConfigurationService&MockObject $configurationService;

	private ServicesService&MockObject $servicesService;

	private ServicesTemplateService&MockObject $servicesTemplateService;

	private RemoteFactory&MockObject $remoteFactory;

	private LocalFactory&MockObject $localFactory;

	private CoreService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->configurationService = $this->createMock(ConfigurationService::class);
		$this->servicesService = $this->createMock(ServicesService::class);
		$this->servicesTemplateService = $this->createMock(ServicesTemplateService::class);
		$this->remoteFactory = $this->createMock(RemoteFactory::class);
		$this->localFactory = $this->createMock(LocalFactory::class);

		$this->service = new CoreService(
			$this->logger,
			$this->jobList,
			$this->configurationService,
			$this->servicesService,
			$this->servicesTemplateService,
			$this->remoteFactory,
			$this->localFactory,
		);
	}

	public function testConnectAccountThrowsForInvalidBasicCredentials(): void {
		$configuration = [
			'auth' => Constants::AUTHENTICATION_TYPE_BASIC,
			'bauth_id' => ' bad-user ',
			'bauth_secret' => 'secret123',
			'location_host' => 'example.com',
			'location_path' => '/remote.php/dav',
		];

		$this->remoteFactory->expects($this->never())
			->method('freshClient');
		$this->servicesService->expects($this->never())
			->method('deposit');
		$this->jobList->expects($this->never())
			->method('add');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid DAV username provided for basic authentication.');

		$this->service->connectAccount('user-1', $configuration);
	}

	public function testConnectAccountPersistsDiscoveredServiceAndSchedulesTask(): void {
		$configuration = [
			'label' => 'Example Server',
			'auth' => Constants::AUTHENTICATION_TYPE_BASIC,
			'bauth_id' => 'user@example.com',
			'bauth_secret' => 'secret123',
			'bauth_location' => '/login',
			'location_host' => 'example.com',
			'location_path' => '/remote.php/dav',
			'location_protocol' => 'https',
			'location_port' => 8443,
			'location_security' => false,
		];
		$remoteClient = $this->createMock(RemoteClient::class);

		$this->remoteFactory->expects($this->once())
			->method('freshClient')
			->with($this->callback(function (ServiceEntity $service): bool {
				return $service->getLabel() === 'Example Server'
					&& $service->getLocationProtocol() === 'https'
					&& $service->getLocationHost() === 'example.com'
					&& $service->getLocationPort() === 8443
					&& $service->getLocationPath() === '/remote.php/dav'
					&& (bool)$service->getLocationSecurity() === false
					&& $service->getAuth() === Constants::AUTHENTICATION_TYPE_BASIC
					&& $service->getBauthId() === 'user@example.com'
					&& $service->getBauthSecret() === 'secret123'
					&& $service->getBauthLocation() === '/login'
					&& $service->getUuid() !== null;
			}))
			->willReturn($remoteClient);

		$remoteClient->expects($this->once())
			->method('discover')
			->willReturn([
				'connected' => true,
				'principalUrl' => '/principals/users/user/',
				'calendarHomeSet' => '/dav/calendars/user/',
				'addressbookHomeSet' => '/dav/addressbooks/user/',
			]);

		$this->servicesService->expects($this->once())
			->method('deposit')
			->with('user-1', $this->callback(function (ServiceEntity $service): bool {
				return $service->getPrincipalUrl() === '/principals/users/user/'
					&& $service->getCalendarsUrl() === '/dav/calendars/user/'
					&& $service->getAddressbooksUrl() === '/dav/addressbooks/user/'
					&& $service->getConnected() === true
					&& $service->getEnabled() === true;
			}))
			->willReturnCallback(function (string $uid, ServiceEntity $service): ServiceEntity {
				$service->setUid($uid);
				$service->setId(42);
				return $service;
			});

		$this->jobList->expects($this->once())
			->method('add')
			->with('OCA\\DAVC\\Tasks\\HarmonizationLauncher', ['uid' => 'user-1', 'sid' => 42]);

		$connectedService = $this->service->connectAccount('user-1', $configuration);

		$this->assertInstanceOf(ServiceEntity::class, $connectedService);
		$this->assertSame(42, $connectedService->getId());
		$this->assertSame('user-1', $connectedService->getUid());
		$this->assertSame('/principals/users/user/', $connectedService->getPrincipalUrl());
	}

	public function testConnectAccountThrowsWhenRemoteDiscoveryThrows(): void {
		$configuration = [
			'auth' => Constants::AUTHENTICATION_TYPE_BASIC,
			'bauth_id' => 'user@example.com',
			'bauth_secret' => 'secret123',
			'location_host' => 'example.com',
			'location_path' => '/remote.php/dav',
		];
		$remoteClient = $this->createMock(RemoteClient::class);

		$this->remoteFactory->expects($this->once())
			->method('freshClient')
			->willReturn($remoteClient);
		$remoteClient->expects($this->once())
			->method('discover')
			->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())
			->method('error')
			->with('Connection failed:', $this->arrayHasKey('exception'));
		$this->servicesService->expects($this->never())
			->method('deposit');
		$this->jobList->expects($this->never())
			->method('add');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('DAV discovery failed for https://example.com:443/remote.php/dav: boom');

		$this->service->connectAccount('user-1', $configuration);
	}

	public function testConnectAccountRejectsInsecureHttpWhenForbidden(): void {
		$configuration = [
			'label' => 'Example Server',
			'auth' => Constants::AUTHENTICATION_TYPE_BASIC,
			'bauth_id' => 'user@example.com',
			'bauth_secret' => 'secret123',
			'location_host' => 'example.com',
			'location_path' => '/remote.php/dav',
			'location_protocol' => 'http',
		];

		$this->configurationService->method('getForbidInsecureHttp')->willReturn(true);

		$this->remoteFactory->expects($this->never())
			->method('freshClient');
		$this->servicesService->expects($this->never())
			->method('deposit');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Insecure (http) connections are not permitted by the administrator.');

		$this->service->connectAccount('user-1', $configuration);
	}

	public function testConnectAccountForcesCertificateVerificationWhenEnabled(): void {
		$configuration = [
			'label' => 'Example Server',
			'auth' => Constants::AUTHENTICATION_TYPE_BASIC,
			'bauth_id' => 'user@example.com',
			'bauth_secret' => 'secret123',
			'location_host' => 'example.com',
			'location_path' => '/remote.php/dav',
			'location_protocol' => 'https',
			'location_security' => false,
		];
		$remoteClient = $this->createMock(RemoteClient::class);

		$this->configurationService->method('getForceCertificateVerification')->willReturn(true);

		$this->remoteFactory->expects($this->once())
			->method('freshClient')
			->with($this->callback(function (ServiceEntity $service): bool {
				return (bool)$service->getLocationSecurity() === true;
			}))
			->willReturn($remoteClient);

		$remoteClient->method('discover')->willReturn([
			'connected' => true,
			'principalUrl' => null,
			'calendarHomeSet' => null,
			'addressbookHomeSet' => null,
		]);

		$this->servicesService->method('deposit')
			->willReturnCallback(function (string $uid, ServiceEntity $service): ServiceEntity {
				$service->setId(7);
				return $service;
			});

		$connectedService = $this->service->connectAccount('user-1', $configuration);

		$this->assertTrue((bool)$connectedService->getLocationSecurity());
	}

	public function testLocalCollectionsDepositUpdatesColorOfExistingCalendar(): void {
		$store = $this->prepareEventsDeposit();
		$collection = $this->calendarCollection('user-1', '#111111');
		$store->expects($this->once())->method('collectionFetch')->with(5)->willReturn($collection);
		$store->expects($this->once())
			->method('collectionModify')
			->with($this->callback(static fn (CollectionEntity $entity): bool => $entity->getColor() === '#22aa33'))
			->willReturnArgument(0);

		$this->service->localCollectionsDeposit('user-1', 3, [], [
			['id' => 5, 'ccid' => '/calendars/user/personal/', 'enabled' => true, 'color' => '#22aa33'],
		]);
	}

	public function testLocalCollectionsDepositKeepsUnchangedCalendarColor(): void {
		$store = $this->prepareEventsDeposit();
		$store->method('collectionFetch')->willReturn($this->calendarCollection('user-1', '#22aa33'));
		$store->expects($this->never())->method('collectionModify');

		$this->service->localCollectionsDeposit('user-1', 3, [], [
			['id' => 5, 'ccid' => '/calendars/user/personal/', 'enabled' => true, 'color' => '#22aa33'],
		]);
	}

	public function testLocalCollectionsDepositIgnoresInvalidCalendarColor(): void {
		$store = $this->prepareEventsDeposit();
		$store->expects($this->never())->method('collectionFetch');
		$store->expects($this->never())->method('collectionModify');

		$this->service->localCollectionsDeposit('user-1', 3, [], [
			['id' => 5, 'ccid' => '/calendars/user/personal/', 'enabled' => true, 'color' => 'red; background: url(x)'],
		]);
	}

	public function testLocalCollectionsDepositDoesNotChangeColorOfOtherUsersCalendar(): void {
		$store = $this->prepareEventsDeposit();
		$store->method('collectionFetch')->willReturn($this->calendarCollection('user-2', '#111111'));
		$store->expects($this->never())->method('collectionModify');

		$this->service->localCollectionsDeposit('user-1', 3, [], [
			['id' => 5, 'ccid' => '/calendars/user/personal/', 'enabled' => true, 'color' => '#22aa33'],
		]);
	}

	public function testLocalCollectionsDepositPrefersPickedColorForNewCalendar(): void {
		$remoteCollection = new Collection();
		$remoteCollection->label = 'Personal';
		$remoteCollection->color = '#111111';
		$remoteEventsService = $this->createMock(RemoteEventsService::class);
		$remoteEventsService->method('collectionFetch')->willReturn($remoteCollection);
		$this->remoteFactory->method('eventsService')->willReturn($remoteEventsService);

		$store = $this->prepareEventsDeposit('/calendars/user/');
		$store->method('collectionFresh')->willReturnCallback(static fn (): CollectionEntity => new CollectionEntity());
		$store->expects($this->once())
			->method('collectionCreate')
			->with($this->callback(static fn (CollectionEntity $entity): bool => $entity->getColor() === '#22aa33'))
			->willReturnArgument(0);

		$this->service->localCollectionsDeposit('user-1', 3, [], [
			['id' => null, 'ccid' => '/calendars/user/personal/', 'label' => 'Personal', 'enabled' => true, 'color' => '#22aa33'],
		]);
	}

	private function prepareEventsDeposit(?string $calendarHome = null): EventStore&MockObject {
		$service = new ServiceEntity();
		$service->setUid('user-1');
		$this->servicesService->method('fetch')->with(3)->willReturn($service);

		$remoteClient = $this->createMock(RemoteClient::class);
		$remoteClient->method('getCalendarHome')->willReturn($calendarHome);
		$this->remoteFactory->method('freshClient')->willReturn($remoteClient);

		$this->configurationService->method('isContactsAppAvailable')->willReturn(false);
		$this->configurationService->method('isCalendarAppAvailable')->willReturn(true);

		$store = $this->createMock(EventStore::class);
		$this->localFactory->method('eventsStore')->willReturn($store);

		return $store;
	}

	private function calendarCollection(string $uid, string $color): CollectionEntity {
		$collection = new CollectionEntity();
		$collection->setId(5);
		$collection->setUid($uid);
		$collection->setColor($color);
		return $collection;
	}
}
