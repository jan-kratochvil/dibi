<?php

/**
 * This file is part of the Dibi, smart database abstraction layer (https://dibiphp.com)
 * Copyright (c) 2005 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Dibi;

use function array_key_exists, count, func_get_args, is_array, is_string;


/**
 * SQL builder via fluent interfaces.
 *
 * @method Fluent select(...$field)
 * @method Fluent distinct()
 * @method Fluent from($table, ...$args = null)
 * @method Fluent where(...$cond)
 * @method Fluent groupBy(...$field)
 * @method Fluent having(...$cond)
 * @method Fluent orderBy(...$field)
 * @method Fluent limit(int $limit)
 * @method Fluent offset(int $offset)
 * @method Fluent join(...$table)
 * @method Fluent leftJoin(...$table)
 * @method Fluent innerJoin(...$table)
 * @method Fluent rightJoin(...$table)
 * @method Fluent outerJoin(...$table)
 * @method Fluent union(Fluent $fluent)
 * @method Fluent unionAll(Fluent $fluent)
 * @method Fluent as(...$field)
 * @method Fluent on(...$cond)
 * @method Fluent and(...$cond)
 * @method Fluent or(...$cond)
 * @method Fluent using(...$cond)
 * @method Fluent update(...$cond)
 * @method Fluent insert(...$cond)
 * @method Fluent delete(...$cond)
 * @method Fluent into(...$cond)
 * @method Fluent values(...$cond)
 * @method Fluent set(...$args)
 * @method Fluent asc()
 * @method Fluent desc()
 */
class Fluent implements IDataSource
{
	public const
		AffectedRows = 'a',
		Identifier = 'n',
		Remove = false;

	#[\Deprecated('use Fluent::Remove')]
	public const REMOVE = self::Remove;

	public static array $masks = [
		'SELECT' => ['SELECT', 'DISTINCT', 'FROM', 'WHERE', 'GROUP BY',
			'HAVING', 'ORDER BY', 'LIMIT', 'OFFSET', ],
		'UPDATE' => ['UPDATE', 'SET', 'WHERE', 'ORDER BY', 'LIMIT'],
		'INSERT' => ['INSERT', 'INTO', 'VALUES', 'SELECT'],
		'DELETE' => ['DELETE', 'FROM', 'USING', 'WHERE', 'ORDER BY', 'LIMIT'],
	];

	/** default modifiers for arrays */
	public static array $modifiers = [
		'SELECT' => '%n',
		'FROM' => '%n',
		'IN' => '%in',
		'VALUES' => '%l',
		'SET' => '%a',
		'WHERE' => '%and',
		'HAVING' => '%and',
		'ORDER BY' => '%by',
		'GROUP BY' => '%by',
	];

	/** clauses separators */
	public static array $separators = [
		'SELECT' => ',',
		'FROM' => ',',
		'WHERE' => 'AND',
		'GROUP BY' => ',',
		'HAVING' => 'AND',
		'ORDER BY' => ',',
		'LIMIT' => false,
		'OFFSET' => false,
		'SET' => ',',
		'VALUES' => ',',
		'INTO' => false,
	];

	/** clauses */
	public static array $clauseSwitches = [
		'JOIN' => 'FROM',
		'INNER JOIN' => 'FROM',
		'LEFT JOIN' => 'FROM',
		'RIGHT JOIN' => 'FROM',
	];

	private readonly Connection $connection;
	private array $setups = [];
	private ?string $command = null;
	private array $clauses = [];
	private array $flags = [];
	private $cursor;

	/** normalized clauses */
	private static HashMap $normalizer;


	public function __construct(Connection $connection)
	{
		$this->connection = $connection;

		if (!isset(self::$normalizer)) {
			self::$normalizer = new HashMap(self::_formatClause(...));
		}
	}


