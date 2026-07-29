<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Service\Remote;

use OCA\DAVC\Models\Calendars\Collection;
use OCA\DAVC\Models\Calendars\Entity;
use OCA\DAVC\Models\DeltaObject;
use RuntimeException;

class RemoteEventsService {

	protected RemoteClient $dataStore;

	protected array $collectionPropertiesDefault = [
		RemoteClient::DAV_RESOURCE_TYPE,
		RemoteClient::DAV_DISPLAYNAME,
		RemoteClient::CALDAV_CALENDAR_DESCRIPTION,
		RemoteClient::CALDAV_SUPPORTED_CALENDAR_COMPONENT_SET,
		RemoteClient::APPLE_ICAL_CALENDAR_COLOR,
		RemoteClient::APPLE_ICAL_CALENDAR_ORDER,
		RemoteClient::DAV_OWNER,
		RemoteClient::DAV_ACL,
		RemoteClient::DAV_CURRENT_USER_PRIVILEGE_SET,
		RemoteClient::DAV_SYNC_TOKEN,
		RemoteClient::CALENDARSERVER_GETCTAG,
		RemoteClient::SABREDAV_SYNC_TOKEN,
	];
	protected array $collectionPropertiesBasic = [
		RemoteClient::DAV_RESOURCE_TYPE,
		RemoteClient::DAV_DISPLAYNAME,
		RemoteClient::DAV_SYNC_TOKEN,
		RemoteClient::CALENDARSERVER_GETCTAG,
		RemoteClient::SABREDAV_SYNC_TOKEN,
	];
	protected array $entityPropertiesDefault = [];
	protected array $entityPropertiesBasic = [
		RemoteClient::DAV_RESOURCE_TYPE,
		RemoteClient::DAV_ETAG,
	];

	public function __construct() {
	}

	public function initialize(RemoteClient $dataStore) {
		if ($dataStore->getCalendarHome() === null) {
			throw new RuntimeException('Remote calendar home set is not configured.');
		}

		$this->dataStore = $dataStore;

		// authenticate calendar home
		$this->dataStore->options($this->dataStore->getCalendarHome());
	}

	/**
	 * list of collections in remote storage
	 *
	 * @return Collection[]
	 *
	 * @psalm-return list<OCA\DAVC\Models\Calendars\Collection>
	 */
	public function collectionList(string $granularity = 'basic'): array {
		// transceive
		$data = $this->dataStore->propFind(
			$this->dataStore->getCalendarHome(),
			1,
			$granularity === 'basic' ? $this->collectionPropertiesBasic : $this->collectionPropertiesDefault
		);

		// convert dav properties to collection objects
		$list = [];
		foreach ($data as $id => $so) {
			// extract only successful properties
			$properties = $so[200] ?? [];
			// validate calendar collection
			if (!isset($properties[RemoteClient::DAV_RESOURCE_TYPE])) {
				continue;
			}
			if (!in_array(RemoteClient::CALDAV_CALENDAR_TYPE, $properties[RemoteClient::DAV_RESOURCE_TYPE]->getValue(), true)) {
				continue;
			}

			$list[] = $this->toCollectionModel($id, $properties);

		}
		// return collection of collections
		return $list;
	}

	/**
	 * retrieve properties for specific collection
	 */
	public function collectionFetch(string $identifier): ?Collection {
		$data = $this->dataStore->propFind($identifier, 0, $this->collectionPropertiesDefault);

		foreach ($data as $id => $so) {
			$properties = $so[200] ?? [];
			if (!isset($properties[RemoteClient::DAV_RESOURCE_TYPE])) {
				continue;
			}
			if (!in_array(RemoteClient::CALDAV_CALENDAR_TYPE, $properties[RemoteClient::DAV_RESOURCE_TYPE]->getValue(), true)) {
				continue;
			}

			return $this->toCollectionModel($id, $properties);
		}

		return null;
	}

	/**
	 * retrieve entities from remote storage
	 *
	 * @param string|null $location Id of parent collection
	 * @param string|null $granularity Amount of detail to return
	 */
	public function entityList(?string $location = null, ?string $granularity = null): array {

		$properties = $granularity === 'basic' ? $this->entityPropertiesBasic : $this->entityPropertiesDefault;

		$resources = $this->dataStore->propFind($location, 1, $properties);

		$list = [];
		foreach ($resources as $identifier => $resource) {
			// Some server implementations include all collection members in the response of a sync-collection report on the collection itself
			if (isset($resource[200][RemoteClient::DAV_RESOURCE_TYPE]) && $resource[200][RemoteClient::DAV_RESOURCE_TYPE] !== null) {
				continue;
			}

			if (!isset($resource[200][RemoteClient::DAV_ETAG])) {
				continue;
			}

			$entity = $this->toEntityModel($resource[200], ['remoteEntityId' => $identifier, 'remoteCollectionId' => $location]);
			$list[$identifier] = $entity;
		}
		return $list;
	}

