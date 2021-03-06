<?php

/*
 * This file is part of the Claroline Connect package.
 *
 * (c) Claroline Consortium <consortium@claroline.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Claroline\CoreBundle\Listener;

use Claroline\AuthenticationBundle\Security\Authentication\Authenticator;
use Claroline\CoreBundle\Command\AdminCliCommand;
use Claroline\CoreBundle\Manager\UserManager;
use Symfony\Component\Console\Event\ConsoleCommandEvent;

class CliListener
{
    private $authenticator;
    private $userManager;

    public function __construct(
        Authenticator $authenticator,
        UserManager $userManager
    ) {
        $this->authenticator = $authenticator;
        $this->userManager = $userManager;
    }

    /**
     * Sets claroline default admin for cli because it's very annoying otherwise to do it manually everytime.
     */
    public function setDefaultUser(ConsoleCommandEvent $event)
    {
        $command = $event->getCommand();

        if ($command instanceof AdminCliCommand) {
            $user = $this->userManager->getDefaultClarolineAdmin();
            $this->authenticator->createToken($user);
        }
    }
}
