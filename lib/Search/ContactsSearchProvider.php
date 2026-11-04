<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Search;

use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

class ContactsSearchProvider implements IProvider {

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l10n,
	) {
	}

	#[\Override]
	public function getId(): string {
		return 'davc-contacts';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('DAV Connector Contacts');
	}

	#[\Override]
	public function getOrder(string $route, array $routeParameters): ?int {
		return 26;
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
				'e.uuid', 'e.data',
				'c.uuid AS collection_uuid', 'c.label AS collection_label',
			)
			->from('davc_entities_contacts', 'e')
			->innerJoin('e', 'davc_collections', 'c', $qb->expr()->eq('e.cid', 'c.id'))
			->where($qb->expr()->eq('e.uid', $qb->createNamedParameter($user->getUID())))
			->andWhere($qb->expr()->iLike('e.data', $qb->createNamedParameter($likeTerm)))
			->orderBy('e.id', 'ASC')
			->setMaxResults($limit)
			->setFirstResult($offset);

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
		$vcard = $row['data'] ?? '';

		$name = $this->extractVCardProperty($vcard, 'FN') ?? $this->l10n->t('Unnamed contact');
		$email = $this->extractVCardProperty($vcard, 'EMAIL');
		$phone = $this->extractVCardProperty($vcard, 'TEL');
		$org = $this->extractVCardProperty($vcard, 'ORG');

		$sublineParts = array_filter([$org, $email ?? $phone]);
		$subline = implode(' · ', $sublineParts);
		if ($subline === '') {
			$subline = $row['collection_label'] ?? '';
		}

		$resourceUrl = $this->urlGenerator->linkToRoute('contacts.page.index');

		return new SearchResultEntry('', $name, $subline, $resourceUrl, 'icon-contacts-dark', false);
	}

	private function extractVCardProperty(string $vcard, string $property): ?string {
		$unfolded = preg_replace('/\r\n[ \t]/', '', $vcard);
		if ($unfolded === null) {
			return null;
		}

		foreach (explode("\n", $unfolded) as $line) {
			$line = rtrim($line, "\r");
			if (preg_match('/^' . preg_quote($property, '/') . '(?:;[^:]*)?:(.+)$/i', $line, $m)) {
				$value = trim($m[1]);
				if ($property === 'ORG') {
					$value = explode(';', $value)[0];
				}
				return $value !== '' ? $value : null;
			}
		}

		return null;
	}
}
