<?php

namespace App\Domain\Uptime;

/**
 * Opens all TCP connections at once and waits on them together, so checking
 * N devices takes about one timeout instead of N.
 */
class TcpProber
{
    /**
     * @param  array<string, array{0: string, 1: int}>  $targets  key => [host, port]
     * @return array<string, array{ok: bool, latency_ms: int|null, error: string|null}>
     */
    public function probe(array $targets, float $timeout = 3.0): array
    {
        $results = [];
        $pending = [];
        $started = [];

        foreach ($targets as $key => [$host, $port]) {
            $address = str_contains($host, ':') ? "[{$host}]" : $host;
            $started[$key] = hrtime(true);
            $socket = @stream_socket_client(
                "tcp://{$address}:{$port}",
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            );

            if ($socket === false) {
                $results[$key] = $this->failure($errno === 0 ? 'dns' : 'refused');

                continue;
            }

            stream_set_blocking($socket, false);
            $pending[$key] = $socket;
        }

        $deadline = hrtime(true) + (int) ($timeout * 1e9);

        while ($pending !== [] && ($left = $deadline - hrtime(true)) > 0) {
            $read = null;
            $write = $pending;
            $except = null;

            if (@stream_select($read, $write, $except, intdiv($left, 1_000_000_000), intdiv($left % 1_000_000_000, 1000)) === false) {
                break;
            }

            foreach ($write as $socket) {
                $key = array_search($socket, $pending, true);
                // A refused async connect also becomes writable; only a connected socket has a peer name.
                $connected = @stream_socket_get_name($socket, true) !== false;
                $results[$key] = $connected
                    ? ['ok' => true, 'latency_ms' => (int) round((hrtime(true) - $started[$key]) / 1e6), 'error' => null]
                    : $this->failure('refused');
                fclose($socket);
                unset($pending[$key]);
            }
        }

        foreach ($pending as $key => $socket) {
            fclose($socket);
            $results[$key] = $this->failure('timeout');
        }

        return $results;
    }

    /** @return array{ok: false, latency_ms: null, error: string} */
    private function failure(string $error): array
    {
        return ['ok' => false, 'latency_ms' => null, 'error' => $error];
    }
}
