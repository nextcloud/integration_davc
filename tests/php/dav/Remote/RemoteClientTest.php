<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Dav\Remote;

use OCA\DAVC\AppInfo\Application;
use OCA\DAVC\Service\Remote\RemoteClient;
use OCP\Http\Client\IClientService;
use OCP\Security\IRemoteHostValidator;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Client\ClientExceptionInterface;
use Test\TestCase;

#[Group('DB')]
class RemoteClientTest extends TestCase {
	private RemoteClient $client;

	private string $davProtocol;

	private string $davHost;

	private int $davPort;

	private string $davPath;

	private string $davUsername;

	private string $davPassword;

	protected function setUp(): void {
		parent::setUp();

		$this->davProtocol = $this->requiredEnv('DAV_PROTOCOL');
		$this->davHost = $this->requiredEnv('DAV_HOST');
		$this->davPort = (int)$this->requiredEnv('DAV_PORT');
		$this->davPath = $this->requiredEnv('DAV_PATH');
		$this->davUsername = $this->requiredEnv('DAV_USERNAME');
		$this->davPassword = $this->requiredEnv('DAV_PASSWORD');

		$container = (new Application())->getContainer();
		$this->client = new RemoteClient($container->get(IClientService::class), $container->get(IRemoteHostValidator::class));
		$this->client->configureLocation(
			$this->davProtocol,
			$this->davHost,
			$this->davPort,
			$this->davPath,
		);
		$this->client->configureTransportVerification(false);
		$this->client->setBasicAuthentication($this->davUsername, $this->davPassword);
	}

	private function requiredEnv(string $key): string {
		$value = getenv($key);
		if ($value === false || $value === '') {
			self::markTestSkipped('DAV integration test environment is not configured.');
		}

		self::assertIsString($value);

		return $value;
	}

	public function testOptions(): void {
		$response = $this->client->options($this->davPath);

		$this->assertGreaterThanOrEqual(200, $response->getStatusCode());
		$this->assertLessThan(400, $response->getStatusCode());
		$this->assertContains('REPORT', $this->client->capabilities('allow'));
		$this->assertNotEmpty($this->client->capabilities('dav'));
	}

	public function testPropFindPrincipal(): void {
		$properties = $this->client->propFind($this->davPath, 0, [
			RemoteClient::DAV_USER_PRINCIPAL,
		]);

		$this->assertNotEmpty($properties);

		$endpointProperties = current($properties);
		$this->assertIsArray($endpointProperties);
		$this->assertArrayHasKey(200, $endpointProperties);
		$this->assertArrayHasKey(RemoteClient::DAV_USER_PRINCIPAL, $endpointProperties[200]);
		$this->assertIsArray($endpointProperties[200][RemoteClient::DAV_USER_PRINCIPAL]);
		$this->assertNotEmpty($endpointProperties[200][RemoteClient::DAV_USER_PRINCIPAL]);
		$this->assertSame(RemoteClient::DAV_HREF, $endpointProperties[200][RemoteClient::DAV_USER_PRINCIPAL][0]['name'] ?? null);
		$this->assertIsString($endpointProperties[200][RemoteClient::DAV_USER_PRINCIPAL][0]['value'] ?? null);
		$this->assertNotSame('', $endpointProperties[200][RemoteClient::DAV_USER_PRINCIPAL][0]['value'] ?? '');
	}

	public function testDiscover(): void {
		$capabilities = $this->client->discover();

		$this->assertTrue($capabilities['connected']);
		$this->assertTrue($capabilities['discovery']);
		$this->assertSame(
			sprintf('%s://%s:%d%s', $this->davProtocol, $this->davHost, $this->davPort, $this->davPath),
			$capabilities['endpoint'],
		);
		$this->assertIsString($capabilities['principalUrl']);
		$this->assertNotSame('', $capabilities['principalUrl']);
		$this->assertStringStartsWith('/', $capabilities['principalUrl']);
		$this->assertIsString($capabilities['calendarHomeSet']);
		$this->assertNotSame('', $capabilities['calendarHomeSet']);
		$this->assertStringStartsWith('/', $capabilities['calendarHomeSet']);
		$this->assertIsString($capabilities['addressbookHomeSet']);
		$this->assertNotSame('', $capabilities['addressbookHomeSet']);
		$this->assertStringStartsWith('/', $capabilities['addressbookHomeSet']);
	}

