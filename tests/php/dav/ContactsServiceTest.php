<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Dav;

use OCA\DAVC\Models\Contacts\Entity;
use OCA\DAVC\Service\ContactsService;
use PHPUnit\Framework\Attributes\Group;

#[Group('DB')]
class ContactsServiceTest extends HarmonizationTestCase {

	public function testInitialHarmonizationImportsExistingContacts(): void {
		if ($this->service->getAddressbooksUrl() === null) {
			self::markTestSkipped('Remote address book home set is not available for DAV integration tests.');
		}
		$remoteContactsService = $this->remoteFactory->contactsService($this->remoteClient);
		$addressbooks = $remoteContactsService->collectionList('default');
		$this->assertNotEmpty($addressbooks);
		$remoteCollectionId = (string)$addressbooks[0]->remoteId;

		$contactUid = bin2hex(random_bytes(16));
		$remoteEntity = new Entity();
		$remoteEntity->remoteCollectionId = rtrim($remoteCollectionId, '/') . '/';
		$remoteEntity->remoteEntityId = $contactUid . '.vcf';
		$remoteEntity->data = $this->buildCardPayload($contactUid);
		$createdEntity = $remoteContactsService->entityCreate($remoteEntity);
		$this->assertInstanceOf(Entity::class, $createdEntity);

		try {
			$localStore = $this->localFactory->contactsStore();
			$collection = $this->createLocalCollection($localStore, $remoteCollectionId);
			$this->assertSame('', (string)$collection->getHesn());

			$this->container->get(ContactsService::class)->harmonize($this->uid, $this->service, $this->remoteClient);

			$imported = [];
			foreach ($localStore->entityListByCollection((int)$collection->getId()) as $localEntity) {
				if (str_contains((string)$localEntity->getData(), 'UID:' . $contactUid)) {
					$imported[] = $localEntity;
				}
			}
			$this->assertCount(1, $imported, 'The existing remote contact was not imported by the initial harmonization');
			$this->assertNotSame('', (string)$localStore->collectionFetch((int)$collection->getId())?->getHesn());
		} finally {
			try {
				$this->remoteClient->delete((string)$createdEntity->remoteEntityId);
			} catch (\Throwable) {
			}
		}
	}

	private function buildCardPayload(string $identifier): string {
		return implode("\r\n", [
			'BEGIN:VCARD',
			'VERSION:4.0',
			'UID:' . $identifier,
			'FN:Initial Harmonization Test',
			'N:Test;Harmonization;;;',
			'EMAIL:' . $identifier . '@example.com',
			'END:VCARD',
			'',
		]);
	}
}
