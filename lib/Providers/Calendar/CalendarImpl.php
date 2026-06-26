<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Providers\Calendar;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\DAVC\Store\Common\Filters\FilterComparisonOperator;
use OCA\DAVC\Store\Common\Filters\FilterConjunctionOperator;
use OCA\DAVC\Store\Common\Range\RangeDate;
use OCA\DAVC\Store\Local\CollectionEntity;
use OCA\DAVC\Store\Local\EventStore;
use OCP\Calendar\ICalendar;
use OCP\Constants;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VTimeZone;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

class CalendarImpl implements ICalendar {

	public function __construct(
		private readonly CollectionEntity $collection,
		private readonly EventStore $store,
	) {
	}

	#[\Override]
	public function getKey(): string {
		return 'davc_' . $this->collection->getId();
	}

	#[\Override]
	public function getUri(): string {
		// Must be a slash-free identifier: Nextcloud wraps this into
		// app-generated--dav-wrapper--{uri} and DAV path parsing splits on '/'.
		return 'davc_' . $this->collection->getId();
	}

	#[\Override]
	public function getDisplayName(): ?string {
		return $this->collection->getLabel();
	}

	#[\Override]
	public function getDisplayColor(): ?string {
		return $this->collection->getColor();
	}

	#[\Override]
	public function getPermissions(): int {
		return Constants::PERMISSION_READ;
	}

	#[\Override]
	public function isDeleted(): bool {
		return false;
	}

	#[\Override]
	public function search(string $pattern, array $searchProperties = [], array $options = [], ?int $limit = null, ?int $offset = null): array {
		$filter = $this->store->entityListFilter();
		$filter->condition('cid', $this->collection->getId(), FilterComparisonOperator::EQ, FilterConjunctionOperator::AND);

		$range = null;
		$start = $options['timerange']['start'] ?? null;
		$end = $options['timerange']['end'] ?? null;
		if ($start instanceof DateTimeInterface || $end instanceof DateTimeInterface) {
			$range = new RangeDate(
				$start instanceof DateTimeInterface ? $start : new DateTimeImmutable('@0'),
				$end instanceof DateTimeInterface ? $end : new DateTimeImmutable('@9999999999'),
			);
		}

		$entities = $this->store->entityList($filter, null, $range);

		$results = [];
		foreach ($entities as $entity) {
			$data = $entity->getData();
			if ($data === null) {
				continue;
			}

			try {
				$vCalendar = Reader::read($data);
			} catch (\Throwable) {
				continue;
			}

			if (!($vCalendar instanceof VCalendar)) {
				continue;
			}

			if ($start instanceof DateTimeInterface && $end instanceof DateTimeInterface) {
				$vCalendar = $vCalendar->expand($start, $end);
			}

			$components = $vCalendar->getComponents();
			$objects = [];
			$timezones = [];
			foreach ($components as $comp) {
				if ($comp instanceof VTimeZone) {
					$timezones[] = $this->transformComponent($comp);
				} else {
					$objects[] = $this->transformComponent($comp);
				}
			}

			if (empty($objects)) {
				continue;
			}

			if ($pattern !== '') {
				$matched = false;
				foreach ($objects as $obj) {
					foreach ($searchProperties as $prop) {
						$value = $obj[$prop][0] ?? null;
						if (is_string($value) && stripos($value, $pattern) !== false) {
							$matched = true;
							break 2;
						}
					}
					if (empty($searchProperties)) {
						$matched = true;
					}
				}
				if (!$matched) {
					continue;
				}
			}

			$results[] = [
				'id' => $entity->getId(),
				'type' => 'VEVENT',
				'uid' => $entity->getUuid() ?? '',
				'uri' => $entity->getCeid() ?? '',
				'objects' => $objects,
				'timezones' => $timezones,
			];
		}

		usort($results, static function (array $a, array $b): int {
			$startA = $a['objects'][0]['DTSTART'][0] ?? new DateTimeImmutable('+10 years');
			$startB = $b['objects'][0]['DTSTART'][0] ?? new DateTimeImmutable('+10 years');
			if (!($startA instanceof DateTimeInterface)) {
				$startA = new DateTimeImmutable('+10 years');
			}
			if (!($startB instanceof DateTimeInterface)) {
				$startB = new DateTimeImmutable('+10 years');
			}
			return $startA->getTimestamp() <=> $startB->getTimestamp();
		});

		if ($offset !== null) {
			$results = array_slice($results, $offset);
		}
		if ($limit !== null) {
			$results = array_slice($results, 0, $limit);
		}

		return $results;
	}

	private function transformComponent(Component $comp): array {
		$data = [];
		$validationRules = $comp->getValidationRules();

		foreach ($comp->getComponents() as $subComp) {
			$name = $subComp->name;
			$data[$name][] = $this->transformComponent($subComp);
		}

		foreach ($comp->children() as $child) {
			if (!($child instanceof Property)) {
				continue;
			}
			$name = $child->name;
			$rule = $validationRules[$name] ?? '*';
			$value = $this->transformProperty($child);

			if ($rule === '+' || $rule === '*') {
				$data[$name][] = $value;
			} else {
				$data[$name] = $value;
			}
		}

		return $data;
	}

	private function transformProperty(Property $prop): mixed {
		if ($prop instanceof Property\ICalendar\DateTime) {
			$value = $prop->getDateTime();
		} else {
			$value = $prop->getValue();
		}
		return [$value, $prop->parameters()];
	}
}
