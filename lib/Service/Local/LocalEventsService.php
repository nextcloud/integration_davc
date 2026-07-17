<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Service\Local;

use OCA\DAVC\Models\Calendars\Collection;
use OCA\DAVC\Models\Calendars\Entity;
use OCA\DAVC\Store\Common\Filters\IFilter;
use OCA\DAVC\Store\Common\Range\IRange;
use OCA\DAVC\Store\Common\Sort\ISort;
use OCA\DAVC\Store\Local\CollectionEntity;
use OCA\DAVC\Store\Local\EventEntity;
use OCA\DAVC\Store\Local\EventStore;
use OCA\DAVC\Store\Local\Filters\CollectionFilter;
use OCA\DAVC\Store\Local\Filters\EventFilter;
use OCA\DAVC\Utile\Event;
use Sabre\VObject\Reader;

class LocalEventsService {
	protected EventStore $_Store;

	public function initialize(EventStore $Store) {
		$this->_Store = $Store;
	}

	/**
	 * list collections from local storage
	 *
	 * @param IFilter $filter Filter for collections
	 *
	 * @return array<Collection>
	 */
	public function collectionList(IFilter $filter): array {
		$co = $this->_Store->collectionList($filter);

		$list = [];
		foreach ($co as $entry) {
			$list[] = $this->toCollectionModel($entry);
		}
		return $list;
	}

	public function collectionListFilter(): CollectionFilter {
		return $this->_Store->collectionListFilter();
	}

	/**
	 * retrieve collection from local storage
	 *
	 * @param int $cid Collection ID
	 *
	 * @return Collection|null
	 */
	public function collectionFetch(int $id): ?Collection {
		$co = $this->_Store->collectionFetch($id);
		return $co instanceof CollectionEntity ? $this->toCollectionModel($co) : null;
	}

	public function collectionDelta(int $id): int|float|string {
		return $this->_Store->chronicleApex($id, true);
	}

	public function collectionModify(int $id, Collection $mutation): ?Collection {
		// retrieve existing entry from data store
		$entry = $this->_Store->collectionFetch($id);

		if ($entry instanceof CollectionEntity) {
			// modify collection properties
			if (isset($mutation->label)) {
				$entry->setLabel($mutation->label);
			}
			if (isset($mutation->visible)) {
				$entry->setVisible($mutation->visible);
			}
			if (isset($mutation->color)) {
				$entry->setColor($mutation->color);
			}
			// update entry in data store
			if (count($entry->getUpdatedFields()) > 0) {
				$entry = $this->_Store->collectionModify($entry);
			}
		}

		// return modified collection
		return $this->toCollectionModel($entry);
	}

	public function collectionDelete(int $id): bool {
		$this->_Store->entityDeleteByCollection($id);
		$this->_Store->collectionDeleteById($id);
		return true;
	}

	/**
	 * retrieve list of entities from local storage
	 *
	 * @param int $cid collection id
	 *
	 * @return array collection of entities
	 */
	public function entityList(?IFilter $filter = null, ?ISort $sort = null, ?IRange $range = null): array {
		$entities = $this->_Store->entityList($filter, $sort, $range);

		$list = [];
		foreach ($entities as $entry) {
			$list[] = $this->toEntityModel($entry);
		}
		return $list;
	}

	public function entityListFilter(): EventFilter {
		return $this->_Store->entityListFilter();
	}

	public function entityListSort(): ISort {
		return $this->_Store->entityListSort();
	}

	public function entityListRange(): IRange {
		return $this->_Store->entityListRange();
	}

	/**
	 * retrieve entity object from local storage
	 *
	 * @param int $id entity id
	 *
	 * @return Entity|null
	 */
	public function entityFetch(int $id): ?Entity {
		$eo = $this->_Store->entityFetch($id);
		return $eo instanceof EventEntity ? $this->toEntityModel($eo) : null;
	}

	/**
	 * retrieve the differences for specific collection from a specific point from local storage
	 *
	 * @param string $uid user id
	 * @param int $cid collection id
	 * @param string $signature collection signature
	 *
	 * @return array collection of differences
	 */
	public function entityDelta(int $cid, string $signature): array {
		$lcc = $this->_Store->chronicleReminisce($cid, $signature);
		return $lcc;
	}

	/**
	 * retrieve entity by correlation id from local storage
	 *
	 * @param int $cid collection id
	 * @param string $ccid correlation collection id
	 * @param string $ceid correlation entity id
	 *
	 * @return Entity|null
	 */
	public function entityFetchByCorrelation(int $cid, string $ccid, string $ceid): ?Entity {
		$eo = $this->_Store->entityFetchByCorrelation($cid, $ccid, $ceid);
		return $eo instanceof EventEntity ? $this->toEntityModel($eo) : null;
	}

	/**
	 * create entity in local storage
	 *
	 * @param string $uid user id
	 * @param int $sid service id
	 * @param int $cid collection id
	 * @param Entity $so source object
	 *
	 * @return Entity
	 */
	public function entityCreate(string $uid, int $sid, int $cid, Entity $so): ?Entity {
		// convert event object to data store entity
		$eo = $this->fromEntityModel(
			$so,
			[
				'Uid' => $uid,
				'Sid' => $sid,
				'Cid' => $cid,
			]
		);
		// create entry in data store
		$eo = $this->_Store->entityCreate($eo);
		return $eo instanceof EventEntity ? $this->toEntityModel($eo) : null;
	}

