<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tests\Unit\Fake;

use Predis\ClientInterface;
use Predis\Command\CommandInterface;

/**
 * In-memory stand-in for a Predis client, covering only the commands the cache
 * store and tag index actually issue. It exists so the Redis code path can be
 * exercised without a server: the point under test is which keys get read,
 * deleted and pruned, and that is entirely decided in PHP.
 */
final class FakeRedisClient implements ClientInterface
{
    /** @var array<string, string> */
    public array $strings = [];

    /** @var array<string, list<string>> */
    public array $sets = [];

    /** @var array<string, int> key => ttl in seconds, as last set by EXPIRE */
    public array $expiries = [];

    /** @var list<string> every command issued, for asserting on read volume */
    public array $calls = [];

    /** @var array<string, \Closure> command => hook, for acting mid-operation */
    private array $hooks = [];

    /** Run $hook just before $command executes — how a concurrent writer is staged. */
    public function onCall(string $command, \Closure $hook): void
    {
        $this->hooks[strtolower($command)] = $hook;
    }

    public function getCommandFactory()
    {
        throw new \LogicException('not needed by these tests');
    }

    public function getOptions()
    {
        throw new \LogicException('not needed by these tests');
    }

    public function connect()
    {
        return null;
    }

    public function disconnect()
    {
        return null;
    }

    public function getConnection()
    {
        throw new \LogicException('not needed by these tests');
    }

    public function createCommand($method, $arguments = [])
    {
        throw new \LogicException('not needed by these tests');
    }

    public function executeCommand(CommandInterface $command)
    {
        throw new \LogicException('not needed by these tests');
    }

    public function __call($method, $arguments)
    {
        $this->calls[] = strtolower($method);

        if (isset($this->hooks[strtolower($method)])) {
            ($this->hooks[strtolower($method)])();
        }

        return match (strtolower($method)) {
            'set' => $this->doSet($arguments[0], $arguments[1]),
            'setex' => $this->doSet($arguments[0], $arguments[2]),
            'get' => $this->strings[$arguments[0]] ?? null,
            'del' => $this->doDel($arguments[0]),
            'sadd' => $this->doSadd($arguments[0], $arguments[1]),
            'srem' => $this->doSrem($arguments[0], $arguments[1]),
            'smembers' => $this->sets[$arguments[0]] ?? [],
            'mget' => array_map(fn(string $k) => $this->strings[$k] ?? null, (array) $arguments[0]),
            'expire' => $this->doExpire($arguments[0], (int) $arguments[1]),
            'persist' => $this->doPersist($arguments[0]),
            'ttl' => $this->doTtl($arguments[0]),
            'exists' => isset($this->strings[$arguments[0]]) ? 1 : 0,
            // Not simulated on purpose: a hand-written stand-in for a Lua
            // script would test the stand-in. Everything that runs one is
            // covered against a real Redis instead.
            'eval' => throw new \LogicException('FakeRedisClient does not run Lua; use the integration test'),
            default => throw new \LogicException("FakeRedisClient does not implement {$method}"),
        };
    }

    private function doExpire(string $key, int $seconds): int
    {
        if (!isset($this->strings[$key]) && !isset($this->sets[$key])) {
            return 0;
        }
        $this->expiries[$key] = $seconds;
        return 1;
    }

    private function doPersist(string $key): int
    {
        if (!isset($this->expiries[$key])) {
            return 0;
        }
        unset($this->expiries[$key]);
        return 1;
    }

    /** Redis semantics: -2 when the key is gone, -1 when it has no expiry. */
    private function doTtl(string $key): int
    {
        if (!isset($this->strings[$key]) && !isset($this->sets[$key])) {
            return -2;
        }
        return $this->expiries[$key] ?? -1;
    }

    private function doSet(string $key, string $value): string
    {
        $this->strings[$key] = $value;
        return 'OK';
    }

    /** @param string|list<string> $keys */
    private function doDel(array|string $keys): int
    {
        $count = 0;
        foreach ((array) $keys as $key) {
            if (isset($this->strings[$key])) {
                unset($this->strings[$key]);
                $count++;
            }
            if (isset($this->sets[$key])) {
                unset($this->sets[$key]);
                $count++;
            }
            unset($this->expiries[$key]);
        }
        return $count;
    }

    /** @param string|list<string> $members */
    private function doSadd(string $key, array|string $members): int
    {
        $added = 0;
        foreach ((array) $members as $member) {
            if (!in_array($member, $this->sets[$key] ?? [], true)) {
                $this->sets[$key][] = $member;
                $added++;
            }
        }
        return $added;
    }

    /** @param string|list<string> $members */
    private function doSrem(string $key, array|string $members): int
    {
        if (!isset($this->sets[$key])) {
            return 0;
        }
        $drop = (array) $members;
        $before = count($this->sets[$key]);
        $this->sets[$key] = array_values(
            array_filter($this->sets[$key], static fn(string $m) => !in_array($m, $drop, true))
        );
        if ($this->sets[$key] === []) {
            unset($this->sets[$key]);
        }
        return $before - count($this->sets[$key] ?? []);
    }
}
