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
    private $name   = false;

    public function __construct($path = null, $drupal = null) {
        $this->root   = $path;
        $this->drupal = $drupal;
    }

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
                $installPath = $im->getInstaller($package->getType())
                    ->getInstallPath($package);
                if (strpos($installPath, $root = $this->getRoot()) !== false) {
                    $installPath = $this->getFS()->makePathRelative(
                        $installPath,
                        $root
                    );
                }
                $name = explode('/', $package->getPrettyName())[1];
                $typeInstallMap[$drupalType][rtrim($installPath, '/')] = sprintf(
                    $typePathMap[$drupalType],
                    $name
                );
            }
        }
        return array_intersect_key($typeInstallMap, $typePathMap);
    }

    private function getCustomFilesFinder()
    {
        $finder = $this->getFinder()
            ->ignoreUnreadableDirs()
            ->ignoreVCS(true)
            ->exclude(['vendor', 'www'])
            ->depth('== 0')
            ->in($this->getRoot());
        return $finder;
    }

    private function getName()
    {
        if (!$this->name) {
            foreach ($this->getCustomFilesFinder() as $file) {
                if (preg_match('/(.*)\.info(\.yml)?/', $file->getFilename(), $matches)) {
                    $this->name = $matches[1];
                }
            }
        }
        if (!$this->name) {
            throw new \Exception('No drupal info file found');
        }
        return $this->name;
    }

    public function mapCustom()
    {
        $root   = $this->getRoot();
        $fs     = $this->getFS();
        $paths  = [];
        foreach ($this->getCustomFilesFinder() as $file) {
            $install = $fs->makePathRelative($file->getRealpath(), $root);
            $paths["custom"][$install] = sprintf(
                $this->getTypePathMap('module').'/%s',
                $this->getName(),
                $file->getFilename()
            );
        }
        return $paths;
    }

    public function mapFiles()
    {
        return [
            'files' => ['cnf/files' => $this->drupal.'/sites/default/files']
        ];
    }

    public function mapSettings()
    {
        return [
            'settings' => ['cnf/settings.php' => $this->drupal.'/sites/default/settings.php']
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
                        "$root$targetPath",
                        getcwd()
                    ), '/');
                    if ($type === 'core') {
                        $fs->mirror("$root$installPath", "$root$dest");
                    }
                    else {
                        $fs->symlink(
                            substr($fs->makePathRelative(
                                "$root$installPath",
                                "$root$dest"
                            ), 3),
                            $dest,
                            true
                        );
                    }
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
            'core'    => $this->drupal,
            'module'  => $this->drupal.'/sites/all/modules/%s',
            'theme'   => $this->drupal.'/sites/all/themes/%s',
            'profile' => $this->drupal.'/profiles/%s'
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