	/**
	 * retrieve entity(ies) from remote storage
	 *
	 * @param string $identifier Id of entity
	 *
	 * @return Entity|null
	 */
	public function entityFetch(string $location, string $identifier): ?Entity {
		$responses = $this->dataStore->multiGet(
			$location,
			[$identifier],
			RemoteClient::CALDAV_CALENDAR_MULTIGET,
			RemoteClient::CALDAV_CALENDAR_DATA,
		);

		if (isset($responses[$identifier])) {
			$response = $responses[$identifier];
			if ($response['status'] === 200) {
				return $this->toEntityModel($response, ['remoteEntityId' => $identifier, 'remoteCollectionId' => $location]);
			}
		}

		return null;
	}

	/**
	 * retrieve entity(ies) from remote storage
	 *
	 * @param array<string> $identifiers Id of entity
	 *
	 * @return array<string,Entity> list of entities indexed by id
	 */
	public function entityFetchMultiple(string $location, array $identifiers): array {
		$responses = $this->dataStore->multiGet(
			$location,
			$identifiers,
			RemoteClient::CALDAV_CALENDAR_MULTIGET,
			RemoteClient::CALDAV_CALENDAR_DATA,
		);

		$entities = [];
		foreach ($identifiers as $identifier) {
			if (isset($responses[$identifier])) {
				$response = $responses[$identifier];
				if ($response['status'] === 200) {
					$entities[$identifier] = $this->toEntityModel($response, ['remoteEntityId' => $identifier, 'remoteCollectionId' => $location]);
				}
			}
		}

		return $entities;
	}

