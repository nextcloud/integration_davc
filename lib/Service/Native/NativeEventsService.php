<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Service\Native;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\DAV\Sharing\Plugin as SharingPlugin;
use OCP\IDBConnection;
use Sabre\DAV\Exception\BadRequest;

class NativeEventsService {

	private const NATIVE_OBJECTS_TABLE = 'calendarobjects';

	public function __construct(
		private readonly CalDavBackend $backend,
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * list of collections accessible by a user
	 *
	 * @return array<array{id: int, uri: string, label: ?string, readOnly: bool}>
	 */
	public function collectionList(string $uid): array {
		return array_map(
			fn (array $row) => $this->toCollectionArray($row),
			$this->backend->getCalendarsForUser($this->principalUri($uid)),
		);
	}

	/**
	 * @return array{id: int, uri: string, label: ?string, readOnly: bool}|null
	 */
	public function collectionFetch(int $collectionId): ?array {
		$row = $this->backend->getCalendarById($collectionId);
		return $row !== null ? $this->toCollectionArray($row) : null;
	}

	/**
	 * list the uris of every object in a collection
	 *
	 * @return array<string>
	 */
	public function entityListUris(int $collectionId): array {
		$query = $this->db->getQueryBuilder();
		$query->select('uri')
			->from(self::NATIVE_OBJECTS_TABLE)
			->where($query->expr()->eq('calendarid', $query->createNamedParameter($collectionId)))
			->andWhere($query->expr()->eq('calendartype', $query->createNamedParameter(CalDavBackend::CALENDAR_TYPE_CALENDAR)))
			->andWhere($query->expr()->isNull('deleted_at'));

		return $query->executeQuery()->fetchFirstColumn();
	}

	/**
	 * fetch full objects for a chunk of uris
	 *
	 * @param array<string> $uris
	 *
	 * @return array<array{uri: string, data: ?string}>
	 */
	public function entityFetchMultiple(int $collectionId, array $uris): array {
		return array_map(
			fn (array $row) => $this->toEntityArray($row),
			$this->backend->getMultipleCalendarObjects($collectionId, $uris),
		);
	}

	/**
	 * find an existing object in the collection by UID
	 *
	 * @return array{uri: string, data: ?string}|null
	 */
	public function entityFindByUid(int $collectionId, string $uid, string $objectUid): ?array {
		$collection = $this->collectionFetch($collectionId);
		if ($collection === null) {
			return null;
		}

		$path = $this->backend->getCalendarObjectByUID($this->principalUri($uid), $objectUid, $collection['uri']);
		if ($path === null) {
			return null;
		}

		$objectUri = substr($path, strlen($collection['uri']) + 1);
		$row = $this->backend->getCalendarObject($collectionId, $objectUri);

		return $row !== null ? $this->toEntityArray($row) : null;
	}

	/**
	 * create an object in the collection, returning null if an object with the same UID already exists
	 *
	 * @return array{uri: string, data: ?string}|null
	 */
	public function entityCreate(int $collectionId, string $uri, string $data): ?array {
		try {
			$this->backend->createCalendarObject($collectionId, $uri, $data);
		} catch (BadRequest) {
			return null;
		}

		$row = $this->backend->getCalendarObject($collectionId, $uri);
		return $row !== null ? $this->toEntityArray($row) : null;
	}

	/**
	 * modify an object in the collection
	 *
	 * @return array{uri: string, data: ?string}|null
	 */
	public function entityModify(int $collectionId, string $uri, string $data): ?array {
		$this->backend->updateCalendarObject($collectionId, $uri, $data);

		$row = $this->backend->getCalendarObject($collectionId, $uri);
		return $row !== null ? $this->toEntityArray($row) : null;
	}

	/**
	 * delete an object in the collection
	 */
	public function entityDelete(int $collectionId, string $uri): bool {
		$this->backend->deleteCalendarObject($collectionId, $uri, $this->backend::CALENDAR_TYPE_CALENDAR, true);
		return true;
	}

	/**
	 * the DAV principal address for a Nextcloud user, as expected by CalDavBackend
	 */
	private function principalUri(string $uid): string {
		return 'principals/users/' . $uid;
	}

	/**
	 * convert a collection row
	 *
	 * @return array{id: int, uri: string, label: ?string, readOnly: bool}
	 */
	private function toCollectionArray(array $row): array {
		return [
			'id' => (int)$row['id'],
			'uri' => (string)$row['uri'],
			'label' => $row['{DAV:}displayname'] ?? null,
			'readOnly' => (bool)($row['{' . SharingPlugin::NS_OWNCLOUD . '}read-only'] ?? false),
		];
	}

	/**
	 * convert an entity row
	 *
	 * @return array{uri: string, data: ?string}
	 */
	private function toEntityArray(array $row): array {
		return [
			'uri' => (string)$row['uri'],
			'data' => $row['calendardata'] ?? null,
		];
	}

}
