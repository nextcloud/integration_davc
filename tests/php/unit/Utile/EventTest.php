<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Utile;

use DateTimeImmutable;
use OCA\DAVC\Utile\Event;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

class EventTest extends TestCase {

	private function calendar(string ...$eventBlocks): VCalendar {
		$data = "BEGIN:VCALENDAR\r\n"
			. "VERSION:2.0\r\n"
			. "PRODID:-//Test//Test//EN\r\n";
		foreach ($eventBlocks as $block) {
			$data .= "BEGIN:VEVENT\r\n"
				. "UID:event-1\r\n"
				. "DTSTAMP:20260101T000000Z\r\n"
				. $block
				. "END:VEVENT\r\n";
		}
		$data .= "END:VCALENDAR\r\n";
		/** @var VCalendar $calendar */
		$calendar = Reader::read($data);
		return $calendar;
	}

	private function timestamp(string $date): int {
		return (new DateTimeImmutable($date))->getTimestamp();
	}

	public function testNonRecurringUsesStartAndEnd(): void {
		[$start, $end] = Event::calculate($this->calendar(
			"DTSTART:20260105T100000Z\r\n"
			. "DTEND:20260105T113000Z\r\n"
		));

		$this->assertSame($this->timestamp('2026-01-05T10:00:00Z'), $start);
		$this->assertSame($this->timestamp('2026-01-05T11:30:00Z'), $end);
	}

	public function testCountRuleEndsOnLastInstanceEnd(): void {
		// weekly x 5, so the last instance starts 4 weeks after the first
		[$start, $end] = Event::calculate($this->calendar(
			"DTSTART:20260105T100000Z\r\n"
			. "DTEND:20260105T110000Z\r\n"
			. "RRULE:FREQ=WEEKLY;COUNT=5\r\n"
		));

		$this->assertSame($this->timestamp('2026-01-05T10:00:00Z'), $start);
		$this->assertSame($this->timestamp('2026-02-02T11:00:00Z'), $end);
	}

	public function testUntilRuleEndsOnLastInstanceEnd(): void {
		[, $end] = Event::calculate($this->calendar(
			"DTSTART:20260105T100000Z\r\n"
			. "DTEND:20260105T110000Z\r\n"
			. "RRULE:FREQ=DAILY;UNTIL=20260110T100000Z\r\n"
		));

		$this->assertSame($this->timestamp('2026-01-10T11:00:00Z'), $end);
	}

	public function testInfiniteRuleIsCappedAtRangeMax(): void {
		[, $end] = Event::calculate($this->calendar(
			"DTSTART:20260105T100000Z\r\n"
			. "DTEND:20260105T110000Z\r\n"
			. "RRULE:FREQ=DAILY\r\n"
		));

		$this->assertSame($this->timestamp(Event::RANGE_MAX), $end);
	}

	public function testExcessivelyLongRuleIsTreatedAsInfinite(): void {
		[, $end] = Event::calculate($this->calendar(
			"DTSTART:20260105T100000Z\r\n"
			. "DTEND:20260105T110000Z\r\n"
			. 'RRULE:FREQ=DAILY;COUNT=' . (Event::INSTANCE_MAX + 1) . "\r\n"
		));

		$this->assertSame($this->timestamp(Event::RANGE_MAX), $end);
	}

	public function testRdateExtendsRuleConclusion(): void {
		// Sabre's EventIterator would ignore the RRULE entirely when RDATE
		// is present, the bounds must cover both
		[$start, $end] = Event::calculate($this->calendar(
			"DTSTART:20260105T100000Z\r\n"
			. "DTEND:20260105T110000Z\r\n"
			. "RRULE:FREQ=WEEKLY;COUNT=2\r\n"
			. "RDATE:20260301T100000Z\r\n"
		));

		$this->assertSame($this->timestamp('2026-01-05T10:00:00Z'), $start);
		$this->assertSame($this->timestamp('2026-03-01T11:00:00Z'), $end);
	}

