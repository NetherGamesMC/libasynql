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
use Threaded;
use function is_string;
use function serialize;
use function unserialize;

class QueryRecvQueue extends Threaded{
	private int $availableThreads = 0;
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
		$row = $this->queries->shift();
		if(is_string($row)){
			[$queryId, $results] = unserialize($row, ["allowed_classes" => true]);
			return true;
		}
		return false;
	}

	/**
	 * @param SqlError|SqlResult[]|null $results
	 */
	public function waitForResults(?int &$queryId, SqlError|array|null &$results) : bool{
		return $this->queries->synchronized(function() use (&$queryId, &$results) : bool{
			while($this->queries->count() === 0 && $this->availableThreads > 0){
				$this->queries->wait();
			}
			return $this->fetchResults($queryId, $results);
		});
	}

	public function addAvailableThread() : void{
		$this->queries->synchronized(fn() => ++$this->availableThreads);
	}

	public function removeAvailableThread() : void{
		$this->queries->synchronized(function() : void{
			--$this->availableThreads;
			$this->queries->notify();
		});
	}
}
