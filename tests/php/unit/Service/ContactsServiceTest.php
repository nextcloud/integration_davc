<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Service;

use OCA\DAVC\Models\Contacts\Collection;
use OCA\DAVC\Models\DeltaObject;
use OCA\DAVC\Service\ContactsService;
use OCA\DAVC\Service\Local\LocalContactsService;
use OCA\DAVC\Service\Local\LocalFactory;
use OCA\DAVC\Service\Remote\RemoteClient;
use OCA\DAVC\Service\Remote\RemoteContactsService;
use OCA\DAVC\Service\Remote\RemoteFactory;
use OCA\DAVC\Store\Local\CollectionEntity;
use OCA\DAVC\Store\Local\ContactStore;
use OCA\DAVC\Store\Local\Filters\ContactFilter;
use OCA\DAVC\Store\Local\ServiceEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ContactsServiceTest extends TestCase {
	private const USER_ID = 'user-1';
	private const SERVICE_ID = 3;
	private const COLLECTION = '/addressbooks/user/contacts/';

	private RemoteContactsService&MockObject $remoteService;

	private LocalContactsService&MockObject $localService;

	private ContactStore&MockObject $localStore;

	private ContactsService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->remoteService = $this->createMock(RemoteContactsService::class);
		$this->localService = $this->createMock(LocalContactsService::class);
		$this->localService->method('entityListFilter')->willReturn(new ContactFilter());
		$this->localService->method('entityList')->willReturn([]);
		$this->localStore = $this->createMock(ContactStore::class);
		$this->localStore->method('collectionModify')->willReturnArgument(0);

		$remoteFactory = $this->createMock(RemoteFactory::class);
		$remoteFactory->method('contactsService')->willReturn($this->remoteService);
		$localFactory = $this->createMock(LocalFactory::class);
		$localFactory->method('contactsService')->willReturn($this->localService);
		$localFactory->method('contactsStore')->willReturn($this->localStore);

		$this->service = new ContactsService($this->createMock(LoggerInterface::class), $localFactory, $remoteFactory);
	}

	public function testHarmonizeWithoutStateComparesFullEntityLists(): void {
		$collection = $this->collection(null);

		$this->remoteService->expects($this->never())->method('entityDelta');
		$this->remoteService->expects($this->once())
			->method('entityList')
			->with(self::COLLECTION, 'basic')
			->willReturn([]);

		$this->harmonize($collection);

		$this->assertSame('sync-token-1', $collection->getHesn());
	}

	public function testHarmonizeWithStateRetrievesDelta(): void {
		$collection = $this->collection('sync-token-1');
		$delta = new DeltaObject();
		$delta->signature = 'sync-token-2';

		$this->remoteService->expects($this->once())
			->method('entityDelta')
			->with(self::COLLECTION, 'sync-token-1')
			->willReturn($delta);
		$this->remoteService->expects($this->never())->method('entityList');

		$this->harmonize($collection);

		$this->assertSame('sync-token-2', $collection->getHesn());
	}

	private function harmonize(CollectionEntity $collection): void {
		$remoteCollection = new Collection();
		$remoteCollection->remoteSignature = 'sync-token-1';
		$remoteCollection->permissions = [];
		$this->remoteService->method('collectionFetch')->willReturn($remoteCollection);
		$this->localStore->method('collectionListByService')->with(self::SERVICE_ID)->willReturn([$collection]);

		$service = new ServiceEntity();
		$service->setId(self::SERVICE_ID);
		$this->service->harmonize(self::USER_ID, $service, $this->createMock(RemoteClient::class));
	}

	private function collection(?string $hesn): CollectionEntity {
		$collection = new CollectionEntity();
		$collection->setId(7);
		$collection->setUid(self::USER_ID);
		$collection->setSid(self::SERVICE_ID);
		$collection->setCcid(self::COLLECTION);
		if ($hesn !== null) {
			$collection->setHesn($hesn);
		}
		return $collection;
	}
}