	/**
	 * modify entity in local storage
	 *
	 * @param string $uid user id
	 * @param int $sid service id
	 * @param int $cid collection id
	 * @param int $eid entity id
	 * @param Entity $so source object
	 *
	 * @return Entity
	 */
	public function entityModify(string $uid, int $sid, int $cid, int $eid, Entity $so): ?Entity {
		// convert event object to data store entity
		$eo = $this->fromEntityModel(
			$so,
			[
				'Id' => $eid,
				'Uid' => $uid,
				'Sid' => $sid,
				'Cid' => $cid,
			]
		);
		// modify entry in data store
		$eo = $this->_Store->entityModify($eo);
		return $eo instanceof EventEntity ? $this->toEntityModel($eo) : null;
	}

	/**
	 * delete entity from local storage
	 *
	 * @param int $eid entity id
	 *
	 * @return bool
	 */
	public function entityDelete(int $id): bool {
		$rs = $this->_Store->entityDeleteById($id);
		return $rs ? true : false;
	}

	/**
	 * delete entity from local storage by remote id
	 *
	 * @param int $cid collection id
	 * @param string $ccid correlation collection id
	 * @param string $ceid correlation entity id
	 *
	 * @return bool
	 */
	public function entityDeleteByCorrelation(int $cid, string $ccid, string $ceid): bool {
		// retrieve entity
		$eo = $this->_Store->entityFetchByCorrelation($cid, $ccid, $ceid);
		// evaluate if entity was retrieved
		if ($eo instanceof EventEntity) {
			// delete entry from data store
			$eo = $this->_Store->entityDelete($eo);
			return true;
		} else {
			return false;
		}

	}

	/**
	 * convert store entity to collection object
	 */
	public function toCollectionModel(CollectionEntity $so): Collection {
		$collection = new Collection();
		$collection->userId = $so->getUid();
		$collection->serviceId = $so->getSid();
		$collection->localId = $so->getId();
		$collection->remoteId = $so->getCcid();
		$collection->uuid = $so->getUuid();
		$collection->permissions = $so->getPermissions();
		$collection->label = $so->getLabel();
		$collection->visible = (bool)$so->getVisible();
		$collection->color = $so->getColor();

		return $collection;
	}

	/**
	 * convert collection object to store entity
	 *
	 * @param Collection $so
	 * @param array<string,mixed> $additional
	 */
	public function fromCollectionModel(Collection $so, array $additional = []): CollectionEntity {
		$to = new CollectionEntity();
		if ($so->localId !== null) {
			$to->setId($so->localId);
		}
		$to->setUid($so->userId);
		if ($so->serviceId !== null) {
			$to->setSid($so->serviceId);
		}
		$to->setCcid($so->remoteId);
		$to->setUuid($so->uuid);
		$to->setPermissions($so->permissions);
		$to->setLabel($so->label);
		$to->setVisible((int)(bool)$so->visible);
		$to->setColor($so->color);

		foreach ($additional as $key => $value) {
			$method = 'set' . ucfirst($key);
			$to->$method($value);
		}

		return $to;
	}

	/**
	 * convert store entity to event object
	 */
	public function toEntityModel(EventEntity $so): Entity {
		$to = new Entity();
		$to->localCollectionId = $so->getCid();
		$to->localEntityId = $so->getId();
		$to->localSignature = $so->getSignature();
		$to->remoteCollectionId = $so->getCcid();
		$to->remoteEntityId = $so->getCeid();
		$to->correlationSignature = $so->getCesn();
		$to->uuid = $so->getUuid();
		$to->data = $so->getData();

		return $to;
	}

	/**
	 * convert event object to store entity
	 *
	 * @param Entity $so
	 * @param array<string,mixed> $additional
	 *
	 * @return EventEntity
	 */
	public function fromEntityModel(Entity $so, array $additional = []): EventEntity {
		// construct entity
		$to = new EventEntity();
		// convert source object to entity
		$to->setCid($so->localCollectionId);
		$to->setCcid($so->remoteCollectionId);
		$to->setCeid($so->remoteEntityId);
		$to->setCesn($so->correlationSignature);
		$to->setData($so->data);
		// calculate signature
		$signature = md5($so->data ?? '');
		$to->setSignature($signature);
		// construct correlation signature
		$to->setCesn($signature . $so->remoteSignature);
		// extract additional values from object
		/** @var \Sabre\VObject\Component\VCalendar $vo */
		$vo = Reader::read($so->data);
		try {
			$vc = $vo->getBaseComponent();

			if ($vc === null) {
				foreach ($vo->getComponents() as $component) {
					if (in_array($component->name, ['VEVENT', 'VTODO', 'VJOURNAL'], true)) {
						$vc = $component;
						break;
					}
				}
				if ($vc === null) {
					throw new \RuntimeException('Failed to parse event data: No base component found.');
				}
			}

			$to->setUuid($vc->UID->getValue());
			// Extract the start and end of the series
			[$startson, $endson] = Event::calculate($vo);
			$to->setStartson($startson);
			$to->setEndson($endson);

			if ($vc->SUMMARY) {
				$to->setLabel($vc->SUMMARY->getValue());
			}
		} catch (\Throwable $t) {
			throw new \RuntimeException('Failed to parse event data: ' . $t->getMessage());
		}

		// override / assign additional values
		foreach ($additional as $key => $value) {
			$method = 'set' . ucfirst($key);
			$to->$method($value);
		}

		return $to;
	}

}
