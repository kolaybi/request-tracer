<?php

namespace KolayBi\RequestTracer;

use Illuminate\Http\Client\Events\ConnectionFailed as IlluminateConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending as IlluminateRequestSending;
use Illuminate\Http\Client\Events\ResponseReceived as IlluminateResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use KolayBi\RequestTracer\Commands\PurgeTracesCommand;
use KolayBi\RequestTracer\Commands\TraceInspectCommand;
use KolayBi\RequestTracer\Commands\TraceWaterfallCommand;
use KolayBi\RequestTracer\Contracts\NullContextProvider;
use KolayBi\RequestTracer\Contracts\TraceContextProvider;
use KolayBi\RequestTracer\Events\Soap\ConnectionFailedEvent as SoapConnectionFailedEvent;
use KolayBi\RequestTracer\Events\Soap\ResponseReceivedEvent as SoapResponseReceivedEvent;
use KolayBi\RequestTracer\Listeners\Http\ConnectionFailedListener as HttpConnectionFailedListener;
use KolayBi\RequestTracer\Listeners\Http\RequestSendingListener as HttpRequestSendingListener;
use KolayBi\RequestTracer\Listeners\Http\ResponseReceivedListener as HttpResponseReceivedListener;
use KolayBi\RequestTracer\Listeners\Soap\ConnectionFailedListener as SoapConnectionFailedListener;
use KolayBi\RequestTracer\Listeners\Soap\ResponseReceivedListener as SoapResponseReceivedListener;
use KolayBi\RequestTracer\Mixins\HttpTracingMixin;
use ReflectionException;

class RequestTracerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/request-tracer.php', 'request-tracer');

        $this->app->singleton(TraceContextProvider::class, function () {
            $class = config('request-tracer.context_provider');

            return $class ? $this->app->make($class) : new NullContextProvider();
        });
    }

    /**
     * @throws ReflectionException
     */
    public function boot(): void
    {
        $this->publishesMigrations([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ]);

        $this->publishes([
            __DIR__ . '/../config/request-tracer.php' => config_path('request-tracer.php'),
        ], 'request-tracer-config');

        if (config('request-tracer.outgoing.enabled', true)) {
            Http::mixin(new HttpTracingMixin());

            Event::listen(IlluminateRequestSending::class, HttpRequestSendingListener::class);
            Event::listen(IlluminateResponseReceived::class, HttpResponseReceivedListener::class);
            Event::listen(IlluminateConnectionFailed::class, HttpConnectionFailedListener::class);
            Event::listen(SoapResponseReceivedEvent::class, SoapResponseReceivedListener::class);
            Event::listen(SoapConnectionFailedEvent::class, SoapConnectionFailedListener::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                PurgeTracesCommand::class,
                TraceInspectCommand::class,
                TraceWaterfallCommand::class,
            ]);
        }
    }
}
