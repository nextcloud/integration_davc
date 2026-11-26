<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Migration;

use Closure;
use Doctrine\DBAL\Types\Type;
use OCA\DAVC\Store\Local\ServicesStore;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\IColumn;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;

/**
 * Widen the credential columns to fit encrypted values and encrypt existing credentials
 *
 * @psalm-api
 */
class Version0012Date20261125000001 extends SimpleMigrationStep {

	public function __construct(
		private readonly IDBConnection $db,
		private readonly ICrypto $crypto,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('davc_services')) {
			return null;
		}
		$table = $schema->getTable('davc_services');

		foreach (ServicesStore::SECURE_COLUMNS as $name) {
			$column = $table->getColumn($name);
			if ($column instanceof IColumn) {
				$column->setType(Types::TEXT);
			} else {
				// Nextcloud <35 returns a Doctrine Column, which only accepts a Type instance
				/** @psalm-suppress InvalidArgument */
				$column->setType(Type::getType(Types::TEXT));
			}
			$column->setLength(null);
		}

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$cmd = $this->db->getQueryBuilder();
		$cmd->select('id', ...ServicesStore::SECURE_COLUMNS)
			->from('davc_services');
		$rsl = $cmd->executeQuery();
		$rows = [];
		try {
			while ($row = $rsl->fetch()) {
				$rows[] = $row;
			}
		} finally {
			$rsl->closeCursor();
		}

		$updated = 0;
		foreach ($rows as $row) {
			$cmd = $this->db->getQueryBuilder();
			$cmd->update('davc_services')
				->where($cmd->expr()->eq('id', $cmd->createNamedParameter($row['id'])));
			$changed = false;
			foreach (ServicesStore::SECURE_COLUMNS as $column) {
				$value = $row[$column] ?? null;
				if (!is_string($value) || $value === '' || $this->isEncrypted($value)) {
					continue;
				}
				$cmd->set($column, $cmd->createNamedParameter($this->crypto->encrypt($value)));
				$changed = true;
			}
			if ($changed) {
				$cmd->executeStatement();
				$updated++;
			}
		}

		$output->info('Encrypted credentials of ' . $updated . ' service(s)');
	}

	private function isEncrypted(string $value): bool {
		try {
			$this->crypto->decrypt($value);
			return true;
		} catch (\Exception) {
			return false;
		}
	}

}