	public function testOverriddenInstanceMovedPastConclusionExtendsEnd(): void {
		[$start, $end] = Event::calculate($this->calendar(
			"DTSTART:20260105T100000Z\r\n"
			. "DTEND:20260105T110000Z\r\n"
			. "RRULE:FREQ=WEEKLY;COUNT=2\r\n",
			"RECURRENCE-ID:20260112T100000Z\r\n"
			. "DTSTART:20260401T100000Z\r\n"
			. "DTEND:20260401T120000Z\r\n"
		));

		$this->assertSame($this->timestamp('2026-01-05T10:00:00Z'), $start);
		$this->assertSame($this->timestamp('2026-04-01T12:00:00Z'), $end);
	}

	public function testOverriddenInstanceMovedBeforeStartExtendsStart(): void {
		[$start, $end] = Event::calculate($this->calendar(
			"DTSTART:20260105T100000Z\r\n"
			. "DTEND:20260105T110000Z\r\n"
			. "RRULE:FREQ=WEEKLY;COUNT=2\r\n",
			"RECURRENCE-ID:20260112T100000Z\r\n"
			. "DTSTART:20260101T100000Z\r\n"
			. "DTEND:20260101T110000Z\r\n"
		));

		$this->assertSame($this->timestamp('2026-01-01T10:00:00Z'), $start);
		$this->assertSame($this->timestamp('2026-01-12T11:00:00Z'), $end);
	}

	public function testOverriddenInstanceWithoutEndUsesBaseDuration(): void {
		[, $end] = Event::calculate($this->calendar(
			"DTSTART:20260105T100000Z\r\n"
			. "DTEND:20260105T113000Z\r\n"
			. "RRULE:FREQ=WEEKLY;COUNT=2\r\n",
			"RECURRENCE-ID:20260112T100000Z\r\n"
			. "DTSTART:20260501T100000Z\r\n"
		));

		$this->assertSame($this->timestamp('2026-05-01T11:30:00Z'), $end);
	}

	public function testOrphanedOverridesOnly(): void {
		// CalDAV permits objects holding only overridden instances
		[$start, $end] = Event::calculate($this->calendar(
			"RECURRENCE-ID:20260105T100000Z\r\n"
			. "DTSTART:20260105T100000Z\r\n"
			. "DTEND:20260105T110000Z\r\n",
			"RECURRENCE-ID:20260112T100000Z\r\n"
			. "DTSTART:20260112T100000Z\r\n"
			. "DTEND:20260112T110000Z\r\n"
		));

		$this->assertSame($this->timestamp('2026-01-05T10:00:00Z'), $start);
		$this->assertSame($this->timestamp('2026-01-12T11:00:00Z'), $end);
	}

	public function testFullyExcludedSeriesStillYieldsBounds(): void {
		// Sabre's EventIterator throws NoInstancesException here, the
		// bounds are conservative and ignore exclusions
		[$start, $end] = Event::calculate($this->calendar(
			"DTSTART:20260105T100000Z\r\n"
			. "DTEND:20260105T110000Z\r\n"
			. "RRULE:FREQ=WEEKLY;COUNT=2\r\n"
			. "EXDATE:20260105T100000Z\r\n"
			. "EXDATE:20260112T100000Z\r\n"
		));

		$this->assertSame($this->timestamp('2026-01-05T10:00:00Z'), $start);
		$this->assertSame($this->timestamp('2026-01-12T11:00:00Z'), $end);
	}

	public function testAllDayRecurringSpansLastDay(): void {
		[$start, $end] = Event::calculate($this->calendar(
			"DTSTART;VALUE=DATE:20260105\r\n"
			. "RRULE:FREQ=DAILY;COUNT=3\r\n"
		));

		$this->assertSame($this->timestamp('2026-01-05T00:00:00Z'), $start);
		$this->assertSame($this->timestamp('2026-01-08T00:00:00Z'), $end);
	}

	public function testMissingStartThrows(): void {
		$this->expectException(\InvalidArgumentException::class);

		Event::calculate($this->calendar(
			"SUMMARY:No start\r\n"
		));
	}
}
