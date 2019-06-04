<?php

namespace Amp\Http\Client;

use Amp\Http\Client\Internal\ApplicationInterceptorClient;
use Amp\Http\Client\Internal\PoolClient;
use Amp\Socket\SocketPool;
use Amp\Socket\UnlimitedSocketPool;

final class ClientBuilder
{
    private $socketPool;
    private $networkInterceptors = [];
    private $applicationInterceptors = [];

    public function __construct(?SocketPool $socketPool = null)
    {
        $this->socketPool = $socketPool ?? new UnlimitedSocketPool;
    }

    public function addNetworkInterceptor(NetworkInterceptor $networkInterceptor): void
    {
        $this->networkInterceptors[] = $networkInterceptor;
    }

    public function addApplicationInterceptor(ApplicationInterceptor $applicationInterceptor): void
    {
        $this->applicationInterceptors[] = $applicationInterceptor;
    }

    public function build(): Client
    {
        $client = new PoolClient($this->socketPool, ...$this->networkInterceptors);

        $applicationInterceptors = $this->applicationInterceptors;
        while ($applicationInterceptor = \array_pop($applicationInterceptors)) {
            $client = new ApplicationInterceptorClient($client, $applicationInterceptor);
        }

        return $client;
    }
}