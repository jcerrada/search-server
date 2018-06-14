<?php

/*
 * This file is part of the Apisearch Server
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Feel free to edit as you please, and have fun.
 *
 * @author Marc Morera <yuhu@mmoreram.com>
 * @author PuntMig Technologies
 */

declare(strict_types=1);

namespace Apisearch\Server\Domain\Plugin;

use Apisearch\Server\Domain\CommandWithRepositoryReferenceAndToken;
use League\Tactician\Middleware;

/**
 * Class PluginMiddleware.
 */
class PluginMiddlewareCollector implements Middleware
{
    /**
     * @var PluginMiddleware[]
     *
     * Plugin middleware
     */
    private $pluginMiddlewares = [];

    /**
     * @var string[]
     *
     * Enabled plugins
     */
    private $enabledPlugins;

    /**
     * PluginMiddlewareCollector constructor.
     *
     * @param string[] $enabledPlugins
     */
    public function __construct(array $enabledPlugins)
    {
        $this->enabledPlugins = $enabledPlugins;
    }

    /**
     * Add plugin middleware.
     *
     * @param PluginMiddleware $pluginMiddleware
     */
    public function addPluginMiddleware(PluginMiddleware $pluginMiddleware)
    {
        $commandNamespace = $pluginMiddleware->getSubscribedEvent();

        if (!array_reduce($this->enabledPlugins, function (bool $found, array $plugin) use ($pluginMiddleware) {
            return $found || (0 === strpos(get_class($pluginMiddleware), $plugin['path']));
        }, false)) {
            return;
        }

        if (!isset($this->pluginMiddlewares[$commandNamespace])) {
            $this->pluginMiddlewares[$commandNamespace] = [];
        }

        $this->pluginMiddlewares[$commandNamespace][] = $pluginMiddleware;
    }

    /**
     * Get PluginMiddlewares.
     *
     * @return PluginMiddleware[]
     */
    public function getPluginMiddlewares(): array
    {
        return $this->pluginMiddlewares;
    }

    /**
     * @param object   $command
     * @param callable $next
     *
     * @return mixed
     */
    public function execute($command, callable $next)
    {
        $lastCallable = $next;

        if (
            $command instanceof CommandWithRepositoryReferenceAndToken &&
            isset($this->pluginMiddlewares[get_class($command)])
        ) {
            /**
             * @var PluginMiddleware
             */
            $middlewares = $this->pluginMiddlewares[get_class($command)];
            foreach ($middlewares as $pluginMiddleware) {
                $lastCallable = function (CommandWithRepositoryReferenceAndToken $command) use ($pluginMiddleware, $lastCallable) {
                    return $pluginMiddleware->execute(
                        $command,
                        $lastCallable
                    );
                };
            }
        }

        return $lastCallable($command);
    }
}