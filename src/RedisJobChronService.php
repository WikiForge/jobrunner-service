<?php

require __DIR__ . '/RedisJobService.php';
require __DIR__ . '/PeriodicScriptParamsIterator.php';

class RedisJobChronService extends RedisJobService {
	/**
	 * time to wait between Lua scripts
	 */
	private const LUA_WAIT_US = 5000;

	/**
	 * time between task runs
	 */
	private const PERIOD_WAIT_US = 1e6;

	/**
	 * Entry point method that starts the service in earnest and keeps running
	 * @throws Exception
	 */
	public function main() {
		$this->notice( "Starting job chron loop(s)..." );

		$host = gethostname();

		// Setup signal handlers...
		$handlerFunc = static function ( $signo ) {
			print "Caught signal ($signo)\n";
			exit( 0 );
		};
		$ok = pcntl_signal( SIGHUP, $handlerFunc )
			&& pcntl_signal( SIGINT, $handlerFunc )
			&& pcntl_signal( SIGTERM, $handlerFunc );
		if ( !$ok ) {
			throw new Exception( 'Could not install singal handlers.' );
		}

		// run out of phase immediately
		usleep( mt_rand( 0, self::PERIOD_WAIT_US ) );

		$memLast = memory_get_usage();
		$this->incrStats( "start-chron.$host" );
		while ( true ) {
			pcntl_signal_dispatch();

			$count = $this->executePeriodicTasks();
			if ( $count ) {
				$this->notice( "Updated the state of $count job(s) (recycle/undelay/abandon)." );
			}

			usleep( self::PERIOD_WAIT_US );

			// Track memory usage
			$memCurrent = memory_get_usage();
			$this->debug( "Memory usage: $memCurrent bytes." );
			$this->incrStats( "memory.$host", $memCurrent - $memLast );
			$this->sendStats();
			$memLast = $memCurrent;
		}
	}

	/**
	 * Recycle or destroy any jobs that have been claimed for too long and release any ready
	 * delayed jobs into the queue. Also abandon and prune out jobs that failed too many times.
	 * Finally, this updates the aggregator server as necessary.
	 *
	 * @return int|bool Number of jobs recycled/deleted/undelayed/abandoned (false if not run)
	 */
	private function executePeriodicTasks() {
		$jobs = 0;

		$host = gethostname();

		$ok = true;
		try {
			// Only let a limited number of services do this at once
			$lockKey = $this->poolLock( __METHOD__, count( $this->queueSrvs ), 300 );
			if ( $lockKey === false ) {
				$this->incrStats( "periodictasks.raced.$host" );
				$this->notice( "Raced out of periodic tasks." );
				return $jobs;
			}

			$this->incrStats( "periodictasks.claimed.$host" );

			// Job queue partition servers
			$qServers = $this->queueSrvs;
			// Randomize to scale the liveliness with the # of runners
			shuffle( $qServers );

			// Track queues that become "ready"
			// map of (queue name => timestamp)
			$aggrMap = [ '_epoch' => time() ];
			// Run the chron script on each job queue partition server...
			foreach ( $qServers as $qServer ) {
				if ( !$this->updatePartitionQueueServer( $qServer, $aggrMap, $jobs, $lockKey ) ) {
					$this->incrStats( "periodictasks.partition-failed.$qServer" );
					$ok = false;
				}
			}
			// Update the map in the aggregator as queues become ready or empty.
			// Brief race conditions get fixed within seconds by the next runs.
			$this->redisCmdHA(
				$this->aggrSrvs,
				'hMSet',
				[ "{$this->getReadyQueueKey()}:temp", $aggrMap ]
			);
			$this->redisCmdHA(
				$this->aggrSrvs,
				'rename',
				[ "{$this->getReadyQueueKey()}:temp", $this->getReadyQueueKey() ]
			);

			// Release the pool lock
			$this->poolUnlock( $lockKey );
		} catch ( RedisExceptionHA $e ) {
			$ok = false;
		}

		if ( $ok ) {
			$this->incrStats( "periodictasks.done.$host" );
		} else {
			$this->incrStats( "periodictasks.failed.$host" );
			$this->error( "Failed to do periodic tasks for some queues." );
		}

		return $jobs;
	}

