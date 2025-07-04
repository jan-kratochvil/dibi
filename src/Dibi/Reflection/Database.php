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
 * Reflection metadata class for a database.
 *
 * @property-read string $name
 * @property-read array $tables
 * @property-read array $tableNames
 */
class Database
{
	/** @var Table[] */
	private array $tables;


	public function __construct(
		private readonly Dibi\Drivers\Engine $reflector,
		private ?string $name = null,
	) {
	}


	public function getName(): ?string
	{
		return $this->name;
	}


	/** @return Table[] */
	public function getTables(): array
	{
		$this->init();
		return array_values($this->tables);
	}


	/** @return string[] */
	public function getTableNames(): array
	{
		$this->init();
		$res = [];
		foreach ($this->tables as $table) {
			$res[] = $table->getName();
		}

		return $res;
	}


	public function getTable(string $name): Table
	{
		$this->init();
		$l = strtolower($name);
		if (isset($this->tables[$l])) {
			return $this->tables[$l];

		} else {
			throw new Dibi\Exception("Database '$this->name' has no table '$name'.");
		}
	}


	public function hasTable(string $name): bool
	{
		$this->init();
		return isset($this->tables[strtolower($name)]);
	}


	protected function init(): void
	{
		if (!isset($this->tables)) {
			$this->tables = [];
			foreach ($this->reflector->getTables() as $info) {
				$this->tables[strtolower($info['name'])] = new Table($this->reflector, $info);
			}
		}
	}
}
