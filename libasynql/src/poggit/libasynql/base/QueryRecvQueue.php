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

namespace poggit\libasynql\base;

use poggit\libasynql\SqlError;
use poggit\libasynql\SqlResult;
use ThreadedArray;
use ThreadedBase;
use function is_string;
use function serialize;
use function unserialize;

class QueryRecvQueue extends ThreadedBase{
	private ThreadedArray $queries;

	public function __construct(){
		$this->queries = new ThreadedArray;
	}

	/**
	 * @param SqlResult[] $results
	 */
	public function publishResult(int $queryId, array $results) : void{
		$this->queries->synchronized(function() use ($queryId, $results) : void{
			$this->queries[] = serialize([$queryId, $results]);
			$this->queries->notify();
		});
	}

	public function publishError(int $queryId, SqlError $error) : void{
		$this->queries->synchronized(function() use ($error, $queryId) : void{
			$this->queries[] = serialize([$queryId, $error]);
			$this->queries->notify();
		});
	}

	public function fetchResults(&$queryId, &$results) : bool{
		if(is_string($row = $this->queries->shift())){
			[$queryId, $results] = unserialize($row, ["allowed_classes" => true]);
			return true;
		}
		return false;
	}

	/**
	 * @return list<array{int, SqlError|SqlResults[]|null}>
	 */
	public function fetchAllResults(): array{
		return $this->queries->synchronized(function(): array{
			$ret = [];
			while($this->fetchResults($queryId, $results)){
				$ret[] = [$queryId, $results];
			}
			return $ret;
		});
	}

	/**
	 * @return list<array{int, SqlError|SqlResults[]|null}>
	 */
	public function waitForResults(int $expectedResults): array{
		return $this->queries->synchronized(function() use ($expectedResults) : array{
			$ret = [];
			while(count($ret) < $expectedResults){
				if(!$this->fetchResults($queryId, $results)){
					$this->queries->wait();
					continue;
				}
				$ret[] = [$queryId, $results];
			}
			return $ret;
		});
	}
}