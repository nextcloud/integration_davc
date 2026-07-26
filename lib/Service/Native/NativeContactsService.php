<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Service\Native;

use OCA\DAV\CardDAV\CardDavBackend;
use OCA\DAV\DAV\Sharing\Plugin as SharingPlugin;
use OCP\IDBConnection;
use Sabre\DAV\Exception\BadRequest;

class NativeContactsService {

	private const NATIVE_OBJECTS_TABLE = 'cards';

	public function __construct(
		private readonly CardDavBackend $backend,
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
			$this->backend->getAddressBooksForUser($this->principalUri($uid)),
		);
	}

	/**
	 * @return array{id: int, uri: string, label: ?string, readOnly: bool}|null
	 */
	public function collectionFetch(int $collectionId): ?array {
		$row = $this->backend->getAddressBookById($collectionId);
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
			->where($query->expr()->eq('addressbookid', $query->createNamedParameter($collectionId)));

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
			$this->backend->getMultipleCards($collectionId, $uris),
		);
	}

	/**
	 * find an existing object in the collection by UID
	 *
	 * @return array{uri: string, data: ?string}|null
	 */
	public function entityFindByUid(int $collectionId, string $uid): ?array {
		$row = $this->backend->getCardByUid($collectionId, $uid);
		return $row !== null ? $this->toEntityArray($row) : null;
	}

	/**
	 * create an object in the collection, returning null if an object with the same UID already exists
	 *
	 * @return array{uri: string, data: ?string}|null
	 */
	public function entityCreate(int $collectionId, string $uri, string $data): ?array {
		try {
			$this->backend->createCard($collectionId, $uri, $data);
		} catch (BadRequest) {
			return null;
		}

		$row = $this->backend->getCard($collectionId, $uri);
		return $row !== false ? $this->toEntityArray($row) : null;
	}

	/**
	 * modify an object in the collection
	 *
	 * @return array{uri: string, data: ?string}|null
	 */
	public function entityModify(int $collectionId, string $uri, string $data): ?array {
		$this->backend->updateCard($collectionId, $uri, $data);

		$row = $this->backend->getCard($collectionId, $uri);
		return $row !== false ? $this->toEntityArray($row) : null;
	}

	/**
	 * delete an object in the collection
	 */
	public function entityDelete(int $collectionId, string $uri): bool {
		return (bool)$this->backend->deleteCard($collectionId, $uri);
	}

	/**
	 * the DAV principal address for a Nextcloud user, as expected by CardDavBackend
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
			'data' => $row['carddata'] ?? null,
		];
	}

}
