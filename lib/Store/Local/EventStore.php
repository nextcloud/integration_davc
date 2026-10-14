<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Store\Local;

use OCA\DAVC\Store\Common\Filters\IFilter;
use OCA\DAVC\Store\Common\Range\IRange;
use OCA\DAVC\Store\Common\Range\IRangeDate;
use OCA\DAVC\Store\Common\Sort\ISort;
use OCA\DAVC\Store\Local\Filters\CollectionFilter;
use OCA\DAVC\Store\Local\Filters\EventFilter;
use OCP\IDBConnection;

class EventStore extends BaseStore {

	public function __construct(IDBConnection $store) {
		$this->_Store = $store;
		$this->_CollectionTable = 'davc_collections';
		$this->_CollectionIdentifier = 'EC';
		$this->_CollectionClass = CollectionEntity::class;
		$this->_EntityTable = 'davc_entities_calendars';
		$this->_EntityIdentifier = 'EE';
		$this->_EntityClass = EventEntity::class;
		$this->_ChronicleTable = 'davc_chronicle';
	}

	/**
	 * retrieve entities from data store
	 *
	 * @param array $elements data fields
	 * @param IFilter $filter filter options
	 * @param IRange $range range options
	 * @param ISort $sort sort options
	 *
	 * @return array of entities
	 */
	#[\Override]
	public function entityList(?IFilter $filter = null, ?ISort $sort = null, ?IRange $range = null, ?array $elements = null): array {
		// evaluate if specific elements where requested
		if (!is_array($elements)) {
			$elements = ['*'];
		}
		// construct data store command
		$cmd = $this->_Store->getQueryBuilder();
		$cmd->select($elements)
			->from($this->_EntityTable);
		// apply range
		if ($range instanceof IRangeDate) {
			// date range filter
			// case 1: event starts and ends within range
			// case 2: event starts before range and ends within range
			// case 3: event starts within range and ends after range
			// case 4: event starts before range and ends after range
			$rangerStart = $range->getStart()->format('U');
			$rangerEnd = $range->getEnd()->format('U');
			$cmd->andWhere($cmd->expr()->orX(
				// case 1: event starts and ends within range
				$cmd->expr()->andX(
					$cmd->expr()->gte('startson', $cmd->createNamedParameter($rangerStart)),
					$cmd->expr()->lte('endson', $cmd->createNamedParameter($rangerEnd)),
				),
				// case 2: event starts before range and ends within range
				$cmd->expr()->andX(
					$cmd->expr()->lt('startson', $cmd->createNamedParameter($rangerStart)),
					$cmd->expr()->gte('endson', $cmd->createNamedParameter($rangerStart)),
					$cmd->expr()->lte('endson', $cmd->createNamedParameter($rangerEnd))
				),
				// case 3: event starts within range and ends after range
				$cmd->expr()->andX(
					$cmd->expr()->gte('startson', $cmd->createNamedParameter($rangerStart)),
					$cmd->expr()->lte('startson', $cmd->createNamedParameter($rangerEnd)),
					$cmd->expr()->gt('endson', $cmd->createNamedParameter($rangerEnd))
				),
				// case 4: event spans entire range
				$cmd->expr()->andX(
					$cmd->expr()->lt('startson', $cmd->createNamedParameter($rangerStart)),
					$cmd->expr()->gt('endson', $cmd->createNamedParameter($rangerEnd))
				),
				// case 5: recurring event with base occurrence before range;
				// expand() in CalendarImpl generates and filters the actual instances
				$cmd->expr()->andX(
					$cmd->expr()->lt('startson', $cmd->createNamedParameter($rangerStart)),
					$cmd->expr()->like('data', $cmd->createNamedParameter('%RRULE:%'))
				)
			));
		}
		// apply filters
		if ($filter instanceof IFilter) {
			$this->fromFilter($cmd, $filter);
		}
		// apply sort
		if ($sort instanceof ISort) {
			$this->fromSort($cmd, $sort);
		}
		// execute command
		$rsl = $cmd->executeQuery();
		$entities = [];
		try {
			while ($data = $rsl->fetch()) {
				$entities[] = $this->toEntity($data);
			}
			return $entities;
		} finally {
			$rsl->closeCursor();
		}
	}

	#[\Override]
	public function collectionListFilter(): CollectionFilter {
		return new CollectionFilter();
	}

	#[\Override]
	public function entityListFilter(): EventFilter {
		return new EventFilter();
	}
}
