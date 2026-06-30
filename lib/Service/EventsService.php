<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Service;

use OCA\DAVC\AppInfo\Application;
use OCA\DAVC\Models\Calendars\Entity;
use OCA\DAVC\Models\DeltaObject;
use OCA\DAVC\Models\HarmonizationStatistics;
use OCA\DAVC\Service\Local\LocalEventsService;
use OCA\DAVC\Service\Local\LocalFactory;
use OCA\DAVC\Service\Remote\RemoteClient;
use OCA\DAVC\Service\Remote\RemoteEventsService;
use OCA\DAVC\Service\Remote\RemoteFactory;
use OCA\DAVC\Store\Local\CollectionEntity;
use OCA\DAVC\Store\Local\EventStore;
use OCA\DAVC\Store\Local\ServiceEntity;
use Psr\Log\LoggerInterface;

class EventsService {
	private bool $debug;
	private string $userId;
	private ServiceEntity $service;
	private RemoteEventsService $remoteEventsService;
	private LocalEventsService $localEventsService;
	private RemoteClient $remoteStore;
	private EventStore $localStore;

	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly LocalFactory $localFactory,
		private readonly RemoteFactory $remoteFactory,
	) {
	}

	/**
	 * Perform harmonization for all collections for a service
	 */
	public function harmonize(string $uid, ServiceEntity $service, RemoteClient $remoteStore) {

		$this->userId = $uid;
		$this->service = $service;
		$this->remoteStore = $remoteStore;
		// assign service defaults
		$this->debug = (bool)$service->getDebug();
		// initialize service remote and local services
		$this->remoteEventsService = $this->remoteFactory->eventsService($this->remoteStore);
		$this->localEventsService = $this->localFactory->eventsService($uid);
		$this->localStore = $this->localFactory->eventsStore();

		// retrieve list of collections
		$collections = $this->localStore->collectionListByService($this->service->getId());
		// iterate through collections
		foreach ($collections as $collection) {
			// evaluate if collection is locked and lock has not expired
			if ($collection->getHlock() == 1
			   && (time() - $collection->getHlockhb()) < 3600) {
				continue;
			}
			// lock collection before harmonization
			if (!$this->debug) {
				$collection->setHlock(1);
			}
			$collection->setHlockhd((int)getmypid());
			$collection = $this->localStore->collectionModify($collection);
			// execute harmonization loop
			do {
				// update lock heartbeat
				$collection->setHlockhb(time());
				$collection = $this->localStore->collectionModify($collection);
				// harmonize collection
				$statistics = $this->harmonizeCollection($collection);
				// evaluate if anything was done and publish notice if needed
				if ($statistics->total() > 0) {
					//$this->CoreService->publishNotice($uid,'Contacts_harmonized', (array)$statistics);
				}
			} while ($statistics->total() > 0);
			// update harmonization time stamp
			$collection->setHlockhb(time());
			// unlock correlation after harmonization
			$collection->setHlock(0);
			$collection = $this->localStore->collectionModify($collection);
		}

	}

	/**
	 * Perform harmonization for all entities in a collection
	 */
	public function harmonizeCollection(CollectionEntity $collection): HarmonizationStatistics {

		// define statistics object
		$statistics = new HarmonizationStatistics();
		// determine that the correlation belongs to the initialized user
		if ($collection->getUid() !== $this->userId) {
			return $statistics;
		}
		// extract required id's
		$serviceId = (int)$collection->getSid();
		$localCollectionId = (int)$collection->getId();
		$remoteCollectionId = (string)$collection->getCcid();
		$remoteCollectionSignature = (string)$collection->getHesn();
		// delete and skip collection if remote id is missing
		if (empty($remoteCollectionId)) {
			$this->localEventsService->collectionDelete($localCollectionId);
			$this->logger->debug(Application::APP_TAG . ' - Deleted cached events collection for ' . $this->userId . ' due to missing external collection');
			return $statistics;
		}
		// delete and skip collection if remote collection is missing
		$remoteCollection = $this->remoteEventsService->collectionFetch($remoteCollectionId);
		if (!isset($remoteCollection)) {
			$this->localEventsService->collectionDelete($localCollectionId);
			$this->logger->debug(Application::APP_TAG . ' - Deleted cached events collection for ' . $this->userId . ' due to missing external collection');
			return $statistics;
		}

		// retrieve a delta of remote entity variations
		try {
			$remoteEntityDelta = $this->remoteEventsService->entityDelta($remoteCollectionId, $remoteCollectionSignature, 'B');
		} catch (\RuntimeException) {
			$remoteEntityDelta = $this->determineRemoteDelta($collection);
		}
		// process remote mutations
		$alterations = array_unique(
			array_merge(
				$remoteEntityDelta->additions->getArrayCopy(),
				$remoteEntityDelta->modifications->getArrayCopy(),
			)
		);
		// chunk alterations to prevent memory exhaustion on large collections
		foreach (array_chunk($alterations, 100) as $chunk) {
			$remoteEntities = $this->remoteEventsService->entityFetchMultiple($remoteCollectionId, $chunk);
			foreach ($remoteEntities as $remoteEntityId => $remoteEntity) {
				// process addition
				$as = $this->harmonizeRemoteAltered($this->userId, $serviceId, $remoteEntity, $localCollectionId);
				// increment statistics
				switch ($as) {
					case 'LC':
						$statistics->LocalCreated += 1;
						break;
					case 'LU':
						$statistics->LocalUpdated += 1;
						break;
					case 'RU':
						$statistics->RemoteUpdated += 1;
						break;
				}
			}
		}

		// process remote deletions
		$alterations = array_unique(
			$remoteEntityDelta->deletions->getArrayCopy()
		);
		foreach ($alterations as $remoteEntityId) {
			// process delete
			$as = $this->harmonizeRemoteDelete($remoteCollectionId, $remoteEntityId, $localCollectionId);
			if ($as == 'LD') {
				// increment statistics
				$statistics->LocalDeleted += 1;
			}
		}

		// update and deposit remote harmonization signature
		$collection->setPermissions($remoteCollection->permissions);
		if (!empty($remoteEntityDelta->signature)) {
			$collection->setHesn($remoteEntityDelta->signature);
		} else {
			$collection->setHesn($remoteCollection->remoteSignature);
		}
		$collection = $this->localStore->collectionModify($collection);
		// clean up
		unset($remoteCollection, $remoteEntityDelta);

		return $statistics;
	}

	/**
	 * determine remote delta based on remote and local entity list comparison
	 */
	public function determineRemoteDelta(CollectionEntity $collection): DeltaObject {
		// retrieve remote entity list and local entity list
		$remoteCollectionId = $collection->getCcid();
		$rList = $this->remoteEventsService->entityList($remoteCollectionId, 'basic');

		$localCollectionId = $collection->getId();
		$lFilter = $this->localEventsService->entityListFilter();
		$lFilter->condition('cid', $localCollectionId);
		$lList = $this->localEventsService->entityList($lFilter);

		// reindex local list by remote entity id for easier comparison
		$lList = array_reduce($lList, function ($list, $entry) {
			if (!empty($entry->remoteEntityId)) {
				$list[$entry->remoteEntityId] = $entry;
			}
			return $list;
		}, []);

		// iterate through remote entities to find entities that do and don't exist in correlations
		$delta = new DeltaObject();
		foreach ($rList as $entry) {
			//
			if (!$entry->remoteCollectionId || $entry->remoteCollectionId !== $remoteCollectionId) {
				continue;
			}
			// determine if entry exists in local list
			// if NOT found add entity to added delta
			if (isset($lList[$entry->remoteEntityId])) {
				if ($entry->remoteSignature !== $lList[$entry->remoteEntityId]->localSignature) {
					$delta->modifications[] = $entry->remoteEntityId;
				}
				unset($lList[$entry->remoteEntityId]);
			} else {
				$delta->additions[] = $entry->remoteEntityId;
			}
		}
		// iterate through remaining correlations
		// if a correlation that was not removed it must have been deleted on the remote system
		foreach ($lList as $entry) {
			$delta->deletions[] = $entry->remoteEntityId;
		}

		return $delta;
	}

	/**
	 * harmonize remotely altered entity
	 *
	 * @param string $uid system user id
	 * @param int $serviceId service id
	 * @param Entity $remoteEntity remote entity
	 * @param int $localCollectionId local collection id
	 *
	 * @return string what action was performed
	 */
	public function harmonizeRemoteAltered(string $uid, int $serviceId, Entity $remoteEntity, int $localCollectionId): string {

		// define default operation status
		$status = 'NA'; // no action
		// define entity place holders
		$ro = $remoteEntity;
		$lo = null;
		// evaluate, if remote entity was returned
		if (!($ro instanceof Entity)) {
			return $status;
		}
		// retrieve local entity with remote collection and entity id
		$lo = $this->localEventsService->entityFetchByCorrelation($localCollectionId, $ro->remoteCollectionId, $ro->remoteEntityId);
		// if local entity exists
		// compare local and remote generated signature to correlation signature
		// stop processing if they match this is necessary to prevent synchronization feedback loop
		if ($lo instanceof Entity && $lo->correlationSignature === ($lo->localSignature . $ro->remoteSignature)) {
			return $status;
		}
		// modify local entity if one EXISTS
		// create local entity if one DOES NOT EXIST
		if ($lo instanceof Entity) {
			// update local entity
			$lo = $this->localEventsService->entityModify($uid, $serviceId, $localCollectionId, $lo->localEntityId, $ro);
			// assign operation status
			$status = 'LU'; // Local Update
		} else {
			// create local entity
			$lo = $this->localEventsService->entityCreate($uid, $serviceId, $localCollectionId, $ro);
			// assign operation status
			$status = 'LC'; // Local Create
		}
		// return operation status
		return $status;
	}

	/**
	 * harmonize remotely deleted entity
	 *
	 * @param string $remoteCollectionId remote collection id
	 * @param string $remoteEntityId remote entity id
	 * @param int $localCollectionId local collection id
	 *
	 * @return string what action was performed
	 */
	public function harmonizeRemoteDelete(string $remoteCollectionId, string $remoteEntityId, int $localCollectionId): string {

		// destroy local entity
		$rs = $this->localEventsService->entityDeleteByCorrelation($localCollectionId, $remoteCollectionId, $remoteEntityId);

		if ($rs) {
			return 'LD';
		} else {
			return 'NA';
		}

	}

}
