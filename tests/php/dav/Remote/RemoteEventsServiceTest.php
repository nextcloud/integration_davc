<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Dav\Remote;

use OCA\DAVC\AppInfo\Application;
use OCA\DAVC\Models\Calendars\Collection;
use OCA\DAVC\Models\Calendars\Entity;
use OCA\DAVC\Service\Remote\RemoteClient;
use OCA\DAVC\Service\Remote\RemoteEventsService;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\Attributes\Group;
use Test\TestCase;

#[Group('DB')]
class RemoteEventsServiceTest extends TestCase {

	private RemoteClient $client;
	private RemoteEventsService $service;

	protected function setUp(): void {
		parent::setUp();

		$davProtocol = $this->requiredEnv('DAV_PROTOCOL');
		$davHost = $this->requiredEnv('DAV_HOST');
		$davPort = (int)$this->requiredEnv('DAV_PORT');
		$davPath = $this->requiredEnv('DAV_PATH');
		$davUsername = $this->requiredEnv('DAV_USERNAME');
		$davPassword = $this->requiredEnv('DAV_PASSWORD');

		$container = (new Application())->getContainer();
		$this->client = new RemoteClient($container->get(IClientService::class));
		$this->client->configureLocation(
			$davProtocol,
			$davHost,
			$davPort,
			$davPath,
		);
		$this->client->configureTransportVerification(false);
		$this->client->setBasicAuthentication($davUsername, $davPassword);

		$capabilities = $this->client->discover();
		if (!is_string($capabilities['calendarHomeSet'] ?? null) || $capabilities['calendarHomeSet'] === '') {
			self::markTestSkipped('Remote calendar home set is not available for DAV integration tests.');
		}

		$this->service = new RemoteEventsService();
		$this->service->initialize($this->client);
	}

	private function requiredEnv(string $key): string {
		$value = getenv($key);
		if ($value === false || $value === '') {
			self::markTestSkipped('DAV integration test environment is not configured.');
		}

		self::assertIsString($value);

		return $value;
	}

	public function testCollectionList(): void {
		$collections = $this->service->collectionList();

		$this->assertNotEmpty($collections);

		foreach ($collections as $collection) {
			$this->assertInstanceOf(Collection::class, $collection);
			$this->assertIsString($collection->remoteId);
			$this->assertNotSame('', $collection->remoteId);
			$this->assertStringStartsWith('/', $collection->remoteId);
		}
	}

	public function testCollectionFetch(): void {
		$collections = $this->service->collectionList('default');
		$this->assertNotEmpty($collections);

		$listedCollection = $collections[0];
		$this->assertInstanceOf(Collection::class, $listedCollection);
		$this->assertIsString($listedCollection->remoteId);
		$this->assertNotSame('', $listedCollection->remoteId);

		$fetchedCollection = $this->service->collectionFetch($listedCollection->remoteId);

		$this->assertInstanceOf(Collection::class, $fetchedCollection);
		$this->assertSame($listedCollection->remoteId, $fetchedCollection->remoteId);
		$this->assertIsString($fetchedCollection->remoteId);
		$this->assertNotSame('', $fetchedCollection->remoteId);
		$this->assertTrue($fetchedCollection->label === null || is_string($fetchedCollection->label));

		foreach ($fetchedCollection->components as $component) {
			$this->assertIsString($component);
			$this->assertNotSame('', $component);
		}
	}

