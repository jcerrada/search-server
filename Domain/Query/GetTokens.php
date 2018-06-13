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

namespace Apisearch\Server\Domain\Query;

use Apisearch\Repository\WithRepositoryReference;
use Apisearch\Server\Domain\AppRequiredCommand;
use Apisearch\Server\Domain\CommandWithRepositoryReferenceAndToken;

/**
 * Class GetTokens.
 */
class GetTokens extends CommandWithRepositoryReferenceAndToken implements WithRepositoryReference, AppRequiredCommand
{
}
