<?php

namespace App\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Toneflix\ResourceModifier\Commands\ResourceMakeCommand as ModifierResourceMakeCommand;

/**
 * `make:resource` builds resources on the resource-modifier base classes,
 * from the stubs in `stubs/`.
 */
#[AsCommand(name: 'make:resource')]
class ResourceMakeCommand extends ModifierResourceMakeCommand
{
    protected $name = 'make:resource';

    protected $description = 'Create a new resource';
}
