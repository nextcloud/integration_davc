<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Store\Local;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method getId(): int
 * @method getUid(): ?string
 * @method setUid(string $uid): void
 * @method getSid(): ?string
 * @method setSid(string $sid): void
 * @method getType(): ?string
 * @method setType(string $type): void
 * @method getCcid(): ?string
 * @method setCcid(string $ccid): void
 * @method getUuid(): ?string
 * @method setUuid(string $uuid): void
 * @method getPermissions(): ?array
 * @method setPermissions(?array $permissions): void
 * @method getComponents(): ?array
 * @method setComponents(?array $components): void
 * @method getLabel(): ?string
 * @method setLabel(string $label): void
 * @method getColor(): ?string
 * @method setColor(string $color): void
 * @method getVisible(): ?string
 * @method setVisible(string $visible): void
 * @method getHisn(): ?string
 * @method setHisn(string $hisn): void
 * @method getHesn(): ?string
 * @method setHesn(string $hesn): void
 * @method getHLock(): ?int
 * @method setHLock(int $status): void
 * @method getHLockHd(): ?int
 * @method setHLockHd(int $id): void
 * @method getHLockHb(): ?int
 * @method setHLockHb(int $timestamp): void
 */
class CollectionEntity extends Entity implements JsonSerializable {
	protected ?string $uid = null;
	protected ?int $sid = null;
	protected ?string $type = null;
	protected ?string $ccid = null;
	protected ?string $uuid = null;
	protected ?array $permissions = null;
	protected ?array $components = null;
	protected ?string $label = null;
	protected ?string $color = null;
	protected ?int $visible = 1;
	protected ?string $hisn = null;
	protected ?string $hesn = null;
	protected ?int $hlock = 0;
	protected ?int $hlockhd = 0;
	protected ?int $hlockhb = 0;

	public function __construct() {
		$this->addType('permissions', Types::JSON);
		$this->addType('components', Types::JSON);
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uid' => $this->uid,
			'sid' => $this->sid,
			'type' => $this->type,
			'ccid' => $this->ccid,
			'uuid' => $this->uuid,
			'permissions' => $this->permissions,
			'components' => $this->components,
			'label' => $this->label,
			'color' => $this->color,
			'visible' => $this->visible,
			'hisn' => $this->hisn,
			'hesn' => $this->hesn,
			'hlock' => $this->hlock,
			'hlockhd' => $this->hlockhd,
			'hlockhb' => $this->hlockhb,
		];
	}
}
