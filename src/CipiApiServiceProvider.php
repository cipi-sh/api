<?php

namespace CipiApi;

use CipiApi\Console\Commands\CipiTokenCreate;
use CipiApi\Console\Commands\CipiTokenList;
use CipiApi\Console\Commands\CipiTokenRevoke;
use CipiApi\Console\Commands\PruneJobLogs;
use CipiApi\Console\Commands\RecordServerMetrics;
use CipiApi\Console\Commands\SeedApiUser;
use CipiApi\Events\JobStateChanged;
use CipiApi\Exceptions\AppsJsonUnreadableException;
use CipiApi\Exceptions\DisallowedCipiCommandException;
use CipiApi\Exceptions\MysqlDatabaseListingUnavailableException;
use CipiApi\Listeners\SendJobNotifications;
use CipiApi\Notifications\LogPushDriver;
use CipiApi\Notifications\PushDriverContract;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

class CipiApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/cipi.php', 'cipi');

        $this->app->singleton(PushDriverContract::class, function ($app) {
            $driver = (string) config('cipi.push.driver', 'log');
            return match ($driver) {
                'log' => new LogPushDriver(),
                default => $app->bound($driver) ? $app->make($driver) : new LogPushDriver(),
            };
        });
    }

    public function boot(): void
    {
        Authenticate::redirectUsing(function ($request) {
            if ($request->is('api/*', 'mcp', 'mcp/*') || $request->expectsJson()) {
                return null;
            }

            return Route::has('login') ? route('login') : '/';
        });

        $exceptionHandler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);

        $exceptionHandler->renderable(
            function (AppsJsonUnreadableException $e, $request) {
                if ($request && ($request->expectsJson() || $request->is('api/*'))) {
                    return response()->json(['error' => $e->getMessage()], 503);
                }
            }
        );

        $exceptionHandler->renderable(
            function (DisallowedCipiCommandException $e, $request) {
                if ($request && ($request->expectsJson() || $request->is('api/*'))) {
                    return response()->json(['error' => $e->getMessage()], 500);
                }
            }
        );

        $exceptionHandler->renderable(
            function (MysqlDatabaseListingUnavailableException $e, $request) {
                if ($request && ($request->expectsJson() || $request->is('api/*'))) {
                    return response()->json(['error' => $e->getMessage()], 503);
                }
            }
        );

        Event::listen(JobStateChanged::class, [SendJobNotifications::class, 'handle']);

        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        if (class_exists(\Laravel\Mcp\Facades\Mcp::class)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/mcp.php');
        }
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'cipi');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CipiTokenCreate::class,
                CipiTokenList::class,
                CipiTokenRevoke::class,
                SeedApiUser::class,
                RecordServerMetrics::class,
                PruneJobLogs::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/cipi.php' => config_path('cipi.php'),
            ], 'cipi-config');

            $this->publishes([
                __DIR__ . '/../public/api-docs' => public_path('api-docs'),
            ], 'cipi-assets');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/cipi'),
            ], 'cipi-views');

            $this->app->booted(function () {
                if (! (bool) config('cipi.metrics.enabled', true)) {
                    return;
                }
                /** @var Schedule $schedule */
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('cipi:record-server-metrics --prune')
                    ->everyMinute()
                    ->withoutOverlapping()
                    ->runInBackground();
                $schedule->command('cipi:prune-job-logs')
                    ->dailyAt('03:30');
            });
        }

        $router = $this->app['router'];
        $router->aliasMiddleware('abilities', CheckAbilities::class);
        $router->aliasMiddleware('ability', CheckForAnyAbility::class);
    }
}
