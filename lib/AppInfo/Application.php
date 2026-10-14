<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\AppInfo;

use OCA\DAVC\Events\UserDeletedListener;
use OCA\DAVC\Providers\Calendar\CalendarProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {

	public const APP_ID = 'integration_davc';
	public const APP_TAG = 'DAVC';
	public const APP_LABEL = 'DAV Connector';

	public function __construct(array $urlParams = []) {
		if ((@include_once __DIR__ . '/../../vendor/autoload.php') === false) {
			throw new \Exception('Cannot include autoload. Did you run install dependencies using composer?');
		}
		parent::__construct(self::APP_ID, $urlParams);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {

		// register calendar provider so DAV Connector calendars appear in dashboard/search
		$context->registerCalendarProvider(CalendarProvider::class);

		// register event handlers
		$dispatcher = $this->getContainer()->get(IEventDispatcher::class);
		$dispatcher->addServiceListener(UserDeletedEvent::class, UserDeletedListener::class);

	}

	#[\Override]
	public function boot(IBootContext $context): void {

	}

}
