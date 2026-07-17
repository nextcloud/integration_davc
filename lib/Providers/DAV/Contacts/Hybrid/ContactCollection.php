<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Providers\DAV\Contacts\Hybrid;

use OCA\DAVC\Models\Contacts\Collection;
use OCA\DAVC\Models\Contacts\Entity;
use OCA\DAVC\Providers\DAV\Constants;
use OCA\DAVC\Service\Local\LocalContactsService;
use OCA\DAVC\Service\Remote\RemoteContactsService;
use OCA\DAVC\Service\Remote\RemoteFactory;
use OCA\DAVC\Store\Common\Filters\FilterComparisonOperator;
use OCA\DAVC\Store\Local\ServicesStore;
use Sabre\CardDAV\IAddressBook;
use Sabre\DAV\IMultiGet;
use Sabre\DAV\IProperties;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Sync\ISyncCollection;

class ContactCollection implements IAddressBook, IProperties, IMultiGet, ISyncCollection {

	private const DAV_USER_PREFIX = 'principals/users/';
	private ?RemoteContactsService $remoteService = null;

	public function __construct(
		private readonly ServicesStore $servicesStore,
		private readonly LocalContactsService $localService,
		private readonly RemoteFactory $remoteFactory,
		private Collection $collection,
	) {
	}

	/**
	 * Lazy load remote service
	 */
	protected function remoteService(): RemoteContactsService {

		if ($this->remoteService !== null) {
			return $this->remoteService;
		}

		$service = $this->servicesStore->fetch($this->collection->serviceId);
		if ($service === null) {
			throw new \Exception('Service not found');
		}

		$this->remoteService = $this->remoteFactory->contactsService($this->remoteFactory->freshClient($service));

		return $this->remoteService;
	}

	/**
	 * Collection principal owner
	 *
	 * @return string|null
	 */
	public function getOwner(): ?string {
		return self::DAV_USER_PREFIX . $this->collection->userId;
	}

	/**
	 * Collection resource id
	 *
	 * @return int
	 */
	public function getResourceId(): int {
		return $this->collection->localId;
	}

	/**
	 * Collection principal group
	 *
	 * @return string|null
	 */
	public function getGroup(): ?string {
		return null;
	}

	/**
	 * Collection id
	 */
	public function getName(): string {
		return (string)$this->collection->uuid;
	}

	/**
	 * Collection id
	 *
	 * @param string $id
	 */
	public function setName($id): void {
		throw new \Sabre\DAV\Exception\Forbidden('This function is not supported');
	}

	/**
	 * Collection permissions
	 *
	 * @return array
	 */
	public function getACL(): array {
		$permissions = $this->collection->permissions;
		if ($permissions === null || count($permissions) === 0) {
			$permissions = ['{DAV:}read', '{DAV:}write-properties'];
		}
		return array_map(function ($permission) {
			return [
				'privilege' => $permission,
				'principal' => $this->getOwner(),
				'protected' => true
			];
		}, $permissions);
	}

	/**
	 * Collection permissions
	 *
	 * @return void
	 */
	public function setACL(array $acl): void {
		throw new \Sabre\DAV\Exception\Forbidden('This function is not supported yet');
	}

	/**
	 * Supported permissions
	 *
	 * @return array|null
	 */
	public function getSupportedPrivilegeSet(): ?array {
		return null;
	}

	/**
	 * Collection modification timestamp
	 *
	 * @return int|null
	 */
	public function getLastModified() {
		return null;
	}

	/**
	 * Collection mutation signature
	 *
	 * @return string|null
	 */
	public function getSyncToken(): ?string {
		return $this->localService->collectionDelta($this->collection->localId);
	}

	/**
	 * Collection delta
	 *
	 * @param string $token
	 * @param int $level
	 * @param int $limit
	 *
	 * @return array|null
	 */
	public function getChanges($token, $level, $limit = null): array {
		// retrieve delta
		$delta = $this->localService->entityDelta($this->collection->localId, (string)$token, $limit);
		// convert results
		$changes['added'] = array_column($delta['additions'], 'uuid');
		$changes['modified'] = array_column($delta['modifications'], 'uuid');
		$changes['deleted'] = array_column($delta['deletions'], 'uuid');
		$changes['syncToken'] = $delta['stamp'];
		return $changes;
	}

	/**
	 * Retrieves properties for this collection
	 *
	 * @param array $properties requested properties
	 *
	 * @return array
	 */
	public function getProperties($properties): array {
		// return collection properties
		return [
			Constants::DAV_PROPERTY_DISPLAYNAME => $this->collection->label,
			Constants::DAV_PROPERTY_ADDRESSBOOK_ENABLED => (string)$this->collection->visible,
		];
	}

	/**
	 * Modifies properties of this collection
	 *
	 * @param PropPatch $data
	 *
	 * @return void
	 */
	public function propPatch(PropPatch $propPatch): void {
		// retrieve mutations
		$mutations = $propPatch->getMutations();
		// evaluate if any mutations apply
		if (count($mutations) > 0) {
			$mutation = new Collection();
			// evaluate if name was changed
			if (isset($mutations[Constants::DAV_PROPERTY_DISPLAYNAME])) {
				$mutation->label = $mutations[Constants::DAV_PROPERTY_DISPLAYNAME];
				$propPatch->setResultCode(Constants::DAV_PROPERTY_DISPLAYNAME, 200);
			}
			if (isset($mutations[Constants::DAV_PROPERTY_ADDRESSBOOK_ENABLED])) {
				$mutation->visible = (bool)$mutations[Constants::DAV_PROPERTY_ADDRESSBOOK_ENABLED];
				$propPatch->setResultCode(Constants::DAV_PROPERTY_ADDRESSBOOK_ENABLED, 200);
			}
			// update collection
			$this->collection = $this->localService->collectionModify($this->collection->localId, $mutation);
		}
	}