	public function testPropFindCalendarHomeSet(): void {
		$capabilities = $this->client->discover();
		$calendarHomeSet = $capabilities['calendarHomeSet'] ?? null;

		$this->assertIsString($calendarHomeSet);
		$this->assertNotSame('', $calendarHomeSet);

		$properties = $this->client->propFind($calendarHomeSet, 1, [
			RemoteClient::DAV_RESOURCE_TYPE,
			RemoteClient::DAV_DISPLAYNAME,
			RemoteClient::DAV_SYNC_TOKEN,
			RemoteClient::CALENDARSERVER_GETCTAG,
			RemoteClient::SABREDAV_SYNC_TOKEN,
		]);

		$this->assertNotEmpty($properties);

		$calendarCollections = $this->filterCollectionPathsByType($properties, RemoteClient::CALDAV_CALENDAR_TYPE);
		$this->assertNotEmpty($calendarCollections);
	}

	public function testPropFindAddressbookHomeSet(): void {
		$capabilities = $this->client->discover();
		$addressbookHomeSet = $capabilities['addressbookHomeSet'] ?? null;

		$this->assertIsString($addressbookHomeSet);
		$this->assertNotSame('', $addressbookHomeSet);

		$properties = $this->client->propFind($addressbookHomeSet, 1, [
			RemoteClient::DAV_RESOURCE_TYPE,
			RemoteClient::DAV_DISPLAYNAME,
			RemoteClient::DAV_SYNC_TOKEN,
			RemoteClient::CALENDARSERVER_GETCTAG,
			RemoteClient::SABREDAV_SYNC_TOKEN,
		]);

		$this->assertNotEmpty($properties);

		$addressbookCollections = $this->filterCollectionPathsByType($properties, RemoteClient::CARDDAV_ADDRESSBOOK_TYPE);
		$this->assertNotEmpty($addressbookCollections);
	}

	public function testReportOnCalendar(): void {
		$capabilities = $this->client->discover();
		$calendarHomeSet = $capabilities['calendarHomeSet'] ?? null;

		$this->assertIsString($calendarHomeSet);
		$this->assertNotSame('', $calendarHomeSet);

		$calendarProperties = $this->client->propFind($calendarHomeSet, 1, [
			RemoteClient::DAV_RESOURCE_TYPE,
			RemoteClient::DAV_DISPLAYNAME,
			RemoteClient::DAV_SYNC_TOKEN,
			RemoteClient::CALENDARSERVER_GETCTAG,
			RemoteClient::SABREDAV_SYNC_TOKEN,
		]);

		$calendarCollections = $this->filterCollectionPathsByType($calendarProperties, RemoteClient::CALDAV_CALENDAR_TYPE);
		if ($calendarCollections === []) {
			self::markTestSkipped('No remote calendar collections are available for REPORT testing.');
		}

		$calendarPath = $calendarCollections[0];

		$this->client->options($calendarPath);

		if (!in_array('sync-collection', $this->client->capabilities('dav'), true) && !in_array('REPORT', $this->client->capabilities('allow'), true)) {
			self::markTestSkipped('DAV sync-collection is not advertised by the remote calendar collection.');
		}

		$responses = $this->client->report($calendarPath, RemoteClient::DAV_SYNC_COLLECTION, 0, [
			[
				'name' => RemoteClient::DAV_SYNC_TOKEN,
				'value' => '',
			],
			[
				'name' => RemoteClient::DAV_SYNC_LEVEL,
				'value' => '1',
			],
			[
				'name' => RemoteClient::DAV_PROPERTY,
				'value' => [
					RemoteClient::DAV_RESOURCE_TYPE => null,
					RemoteClient::DAV_ETAG => null,
				],
			],
		]);

		$this->assertIsArray($responses);
		$this->assertArrayHasKey('token', $responses);
		$this->assertIsString($responses['token']);
		$this->assertNotSame('', $responses['token']);
	}