	public function testEntityList(): void {
		$collections = $this->service->collectionList('default');
		$this->assertNotEmpty($collections);

		$calendar = $collections[0];
		$this->assertInstanceOf(Collection::class, $calendar);
		$this->assertIsString($calendar->remoteId);
		$this->assertNotSame('', $calendar->remoteId);

		$initialEntities = $this->service->entityList($calendar->remoteId, 'basic');

		$identifier = bin2hex(random_bytes(16));
		$resourcePath = rtrim($calendar->remoteId, '/') . '/' . $identifier . '.ics';
		$this->assertArrayNotHasKey($resourcePath, $initialEntities);

		$created = false;

		try {
			$createResponse = $this->client->create(
				$resourcePath,
				$this->buildCalendarPayload($identifier, 'Created'),
				'text/calendar; charset=utf-8',
			);
			$created = true;

			$this->assertGreaterThanOrEqual(200, $createResponse['status']);
			$this->assertLessThan(300, $createResponse['status']);

			$listedEntities = $this->service->entityList($calendar->remoteId, 'basic');

			$this->assertCount(count($initialEntities) + 1, $listedEntities);
			$this->assertArrayHasKey($resourcePath, $listedEntities);
			$this->assertInstanceOf(Entity::class, $listedEntities[$resourcePath]);
			$this->assertSame($resourcePath, $listedEntities[$resourcePath]->remoteEntityId);
			$this->assertSame($calendar->remoteId, $listedEntities[$resourcePath]->remoteCollectionId);
			$this->assertIsString($listedEntities[$resourcePath]->remoteSignature);
			$this->assertNotSame('', $listedEntities[$resourcePath]->remoteSignature);
		} finally {
			if ($created) {
				try {
					$this->client->delete($resourcePath);
				} catch (\Throwable) {
				}
			}
		}
	}

	public function testEntityFetch(): void {
		$collections = $this->service->collectionList('default');
		$this->assertNotEmpty($collections);

		$calendar = $collections[0];
		$this->assertInstanceOf(Collection::class, $calendar);
		$this->assertIsString($calendar->remoteId);
		$this->assertNotSame('', $calendar->remoteId);

		$identifier = bin2hex(random_bytes(16));
		$resourcePath = rtrim($calendar->remoteId, '/') . '/' . $identifier . '.ics';
		$payload = $this->buildCalendarPayload($identifier, 'Fetch');
		$created = false;

		try {
			$createResponse = $this->client->create(
				$resourcePath,
				$payload,
				'text/calendar; charset=utf-8',
			);
			$created = true;

			$this->assertGreaterThanOrEqual(200, $createResponse['status']);
			$this->assertLessThan(300, $createResponse['status']);

			$fetchedEntity = $this->service->entityFetch($calendar->remoteId, $resourcePath);

			$this->assertInstanceOf(Entity::class, $fetchedEntity);
			$this->assertSame($calendar->remoteId, $fetchedEntity->remoteCollectionId);
			$this->assertSame($resourcePath, $fetchedEntity->remoteEntityId);
			$this->assertIsString($fetchedEntity->remoteSignature);
			$this->assertNotSame('', $fetchedEntity->remoteSignature);
			$this->assertSame($payload, $fetchedEntity->data);
		} finally {
			if ($created) {
				try {
					$this->client->delete($resourcePath);
				} catch (\Throwable) {
				}
			}
		}
	}