	/**
	 * delta for entities in remote storage
	 *
	 * @return DeltaObject
	 */
	public function entityDelta(string $location, string $state): DeltaObject {
		$davAbilities = $this->dataStore->capabilities('dav') ?? [];
		$davMethods = $this->dataStore->capabilities('allow') ?? [];

		if (in_array('sync-collection', $davAbilities, true) === false && in_array('REPORT', $davMethods, true) === false) {
			throw new RuntimeException('Remote server does not support DAV sync-collection reports.');
		}

		$delta = new DeltaObject();

		try {
			$responses = $this->dataStore->report($location, RemoteClient::DAV_SYNC_COLLECTION, 0, [
				[
					'name' => RemoteClient::DAV_SYNC_TOKEN,
					'value' => $state,
				],
				[
					'name' => RemoteClient::DAV_SYNC_LEVEL	,
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

			if (isset($responses['token'])) {
				$delta->signature = $responses['token'];
				unset($responses['token']);
			}

			foreach ($responses as $href => $response) {
				// Some server implementations return no status for deleted items
				if (!isset($response[200]) && !isset($response[404])) {
					$delta->deletions->append((string)$href);
					continue;
				}
				// Some server implementations return 404 for deleted items
				if (isset($response[404])) {
					$delta->deletions->append((string)$href);
					continue;
				}

				// Some server implementations include all collection members in the response of a sync-collection report on the collection itself
				if (isset($response[200][RemoteClient::DAV_RESOURCE_TYPE]) && $response[200][RemoteClient::DAV_RESOURCE_TYPE] !== null) {
					continue;
				}

				if (!isset($response[200][RemoteClient::DAV_ETAG])) {
					continue;
				}

				// DAV sync reports do not distinguish created from modified items.
				$delta->modifications->append((string)$href);
			}

			return $delta;
		} catch (\Throwable) {
			throw new RuntimeException('Failed to retrieve delta from remote server.');
		}
	}

	/**
	 * create entity in remote storage
	 */
	public function entityCreate(Entity $so): ?Entity {

		if (str_starts_with($so->remoteEntityId, $so->remoteCollectionId)) {
			$path = $so->remoteEntityId;
		} else {
			$path = $so->remoteCollectionId . $so->remoteEntityId;
		}
		$data = $so->data;

		$result = $this->dataStore->create($path, $data, 'text/calendar; charset=utf-8');

		$ro = clone $so;
		// persist the full resource path so the stored identifier matches what a
		// subsequent pull from the remote server would report for this entity
		$ro->remoteEntityId = $path;
		$ro->remoteSignature = $result['etag'] ?? null;

		return $ro;
	}

	/**
	 * update entity in remote storage
	 */
	public function entityModify(Entity $so): ?Entity {

		if (str_starts_with($so->remoteEntityId, $so->remoteCollectionId)) {
			$path = $so->remoteEntityId;
		} else {
			$path = $so->remoteCollectionId . $so->remoteEntityId;
		}
		$data = $so->data;

		$result = $this->dataStore->update($path, $data, 'text/calendar; charset=utf-8');

		$ro = clone $so;
		$ro->remoteSignature = $result['etag'] ?? null;

		return $ro;
	}

	/**
	 * delete entity from remote storage
	 */
	public function entityDelete(string $location, string $identifier): ?string {
		if (str_starts_with($identifier, $location)) {
			$path = $identifier;
		} else {
			$path = rtrim($location, '/') . '/' . ltrim($identifier, '/');
		}

		$this->dataStore->delete($path);
		return $identifier;
	}

	/**
	 * move entity in remote storage
	 */
	public function entityMove(string $sourceLocation, string $identifier, string $destinationLocation): string {
		return '';
	}

	/**
	 * convert dav collection to event collection
	 */
	private function toCollectionModel(string $id, array $so): Collection {
		$to = new Collection();
		$to->remoteId = $id;
		$to->remoteSignature = $so[RemoteClient::DAV_SYNC_TOKEN] ?? $so[RemoteClient::SABREDAV_SYNC_TOKEN] ?? $so[RemoteClient::CALENDARSERVER_GETCTAG] ?? null;
		$to->label = $so[RemoteClient::DAV_DISPLAYNAME] ?? null;
		$to->description = $so[RemoteClient::CALDAV_CALENDAR_DESCRIPTION] ?? null;
		$to->priority = isset($so[RemoteClient::APPLE_ICAL_CALENDAR_ORDER]) ? (int)$so[RemoteClient::APPLE_ICAL_CALENDAR_ORDER] : null;
		$to->color = $so[RemoteClient::APPLE_ICAL_CALENDAR_COLOR] ?? null;
		$to->components = RemoteConvert::extractSupportedComponents($so[RemoteClient::CALDAV_SUPPORTED_CALENDAR_COMPONENT_SET] ?? null);

		$principalUrl = RemoteConvert::extractUrlPath($this->dataStore->getPrincipalUrl());
		$currentUserPrivilegeSet = $so[RemoteClient::DAV_CURRENT_USER_PRIVILEGE_SET] ?? null;
		$acl = $so[RemoteClient::DAV_ACL] ?? null;
		$owner = isset($so[RemoteClient::DAV_OWNER]) ? RemoteConvert::extractPrincipal($so[RemoteClient::DAV_OWNER]) : null;

		if (is_array($currentUserPrivilegeSet)) {
			// server-resolved effective privileges for the authenticated user, per RFC 3744 5.4;
			// does not depend on {DAV:}owner or on matching principal hrefs from {DAV:}acl ourselves
			$to->permissions = RemoteConvert::extractPrivilegeNames($currentUserPrivilegeSet);
		} elseif (is_array($acl)) {
			// best-effort fallback: only the ACE granted directly to the authenticated
			// principal is used, since {DAV:}acl may also contain unrelated ACEs (e.g. for
			// other users or groups) that must not be attributed to the current user
			$permissions = RemoteConvert::extractPermissions($acl);
			$to->permissions = $permissions[$principalUrl] ?? [];
		} elseif ($owner !== null && RemoteConvert::extractUrlPath($owner) === $principalUrl) {
			// Server did not return ACL entries or a privilege set; infer owner-level
			// permission when the collection owner matches the authenticated principal.
			$to->permissions = ['{DAV:}all'];
		} else {
			$to->permissions = [];
		}

		return $to;
	}

	/**
	 * convert remote calendar payload to entity
	 *
	 * @param array<string, mixed> $additional
	 */
	public function toEntityModel(array $data, array $additional = []): Entity {
		$to = new Entity();
		$to->remoteEntityId = $data['href'] ?? null;
		$to->remoteSignature = $data['etag'] ?? $data[RemoteClient::DAV_ETAG] ?? md5($data['payload'] ?? '');
		$to->data = $data['payload'] ?? null;

		foreach ($additional as $label => $value) {
			if (property_exists($to, $label)) {
				$to->$label = $value;
			}
		}

		return $to;
	}

}
