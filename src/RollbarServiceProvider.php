<?php namespace Jenssegers\Rollbar;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Rollbar;
use RollbarNotifier;

class RollbarServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Bootstrap the application events.
     */
    public function boot()
    {
        $app = $this->app;

        // Listen to log messages.
        $app['log']->listen(function ($level, $message, $context) use ($app) {
            $app['Jenssegers\Rollbar\RollbarLogHandler']->log($level, $message, $context);
        });
    }

    /**
     * Register the service provider.
     */
    public function register()
    {
        // Don't register rollbar if it is not configured.
        if (! getenv('ROLLBAR_TOKEN') and ! $this->app['config']->get('services.rollbar')) {
            return;
        }

        $app = $this->app;

        $this->app['RollbarNotifier'] = $this->app->share(function ($app) {
            // Default configuration.
            $defaults = [
                'environment'  => $app->environment(),
                'root'         => base_path(),
            ];

            $config = array_merge($defaults, $app['config']->get('services.rollbar', []));

            $config['access_token'] = getenv('ROLLBAR_TOKEN') ?: $app['config']->get('services.rollbar.access_token');

            if (empty($config['access_token'])) {
                throw new InvalidArgumentException('Rollbar access token not configured');
            }

            Rollbar::$instance = $rollbar = new RollbarNotifier($config);

            return $rollbar;
        });

        $this->app['Jenssegers\Rollbar\RollbarLogHandler'] = $this->app->share(function ($app) {
            $level = getenv('ROLLBAR_LEVEL') ?: $app['config']->get('services.rollbar.level', 'debug');

            return new RollbarLogHandler($app['RollbarNotifier'], $app, $level);
        });

        // Register the fatal error handler.
        register_shutdown_function(function () use ($app) {
            if (isset($app['RollbarNotifier'])) {
                $app->make('RollbarNotifier');
                Rollbar::report_fatal_error();
            }
        });

        // If the Rollbar client was resolved, then there is a possibility that there
        // are unsent error messages in the internal queue, so let's flush them.
        register_shutdown_function(function () use ($app) {
            if (isset($app['RollbarNotifier'])) {
                $app['RollbarNotifier']->flush();
            }
        });
    }
}
