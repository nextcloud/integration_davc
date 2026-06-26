<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Search;

use DateTime;
use DateTimeZone;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

class EventsSearchProvider implements IProvider {

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l10n,
	) {
	}

	#[\Override]
	public function getId(): string {
		return 'davc-events';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('DAV Connector Events');
	}

	#[\Override]
	public function getOrder(string $route, array $routeParameters): ?int {
		return 31;
	}

	#[\Override]
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term = $query->getFilter('term')?->get();
		if ($term === null || $term === '') {
			return SearchResult::complete($this->getName(), []);
		}

		$limit = $query->getLimit();
		$offset = (int)($query->getCursor() ?? 0);

		$likeTerm = '%' . $this->db->escapeLikeParameter($term) . '%';

		$qb = $this->db->getQueryBuilder();
		$qb->select(
				'e.uuid', 'e.label', 'e.description', 'e.startson', 'e.endson',
				'c.uuid AS collection_uuid', 'c.label AS collection_label',
			)
			->from('davc_entities_calendars', 'e')
			->innerJoin('e', 'davc_collections', 'c', $qb->expr()->eq('e.cid', 'c.id'))
			->where($qb->expr()->eq('e.uid', $qb->createNamedParameter($user->getUID())))
			->andWhere($qb->expr()->orX(
				$qb->expr()->like('e.label', $qb->createNamedParameter($likeTerm)),
				$qb->expr()->like('e.description', $qb->createNamedParameter($likeTerm)),
			))
			->orderBy('e.startson', 'ASC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		$since = $query->getFilter('since')?->get();
		$until = $query->getFilter('until')?->get();
		if ($since instanceof \DateTimeInterface) {
			$qb->andWhere($qb->expr()->gte('e.endson', $qb->createNamedParameter($since->format('U'))));
		}
		if ($until instanceof \DateTimeInterface) {
			$qb->andWhere($qb->expr()->lte('e.startson', $qb->createNamedParameter($until->format('U'))));
		}

		$result = $qb->executeQuery();
		$entries = [];
		while ($row = $result->fetch()) {
			$entries[] = $this->rowToResult($row, $user->getUID());
		}
		$result->closeCursor();

		return SearchResult::paginated(
			$this->getName(),
			$entries,
			$offset + count($entries),
		);
	}

	private function rowToResult(array $row, string $uid): SearchResultEntry {
		$title = $row['label'] ?? $this->l10n->t('Untitled event');
		$subline = ($row['collection_label'] ?? '') . ' · ' . $this->formatDatetime((int)$row['startson']);

		$davUrl = $this->urlGenerator->linkTo('', 'remote.php')
			. '/dav/calendars/' . urlencode($uid)
			. '/app-generated--integration_davc--' . $row['collection_uuid']
			. '/' . $row['uuid'];

		$resourceUrl = $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute('calendar.view.index')
			. 'edit/' . base64_encode($davUrl)
		);

		$entry = new SearchResultEntry('', $title, $subline, $resourceUrl, 'icon-calendar-dark', false);
		$entry->addAttribute('createdAt', (string)$row['startson']);

		return $entry;
	}

	private function formatDatetime(int $timestamp): string {
		$dt = new DateTime('@' . $timestamp);
		$dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
		return $this->l10n->l('datetime', $dt, ['width' => 'medium|short']);
	}
}
