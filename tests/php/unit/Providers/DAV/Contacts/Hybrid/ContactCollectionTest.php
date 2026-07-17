<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Providers\DAV\Contacts\Hybrid;

use OCA\DAVC\Models\Contacts\Collection;
use OCA\DAVC\Models\Contacts\Entity;
use OCA\DAVC\Providers\DAV\Contacts\Hybrid\ContactCollection;
use OCA\DAVC\Providers\DAV\Contacts\Hybrid\ContactEntity;
use OCA\DAVC\Service\Local\LocalContactsService;
use OCA\DAVC\Service\Remote\RemoteClient;
use OCA\DAVC\Service\Remote\RemoteContactsService;
use OCA\DAVC\Service\Remote\RemoteFactory;
use OCA\DAVC\Store\Local\Filters\ContactFilter;
use OCA\DAVC\Store\Local\ServiceEntity;
use OCA\DAVC\Store\Local\ServicesStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ContactCollectionTest extends TestCase {
	private ServicesStore&MockObject $servicesStore;
	private LocalContactsService&MockObject $localService;
	private RemoteFactory&MockObject $remoteFactory;
	private Collection $collection;
	private ContactCollection $sut;

	protected function setUp(): void {
		parent::setUp();

		$this->servicesStore = $this->createMock(ServicesStore::class);
		$this->localService = $this->createMock(LocalContactsService::class);
		$this->remoteFactory = $this->createMock(RemoteFactory::class);

		$this->collection = new Collection();
		$this->collection->userId = 'user1';
		$this->collection->serviceId = 1;
		$this->collection->localId = 42;
		$this->collection->uuid = 'collection-uuid';
		$this->collection->remoteId = '/remote/Contacts/';

		$this->sut = new ContactCollection(
			$this->servicesStore,
			$this->localService,
			$this->remoteFactory,
			$this->collection,
		);

		$this->localService->method('entityListFilter')
			->willReturnCallback(static fn (): ContactFilter => new ContactFilter());
	}

	/**
	 * extract the value of a given attribute from a filter
	 */
	private function conditionValue(ContactFilter $filter, string $attribute): mixed {
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
			->willReturnCallback(function (ContactFilter $filter) use ($entity): array {
				$this->assertSame('entity-uuid', $this->conditionValue($filter, 'uuid'));
				return [$entity];
			});

		$child = $this->sut->getChild('entity-uuid');

		$this->assertInstanceOf(ContactEntity::class, $child);
		$this->assertSame('entity-uuid', $child->getName());
	}

	public function testGetChildFallsBackToRemoteEntityId(): void {
		$entity = new Entity();
		$entity->uuid = 'entity-uuid';
		$entity->remoteEntityId = '/remote/Contacts/submitted-id.vcf';

		$this->localService->expects($this->exactly(2))
			->method('entityList')
			->willReturnCallback(function (ContactFilter $filter) use ($entity): array {
				// uuid lookup yields nothing, ceid lookup resolves the entity by
				// the reconstructed full resource path
				if ($this->conditionValue($filter, 'uuid') === 'submitted-id.vcf') {
					return [];
				}
				$this->assertSame('/remote/Contacts/submitted-id.vcf', $this->conditionValue($filter, 'ceid'));
				return [$entity];
			});

		$child = $this->sut->getChild('submitted-id.vcf');

		$this->assertInstanceOf(ContactEntity::class, $child);
		$this->assertSame('entity-uuid', $child->getName());
	}

	public function testGetChildThrowsWhenMissing(): void {
		$this->localService->expects($this->exactly(2))
			->method('entityList')
			->willReturn([]);

		$this->expectException(\Sabre\DAV\Exception\NotFound::class);

		$this->sut->getChild('unknown');
	}

	public function testChildExistsByUuid(): void {
		$this->localService->expects($this->once())
			->method('entityList')
			->willReturn([new Entity()]);

		$this->assertTrue($this->sut->childExists('entity-uuid'));
	}

	public function testChildExistsFallsBackToRemoteEntityId(): void {
		$this->localService->expects($this->exactly(2))
			->method('entityList')
			->willReturnCallback(function (ContactFilter $filter): array {
				if ($this->conditionValue($filter, 'uuid') === 'submitted-id.vcf') {
					return [];
				}
				$this->assertSame('/remote/Contacts/submitted-id.vcf', $this->conditionValue($filter, 'ceid'));
				return [new Entity()];
			});

		$this->assertTrue($this->sut->childExists('submitted-id.vcf'));
	}

	public function testChildExistsReturnsFalseWhenMissing(): void {
		$this->localService->expects($this->exactly(2))
			->method('entityList')
			->willReturn([]);

		$this->assertFalse($this->sut->childExists('unknown'));
	}

	/**
	 * wire the lazy loaded remote service to a mock
	 */
	private function mockRemoteService(): RemoteContactsService&MockObject {
		$service = $this->createMock(ServiceEntity::class);
		$client = $this->createMock(RemoteClient::class);
		$remoteService = $this->createMock(RemoteContactsService::class);

		$this->servicesStore->method('fetch')->with(1)->willReturn($service);
		$this->remoteFactory->method('freshClient')->with($service)->willReturn($client);
		$this->remoteFactory->method('contactsService')->with($client)->willReturn($remoteService);

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
				$this->assertSame('BEGIN:VCARD', $entity->data);
				$this->assertSame('fresh-id', $entity->remoteEntityId);
				return $created;
			});
		$this->localService->expects($this->once())
			->method('entityCreate')
			->with('user1', 1, 42, $created)
			->willReturn($created);

		$this->assertSame('signature', $this->sut->createFile('fresh-id', 'BEGIN:VCARD'));
	}

	public function testCreateFileConvertsStreamToString(): void {
		$remoteService = $this->mockRemoteService();

		$created = new Entity();
		$created->localSignature = 'signature';

		$remoteService->expects($this->once())
			->method('entityCreate')
			->willReturnCallback(function (Entity $entity) use ($created): Entity {
				$this->assertSame('BEGIN:VCARD', $entity->data);
				return $created;
			});
		$this->localService->expects($this->once())
			->method('entityCreate')
			->with('user1', 1, 42, $created)
			->willReturn($created);

		$this->assertSame('signature', $this->sut->createFile('fresh-id', $this->memoryStream('BEGIN:VCARD')));
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
				$this->assertSame('BEGIN:VCARD', $eo->data);
				return $entity;
			});
		$this->localService->expects($this->once())
			->method('entityModify')
			->with('user1', 1, 42, 7, $entity)
			->willReturn($entity);

		$this->assertSame('signature', $this->sut->modifyFile($entity, 'BEGIN:VCARD'));
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
				$this->assertSame('BEGIN:VCARD', $eo->data);
				return $entity;
			});
		$this->localService->expects($this->once())
			->method('entityModify')
			->with('user1', 1, 42, 7, $entity)
			->willReturn($entity);

		$this->assertSame('signature', $this->sut->modifyFile($entity, $this->memoryStream('BEGIN:VCARD')));
	}
}
