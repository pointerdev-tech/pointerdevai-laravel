<?php

declare(strict_types=1);

namespace PointerDev\PointerAI;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use PointerDev\PointerAI\Auth\PointerAIRuntimeSessionManager;
use PointerDev\PointerAI\Auth\PointerAIRuntimeSessionMiddleware;

class PointerAIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/pointerai.php', 'pointerai');

        $clientFactory = function (Application $app): PointerAIClient {
            $config = (array) $app['config']->get('pointerai', []);

            return new PointerAIClient(
                baseUrl: (string) ($config['base_url'] ?? ''),
                projectId: (string) ($config['project_id'] ?? ''),
                publishableKey: (string) ($config['publishable_key'] ?? ''),
                secretKey: isset($config['secret_key']) ? (string) $config['secret_key'] : null,
                endUserToken: isset($config['end_user_token']) ? (string) $config['end_user_token'] : null,
                timeoutSeconds: (int) ($config['timeout'] ?? 20)
            );
        };

        if (method_exists($this->app, 'scoped')) {
            // Ensure mutable auth/session state is isolated per request (Octane/Swoole-safe).
            $this->app->scoped(PointerAIClient::class, $clientFactory);
        } else {
            $this->app->singleton(PointerAIClient::class, $clientFactory);
        }

        $this->app->alias(PointerAIClient::class, 'pointerai.client');
        $this->app->bind(PointerAIRuntimeSessionManager::class, function (Application $app): PointerAIRuntimeSessionManager {
            $config = (array) $app['config']->get('pointerai', []);
            return new PointerAIRuntimeSessionManager(
                // Runtime manager mutates the same request-scoped client that app code resolves.
                client: $app->make(PointerAIClient::class),
                config: $config
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/pointerai.php' => config_path('pointerai.php'),
        ], 'pointerai-config');

        if ($this->app->bound('router')) {
            /** @var Router $router */
            $router = $this->app->make('router');
            $router->aliasMiddleware('pointerai.runtime-session', PointerAIRuntimeSessionMiddleware::class);
        }
    }
}