	/**
	 * Creates sub collection
	 *
	 * @param string $name
	 */
	public function createDirectory($name): void {
		throw new \Sabre\DAV\Exception\Forbidden('This function is not supported');
	}

	/**
	 * Deletes this collection and all entities
	 *
	 * @return void
	 */
	public function delete(): void {
		$this->localService->collectionDelete($this->collection->localId);
	}

	/**
	 * List all entities in this collection
	 *
	 * @return array<int,ContactEntity>
	 */
	public function getChildren(): array {
		// construct collection filter
		$listFilter = $this->localService->entityListFilter();
		$listFilter->condition('cid', $this->collection->localId);
		// retrieve entries
		$entries = $this->localService->entityList($listFilter);
		// transform entries
		$list = [];
		foreach ($entries as $entry) {
			$list[] = new ContactEntity($this, $entry);
		}
		return $list;
	}

	/**
	 * determine if a specific entity exists in this collection
	 *
	 * @param string $id
	 *
	 * @return bool
	 */
	public function childExists($id): bool {
		// construct filter
		$listFilter = $this->localService->entityListFilter();
		$listFilter->condition('cid', $this->collection->localId);
		$listFilter->condition('uuid', $id, FilterComparisonOperator::EQ);
		// retrieve object properties
		$entities = $this->localService->entityList($listFilter);
		// fall back to a lookup by remote entity id
		if (count($entities) === 0 && $this->collection->remoteId !== null) {
			$listFilter = $this->localService->entityListFilter();
			$listFilter->condition('cid', $this->collection->localId);
			$listFilter->condition('ceid', $this->collection->remoteId . $id, FilterComparisonOperator::EQ);
			$entities = $this->localService->entityList($listFilter);
		}
		return count($entities) > 0;
	}

	/**
	 * retrieve specific entities in this collection
	 *
	 * @param array<int,string> $ids
	 *
	 * @return array<int,ContactEntity>
	 */
	public function getMultipleChildren(array $ids): array {
		// construct filter
		$listFilter = $this->localService->entityListFilter();
		$listFilter->condition('cid', $this->collection->localId);
		$listFilter->condition('uuid', $ids, FilterComparisonOperator::IN);
		// retrieve object properties
		$entities = $this->localService->entityList($listFilter);
		// construct place holder
		$list = [];
		// convert entities
		foreach ($entities as $entry) {
			$list[] = new ContactEntity($this, $entry);
		}
		return $list;
	}

	/**
	 * retrieve a specific entity in this collection
	 *
	 * @param string $id existing entity id
	 *
	 * @return ContactEntity|false
	 */
	public function getChild($id): ContactEntity|false {
		// construct filter
		$listFilter = $this->localService->entityListFilter();
		$listFilter->condition('cid', $this->collection->localId);
		$listFilter->condition('uuid', $id, FilterComparisonOperator::EQ);
		// retrieve object properties
		$entities = $this->localService->entityList($listFilter);
		// fall back to a lookup by remote entity id
		if (count($entities) === 0 && $this->collection->remoteId !== null) {
			$listFilter = $this->localService->entityListFilter();
			$listFilter->condition('cid', $this->collection->localId);
			$listFilter->condition('ceid', $this->collection->remoteId . $id, FilterComparisonOperator::EQ);
			$entities = $this->localService->entityList($listFilter);
		}
		// evaluate if object properties where retrieved
		if (count($entities) > 0) {
			return new ContactEntity($this, $entities[0]);
		} else {
			throw new \Sabre\DAV\Exception\NotFound('Entity not found');
		}
	}

	/**
	 * create a entity in this collection
	 *
	 * @param string $id fresh entity id
	 * @param string|resource|null $data fresh entity contents
	 *
	 * @return string entity signature
	 */
	public function createFile($id, $data = null): string {

		$eo = new Entity();
		$eo->localCollectionId = $this->collection->localId;
		$eo->remoteCollectionId = $this->collection->remoteId;
		$eo->remoteEntityId = $id;
		$eo->data = $this->normalizeData($data);

		$remoteService = $this->remoteService();

		$entity = $remoteService->entityCreate($eo);
		$entity = $this->localService->entityCreate(
			$this->collection->userId,
			$this->collection->serviceId,
			$this->collection->localId,
			$entity
		);

		// return state
		return $entity->localSignature ?? '';
	}

	/**
	 * modify a entity in this collection
	 *
	 * @param Entity $entity existing entity object
	 * @param string|resource $data modified entity contents
	 *
	 * @return string entity signature
	 */
	public function modifyFile(Entity $entity, $data): string {

		$entity->data = $this->normalizeData($data);

		$remoteService = $this->remoteService();

		$entity = $remoteService->entityModify($entity);
		$entity = $this->localService->entityModify(
			$this->collection->userId,
			$this->collection->serviceId,
			$entity->localCollectionId,
			$entity->localEntityId,
			$entity
		);

		// return state
		return $entity->localSignature ?? '';
	}

	/**
	 * delete a entity in this collection
	 *
	 * @param Entity $entity existing entity object
	 *
	 * @return void
	 */
	public function deleteFile(Entity $entity): void {
		$remoteService = $this->remoteService();
		$remoteService->entityDelete($entity->remoteCollectionId, $entity->remoteEntityId);
		$this->localService->entityDelete($entity->localEntityId);
	}

	/**
	 * convert entity contents to a string
	 *
	 * @param string|resource|null $data
	 */
	private function normalizeData($data): ?string {
		if (is_resource($data)) {
			$data = stream_get_contents($data);
		}
		return is_string($data) ? $data : null;
	}
}
