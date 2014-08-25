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

    public function __construct($path, $drupal)
    {
        $this->root   = rtrim($path, '/');
        $this->drupal = rtrim($drupal, '/');
    }

    private function getFS()
    {
        return $this->fs ? $this->fs : $this->fs = new Filesystem();
    }

    public function getRoot()
    {
        return $this->root;
    }

    private function getFinder()
    {
        return new Finder();
    }

    public function getMap(InstallationManager $im, RepositoryManager $rm)
    {
        return array_merge(
            $this->mapContrib($im, $rm),
            $this->mapByType('module'),
            $this->mapByType('theme'),
            $this->mapCustom(),
            $this->mapSettings(),
            $this->mapVendor(),
            $this->mapFiles()
        );
    }

    public function mapContrib(InstallationManager $im, RepositoryManager $rm)
    {
        $typePathMap = $this->getTypePathMap();
        $typeInstallMap = array();
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
                $names = explode('/', $package->getPrettyName());
                $name = $names[1];
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
            ->exclude(array('vendor', 'www', 'cnf'))
            ->depth('== 0')
            ->in($this->getRoot())
            ->name("*.php")
            ->name("*.inc")
            ->name("*.module")
            ->name("*.info")
            ->name("*.install")
            ->name('src')
            ->name('lib');
        return $finder;
    }

    private function getTypeFinder($type)
    {
        if (file_exists($dir = $this->getRoot()."/{$type}s")) {
            $finder = $this->getFinder()
                ->ignoreUnreadableDirs()
                ->depth('== 0')
                ->in($dir);
            return $finder;
        }
        return array();
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
        return $this->name;
    }

    public function mapCustom()
    {
        $root   = $this->getRoot();
        $fs     = $this->getFS();
        $paths  = array();
        if ($name = $this->getName()) {
            foreach ($this->getCustomFilesFinder() as $file) {
                $install = rtrim($fs->makePathRelative($file->getRealpath(), $root), '/');
                $paths["custom"][$install] = sprintf(
                    $this->getTypePathMap('module').'/%s',
                    $name,
                    $file->getFilename()
                );
            }
        }
        return $paths;
    }

    public function mapFiles()
    {
        return array(
            'files' => array('cnf/files' => $this->drupal.'/sites/default/files')
        );
    }

    public function mapSettings()
    {
        return array(
            'settings' => array('cnf/settings.php' => $this->drupal.'/sites/default/settings.php')
        );
    }

    public function mapVendor()
    {
        return array(
            'vendor' => array('vendor' => $this->drupal.'/sites/default/vendor')
        );
    }

    public function mapByType($type)
    {
        $paths  = array();
        $root   = $this->getRoot();
        $fs     = $this->getFS();
        foreach ($this->getTypeFinder($type) as $file) {
            $install = rtrim($fs->makePathRelative($file->getRealpath(), $root), '/');
            $paths["{$type}s"][$install] = sprintf(
                $this->getTypePathMap($type),
                $file->getFilename()
            );
        }
        return $paths;
    }

    public function mirror($map)
    {
        $fs = $this->getFS();
        $root = $this->getRoot();
        foreach ($map as $type => $pathMap) {
            foreach ($pathMap as $installPath => $targetPath) {
                if ($fs->exists("$root/$installPath")) {
                    if ($type === 'core') {
                        $fs->mirror("$root/$installPath", "$targetPath");
                    }
                    else {
                        $fs->symlink(
                            rtrim(substr($fs->makePathRelative(
                                "$root/$installPath",
                                $targetPath
                            ), 3), '/'),
                            $targetPath,
                            true
                        );
                    }
                }
            }
        }
    }

    public function clear()
    {
        $typePathMap = $this->getTypePathMap();
        $this->getFS()->remove(array('directory', $typePathMap['core']));
    }

    public function getTypePathMap($type = null)
    {
        $map = array(
            'core'    => $this->drupal,
            'module'  => $this->drupal.'/sites/all/modules/%s',
            'theme'   => $this->drupal.'/sites/all/themes/%s',
            'drush'   => $this->drupal.'/sites/all/drush/%s',
            'profile' => $this->drupal.'/profiles/%s'
        );
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
