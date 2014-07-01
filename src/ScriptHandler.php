<?php

namespace Drupal\Tangler;

use Composer\Script\Event;

class ScriptHandler
{
    public static function postUpdate(Event $event)
    {
        $composer = $event->getComposer();
        $mapper = new Mapper(null, 'www');
        $mapper->mirror($mapper->getMap(
            $composer->getInstallationManager(),
            $composer->getRepositoryManager()
        ));
    }
}
