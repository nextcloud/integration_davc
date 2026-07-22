<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * @psalm-api
 */
class Version0010Date20260501000003 extends SimpleMigrationStep {

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

		// check if the table already exists
		if ($schema->hasTable('davc_collections')) {
			return;
		}
		// create the table
		$table = $schema->createTable('davc_collections');
		// id
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true
		]);
		// user id
		$table->addColumn('uid', Types::STRING, [
			'length' => 255,
			'notnull' => true
		]);
		// service id
		$table->addColumn('sid', Types::BIGINT, [
			'notnull' => true
		]);
		// type
		$table->addColumn('type', Types::STRING, [
			'length' => 255,
			'notnull' => true
		]);
		// collection id
		$table->addColumn('ccid', Types::STRING, [
			'length' => 255,
			'notnull' => false
		]);
		// uuid
		$table->addColumn('uuid', Types::STRING, [
			'length' => 255,
			'notnull' => true
		]);
		// permissions
		$table->addColumn('permissions', Types::JSON, [
			'notnull' => false
		]);
		// label
		$table->addColumn('label', Types::STRING, [
			'length' => 255,
			'notnull' => true
		]);
		// color
		$table->addColumn('color', Types::STRING, [
			'length' => 255,
			'notnull' => false
		]);
		// visible
		$table->addColumn('visible', Types::BOOLEAN, [
			'notnull' => false,
			'default' => true
		]);
		// hisn
		$table->addColumn('hisn', Types::STRING, [
			'length' => 255,
			'notnull' => false
		]);
		// hesn
		$table->addColumn('hesn', Types::STRING, [
			'length' => 255,
			'notnull' => false
		]);
		// hlock
		$table->addColumn('hlock', Types::INTEGER, [
			'notnull' => false
		]);
		// hlockhb
		$table->addColumn('hlockhb', Types::INTEGER, [
			'notnull' => false
		]);
		// hlockhd
		$table->addColumn('hlockhd', Types::INTEGER, [
			'notnull' => false
		]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['uid'], 'davc_collections_index_1'); // by user id
		$table->addIndex(['uid', 'type'], 'davc_collections_index_1b'); // by user id and type
		$table->addIndex(['sid'], 'davc_collections_index_2'); // by service id
		$table->addIndex(['sid', 'type'], 'davc_collections_index_2b'); // by service id and type

		return $schema;
	}

}
