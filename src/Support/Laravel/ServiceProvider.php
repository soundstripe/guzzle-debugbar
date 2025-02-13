<?php
namespace GuzzleHttp\Profiling\Debugbar\Support\Laravel;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware as GuzzleMiddleware;
use GuzzleHttp\Profiling\Debugbar\ExceptionMiddleware;
use GuzzleHttp\Profiling\Debugbar\Profiler;
use GuzzleHttp\Profiling\Middleware;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * @var bool
     */
    protected $defer = true;

    /**
     * @return array
     */
    public function provides()
    {
        return [
            Client::class,
            ClientInterface::class,
            HandlerStack::class,
        ];
    }

    /**
     * Register method.
     */
    public function register()
    {
        // Configuring all guzzle clients.
        $this->app->bind(ClientInterface::class, function () {
            // Guzzle client
            return new Client(['handler' => $this->app->make(HandlerStack::class)]);
        });

        $this->app->alias(ClientInterface::class, Client::class);

        // Bind if needed.
        $this->app->bind(HandlerStack::class, function () {
            return HandlerStack::create();
        });

        // If resolved, by this SP or another, add some layers.
        $this->app->resolving(HandlerStack::class, function (HandlerStack $stack) {
            // We cannot log with debugbar from the CLI
            if ($this->app->runningInConsole()) {
                return;
            }
            
            /** @var \DebugBar\DebugBar $debugBar */
            $debugBar = $this->app->make('debugbar');

            $stack->push(new Middleware(new Profiler($timeline = $debugBar->getCollector('time'))));
            $stack->unshift(new ExceptionMiddleware($debugBar->getCollector('exceptions')));

            /** @var \GuzzleHttp\MessageFormatter $formatter */
            $formatter = $this->app->make(MessageFormatter::class);
            $stack->unshift(GuzzleMiddleware::log($debugBar->getCollector('messages'), $formatter));

            // Also log to the default PSR logger.
            if ($this->app->bound(LoggerInterface::class)) {
                $logger = $this->app->make(LoggerInterface::class);

                // Don't log to the same logger twice.
                if ($logger === $debugBar->getCollector('messages')) {
                    return;
                }

                // Push the middleware on the stack.
                $stack->unshift(GuzzleMiddleware::log($logger, $formatter));
            }
        });
    }
}