	/**
	 * Appends new argument to the clause.
	 */
	public function __call(string $clause, array $args): static
	{
		$clause = self::$normalizer->$clause;

		// lazy initialization
		if ($this->command === null) {
			if (isset(self::$masks[$clause])) {
				$this->clauses = array_fill_keys(self::$masks[$clause], null);
			}

			$this->cursor = &$this->clauses[$clause];
			$this->cursor = [];
			$this->command = $clause;
		}

		// auto-switch to a clause
		if (isset(self::$clauseSwitches[$clause])) {
			$this->cursor = &$this->clauses[self::$clauseSwitches[$clause]];
		}

		if (array_key_exists($clause, $this->clauses)) {
			// append to clause
			$this->cursor = &$this->clauses[$clause];

			// TODO: really delete?
			if ($args === [self::Remove]) {
				$this->cursor = null;
				return $this;
			}

			if (isset(self::$separators[$clause])) {
				$sep = self::$separators[$clause];
				if ($sep === false) { // means: replace
					$this->cursor = [];

				} elseif (!empty($this->cursor)) {
					$this->cursor[] = $sep;
				}
			}
		} else {
			// append to currect flow
			if ($args === [self::Remove]) {
				return $this;
			}

			$this->cursor[] = $clause;
		}

		if ($this->cursor === null) {
			$this->cursor = [];
		}

		// special types or argument
		if (count($args) === 1) {
			$arg = $args[0];
			// TODO: really ignore true?
			if ($arg === true) { // flag
				return $this;

			} elseif (is_string($arg) && preg_match('#^[a-z:_][a-z0-9_.:]*\z#i', $arg)) { // identifier
				$args = [$clause === 'AS' ? '%N' : '%n', $arg];

			} elseif (is_array($arg) || ($arg instanceof \Traversable && !$arg instanceof self)) { // any array
				if (isset(self::$modifiers[$clause])) {
					$args = [self::$modifiers[$clause], $arg];

				} elseif (is_string(key($arg))) { // associative array
					$args = ['%a', $arg];
				}
			} // case $arg === false is handled above
		}

		foreach ($args as $arg) {
			if ($arg instanceof self) {
				$arg = new Literal("($arg)");
			}

			$this->cursor[] = $arg;
		}

		return $this;
	}


	/**
	 * Switch to a clause.
	 */
	public function clause(string $clause): static
	{
		$this->cursor = &$this->clauses[self::$normalizer->$clause];
		if ($this->cursor === null) {
			$this->cursor = [];
		}

		return $this;
	}


	/**
	 * Removes a clause.
	 */
	public function removeClause(string $clause): static
	{
		$this->clauses[self::$normalizer->$clause] = null;
		return $this;
	}


	/**
	 * Change a SQL flag.
	 */
	public function setFlag(string $flag, bool $value = true): static
	{
		$flag = strtoupper($flag);
		if ($value) {
			$this->flags[$flag] = true;
		} else {
			unset($this->flags[$flag]);
		}

		return $this;
	}


	/**
	 * Is a flag set?
	 */
	final public function getFlag(string $flag): bool
	{
		return isset($this->flags[strtoupper($flag)]);
	}


	/**
	 * Returns SQL command.
	 */
	final public function getCommand(): ?string
	{
		return $this->command;
	}


	final public function getConnection(): Connection
	{
		return $this->connection;
	}


	/**
	 * Adds Result setup.
	 */
	public function setupResult(string $method): static
	{
		$this->setups[] = func_get_args();
		return $this;
	}


	/********************* executing ****************d*g**/


	/**
	 * Generates and executes SQL query.
	 * Returns result set or number of affected rows
	 * @return ($return is self::Identifier|self::AffectedRows ? int : Result)
	 * @throws Exception
	 */
	public function execute(?string $return = null): Result|int|null
	{
		$res = $this->query($this->_export());
		return match ($return) {
			self::Identifier => $this->connection->getInsertId(),
			self::AffectedRows => $this->connection->getAffectedRows(),
			default => $res,
		};
	}


	/**
	 * Generates, executes SQL query and fetches the single row.
	 */
	public function fetch(): Row|array|null
	{
		return $this->command === 'SELECT' && !$this->clauses['LIMIT']
			? $this->query($this->_export(null, ['%lmt', 1]))->fetch()
			: $this->query($this->_export())->fetch();
	}


