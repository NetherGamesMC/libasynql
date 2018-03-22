<?php

/*
 * libasynql
 *
 * Copyright (C) 2018 SOFe
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace poggit\libasynql\generic;

use AssertionError;
use InvalidArgumentException;
use poggit\libasynql\GenericStatement;
use poggit\libasynql\SqlDialect;
use function in_array;
use function mb_strlen;
use function mb_strpos;
use function mb_substr;
use function uksort;

abstract class GenericStatementImpl implements GenericStatement{
	/** @var string */
	protected $name;
	/** @var string */
	protected $query;
	/** @var GenericVariable[] */
	protected $variables;
	/** @var string|null */
	protected $file;
	/** @var int */
	protected $lineNo;

	/** @var string[] */
	protected $varPositions = [];

	public function getName() : string{
		return $this->name;
	}

	public function getQuery() : string{
		return $this->query;
	}

	public function getVariables() : array{
		return $this->variables;
	}

	public function getFile() : ?string{
		return $this->file;
	}

	public function getLineNumber() : int{
		return $this->lineNo;
	}

	/**
	 * @param string            $dialect
	 * @param string            $name
	 * @param string            $query
	 * @param GenericVariable[] $variables
	 * @param string|null       $file
	 * @param int               $lineNo
	 * @return GenericStatementImpl
	 */
	public static function forDialect(string $dialect, string $name, string $query, array $variables, ?string $file, int $lineNo) : GenericStatementImpl{
		static $classMap = [
			SqlDialect::MYSQL => MysqlStatementImpl::class,
			SqlDialect::SQLITE => SqliteStatementImpl::class,
		];
		$className = $classMap[$dialect];
		return new $className($name, $query, $variables, $file, $lineNo);
	}

	public function __construct(string $name, string $query, array $variables, ?string $file, int $lineNo){
		$this->name = $name;
		$this->query = $query;
		$this->variables = $variables;
		$this->file = $file;
		$this->lineNo = $lineNo;

		$this->compilePositions();
	}

	protected function compilePositions() : void{
		uksort($this->variables, function($s1, $s2){
			return mb_strlen($s2) <=> mb_strlen($s1);
		});

		$usedNames = [];

		$positions = [];
		$quotesState = null;
		for($i = 1, $iMax = mb_strlen($this->query); $i < $iMax; ++$i){
			$thisChar = mb_substr($this->query, $i, 1);

			if($quotesState !== null){
				if($thisChar === "\\"){
					++$i; // skip one character
					continue;
				}
				if($thisChar === $quotesState){
					$quotesState = null;
					continue;
				}
				continue;
			}
			if(in_array($thisChar, ["'", "\"", "`"], true)){
				$quotesState = $thisChar;
				continue;
			}

			if($thisChar === ":"){
				$name = null;

				foreach($this->variables as $variable){
					if(mb_strpos($this->query, $variable->getName(), $i + 1) === $i + 1){
						$positions[$i] = $name = $variable->getName();
						break;
						// if multiple variables match, the first one i.e. the longest one wins
					}
				}

				if($name !== null){
					$usedNames[$name] = true;
					$i += mb_strlen($name); // skip the name
				}
			}
		}

		$newQuery = "";
		$lastPos = 0;
		foreach($positions as $pos => $name){
			$newQuery .= mb_substr($this->query, $lastPos, $pos - $lastPos);
			$this->varPositions[mb_strlen($newQuery)] = $name; // we aren't using $pos here, because we want the position in the cleaned string, not the position in the original query string
			$lastPos = $pos + mb_strlen($name) + 1;
		}
		$newQuery .= mb_substr($this->query, $lastPos);

		$this->query = $newQuery;

		foreach($this->variables as $variable){
			if(!isset($usedNames[$variable->getName()])){
				throw new InvalidArgumentException("The variable {$variable->getName()} is not used anywhere in the query! Check for typos.");
			}
		}
	}

	public function format(array $vars, ?string $placeHolder, ?array &$outArgs) : string{
		$outArgs = [];
		foreach($this->variables as $variable){
			if(!$variable->isOptional() && !isset($vars[$variable->getName()])){
				throw new InvalidArgumentException("Missing required variable {$variable->getName()}");
			}
		}

		$query = "";

		$lastPos = 0;
		foreach($this->varPositions as $pos => $name){
			$query .= mb_substr($this->query, $lastPos, $pos - $lastPos);
			$value = $vars[$name] ?? $this->variables[$name]->getDefault();
			try{
				$append = $this->formatVariable($this->variables[$name], $value);
			}catch(AssertionError $e){
				throw new InvalidArgumentException("Invalid value for :$name - " . $e->getMessage(), 0, $e);
			}
			if($append !== null){
				$query .= $append;
			}else{
				if($placeHolder !== null){
					$query .= $placeHolder;
					$outArgs[] = $outArgs;
				}else{
					$query .= $varName = ":var{$pos}";
					$outArgs[$varName] = $value;
				}
			}
			$lastPos = $pos;
		}
		$query .= mb_substr($this->query, $lastPos);

		return $query;
	}

	protected abstract function formatVariable(GenericVariable $variable, $value) : ?string;
}
