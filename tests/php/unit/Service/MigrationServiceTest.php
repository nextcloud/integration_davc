<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Service;

use OCA\DAVC\Exception\ServiceNotFoundException;
use OCA\DAVC\Models\Calendars\Entity as CalendarEntity;
use OCA\DAVC\Models\Contacts\Entity as ContactEntity;
use OCA\DAVC\Service\MigrationService;
use OCA\DAVC\Service\Native\NativeContactsService;
use OCA\DAVC\Service\Native\NativeEventsService;
use OCA\DAVC\Service\Remote\RemoteClient;
use OCA\DAVC\Service\Remote\RemoteContactsService;
use OCA\DAVC\Service\Remote\RemoteEventsService;
use OCA\DAVC\Service\Remote\RemoteFactory;
use OCA\DAVC\Service\ServicesService;
use OCA\DAVC\Store\Local\ServiceEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MigrationServiceTest extends TestCase {
	private ServicesService&MockObject $servicesService;
	private RemoteFactory&MockObject $remoteFactory;
	private NativeEventsService&MockObject $nativeEventsService;
	private NativeContactsService&MockObject $nativeContactsService;
	private ITimeFactory&MockObject $time;
	private ?ServiceEntity $userService;
	/** @var array<array{id: int, uri: string, label: ?string, readOnly: bool}> native collections the user can access */
	private array $nativeCollections = [];

	private MigrationService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->servicesService = $this->createMock(ServicesService::class);
		$this->remoteFactory = $this->createMock(RemoteFactory::class);
		$this->nativeEventsService = $this->createMock(NativeEventsService::class);
		$this->nativeContactsService = $this->createMock(NativeContactsService::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(0);

		$this->userService = new ServiceEntity();
		$this->servicesService->method('fetchByUserIdAndServiceId')
			->willReturnCallback(fn (): ?ServiceEntity => $this->userService);
		$this->nativeCollections = [['id' => 5, 'uri' => 'personal', 'label' => 'Personal', 'readOnly' => false]];
		$this->nativeEventsService->method('collectionList')->with('user-1')->willReturnCallback(fn (): array => $this->nativeCollections);
		$this->nativeContactsService->method('collectionList')->with('user-1')->willReturnCallback(fn (): array => $this->nativeCollections);
		$this->remoteFactory->method('freshClient')
			->willReturn($this->createMock(RemoteClient::class));

		$this->service = new MigrationService(
			$this->createMock(LoggerInterface::class),
			$this->time,
			$this->servicesService,
			$this->remoteFactory,
			$this->nativeEventsService,
			$this->nativeContactsService,
		);
	}

	private function startState(string $resource, string $direction): array {
		return $this->service->start('user-1', 42, $resource, $direction, '5', '5', false);
	}

	public function testContactOutboundFetchesUrisThenHydratesChunkForCreate(): void {
		$this->nativeContactsService->expects($this->once())
			->method('entityListUris')
			->with(5)
			->willReturn(['b.vcf', 'a.vcf']);

		$hydrated = array_map(static fn (string $uri) => ['uri' => $uri, 'data' => 'BEGIN:VCARD...'], ['a.vcf', 'b.vcf']);

		$this->nativeContactsService->expects($this->once())
			->method('entityFetchMultiple')
			->with(5, ['a.vcf', 'b.vcf'])
			->willReturn($hydrated);

		$remote = $this->createMock(RemoteContactsService::class);
		$remote->expects($this->exactly(2))
			->method('entityCreate')
			->willReturnCallback(static function (ContactEntity $entity) {
				$created = new ContactEntity();
				$created->uuid = $entity->remoteEntityId;
				return $created;
			});
		$this->remoteFactory->method('contactsService')->willReturn($remote);

		$state = $this->startState(MigrationService::RESOURCE_CONTACT, MigrationService::DIRECTION_OUTBOUND);
		$state = $this->service->process($state, 5);

		$this->assertSame(MigrationService::STATUS_COMPLETED, $state['status']);
		$this->assertSame(2, $state['statistics']['created']);
	}

	public function testEventOutboundFetchesIdentifiersThenHydratesChunkForCreate(): void {
		$this->nativeEventsService->expects($this->once())
			->method('entityListUris')
			->with(5)
			->willReturn(['b.ics', 'a.ics']);

		$hydrated = array_map(static fn (string $uri) => ['uri' => $uri, 'data' => 'BEGIN:VCALENDAR...'], ['a.ics', 'b.ics']);

		$this->nativeEventsService->expects($this->once())
			->method('entityFetchMultiple')
			->with(5, ['a.ics', 'b.ics'])
			->willReturn($hydrated);

		$remote = $this->createMock(RemoteEventsService::class);
		$remote->expects($this->exactly(2))
			->method('entityCreate')
			->willReturnCallback(static function (CalendarEntity $entity) {
				$created = new CalendarEntity();
				$created->uuid = $entity->remoteEntityId;
				return $created;
			});
		$this->remoteFactory->method('eventsService')->willReturn($remote);

		$state = $this->startState(MigrationService::RESOURCE_EVENT, MigrationService::DIRECTION_OUTBOUND);
		$state = $this->service->process($state, 5);

		$this->assertSame(MigrationService::STATUS_COMPLETED, $state['status']);
		$this->assertSame(2, $state['statistics']['created']);
	}

	public function testContactInboundListsRemoteBasicThenCreatesLocally(): void {
		$remote = $this->createMock(RemoteContactsService::class);
		$remote->expects($this->once())
			->method('entityList')
			->with('5', 'basic')
			->willReturn(['a.vcf' => new ContactEntity(), 'b.vcf' => new ContactEntity()]);

		$hydrated = [];
		foreach (['a.vcf', 'b.vcf'] as $uri) {
			$entity = new ContactEntity();
			$entity->remoteEntityId = $uri;
			$entity->data = 'BEGIN:VCARD...';
			$hydrated[$uri] = $entity;
		}
		$remote->expects($this->once())
			->method('entityFetchMultiple')
			->with('5', ['a.vcf', 'b.vcf'])
			->willReturn($hydrated);
		$this->remoteFactory->method('contactsService')->willReturn($remote);

		$this->nativeContactsService->method('entityFindByUid')->willReturn(null);
		$this->nativeContactsService->expects($this->exactly(2))
			->method('entityCreate')
			->willReturn(['uri' => 'created.vcf', 'data' => 'BEGIN:VCARD...']);

		$state = $this->startState(MigrationService::RESOURCE_CONTACT, MigrationService::DIRECTION_INBOUND);
		$state = $this->service->process($state, 5);

		$this->assertSame(MigrationService::STATUS_COMPLETED, $state['status']);
		$this->assertSame(2, $state['statistics']['created']);
	}

	public function testEventInboundListsRemoteBasicThenCreatesLocally(): void {
		$remote = $this->createMock(RemoteEventsService::class);
		$remote->expects($this->once())
			->method('entityList')
			->with('5', 'basic')
			->willReturn(['a.ics' => new CalendarEntity(), 'b.ics' => new CalendarEntity()]);

		$hydrated = [];
		foreach (['a.ics', 'b.ics'] as $uri) {
			$entity = new CalendarEntity();
			$entity->remoteEntityId = $uri;
			$entity->data = 'BEGIN:VCALENDAR...';
			$hydrated[$uri] = $entity;
		}
		$remote->expects($this->once())
			->method('entityFetchMultiple')
			->with('5', ['a.ics', 'b.ics'])
			->willReturn($hydrated);
		$this->remoteFactory->method('eventsService')->willReturn($remote);

		$this->nativeEventsService->method('entityFindByUid')->willReturn(null);
		$this->nativeEventsService->expects($this->exactly(2))
			->method('entityCreate')
			->willReturn(['uri' => 'created.ics', 'data' => 'BEGIN:VCALENDAR...']);

		$state = $this->startState(MigrationService::RESOURCE_EVENT, MigrationService::DIRECTION_INBOUND);
		$state = $this->service->process($state, 5);

		$this->assertSame(MigrationService::STATUS_COMPLETED, $state['status']);
		$this->assertSame(2, $state['statistics']['created']);
	}

	public function testUnknownResourceMarksMigrationFailed(): void {
		$state = $this->startState(MigrationService::RESOURCE_EVENT, MigrationService::DIRECTION_OUTBOUND);
		$state['resource'] = 'unknown';
		$state = $this->service->process($state, 5);

		$this->assertSame(MigrationService::STATUS_FAILED, $state['status']);
		$this->assertNotNull($state['lastError']);
	}

	public function testStartRejectsUnknownResource(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->startState('unknown', MigrationService::DIRECTION_OUTBOUND);
	}

	public function testStartRejectsUnknownDirection(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->startState(MigrationService::RESOURCE_EVENT, 'sideways');
	}

	public function testStartRejectsServiceOfOtherUser(): void {
		$this->userService = null;

		$this->expectException(ServiceNotFoundException::class);
		$this->startState(MigrationService::RESOURCE_EVENT, MigrationService::DIRECTION_OUTBOUND);
	}

	public static function migrationProvider(): array {
		return [
			'event outbound' => [MigrationService::RESOURCE_EVENT, MigrationService::DIRECTION_OUTBOUND],
			'event inbound' => [MigrationService::RESOURCE_EVENT, MigrationService::DIRECTION_INBOUND],
			'contact outbound' => [MigrationService::RESOURCE_CONTACT, MigrationService::DIRECTION_OUTBOUND],
			'contact inbound' => [MigrationService::RESOURCE_CONTACT, MigrationService::DIRECTION_INBOUND],
		];
	}

	#[DataProvider('migrationProvider')]
	public function testStartRejectsNativeCollectionOfOtherUser(string $resource, string $direction): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->start('user-1', 42, $resource, $direction, '99', '99', false);
	}

	public function testStartRejectsReadOnlyNativeTarget(): void {
		$this->nativeCollections = [['id' => 5, 'uri' => 'shared', 'label' => 'Shared', 'readOnly' => true]];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('read-only');
		$this->startState(MigrationService::RESOURCE_EVENT, MigrationService::DIRECTION_INBOUND);
	}

	public function testStartAllowsReadOnlyNativeSource(): void {
		$this->nativeCollections = [['id' => 5, 'uri' => 'shared', 'label' => 'Shared', 'readOnly' => true]];

		$state = $this->startState(MigrationService::RESOURCE_EVENT, MigrationService::DIRECTION_OUTBOUND);

		$this->assertSame(MigrationService::STATUS_RUNNING, $state['status']);
	}

	public function testProcessFailsWhenNativeCollectionIsNoLongerAccessible(): void {
		$state = $this->startState(MigrationService::RESOURCE_EVENT, MigrationService::DIRECTION_OUTBOUND);
		$this->nativeCollections = [];
		$this->nativeEventsService->expects($this->never())->method('entityListUris');
		$this->nativeEventsService->expects($this->never())->method('entityFetchMultiple');

		$state = $this->service->process($state, 5);

		$this->assertSame(MigrationService::STATUS_FAILED, $state['status']);
	}

	public function testProcessFailsWhenNativeTargetBecameReadOnly(): void {
		$state = $this->startState(MigrationService::RESOURCE_CONTACT, MigrationService::DIRECTION_INBOUND);
		$this->nativeCollections = [['id' => 5, 'uri' => 'shared', 'label' => 'Shared', 'readOnly' => true]];
		$this->nativeContactsService->expects($this->never())->method('entityCreate');
		$this->nativeContactsService->expects($this->never())->method('entityModify');

		$state = $this->service->process($state, 5);

		$this->assertSame(MigrationService::STATUS_FAILED, $state['status']);
	}
}
