<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require dirname(__DIR__, 4) . '/tests/bootstrap.php';

\OC::$composerAutoloader->addPsr4('OCA\\DAVC\\', dirname(__DIR__, 2) . '/lib');
\OC::$composerAutoloader->addClassMap([
	'OCA\\DAVC\\Tests\\Dav\\HarmonizationTestCase' => __DIR__ . '/dav/HarmonizationTestCase.php',
]);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (isset($_SERVER['APP_DEBUG']) && $_SERVER['APP_DEBUG']) {
	umask(0000);
}