	public function testEntityFetchMultiple(): void {
		$collections = $this->service->collectionList('default');
		$this->assertNotEmpty($collections);

		$calendar = $collections[0];
		$this->assertInstanceOf(Collection::class, $calendar);
		$this->assertIsString($calendar->remoteId);
		$this->assertNotSame('', $calendar->remoteId);

		$firstIdentifier = bin2hex(random_bytes(16));
		$firstResourcePath = rtrim($calendar->remoteId, '/') . '/' . $firstIdentifier . '.ics';
		$firstPayload = $this->buildCalendarPayload($firstIdentifier, 'Fetch Multiple One');

		$secondIdentifier = bin2hex(random_bytes(16));
		$secondResourcePath = rtrim($calendar->remoteId, '/') . '/' . $secondIdentifier . '.ics';
		$secondPayload = $this->buildCalendarPayload($secondIdentifier, 'Fetch Multiple Two');

		$createdResourcePaths = [];

		try {
			$firstCreateResponse = $this->client->create(
				$firstResourcePath,
				$firstPayload,
				'text/calendar; charset=utf-8',
			);
			$createdResourcePaths[] = $firstResourcePath;
			$this->assertGreaterThanOrEqual(200, $firstCreateResponse['status']);
			$this->assertLessThan(300, $firstCreateResponse['status']);

			$secondCreateResponse = $this->client->create(
				$secondResourcePath,
				$secondPayload,
				'text/calendar; charset=utf-8',
			);
			$createdResourcePaths[] = $secondResourcePath;
			$this->assertGreaterThanOrEqual(200, $secondCreateResponse['status']);
			$this->assertLessThan(300, $secondCreateResponse['status']);

			$fetchedEntities = $this->service->entityFetchMultiple($calendar->remoteId, [
				$firstResourcePath,
				$secondResourcePath,
			]);

			$this->assertCount(2, $fetchedEntities);
			$this->assertArrayHasKey($firstResourcePath, $fetchedEntities);
			$this->assertArrayHasKey($secondResourcePath, $fetchedEntities);

			$this->assertInstanceOf(Entity::class, $fetchedEntities[$firstResourcePath]);
			$this->assertSame($calendar->remoteId, $fetchedEntities[$firstResourcePath]->remoteCollectionId);
			$this->assertSame($firstResourcePath, $fetchedEntities[$firstResourcePath]->remoteEntityId);
			$this->assertIsString($fetchedEntities[$firstResourcePath]->remoteSignature);
			$this->assertNotSame('', $fetchedEntities[$firstResourcePath]->remoteSignature);
			$this->assertSame($firstPayload, $fetchedEntities[$firstResourcePath]->data);

			$this->assertInstanceOf(Entity::class, $fetchedEntities[$secondResourcePath]);
			$this->assertSame($calendar->remoteId, $fetchedEntities[$secondResourcePath]->remoteCollectionId);
			$this->assertSame($secondResourcePath, $fetchedEntities[$secondResourcePath]->remoteEntityId);
			$this->assertIsString($fetchedEntities[$secondResourcePath]->remoteSignature);
			$this->assertNotSame('', $fetchedEntities[$secondResourcePath]->remoteSignature);
			$this->assertSame($secondPayload, $fetchedEntities[$secondResourcePath]->data);
		} finally {
			foreach ($createdResourcePaths as $createdResourcePath) {
				try {
					$this->client->delete($createdResourcePath);
				} catch (\Throwable) {
				}
			}
		}
	}

	public function testEntityDelta(): void {
		$collections = $this->service->collectionList('default');
		$this->assertNotEmpty($collections);

		$calendar = $collections[0];
		$this->assertInstanceOf(Collection::class, $calendar);
		$this->assertIsString($calendar->remoteId);
		$this->assertNotSame('', $calendar->remoteId);

		$this->client->options($calendar->remoteId);

		try {
			$initialDelta = $this->service->entityDelta($calendar->remoteId, '');
		} catch (\RuntimeException $e) {
			if ($e->getMessage() === 'Remote server does not support DAV sync-collection reports.') {
				self::markTestSkipped($e->getMessage());
			}

			throw $e;
		}

		$this->assertIsString($initialDelta->signature);
		$this->assertNotSame('', $initialDelta->signature);

		$identifier = bin2hex(random_bytes(16));
		$resourcePath = rtrim($calendar->remoteId, '/') . '/' . $identifier . '.ics';
		$payload = $this->buildCalendarPayload($identifier, 'Delta');
		$created = false;
		$deleted = false;

		try {
			$createResponse = $this->client->create(
				$resourcePath,
				$payload,
				'text/calendar; charset=utf-8',
			);
			$created = true;

			$this->assertGreaterThanOrEqual(200, $createResponse['status']);
			$this->assertLessThan(300, $createResponse['status']);

			$createdDelta = $this->service->entityDelta($calendar->remoteId, $initialDelta->signature);

			$this->assertIsString($createdDelta->signature);
			$this->assertNotSame('', $createdDelta->signature);
			$this->assertNotSame($initialDelta->signature, $createdDelta->signature);

			$this->assertContains($resourcePath, iterator_to_array($createdDelta->modifications, false));
			$this->assertNotContains($resourcePath, iterator_to_array($createdDelta->deletions, false));

			$deleteResponse = $this->client->delete($resourcePath);
			$deleted = true;

			$this->assertGreaterThanOrEqual(200, $deleteResponse->getStatusCode());
			$this->assertLessThan(300, $deleteResponse->getStatusCode());

			$deletedDelta = $this->service->entityDelta($calendar->remoteId, $createdDelta->signature);

			$this->assertIsString($deletedDelta->signature);
			$this->assertNotSame('', $deletedDelta->signature);
			$this->assertNotSame($createdDelta->signature, $deletedDelta->signature);

			$this->assertContains($resourcePath, iterator_to_array($deletedDelta->deletions, false));
		} finally {
			if ($created && !$deleted) {
				try {
					$this->client->delete($resourcePath);
				} catch (\Throwable) {
				}
			}
		}
	}

