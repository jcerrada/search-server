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

namespace Apisearch\Server\Controller\Listener;

use Apisearch\Exception\InvalidTokenException;
use Apisearch\Http\Http;
use Apisearch\Model\AppUUID;
use Apisearch\Model\IndexUUID;
use Apisearch\Model\Token;
use Apisearch\Model\TokenUUID;
use Apisearch\Server\Domain\Token\TokenValidator;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;

/**
 * Class TokenValidationOverHTTP.
 */
class TokenValidationOverHTTP
{
    /**
     * @var TokenValidator
     *
     * Token validator
     */
    private $tokenValidator;

    /**
     * TokenValidationOverHTTP constructor.
     *
     * @param TokenValidator $tokenValidator
     */
    public function __construct(TokenValidator $tokenValidator)
    {
        $this->tokenValidator = $tokenValidator;
    }

    /**
     * Validate token given a Request.
     *
     * @param GetResponseEvent $event
     */
    public function validateTokenOnKernelRequest(GetResponseEvent $event)
    {
        $request = $event->getRequest();
        $query = $request->query;
        $token = $query->get(Http::TOKEN_FIELD, '');
        if (is_null($token)) {
            throw InvalidTokenException::createInvalidTokenPermissions('');
        }

        $tokenString = $token instanceof Token
            ? $token->getTokenUUID()->composeUUID()
            : $token;

        $origin = $request->headers->get('Origin', '');
        $origin = str_replace(['http://', 'https://'], ['', ''], $origin);

        $token = $this
            ->tokenValidator
            ->validateToken(
                AppUUID::createById($query->get(Http::APP_ID_FIELD, '')),
                IndexUUID::createById($query->get(Http::INDEX_FIELD, '')),
                TokenUUID::createById($tokenString),
                $origin,
                $request->getPathInfo(),
                $request->getMethod()
            );

        $request
            ->query
            ->set(Http::TOKEN_FIELD, $token);
    }
}
