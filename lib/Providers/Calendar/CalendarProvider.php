<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Providers\Calendar;

use OCA\DAVC\Store\Local\EventStore;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarProvider;
use OCP\IRequest;

class CalendarProvider implements ICalendarProvider {

	public function __construct(
		private readonly EventStore $store,
		private readonly IRequest $request,
	) {
	}

	/**
	 * @return ICalendar[]
	 */
	#[\Override]
	public function getCalendars(string $principalUri, array $calendarUris = []): array {
		// AppCalendarPlugin (core) wraps every ICalendarProvider calendar into
		// app-generated--dav-wrapper--{uri}, which would duplicate the ExternalCalendar
		// objects already served by our Sabre DAV Provider. Returning [] during CalDAV
		// requests prevents the duplicate; the dashboard/search path is unaffected.
		if (str_starts_with($this->request->getRequestUri(), '/remote.php/dav')) {
			return [];
		}

		$parts = explode('/', $principalUri);
		$uid = end($parts);
		if ($uid === false || $uid === '') {
			return [];
		}

		$collections = $this->store->collectionListByUser($uid);

		$calendars = [];
		foreach ($collections as $collection) {
			if (!empty($calendarUris) && !in_array('davc_' . $collection->getId(), $calendarUris, true)) {
				continue;
			}
			$calendars[] = new CalendarImpl($collection, $this->store);
		}

		return $calendars;
	}
}