	public function testEntityCreate(): void {
		$collections = $this->service->collectionList('default');
		$this->assertNotEmpty($collections);

		$calendar = $collections[0];
		$this->assertInstanceOf(Collection::class, $calendar);
		$this->assertIsString($calendar->remoteId);
		$this->assertNotSame('', $calendar->remoteId);

		$identifierBase = bin2hex(random_bytes(16));
		$identifier = $identifierBase . '.ics';
		$resourcePath = rtrim($calendar->remoteId, '/') . '/' . $identifier;
		$payload = $this->buildCalendarPayload($identifierBase, 'Service Create');

		$entity = new Entity();
		$entity->remoteCollectionId = rtrim($calendar->remoteId, '/') . '/';
		$entity->remoteEntityId = $identifier;
		$entity->data = $payload;

		$created = false;

		try {
			$createdEntity = $this->service->entityCreate($entity);
			$created = true;

			$this->assertInstanceOf(Entity::class, $createdEntity);
			$this->assertSame($entity->remoteCollectionId, $createdEntity->remoteCollectionId);
			// the created entity carries the full resource path as its remote id
			$this->assertSame($resourcePath, $createdEntity->remoteEntityId);
			$this->assertSame($payload, $createdEntity->data);
			$this->assertIsString($createdEntity->remoteSignature);
			$this->assertNotSame('', $createdEntity->remoteSignature);

			$fetchedResponses = $this->client->multiGet(
				$calendar->remoteId,
				[$resourcePath],
				RemoteClient::CALDAV_CALENDAR_MULTIGET,
				RemoteClient::CALDAV_CALENDAR_DATA,
			);

			$this->assertArrayHasKey($resourcePath, $fetchedResponses);
			$this->assertSame(200, $fetchedResponses[$resourcePath]['status']);
			$this->assertSame($payload, $fetchedResponses[$resourcePath]['payload']);
			$this->assertSame($createdEntity->remoteSignature, $fetchedResponses[$resourcePath]['etag']);
		} finally {
			if ($created) {
				try {
					$this->client->delete($resourcePath);
				} catch (\Throwable) {
				}
			}
		}
	}