	public function testReportOnAddressbook(): void {
		$capabilities = $this->client->discover();
		$addressbookHomeSet = $capabilities['addressbookHomeSet'] ?? null;

		$this->assertIsString($addressbookHomeSet);
		$this->assertNotSame('', $addressbookHomeSet);

		$addressbookProperties = $this->client->propFind($addressbookHomeSet, 1, [
			RemoteClient::DAV_RESOURCE_TYPE,
			RemoteClient::DAV_DISPLAYNAME,
			RemoteClient::DAV_SYNC_TOKEN,
			RemoteClient::CALENDARSERVER_GETCTAG,
			RemoteClient::SABREDAV_SYNC_TOKEN,
		]);

		$addressbookCollections = $this->filterCollectionPathsByType($addressbookProperties, RemoteClient::CARDDAV_ADDRESSBOOK_TYPE);
		if ($addressbookCollections === []) {
			self::markTestSkipped('No remote addressbook collections are available for REPORT testing.');
		}

		$addressbookPath = $addressbookCollections[0];

		$this->client->options($addressbookPath);

		if (!in_array('sync-collection', $this->client->capabilities('dav'), true) && !in_array('REPORT', $this->client->capabilities('allow'), true)) {
			self::markTestSkipped('DAV sync-collection is not advertised by the remote addressbook collection.');
		}

		$responses = $this->client->report($addressbookPath, RemoteClient::DAV_SYNC_COLLECTION, 0, [
			[
				'name' => RemoteClient::DAV_SYNC_TOKEN,
				'value' => '',
			],
			[
				'name' => RemoteClient::DAV_SYNC_LEVEL,
				'value' => '1',
			],
			[
				'name' => RemoteClient::DAV_PROPERTY,
				'value' => [
					RemoteClient::DAV_RESOURCE_TYPE => null,
					RemoteClient::DAV_ETAG => null,
				],
			],
		]);

		$this->assertIsArray($responses);
		$this->assertArrayHasKey('token', $responses);
		$this->assertIsString($responses['token']);
		$this->assertNotSame('', $responses['token']);
	}

	public function testCreateUpdateDeleteOnCalendar(): void {
		$calendarPath = $this->discoverFirstCollectionPath('calendarHomeSet', RemoteClient::CALDAV_CALENDAR_TYPE);
		if ($calendarPath === null) {
			self::markTestSkipped('No remote calendar collections are available for write testing.');
		}

		$identifier = bin2hex(random_bytes(16));
		$resourcePath = rtrim($calendarPath, '/') . '/' . $identifier . '.ics';
		$created = false;
		$deleted = false;

		try {
			$create = $this->client->create(
				$resourcePath,
				$this->buildCalendarPayload($identifier, 'Created'),
				'text/calendar; charset=utf-8',
			);
			$created = true;

			$this->assertGreaterThanOrEqual(200, $create['status']);
			$this->assertLessThan(300, $create['status']);

			$createdEtag = $this->extractStringPropertyValue(
				$this->client->propFind($resourcePath, 0, [RemoteClient::DAV_ETAG]),
				RemoteClient::DAV_ETAG,
			) ?? $create['etag'];
			$this->assertIsString($createdEtag);
			$this->assertNotSame('', $createdEtag);

			$update = $this->client->update(
				$resourcePath,
				$this->buildCalendarPayload($identifier, 'Updated'),
				'text/calendar; charset=utf-8',
				$createdEtag,
			);

			$this->assertGreaterThanOrEqual(200, $update['status']);
			$this->assertLessThan(300, $update['status']);

			$updatedEtag = $this->extractStringPropertyValue(
				$this->client->propFind($resourcePath, 0, [RemoteClient::DAV_ETAG]),
				RemoteClient::DAV_ETAG,
			) ?? $update['etag'];
			$this->assertIsString($updatedEtag);
			$this->assertNotSame('', $updatedEtag);
			$this->assertNotSame($createdEtag, $updatedEtag);

			$deleteResponse = $this->client->delete($resourcePath);
			$deleted = true;

			$this->assertGreaterThanOrEqual(200, $deleteResponse->getStatusCode());
			$this->assertLessThan(300, $deleteResponse->getStatusCode());
			$this->assertDeletedResourceIsUnavailable($resourcePath);
		} finally {
			if ($created && !$deleted) {
				try {
					$this->client->delete($resourcePath);
				} catch (\Throwable) {
				}
			}
		}
	}

