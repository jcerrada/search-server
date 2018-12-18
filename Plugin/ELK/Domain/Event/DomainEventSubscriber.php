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
 */

declare(strict_types=1);

namespace Apisearch\Plugin\ELK\Domain\Event;

use Apisearch\Plugin\Redis\Domain\RedisWrapper;
use Apisearch\Server\Domain\Event\DomainEventWithRepositoryReference;
use Apisearch\Server\Domain\Event\EventSubscriber;
use Apisearch\Server\Domain\Event\ExceptionWasCached;
use Apisearch\Server\Domain\Formatter\TimeFormatBuilder;
use Monolog\Formatter\LogstashFormatter;
use Monolog\Handler\RedisHandler;
use Monolog\Logger;
use Monolog\Processor\MemoryPeakUsageProcessor;
use Monolog\Processor\MemoryUsageProcessor;
use Exception;

/**
 * Class DomainEventSubscriber.
 */
class DomainEventSubscriber implements EventSubscriber
{
    /**
     * @var RedisWrapper
     *
     * RedisWrapper
     */
    private $redisWrapper;

    /**
     * @var TimeFormatBuilder
     *
     * Time format builder
     */
    private $timeFormatBuilder;

    /**
     * @var string
     *
     * Key
     */
    private $key;

    /**
     * @var string
     *
     * Service
     */
    private $service;

    /**
     * @var Logger
     *
     * Logger
     */
    private $logger;

    /**
     * RedisMetadataRepository constructor.
     *
     * @param RedisWrapper      $redisWrapper
     * @param TimeFormatBuilder $timeFormatBuilder
     * @param string            $key
     * @param string            $service
     */
    public function __construct(
        RedisWrapper $redisWrapper,
        TimeFormatBuilder $timeFormatBuilder,
        string $key,
        string $service
    ) {
        $this->redisWrapper = $redisWrapper;
        $this->timeFormatBuilder = $timeFormatBuilder;
        $this->key = $key;
        $this->service = $service;
    }

    /**
     * Subscriber should handle event.
     *
     * @param DomainEventWithRepositoryReference $domainEventWithRepositoryReference
     *
     * @return bool
     */
    public function shouldHandleEvent(DomainEventWithRepositoryReference $domainEventWithRepositoryReference): bool
    {
        return true;
    }

    /**
     * Get logger.
     *
     * @return Logger
     */
    public function getLogger()
    {
        if ($this->logger instanceof Logger) {
            return $this->logger;
        }

        $redisHandler = new RedisHandler(
            $this
                ->redisWrapper
                ->getClient(),
            $this->key
        );

        $formatter = new LogstashFormatter('apisearch');
        $redisHandler
            ->setFormatter($formatter)
            ->setLevel(Logger::ERROR);
        $this->logger = new Logger('apisearch_to_logstash', [$redisHandler], [
            new MemoryUsageProcessor(),
            new MemoryPeakUsageProcessor(),
        ]);

        return $this->logger;
    }

    /**
     * Handle event.
     *
     * @param DomainEventWithRepositoryReference $domainEventWithRepositoryReference
     */
    public function handle(DomainEventWithRepositoryReference $domainEventWithRepositoryReference)
    {
        $event = $domainEventWithRepositoryReference->getDomainEvent();
        $reducedArray = $event->toLogger();
        $reducedArray['occurred_on'] = $this
            ->timeFormatBuilder
            ->formatTimeFromMillisecondsToBasicDateTime(
                $event->occurredOn()
            );

        try {
            $this
                ->getLogger()
                ->addRecord(
                    $event instanceof ExceptionWasCached ? Logger::ERROR : Logger::INFO,
                    json_encode([
                            'environment' => getenv('APP_ENV') ?g? 'prod',
                            'service' => $this->service,
                            'repository_reference' => $domainEventWithRepositoryReference
                                ->getRepositoryReference()
                                ->compose(),
                            'time_cost' => $domainEventWithRepositoryReference->getTimeCost(),
                        ] + $reducedArray)
                );
        } catch (Exception $exception) {
            // Nothing to do.
        }
    }
}
