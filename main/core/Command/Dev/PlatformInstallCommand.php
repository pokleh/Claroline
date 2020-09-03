<?php

/*
 * This file is part of the Claroline Connect package.
 *
 * (c) Claroline Consortium <consortium@claroline.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Claroline\CoreBundle\Command\Dev;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Performs a fresh installation of the platform.
 */
class PlatformInstallCommand extends Command
{
    protected function configure()
    {
        $this->setDescription('Installs the platform.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /*
         * Set the app/config directory in the installation state.
         * - No bundles.bup.ini
         * - Empty previous-installed.json
         */
        $kernel = $this->getApplication()->getKernel();
        $rootDir = $kernel->getProjectDir().'/app';
        $previous = $rootDir.'/config/previous-installed.json';
        @unlink($previous);
        file_put_contents($previous, '[]');

        $this
            ->getApplication()
            ->get('claroline:update')
            ->run(new ArrayInput([]), $output);
    }
}