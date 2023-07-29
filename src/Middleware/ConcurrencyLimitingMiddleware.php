<?php declare(strict_types=1);

namespace Amp\Http\Server\Middleware;

use Amp\DeferredFuture;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

final class ConcurrencyLimitingMiddleware implements Middleware
{
    private int $pendingRequests = 0;

    /** @var \SplQueue<DeferredFuture> */
    private readonly \SplQueue $queue;

    /**
     * @param positive-int $concurrencyLimit
     */
    public function __construct(private readonly int $concurrencyLimit)
    {
        /** @psalm-suppress DocblockTypeContradiction */
        if ($this->concurrencyLimit <= 0) {
            throw new \ValueError('The concurrency limit must be a positive integer');
        }

        $this->queue = new \SplQueue();
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        if (++$this->pendingRequests > $this->concurrencyLimit) {
            $deferred = new DeferredFuture();
            $this->queue->push($deferred);
            $deferred->getFuture()->await();
        }

        try {
            return $requestHandler->handleRequest($request);
        } finally {
            --$this->pendingRequests;
            if (!$this->queue->isEmpty()) {
                $this->queue->shift()->complete();
            }
        }
    }
}
