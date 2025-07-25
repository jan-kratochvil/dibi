<?php

/**
 * This file is part of the Dibi, smart database abstraction layer (https://dibiphp.com)
 * Copyright (c) 2005 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Dibi\Reflection;

use Dibi;
use function array_values, strtolower;


/**
 * Reflection metadata class for a result set.
 *
 * @property-read array $columns
 * @property-read array $columnNames
 */
class Result
{
	/** @var Column[]|null */
	private ?array $columns;

	/** @var Column[]|null */
	private ?array $names;


	public function __construct(
		private readonly Dibi\Drivers\Result $driver,
	) {
	}


	/** @return Column[] */
	public function getColumns(): array
	{
		$this->initColumns();
		return array_values($this->columns);
	}


	/** @return string[] */
	public function getColumnNames(bool $fullNames = false): array
	{
		$this->initColumns();
		$res = [];
		foreach ($this->columns as $column) {
			$res[] = $fullNames ? $column->getFullName() : $column->getName();
		}

		return $res;
	}


	public function getColumn(string $name): Column
	{
		$this->initColumns();
		$l = strtolower($name);
		if (isset($this->names[$l])) {
			return $this->names[$l];

		} else {
			throw new Dibi\Exception("Result set has no column '$name'.");
		}
	}


	public function hasColumn(string $name): bool
	{
		$this->initColumns();
		return isset($this->names[strtolower($name)]);
	}


	protected function initColumns(): void
	{
		if (!isset($this->columns)) {
			$this->columns = [];
			$reflector = $this->driver instanceof Dibi\Drivers\Engine
				? $this->driver
				: null;
			foreach ($this->driver->getResultColumns() as $info) {
				$this->columns[] = $this->names[strtolower($info['name'])] = new Column($reflector, $info);
			}
		}
	}
}
