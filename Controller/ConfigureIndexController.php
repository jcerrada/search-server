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

namespace Apisearch\Server\Controller;

use Apisearch\Config\Config;
use Apisearch\Exception\InvalidFormatException;
use Apisearch\Http\Http;
use Apisearch\Repository\RepositoryReference;
use Apisearch\Server\Domain\Command\ConfigureIndex;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class ConfigureIndexController.
 */
class ConfigureIndexController extends ControllerWithBusAndEventRepository
{
    /**
     * Config the index.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function __invoke(Request $request): JsonResponse
    {
        $this->configureEventRepository($request);
        $query = $request->query;
        $requestBody = json_decode($request->getContent(), true);

        if (
            is_null($requestBody) ||
            !is_array($requestBody) ||
            !isset($requestBody[Http::CONFIG_FIELD])
        ) {
            throw InvalidFormatException::configFormatNotValid($request->getContent());
        }

        $configAsArray = $requestBody[Http::CONFIG_FIELD];
        $this
            ->commandBus
            ->handle(new ConfigureIndex(
                RepositoryReference::create(
                    $query->get(Http::APP_ID_FIELD, ''),
                    $query->get(Http::INDEX_FIELD, '')
                ),
                $query->get(Http::TOKEN_FIELD, ''),
                Config::createFromArray($configAsArray)
            ));

        return new JsonResponse('Config applied', 200);
    }
}
