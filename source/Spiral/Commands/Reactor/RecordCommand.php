<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright �2009-2015
 */
namespace Spiral\Commands\Reactor;

use Spiral\Commands\Reactor\Prototypes\EntityCommand;
use Spiral\Reactor\Generators\RecordGenerator;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Generate ORM record with pre-defined schema and validation placeholders.
 */
class RecordCommand extends EntityCommand
{
    /**
     * Success message. To be used by DocumentCommand.
     */
    const SUCCESS_MESSAGE = 'ORM Record was successfully created:';

    /**
     * Generator class to be used.
     */
    const GENERATOR = RecordGenerator::class;

    /**
     * Generation type to be used.
     */
    const TYPE = 'entity';

    /**
     * {@inheritdoc}
     */
    protected $name = 'create:record';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Generate new ORM Record.';

    /**
     * {@inheritdoc}
     */
    protected $arguments = [
        ['name', InputArgument::REQUIRED, 'Record name.']
    ];
}