<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Dav;

use OCA\DAVC\Models\Calendars\Entity;
use OCA\DAVC\Service\EventsService;
use PHPUnit\Framework\Attributes\Group;

#[Group('DB')]
class EventsServiceTest extends HarmonizationTestCase {

	public function testInitialHarmonizationImportsExistingEvents(): void {
		if ($this->service->getCalendarsUrl() === null) {
			self::markTestSkipped('Remote calendar home set is not available for DAV integration tests.');
		}
		$remoteEventsService = $this->remoteFactory->eventsService($this->remoteClient);
		$calendars = $remoteEventsService->collectionList('default');
		$this->assertNotEmpty($calendars);
		$remoteCollectionId = (string)$calendars[0]->remoteId;

		$eventUid = bin2hex(random_bytes(16));
		$remoteEntity = new Entity();
		$remoteEntity->remoteCollectionId = rtrim($remoteCollectionId, '/') . '/';
		$remoteEntity->remoteEntityId = $eventUid . '.ics';
		$remoteEntity->data = $this->buildCalendarPayload($eventUid);
		$createdEntity = $remoteEventsService->entityCreate($remoteEntity);
		$this->assertInstanceOf(Entity::class, $createdEntity);

		try {
			$localStore = $this->localFactory->eventsStore();
			$collection = $this->createLocalCollection($localStore, $remoteCollectionId);
			$this->assertSame('', (string)$collection->getHesn());

			$this->container->get(EventsService::class)->harmonize($this->uid, $this->service, $this->remoteClient);

			$imported = [];
			foreach ($localStore->entityListByCollection((int)$collection->getId()) as $localEntity) {
				if (str_contains((string)$localEntity->getData(), 'UID:' . $eventUid)) {
					$imported[] = $localEntity;
				}
			}
			$this->assertCount(1, $imported, 'The existing remote event was not imported by the initial harmonization');
			$this->assertNotSame('', (string)$localStore->collectionFetch((int)$collection->getId())?->getHesn());
		} finally {
			try {
				$this->remoteClient->delete((string)$createdEntity->remoteEntityId);
			} catch (\Throwable) {
			}
		}
	}

	private function buildCalendarPayload(string $identifier): string {
		return implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Nextcloud DAVC//EventsServiceTest//EN',
			'BEGIN:VEVENT',
			'UID:' . $identifier,
			'DTSTAMP:' . gmdate('Ymd\THis\Z'),
			'DTSTART:20260101T120000Z',
			'DTEND:20260101T130000Z',
			'SUMMARY:Initial Harmonization Test',
			'END:VEVENT',
			'END:VCALENDAR',
			'',
		]);
	}
}