	/**
	 * Like fetch(), but returns only first field.
	 * Returns value on success, null if no next record
	 */
	public function fetchSingle(): mixed
	{
		return $this->command === 'SELECT' && !$this->clauses['LIMIT']
			? $this->query($this->_export(null, ['%lmt', 1]))->fetchSingle()
			: $this->query($this->_export())->fetchSingle();
	}


	/**
	 * Fetches all records from table.
	 */
	public function fetchAll(?int $offset = null, ?int $limit = null): array
	{
		return $this->query($this->_export(null, ['%ofs %lmt', $offset, $limit]))->fetchAll();
	}


	/**
	 * Fetches all records from table and returns associative tree.
	 * @param  string  $assoc  associative descriptor
	 */
	public function fetchAssoc(string $assoc): array
	{
		return $this->query($this->_export())->fetchAssoc($assoc);
	}


	/**
	 * Fetches all records from table like $key => $value pairs.
	 */
	public function fetchPairs(?string $key = null, ?string $value = null): array
	{
		return $this->query($this->_export())->fetchPairs($key, $value);
	}


	/**
	 * Required by the IteratorAggregate interface.
	 */
	public function getIterator(?int $offset = null, ?int $limit = null): ResultIterator
	{
		return $this->query($this->_export(null, ['%ofs %lmt', $offset, $limit]))->getIterator();
	}


	/**
	 * Generates and prints SQL query or it's part.
	 */
	public function test(?string $clause = null): bool
	{
		return $this->connection->test($this->_export($clause));
	}


	public function count(): int
	{
		return Helpers::intVal($this->query([
			'SELECT COUNT(*) FROM (%ex', $this->_export(), ') [data]',
		])->fetchSingle());
	}


	private function query($args): Result
	{
		$res = $this->connection->query($args);
		foreach ($this->setups as $setup) {
			$method = array_shift($setup);
			$res->$method(...$setup);
		}

		return $res;
	}


	/********************* exporting ****************d*g**/


	public function toDataSource(): DataSource
	{
		return new DataSource($this->connection->translate($this->_export()), $this->connection);
	}


	/**
	 * Returns SQL query.
	 */
	final public function __toString(): string
	{
		return $this->connection->translate($this->_export());
	}


	/**
	 * Generates parameters for Translator.
	 */
	protected function _export(?string $clause = null, array $args = []): array
	{
		if ($clause === null) {
			$data = $this->clauses;
			if ($this->command === 'SELECT' && ($data['LIMIT'] || $data['OFFSET'])) {
				$args = array_merge(['%lmt %ofs', $data['LIMIT'][0] ?? null, $data['OFFSET'][0] ?? null], $args);
				unset($data['LIMIT'], $data['OFFSET']);
			}
		} else {
			$clause = self::$normalizer->$clause;
			if (array_key_exists($clause, $this->clauses)) {
				$data = [$clause => $this->clauses[$clause]];
			} else {
				return [];
			}
		}

		foreach ($data as $clause => $statement) {
			if ($statement !== null) {
				$args[] = $clause;
				if ($clause === $this->command && $this->flags) {
					$args[] = implode(' ', array_keys($this->flags));
				}

				foreach ($statement as $arg) {
					$args[] = $arg;
				}
			}
		}

		return $args;
	}


	/**
	 * Format camelCase clause name to UPPER CASE.
	 * @internal
	 */
	public static function _formatClause(string $s): string
	{
		if ($s === 'order' || $s === 'group') {
			$s .= 'By';
			trigger_error("Did you mean '$s'?", E_USER_NOTICE);
		}

		return strtoupper(preg_replace('#[a-z](?=[A-Z])#', '$0 ', $s));
	}


	public function __clone()
	{
		// remove references
		foreach ($this->clauses as $clause => $val) {
			$this->clauses[$clause] = &$val;
			unset($val);
		}

		$this->cursor = &$foo;
	}
}
