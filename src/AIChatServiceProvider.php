<?php

declare(strict_types=1);

namespace PointerDev\AIChat;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use PointerDev\AIChat\Auth\AIChatRuntimeSessionManager;
use PointerDev\AIChat\Auth\AIChatRuntimeSessionMiddleware;

class AIChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ai-chat.php', 'ai-chat');

        $clientFactory = function (Application $app): AIChatClient {
            $config = (array) $app['config']->get('ai-chat', []);

            return new AIChatClient(
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
            $this->app->scoped(AIChatClient::class, $clientFactory);
        } else {
            $this->app->singleton(AIChatClient::class, $clientFactory);
        }

        $this->app->alias(AIChatClient::class, 'ai-chat.client');
        $this->app->bind(AIChatRuntimeSessionManager::class, function (Application $app): AIChatRuntimeSessionManager {
            $config = (array) $app['config']->get('ai-chat', []);
            return new AIChatRuntimeSessionManager(
                // Runtime manager mutates the same request-scoped client that app code resolves.
                client: $app->make(AIChatClient::class),
                config: $config
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/ai-chat.php' => config_path('ai-chat.php'),
        ], 'ai-chat-config');

        if ($this->app->bound('router')) {
            /** @var Router $router */
            $router = $this->app->make('router');
            $router->aliasMiddleware('ai-chat.runtime-session', AIChatRuntimeSessionMiddleware::class);
        }
    }
}