	/**
	 * @param string $qServer Redis host
	 * @param array &$aggrMap Map of (queue name => timestamp)
	 * @param int &$jobs
	 * @param string $lockKey
	 * @return bool
	 * @throws RedisExceptionHA
	 */
	private function updatePartitionQueueServer( string $qServer, array &$aggrMap, int &$jobs, string $lockKey ): bool {
		$qConn = $this->getRedisConn( $qServer );
		if ( !$qConn ) {
			// partition down
			return false;
		}

		// Get the list of queues with non-abandoned jobs
		try {
			$queueIds = $this->redisCmd(
				$qConn,
				'sMembers',
				[ 'global:jobqueue:s-queuesWithJobs' ]
			);
		} catch ( RedisException $e ) {
			$this->handleRedisError( $e, $qServer );
			return false;
		}

		// Build up per-queue script arguments using an Iterator to avoid client OOMs...
		// equal priority
		shuffle( $queueIds );
		$paramsByQueue = new PeriodicScriptParamsIterator( $this, $queueIds );

		$ok = true;
		$queuesChecked = 0;
		// load LUA script only once per server round
		$scriptLoaded = false;
		foreach ( $paramsByQueue as $params ) {
			// Run periodic updates on this partition of this queue
			$affected = $this->updatePartitionQueue( $qServer, $params, $aggrMap, $scriptLoaded );
			$ok = $ok && ( $affected !== false );

			// avoid CPU hogging
			usleep( self::LUA_WAIT_US );

			$jobs += (int)$affected;

			// Don't let the pool lock expire mid-run
			if ( ( ++$queuesChecked % 100 ) == 0 ) {
				$this->poolRefreshLock( $lockKey );
			}
		}

		return $ok;
	}

	/**
	 * @param string $qServer Redis host
	 * @param array $params A single value from PeriodicScriptParamsIterator
	 * @param array &$aggrMap Map of (queue name => timestamp)
	 * @param bool &$scriptLoaded
	 * @return int|bool Affected jobs or false on failure
	 */
	private function updatePartitionQueue( string $qServer, array $params, array &$aggrMap, bool &$scriptLoaded ) {
		$qConn = $this->getRedisConn( $qServer );
		if ( !$qConn ) {
			// partition down
			return false;
		}

		try {
			// Load the LUA script into memory if needed
			$script = PeriodicScriptParamsIterator::getChronScript();
			if ( $scriptLoaded ) {
				$sha1 = sha1( $script );
			} else {
				$sha1 = $this->redisCmd( $qConn, 'script', [ 'load', $script ] );
				$scriptLoaded = true;
			}

			$result = $this->redisCmd(
				$qConn,
				'evalSha',
				[ $sha1, $params['params'], $params['keys'] ]
			);

			if ( $result ) {
				[ $qType, $qDomain ] = $params['queue'];
				[ $released, $abandoned, $pruned, $undelayed, $ready ] = $result;
				if ( $ready > 0 ) {
					// This checks $ready to handle lost aggregator updates as well as
					// to merge after network partitions that caused aggregator fail-over.
					$aggrMap[$this->encQueueName( $qType, $qDomain )] = time();
				}
				$affectedJobs = ( array_sum( $result ) - $ready );
				$this->incrStats( "job-recycle.$qType", $released );
				$this->incrStats( "job-abandon.$qType", $abandoned );
				$this->incrStats( "job-undelay.$qType", $undelayed );
				$this->incrStats( "job-prune.$qType", $pruned );
			} else {
				$affectedJobs = false;
			}
		} catch ( RedisException $e ) {
			$affectedJobs = false;
			$this->handleRedisError( $e, $qServer );
		}

		return $affectedJobs;
	}

	/**
	 * @param string $name
	 * @param int $slots
	 * @param int $ttl
	 * @return string|bool Lock key or false
	 * @throws RedisExceptionHA
	 */
	private function poolLock( string $name, int $slots, int $ttl ) {
		for ( $i = 0; $i < $slots; ++$i ) {
			$key = "$name:lock:$i";
			$now = microtime( true );

			$oldLock = $this->redisCmdHA( $this->aggrSrvs, 'get', [ $key ] );
			if ( $oldLock === false || $oldLock < ( $now - $ttl ) ) {
				$casLock = $this->redisCmdHA( $this->aggrSrvs, 'getset', [ $key, $now ] );
				if ( $casLock == $oldLock ) {
					return $key;
				}
			}
		}

		return false;
	}

	/**
	 * @param string $key
	 * @return bool
	 * @throws RedisExceptionHA
	 */
	private function poolRefreshLock( string $key ): bool {
		return $this->redisCmdHA( $this->aggrSrvs, 'set', [ $key, microtime( true ) ] );
	}

	/**
	 * @param string $key
	 * @return bool
	 * @throws RedisExceptionHA
	 */
	private function poolUnlock( string $key ): bool {
		return (bool)$this->redisCmdHA( $this->aggrSrvs, 'del', [ $key ] );
	}

	/**
	 * @param string $type Queue type
	 * @return int Seconds
	 */
	public function getTTLForType( string $type ): int {
		return $this->claimTTLMap[$type] ?? $this->claimTTLMap['*'];
	}

	/**
	 * @param string $type Queue type
	 * @return int
	 */
	public function getAttemptsForType( string $type ): int {
		return $this->attemptsMap[$type] ?? $this->attemptsMap['*'];
	}
}
