<?php

namespace Drupal\Tangler;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Composer\Installer\InstallationManager;
use Composer\Repository\RepositoryManager;

class Mapper
{
    private $fs     = false;
    private $finder = false;
    private $root   = false;

    private function getFS()
    {
        return $this->fs ? $this->fs : $this->fs = new Filesystem();
    }

    private function getRoot()
    {
        if ($this->root) {
            return $this->root;
        }
        $paths = ['/'];
        foreach(array_filter(explode('/', getcwd())) as $dir) {
            $paths[] = end($paths).$dir.'/';
        }
        $fs = $this->getFS();
        foreach (array_reverse($paths) as $path) {
            if ($fs->exists($path.'/composer.json')) {
                return $this->root = $path;
            }
        }
    }

    private function getFinder()
    {
        return new Finder();
    }

    public function getMap(InstallationManager $im, RepositoryManager $rm)
    {
        return array_merge(
            $this->mapContrib($im, $rm),
            $this->mapCustom(),
            $this->mapSettings(),
            $this->mapFiles()
        );
    }

    public function mapContrib(InstallationManager $im, RepositoryManager $rm)
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
                $typeInstallMap[$drupalType][$installPath] = sprintf($typePathMap[$drupalType], $name);
            }
        }
        return array_intersect_key($typeInstallMap, $typePathMap);
    }

    public function mapCustom()
    {
        $root   = $this->getRoot();
        $custom = "$root/drupal";
        $fs     = $this->getFS();
        $paths  = [];
        foreach (['module', 'theme', 'profile'] as $type) {
            if ($fs->exists("$custom/{$type}s")) {
                $finder = $this->getFinder()
                    ->ignoreUnreadableDirs()
                    ->depth('== 0')
                    ->in("$custom/{$type}s");
                foreach ($finder as $file) {
                    $install = $fs->makePathRelative($file->getRealpath(), $root);
                    $paths["custom_$type"][$install] = sprintf(
                        $this->getTypePathMap($type),
                        $file->getFilename()
                    );
                }
            }
        }
        return $paths;
    }

    public function mapFiles()
    {
        return [
            'files' => ['cnf/files' => 'www/sites/default/files']
        ];
    }

    public function mapSettings()
    {
        return [
            'settings' => ['cnf/settings.php' => 'www/sites/default/settings.php']
        ];
    }

    public function mirror($map)
    {
        $fs = $this->getFS();
        $root = $this->getRoot();
        foreach ($map as $type => $pathMap) {
            foreach ($pathMap as $installPath => $targetPath) {
                if ($fs->exists("$root/$installPath")) {
                    $dest = rtrim($fs->makePathRelative(
                        rtrim("$root$targetPath", '/'),
                        getcwd()
                    ), '/');
                    $fs->symlink(
                        $fs->makePathRelative(
                            rtrim("$root$installPath", '/'),
                            $dest
                        ),
                        $dest,
                        true
                    );
                }
            }
        }
    }

    public function clear()
    {
        $this->getFS()->remove(['directory', $this->getTypePathMap()['core']]);
    }

    public function getTypePathMap($type = null)
    {
        $map = [
            'core'    => 'www',
            'module'  => 'www/sites/all/modules/contrib/%s',
            'theme'   => 'www/sites/all/themes/%s',
            'profile' => 'www/profiles/%s'
        ];
        if ($type) {
            return $map[$type];
        }
        return $map;
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
