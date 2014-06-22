<?php

namespace Drupal\Tangler;

use Symfony\Component\Filesystem\Filesystem;
use Composer\Installer\InstallationManager;
use Composer\Repository\RepositoryManager;

class Mapper
{
    private $fs = false;

    private function getFS()
    {
        return $this->fs ? $this->fs : $this->fs = new Filesystem();
    }

    public function getMap(InstallationManager $im, RepositoryManager $rm)
    {
        $typePathMap = $this->getTypePathMap();
        $typeInstallMap = [];
        $packages = $rm->getLocalRepository()
            ->getCanonicalPackages();
        foreach ($packages as $package) {
            if ($drupalType = $this->getDrupalType($package)) {
                $name = explode('/', $package->getPrettyName())[1];
                $installPath = $im->getInstaller($package->getType())
                    ->getInstallPath($package);
                $typeInstallMap[$drupalType][] = [
                    $installPath => sprintf($typePathMap[$drupalType], $name)
                ];
            }
        }
        return array_intersect_key($typeInstallMap, $typePathMap);
    }

    public function mirror($map)
    {
        $fs = $this->getFS();
        foreach ($map as $type => $pathMaps) {
            foreach ($pathMaps as $pathMap) {
                foreach ($pathMap as $installPath => $targetPath) {
                    $fs->mirror($installPath, $targetPath);
                }
            }
        }
    }

    public function clear()
    {
        $this->getFS()->remove(['directory', $this->getTypePathMap()['core']]);
    }

    public function getTypePathMap()
    {
        return [
            'core'    => 'www',
            'module'  => 'www/sites/all/modules/%s',
            'theme'   => 'www/sites/all/themes/%s',
            'profile' => 'www/profiles/%s'
        ];
    }

    public function getDrupalType($package)
    {
        if (strpos($package->getType(), 'drupal') === 0) {
            return substr($package->getType(), strlen('drupal-'));
        }
        elseif ($package->getPrettyName() === 'drupal/drupal') {
            return 'core';
        }
        return false;
    }
}