	public function testCreateUpdateDeleteOnAddressbook(): void {
		$addressbookPath = $this->discoverFirstCollectionPath('addressbookHomeSet', RemoteClient::CARDDAV_ADDRESSBOOK_TYPE);
		if ($addressbookPath === null) {
			self::markTestSkipped('No remote addressbook collections are available for write testing.');
		}

		$identifier = bin2hex(random_bytes(16));
		$resourcePath = rtrim($addressbookPath, '/') . '/' . $identifier . '.vcf';
		$created = false;
		$deleted = false;

		try {
			$create = $this->client->create(
				$resourcePath,
				$this->buildAddressBookPayload($identifier, 'Created'),
				'text/vcard; charset=utf-8',
			);
			$created = true;

			$this->assertGreaterThanOrEqual(200, $create['status']);
			$this->assertLessThan(300, $create['status']);

			$createdEtag = $this->extractStringPropertyValue(
				$this->client->propFind($resourcePath, 0, [RemoteClient::DAV_ETAG]),
				RemoteClient::DAV_ETAG,
			) ?? $create['etag'];
			$this->assertIsString($createdEtag);
			$this->assertNotSame('', $createdEtag);

			$update = $this->client->update(
				$resourcePath,
				$this->buildAddressBookPayload($identifier, 'Updated'),
				'text/vcard; charset=utf-8',
				$createdEtag,
			);

			$this->assertGreaterThanOrEqual(200, $update['status']);
			$this->assertLessThan(300, $update['status']);

			$updatedEtag = $this->extractStringPropertyValue(
				$this->client->propFind($resourcePath, 0, [RemoteClient::DAV_ETAG]),
				RemoteClient::DAV_ETAG,
			) ?? $update['etag'];
			$this->assertIsString($updatedEtag);
			$this->assertNotSame('', $updatedEtag);
			$this->assertNotSame($createdEtag, $updatedEtag);

			$deleteResponse = $this->client->delete($resourcePath);
			$deleted = true;

			$this->assertGreaterThanOrEqual(200, $deleteResponse->getStatusCode());
			$this->assertLessThan(300, $deleteResponse->getStatusCode());
			$this->assertDeletedResourceIsUnavailable($resourcePath);
		} finally {
			if ($created && !$deleted) {
				try {
					$this->client->delete($resourcePath);
				} catch (\Throwable) {
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $responses
	 * @return list<string>
	 */
	private function filterCollectionPathsByType(array $responses, string $collectionType): array {
		$matches = [];

		foreach ($responses as $path => $responseProperties) {
			$resourceType = $responseProperties[200][RemoteClient::DAV_RESOURCE_TYPE] ?? null;
			if ($resourceType === null || !method_exists($resourceType, 'getValue')) {
				continue;
			}

			$values = $resourceType->getValue();
			if (!is_array($values) || !in_array($collectionType, $values, true)) {
				continue;
			}

			$matches[] = (string)$path;
		}

		return $matches;
	}

	private function discoverFirstCollectionPath(string $homeSetKey, string $collectionType): ?string {
		$capabilities = $this->client->discover();
		$homeSet = $capabilities[$homeSetKey] ?? null;
		if (!is_string($homeSet) || $homeSet === '') {
			return null;
		}

		$properties = $this->client->propFind($homeSet, 1, [
			RemoteClient::DAV_RESOURCE_TYPE,
			RemoteClient::DAV_DISPLAYNAME,
			RemoteClient::DAV_SYNC_TOKEN,
			RemoteClient::CALENDARSERVER_GETCTAG,
			RemoteClient::SABREDAV_SYNC_TOKEN,
		]);

		$collections = $this->filterCollectionPathsByType($properties, $collectionType);

		return $collections[0] ?? null;
	}

	private function buildCalendarPayload(string $identifier, string $summarySuffix): string {
		$timestamp = gmdate('Ymd\THis\Z');

		return implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Nextcloud DAVC//RemoteClientTest//EN',
			'BEGIN:VEVENT',
			'UID:' . $identifier,
			'DTSTAMP:' . $timestamp,
			'DTSTART:20260101T120000Z',
			'DTEND:20260101T130000Z',
			'SUMMARY:Remote Client Test ' . $summarySuffix,
			'END:VEVENT',
			'END:VCALENDAR',
			'',
		]);
	}

	private function buildAddressBookPayload(string $identifier, string $labelSuffix): string {
		return implode("\r\n", [
			'BEGIN:VCARD',
			'VERSION:4.0',
			'UID:' . $identifier,
			'FN:Remote Client Test ' . $labelSuffix,
			'N:Test;' . $labelSuffix . ';;;',
			'EMAIL:' . $identifier . '@example.com',
			'END:VCARD',
			'',
		]);
	}

	private function extractStringPropertyValue(array $responses, string $propertyName): ?string {
		foreach ($responses as $responseProperties) {
			$value = $responseProperties[200][$propertyName] ?? null;
			if (is_string($value) && $value !== '') {
				return $value;
			}

			if (is_object($value) && method_exists($value, '__toString')) {
				$stringValue = (string)$value;
				if ($stringValue !== '') {
					return $stringValue;
				}
			}
		}

		return null;
	}

	private function assertDeletedResourceIsUnavailable(string $resourcePath): void {
		try {
			$this->assertFalse($this->hasSuccessfulPropFindResponse(
				$this->client->propFind($resourcePath, 0, [RemoteClient::DAV_ETAG]),
			));
		} catch (ClientExceptionInterface $e) {
			$this->assertStringContainsString('404', $e->getMessage());
		}
	}

	private function hasSuccessfulPropFindResponse(array $responses): bool {
		foreach ($responses as $responseProperties) {
			if (isset($responseProperties[200])) {
				return true;
			}
		}

		return false;
	}

}
