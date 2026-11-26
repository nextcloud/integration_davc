<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Store\Local;

use OCA\DAVC\Store\Common\Filters\FilterBase;
use OCA\DAVC\Store\Common\Filters\FilterComparisonOperator;
use OCA\DAVC\Store\Common\Filters\FilterConjunctionOperator;
use OCA\DAVC\Store\Common\Filters\IFilter;
use OCA\DAVC\Store\Common\Sort\ISort;
use OCA\DAVC\Store\Common\Sort\SortBase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class ServicesStore {

	protected IDBConnection $_Store;
	protected string $_EntityTable = 'davc_services';
	protected string $_EntityClass = ServiceEntity::class;

	/** columns holding credentials, which are stored encrypted */
	public const SECURE_COLUMNS = ['bauth_secret', 'oauth_access_token', 'oauth_refresh_token'];

	public function __construct(
		IDBConnection $store,
		private readonly ICrypto $crypto,
		private readonly LoggerInterface $logger,
	) {
		$this->_Store = $store;
	}

	protected function toEntity(array $row): ServiceEntity {
		unset($row['DOCTRINE_ROWNUM']); // remove doctrine/dbal helper column
		foreach (self::SECURE_COLUMNS as $column) {
			if (!isset($row[$column]) || $row[$column] === '') {
				continue;
			}
			// a value that cannot be decrypted is discarded so the service stays manageable
			try {
				$row[$column] = $this->crypto->decrypt((string)$row[$column]);
			} catch (\Exception $e) {
				$this->logger->warning('Unable to decrypt ' . $column . ' of service ' . ($row['id'] ?? '') . ', the service needs to be reconnected', ['exception' => $e]);
				$row[$column] = null;
			}
		}
		return \call_user_func($this->_EntityClass . '::fromRow', $row);
	}

	/**
	 * convert the updated fields of an entity to column values, credentials are encrypted
	 *
	 * @return array<string, mixed>
	 */
	protected function fromEntity(ServiceEntity $entity): array {
		$values = [];
		foreach (array_keys($entity->getUpdatedFields()) as $property) {
			$column = $entity->propertyToColumn($property);
			$getter = 'get' . ucfirst($property);
			$value = $entity->$getter();
			if (in_array($column, self::SECURE_COLUMNS, true) && is_string($value) && $value !== '') {
				$value = $this->crypto->encrypt($value);
			}
			$values[$column] = $value;
		}
		return $values;
	}

	protected function fromFilter(IQueryBuilder $cmd, IFilter $filter): void {
		foreach ($filter->conditions() as $entry) {
			$comparison = match ($entry['comparator']) {
				FilterComparisonOperator::EQ => $cmd->expr()->eq($entry['attribute'], $cmd->createNamedParameter($entry['value'])),
				FilterComparisonOperator::GT => $cmd->expr()->gt($entry['attribute'], $cmd->createNamedParameter($entry['value'])),
				FilterComparisonOperator::LT => $cmd->expr()->lt($entry['attribute'], $cmd->createNamedParameter($entry['value'])),
				FilterComparisonOperator::GTE => $cmd->expr()->gte($entry['attribute'], $cmd->createNamedParameter($entry['value'])),
				FilterComparisonOperator::LTE => $cmd->expr()->lte($entry['attribute'], $cmd->createNamedParameter($entry['value'])),
				FilterComparisonOperator::NEQ => $cmd->expr()->neq($entry['attribute'], $cmd->createNamedParameter($entry['value'])),
				FilterComparisonOperator::IN => $cmd->expr()->in($entry['attribute'], $cmd->createNamedParameter($entry['value'])),
				FilterComparisonOperator::NIN => $cmd->expr()->notIn($entry['attribute'], $cmd->createNamedParameter($entry['value'])),
				FilterComparisonOperator::LIKE => $cmd->expr()->like($entry['attribute'], $cmd->createNamedParameter($entry['value'])),
				FilterComparisonOperator::NLIKE => $cmd->expr()->notLike($entry['attribute'], $cmd->createNamedParameter($entry['value'])),
			};
			if ($entry['conjunction'] === FilterConjunctionOperator::AND) {
				$cmd->andWhere($comparison);
			} elseif ($entry['conjunction'] === FilterConjunctionOperator::OR) {
				$cmd->orWhere($comparison);
			} else {
				$cmd->where($comparison);
			}
		}
	}

	protected function fromSort(IQueryBuilder $cmd, ISort $sort): void {
		foreach ($sort->conditions() as $entry) {
			$cmd->addOrderBy($entry['attribute'], $entry['direction'] ? 'ASC' : 'DESC');
		}
	}

	/**
	 * retrieve services for specific user from data store
	 *
	 * @param IFilter $filter filter options
	 * @param ISort $sort sort options
	 *
	 * @return array<int,ServiceEntity>
	 */
	public function list(?IFilter $filter = null, ?ISort $sort = null): array {
		// construct data store command
		$cmd = $this->_Store->getQueryBuilder();
		$cmd->select('*')
			->from($this->_EntityTable);
		// apply filters
		if ($filter instanceof IFilter) {
			$this->fromFilter($cmd, $filter);
		}
		// apply sort
		if ($sort instanceof ISort) {
			$this->fromSort($cmd, $sort);
		}
		// execute command
		$rsl = $cmd->executeQuery();
		$entities = [];
		try {
			while ($data = $rsl->fetch()) {
				$entities[$data['id']] = $this->toEntity($data);
			}
			return $entities;
		} finally {
			$rsl->closeCursor();
		}
	}

	public function listFilter(): IFilter {
		return new FilterBase();
	}

	public function listSort(): ISort {
		return new SortBase();
	}

	/**
	 * retrieve entity from data store
	 *
	 * @param int $id entity id
	 *
	 * @return ServiceEntity|null
	 */
	public function fetch(int $id): ?ServiceEntity {

		// construct data store command
		$cmd = $this->_Store->getQueryBuilder();
		$cmd->select('*')
			->from($this->_EntityTable)
			->where($cmd->expr()->eq('id', $cmd->createNamedParameter($id)));
		// execute command
		$rsl = $cmd->executeQuery();
		try {
			$entity = $rsl->fetch();
			if (is_array($entity)) {
				return $this->toEntity($entity);
			} else {
				return null;
			}
		} finally {
			$rsl->closeCursor();
		}

	}

	/**
	 * create a entity entry in the data store
	 *
	 * @param ServiceEntity $entity
	 *
	 * @return ServiceEntity
	 */
	public function create(ServiceEntity $entity): ServiceEntity {

		// construct data store command
		$cmd = $this->_Store->getQueryBuilder();
		$cmd->insert($this->_EntityTable);
		// assign values
		foreach ($this->fromEntity($entity) as $column => $value) {
			$cmd->setValue($column, $cmd->createNamedParameter($value));
		}
		// execute command
		$cmd->executeStatement();
		// determine if id needs to be assigned
		if ($entity->id === null) {
			$entity->setId($cmd->getLastInsertId());
		}

		$entity->resetUpdatedFields();

		return $entity;
	}

	/**
	 * modify a entity entry in the data store
	 *
	 * @param string $uid user id
	 * @param ServiceEntity $entity
	 *
	 * @return ServiceEntity
	 */
	public function modify(string $uid, ServiceEntity $entity): ServiceEntity {

		// construct data store command
		$cmd = $this->_Store->getQueryBuilder();
		$cmd->update($this->_EntityTable)
			->where($cmd->expr()->eq('uid', $cmd->createNamedParameter($uid)))
			->andWhere($cmd->expr()->eq('id', $cmd->createNamedParameter($entity->getId())));
		// assign values
		if (count($entity->getUpdatedFields())) {
			foreach ($this->fromEntity($entity) as $column => $value) {
				$cmd->set($column, $cmd->createNamedParameter($value));
			}
			// execute command
			$cmd->executeStatement();
			// determine if id needs to be assigned
			if ($entity->id === null) {
				$entity->setId($cmd->getLastInsertId());
			}
		}

		$entity->resetUpdatedFields();

		return $entity;
	}

	/**
	 * delete a entity from the data store
	 *
	 * @param string $uid user id
	 * @param ServiceEntity $entity
	 *
	 * @return ServiceEntity
	 */
	public function delete(string $uid, ServiceEntity $entity): ServiceEntity {

		// construct data store command
		$cmd = $this->_Store->getQueryBuilder();
		$cmd->delete($this->_EntityTable)
			->where($cmd->expr()->eq('uid', $cmd->createNamedParameter($uid)))
			->andWhere($cmd->expr()->eq('id', $cmd->createNamedParameter($entity->getId())));
		// execute command
		$cmd->executeStatement();

		// return result
		return $entity;
	}

	/**
	 * delete services for a specific user from data store
	 *
	 * @param string $uid user id
	 *
	 * @return mixed
	 */
	public function deleteByUser(string $uid): mixed {

		// construct data store command
		$cmd = $this->_Store->getQueryBuilder();
		$cmd->delete($this->_EntityTable)
			->where($cmd->expr()->eq('uid', $cmd->createNamedParameter($uid)));
		// execute command and return result
		return $cmd->executeStatement();
	}

}
