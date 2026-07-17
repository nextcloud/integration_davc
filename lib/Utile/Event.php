<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Utile;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Property\ICalendar\DateTime;
use Sabre\VObject\Recur\RRuleIterator;

class Event {

	/**
	 * Effective end for infinite series, the maximum four digit ISO 8601 date
	 */
	public const RANGE_MAX = '9999-12-31';

	/**
	 * Maximum rule instances to expand before a series is treated as effectively infinite
	 */
	public const INSTANCE_MAX = 3500;

	/**
	 * Calculates the start of the first instance and the end of the last instance
	 * of an event series.
	 *
	 * The result is intentionally conservative: exceptions (EXDATE/EXRULE) only
	 * remove instances, so they are ignored and the bounds may be wider than the
	 * real instance set, but never narrower. Unlike Sabre's EventIterator, RRULE
	 * and RDATE are combined when both are present, and overridden instances
	 * (RECURRENCE-ID) that were moved outside the base pattern are taken into
	 * account.
	 *
	 * @param VCalendar $components calendar object holding all instances of one series (same UID), base and overridden instances
	 *
	 * @return array{0:int,1:int} start of the first instance and end of the last instance, as timestamps
	 */
	public static function calculate(VCalendar $components): array {
		// split the series into the base component and the overridden instances
		$base = null;
		$overrides = [];
		foreach ($components->getComponents() as $component) {
			if (!in_array($component->name, ['VEVENT', 'VTODO', 'VJOURNAL'], true)) {
				continue;
			}
			if (!isset($component->{'RECURRENCE-ID'})) {
				$base = $component;
			} else {
				$overrides[] = $component;
			}
		}
		// CalDAV permits a series holding only overridden instances,
		// treat the first one as the base in that case
		if ($base === null) {
			$base = array_shift($overrides);
		}
		if ($base === null || !isset($base->DTSTART)) {
			throw new InvalidArgumentException('Series bounds require at least one component with a DTSTART');
		}

		$baseStart = $base->DTSTART->getDateTime();
		$duration = self::duration($base, $baseStart);
		$firstStart = $baseStart->getTimestamp();
		$lastEnd = $firstStart + $duration;
		$rangeMax = new DateTimeImmutable(self::RANGE_MAX);

		if (isset($base->RRULE)) {
			$rule = new RRuleIterator($base->RRULE->getParts(), $baseStart);
			if ($rule->isInfinite()) {
				$lastEnd = max($lastEnd, $rangeMax->getTimestamp());
			} else {
				$last = $baseStart;
				$instances = 0;
				$capped = false;
				while ($rule->valid()) {
					// treat excessively long series as effectively infinite
					if (++$instances > self::INSTANCE_MAX) {
						$capped = true;
						break;
					}
					$last = $rule->current();
					$rule->next();
				}
				$lastEnd = $capped
					? max($lastEnd, $rangeMax->getTimestamp())
					: max($lastEnd, $last->getTimestamp() + $duration);
			}
		}
		// RDATE instances are additional to RRULE instances when both are present
		if (isset($base->RDATE)) {
			foreach ($base->RDATE as $property) {
				if (!$property instanceof DateTime) {
					continue;
				}
				foreach ($property->getDateTimes() as $date) {
					$firstStart = min($firstStart, $date->getTimestamp());
					$lastEnd = max($lastEnd, $date->getTimestamp() + $duration);
				}
			}
		}
		// overridden instances may have been moved before the first
		// or past the last instance of the base pattern
		foreach ($overrides as $component) {
			if (!isset($component->DTSTART)) {
				continue;
			}
			$start = $component->DTSTART->getDateTime();
			$firstStart = min($firstStart, $start->getTimestamp());
			$lastEnd = max($lastEnd, $start->getTimestamp() + self::duration($component, $start, $duration));
		}

		return [$firstStart, $lastEnd];
	}

	/**
	 * instance duration in seconds from DTEND/DUE, DURATION or the RFC 5545
	 * one day span for date valued starts without an end
	 */
	private static function duration(Component $component, DateTimeInterface $start, ?int $default = null): int {
		$end = $component->DTEND ?? $component->DUE;
		if ($end instanceof DateTime) {
			return $end->getDateTime()->getTimestamp() - $start->getTimestamp();
		}
		if (isset($component->DURATION)) {
			return DateTimeImmutable::createFromInterface($start)
				->add($component->DURATION->getDateInterval())
				->getTimestamp() - $start->getTimestamp();
		}
		if ($default !== null) {
			return $default;
		}
		if (!$component->DTSTART->hasTime()) {
			return 86400;
		}
		return 0;
	}

}