	public function testEntityModify(): void {
		$collections = $this->service->collectionList('default');
		$this->assertNotEmpty($collections);

		$calendar = $collections[0];
		$this->assertInstanceOf(Collection::class, $calendar);
		$this->assertIsString($calendar->remoteId);
		$this->assertNotSame('', $calendar->remoteId);

		$identifierBase = bin2hex(random_bytes(16));
		$identifier = $identifierBase . '.ics';
		$resourcePath = rtrim($calendar->remoteId, '/') . '/' . $identifier;
		$initialPayload = $this->buildCalendarPayload($identifierBase, 'Initial');
		$updatedPayload = $this->buildCalendarPayload($identifierBase, 'Modified');
		$created = false;

		try {
			$createResponse = $this->client->create(
				$resourcePath,
				$initialPayload,
				'text/calendar; charset=utf-8',
			);
			$created = true;

			$this->assertGreaterThanOrEqual(200, $createResponse['status']);
			$this->assertLessThan(300, $createResponse['status']);

			$entity = new Entity();
			$entity->remoteCollectionId = rtrim($calendar->remoteId, '/') . '/';
			$entity->remoteEntityId = $identifier;
			$entity->data = $updatedPayload;

			$modifiedEntity = $this->service->entityModify($entity);

			$this->assertInstanceOf(Entity::class, $modifiedEntity);
			$this->assertSame($entity->remoteCollectionId, $modifiedEntity->remoteCollectionId);
			$this->assertSame($entity->remoteEntityId, $modifiedEntity->remoteEntityId);
			$this->assertSame($updatedPayload, $modifiedEntity->data);
			$this->assertIsString($modifiedEntity->remoteSignature);
			$this->assertNotSame('', $modifiedEntity->remoteSignature);
			$this->assertNotSame($createResponse['etag'], $modifiedEntity->remoteSignature);

			$fetchedResponses = $this->client->multiGet(
				$calendar->remoteId,
				[$resourcePath],
				RemoteClient::CALDAV_CALENDAR_MULTIGET,
				RemoteClient::CALDAV_CALENDAR_DATA,
			);

			$this->assertArrayHasKey($resourcePath, $fetchedResponses);
			$this->assertSame(200, $fetchedResponses[$resourcePath]['status']);
			$this->assertSame($updatedPayload, $fetchedResponses[$resourcePath]['payload']);
			$this->assertSame($modifiedEntity->remoteSignature, $fetchedResponses[$resourcePath]['etag']);
		} finally {
			if ($created) {
				try {
					$this->client->delete($resourcePath);
				} catch (\Throwable) {
				}
			}
		}
	}

	public function testEntityDelete(): void {
		$collections = $this->service->collectionList('default');
		$this->assertNotEmpty($collections);

		$calendar = $collections[0];
		$this->assertInstanceOf(Collection::class, $calendar);
		$this->assertIsString($calendar->remoteId);
		$this->assertNotSame('', $calendar->remoteId);

		$identifierBase = bin2hex(random_bytes(16));
		$identifier = $identifierBase . '.ics';
		$resourcePath = rtrim($calendar->remoteId, '/') . '/' . $identifier;
		$payload = $this->buildCalendarPayload($identifierBase, 'Delete');

		$createResponse = $this->client->create(
			$resourcePath,
			$payload,
			'text/calendar; charset=utf-8',
		);

		$this->assertGreaterThanOrEqual(200, $createResponse['status']);
		$this->assertLessThan(300, $createResponse['status']);

		$deletedIdentifier = $this->service->entityDelete($calendar->remoteId, $identifier);

		$this->assertSame($identifier, $deletedIdentifier);

		$fetchedResponses = $this->client->multiGet(
			$calendar->remoteId,
			[$resourcePath],
			RemoteClient::CALDAV_CALENDAR_MULTIGET,
			RemoteClient::CALDAV_CALENDAR_DATA,
		);

		$this->assertArrayHasKey($resourcePath, $fetchedResponses);
		$this->assertNotSame(200, $fetchedResponses[$resourcePath]['status']);
	}

	private function buildCalendarPayload(string $identifier, string $summarySuffix): string {
		$timestamp = gmdate('Ymd\THis\Z');

		return implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Nextcloud DAVC//RemoteEventsServiceTest//EN',
			'BEGIN:VEVENT',
			'UID:' . $identifier,
			'DTSTAMP:' . $timestamp,
			'DTSTART:20260101T120000Z',
			'DTEND:20260101T130000Z',
			'SUMMARY:Remote Events Service Test ' . $summarySuffix,
			'END:VEVENT',
			'END:VCALENDAR',
			'',
		]);
	}

}
