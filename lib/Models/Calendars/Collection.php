<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Models\Calendars;

class Collection {

	public string $userId = '';
	public ?int $serviceId = null;
	public ?int $localId = null;
	public ?string $localSignature = null;
	public ?string $remoteId = null;
	public ?string $remoteSignature = null;
	public ?array $permissions = null;
	public ?array $components = null;
	public ?string $uuid = null;
	public ?string $label = null;
	public ?string $description = null;
	public ?int $priority = null;
	public ?bool $visible = null;
	public ?string $color = null;

}
