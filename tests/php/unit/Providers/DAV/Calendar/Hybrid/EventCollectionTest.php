<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Providers\DAV\Calendar\Hybrid;

use OCA\DAVC\Models\Calendars\Collection;
use OCA\DAVC\Models\Calendars\Entity;
use OCA\DAVC\Providers\DAV\Calendar\Hybrid\EventCollection;
use OCA\DAVC\Providers\DAV\Calendar\Hybrid\EventEntity;
use OCA\DAVC\Service\Local\LocalEventsService;
use OCA\DAVC\Service\Remote\RemoteClient;
use OCA\DAVC\Service\Remote\RemoteEventsService;
use OCA\DAVC\Service\Remote\RemoteFactory;
use OCA\DAVC\Store\Local\Filters\EventFilter;
use OCA\DAVC\Store\Local\ServiceEntity;
use OCA\DAVC\Store\Local\ServicesStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class EventCollectionTest extends TestCase {
	private ServicesStore&MockObject $servicesStore;
	private LocalEventsService&MockObject $localService;
	private RemoteFactory&MockObject $remoteFactory;
	private Collection $collection;
	private EventCollection $sut;

	protected function setUp(): void {
		parent::setUp();

		$this->servicesStore = $this->createMock(ServicesStore::class);
		$this->localService = $this->createMock(LocalEventsService::class);
		$this->remoteFactory = $this->createMock(RemoteFactory::class);

		$this->collection = new Collection();
		$this->collection->userId = 'user1';
		$this->collection->serviceId = 1;
		$this->collection->localId = 42;
		$this->collection->uuid = 'collection-uuid';
		$this->collection->remoteId = '/remote/Calendar/';

		$this->sut = new EventCollection(
			$this->servicesStore,
			$this->localService,
			$this->remoteFactory,
			$this->collection,
		);

		$this->localService->method('entityListFilter')
			->willReturnCallback(static fn (): EventFilter => new EventFilter());
	}

	/**
	 * extract the value of a given attribute from a filter
	 */
	private function conditionValue(EventFilter $filter, string $attribute): mixed {
		foreach ($filter->conditions() as $condition) {
			if ($condition['attribute'] === $attribute) {
				return $condition['value'];
			}
		}
		return null;
	}

	public function testGetChildByUuid(): void {
		$entity = new Entity();
		$entity->uuid = 'entity-uuid';

		$this->localService->expects($this->once())
			->method('entityList')
			->willReturnCallback(function (EventFilter $filter) use ($entity): array {
				// the full resource name is used for the uuid lookup
				$this->assertSame('entity-uuid', $this->conditionValue($filter, 'uuid'));
				return [$entity];
			});

		$child = $this->sut->getChild('entity-uuid');

		$this->assertInstanceOf(EventEntity::class, $child);
		$this->assertSame('entity-uuid', $child->getName());
	}

	public function testGetChildFallsBackToRemoteEntityId(): void {
		$entity = new Entity();
		$entity->uuid = 'entity-uuid';
		$entity->remoteEntityId = '/remote/Calendar/submitted-id.ics';

		$this->localService->expects($this->exactly(2))
			->method('entityList')
			->willReturnCallback(function (EventFilter $filter) use ($entity): array {
				// uuid lookup yields nothing, ceid lookup resolves the entity by
				// the remote id prefixed with the collection remote id
				if ($this->conditionValue($filter, 'uuid') === 'submitted-id') {
					return [];
				}
				$this->assertSame('/remote/Calendar/submitted-id', $this->conditionValue($filter, 'ceid'));
				return [$entity];
			});

		$child = $this->sut->getChild('submitted-id');

		$this->assertInstanceOf(EventEntity::class, $child);
		$this->assertSame('entity-uuid', $child->getName());
	}

	public function testGetChildThrowsWhenMissing(): void {
		$this->localService->expects($this->exactly(2))
			->method('entityList')
			->willReturn([]);

		$this->expectException(\Sabre\DAV\Exception\NotFound::class);

		$this->sut->getChild('unknown.ics');
	}

	public function testChildExistsByUuid(): void {
		$this->localService->expects($this->once())
			->method('entityList')
			->willReturnCallback(function (EventFilter $filter): array {
				$this->assertSame('entity-uuid', $this->conditionValue($filter, 'uuid'));
				return [new Entity()];
			});

		$this->assertTrue($this->sut->childExists('entity-uuid'));
	}

	public function testChildExistsFallsBackToRemoteEntityId(): void {
		$this->localService->expects($this->exactly(2))
			->method('entityList')
			->willReturnCallback(function (EventFilter $filter): array {
				if ($this->conditionValue($filter, 'uuid') === 'submitted-id') {
					return [];
				}
				$this->assertSame('/remote/Calendar/submitted-id', $this->conditionValue($filter, 'ceid'));
				return [new Entity()];
			});

		$this->assertTrue($this->sut->childExists('submitted-id'));
	}

	public function testChildExistsReturnsFalseWhenMissing(): void {
		$this->localService->expects($this->exactly(2))
			->method('entityList')
			->willReturn([]);

		$this->assertFalse($this->sut->childExists('unknown.ics'));
	}

	/**
	 * wire the lazy loaded remote service to a mock
	 */
	private function mockRemoteService(): RemoteEventsService&MockObject {
		$service = $this->createMock(ServiceEntity::class);
		$client = $this->createMock(RemoteClient::class);
		$remoteService = $this->createMock(RemoteEventsService::class);

		$this->servicesStore->method('fetch')->with(1)->willReturn($service);
		$this->remoteFactory->method('freshClient')->with($service)->willReturn($client);
		$this->remoteFactory->method('eventsService')->with($client)->willReturn($remoteService);

		return $remoteService;
	}

	/**
	 * construct a rewound memory stream with the given contents
	 *
	 * @return resource
	 */
	private function memoryStream(string $contents) {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, $contents);
		rewind($stream);
		return $stream;
	}

	public function testCreateFileWithString(): void {
		$remoteService = $this->mockRemoteService();

		$created = new Entity();
		$created->localSignature = 'signature';

		$remoteService->expects($this->once())
			->method('entityCreate')
			->willReturnCallback(function (Entity $entity) use ($created): Entity {
				$this->assertSame('BEGIN:VCALENDAR', $entity->data);
				$this->assertSame('fresh-id', $entity->remoteEntityId);
				return $created;
			});
		$this->localService->expects($this->once())
			->method('entityCreate')
			->with('user1', 1, 42, $created)
			->willReturn($created);

		$this->assertSame('signature', $this->sut->createFile('fresh-id', 'BEGIN:VCALENDAR'));
	}

	public function testCreateFileConvertsStreamToString(): void {
		$remoteService = $this->mockRemoteService();

		$created = new Entity();
		$created->localSignature = 'signature';

		$remoteService->expects($this->once())
			->method('entityCreate')
			->willReturnCallback(function (Entity $entity) use ($created): Entity {
				$this->assertSame('BEGIN:VCALENDAR', $entity->data);
				return $created;
			});
		$this->localService->expects($this->once())
			->method('entityCreate')
			->with('user1', 1, 42, $created)
			->willReturn($created);

		$this->assertSame('signature', $this->sut->createFile('fresh-id', $this->memoryStream('BEGIN:VCALENDAR')));
	}

	public function testModifyFileWithString(): void {
		$remoteService = $this->mockRemoteService();

		$entity = new Entity();
		$entity->localCollectionId = 42;
		$entity->localEntityId = 7;
		$entity->localSignature = 'signature';

		$remoteService->expects($this->once())
			->method('entityModify')
			->willReturnCallback(function (Entity $eo) use ($entity): Entity {
				$this->assertSame('BEGIN:VCALENDAR', $eo->data);
				return $entity;
			});
		$this->localService->expects($this->once())
			->method('entityModify')
			->with('user1', 1, 42, 7, $entity)
			->willReturn($entity);

		$this->assertSame('signature', $this->sut->modifyFile($entity, 'BEGIN:VCALENDAR'));
	}

	public function testModifyFileConvertsStreamToString(): void {
		$remoteService = $this->mockRemoteService();

		$entity = new Entity();
		$entity->localCollectionId = 42;
		$entity->localEntityId = 7;
		$entity->localSignature = 'signature';

		$remoteService->expects($this->once())
			->method('entityModify')
			->willReturnCallback(function (Entity $eo) use ($entity): Entity {
				$this->assertSame('BEGIN:VCALENDAR', $eo->data);
				return $entity;
			});
		$this->localService->expects($this->once())
			->method('entityModify')
			->with('user1', 1, 42, 7, $entity)
			->willReturn($entity);

		$this->assertSame('signature', $this->sut->modifyFile($entity, $this->memoryStream('BEGIN:VCALENDAR')));
	}
}
