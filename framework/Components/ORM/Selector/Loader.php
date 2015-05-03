<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\ORM\Selector;

use Spiral\Components\DBAL\Database;
use Spiral\Components\DBAL\QueryResult;
use Spiral\Components\ORM\Entity;
use Spiral\Components\ORM\ORM;
use Spiral\Components\ORM\ORMException;
use Spiral\Components\ORM\Selector;

abstract class Loader
{
    /**
     * Relation type is required to correctly resolve foreign model.
     */
    const RELATION_TYPE = null;

    /**
     * Default load method (inload or postload).
     */
    const LOAD_METHOD = null;

    /**
     * Loaders associated with relation type.
     *
     * @var array
     */
    protected static $loaderClasses = array(
        Entity::HAS_ONE    => 'Spiral\Components\ORM\Selector\Loaders\HasOneLoader',
        Entity::HAS_MANY   => 'Spiral\Components\ORM\Selector\Loaders\HasManyLoader',
        Entity::BELONGS_TO => 'Spiral\Components\ORM\Selector\Loaders\BelongsToLoader'
    );

    /**
     * Container related to parent loader.
     *
     * @var string
     */
    protected $container = '';

    /**
     * Relation definition options.
     *
     * @var array
     */
    protected $relationDefinition = array();

    /**
     * ORM component is required to fetch all required model schemas.
     *
     * @invisible
     * @var ORM
     */
    protected $orm = null;

    /**
     * Related model schema.
     *
     * @invisible
     * @var array
     */
    protected $schema = array();

    /**
     * Parent loader.
     *
     * @invisible
     * @var Loader|null
     */
    protected $parent = null;

    /**
     * Loader options usually used while declaring joins and etc.
     *
     * @var array
     */
    protected $options = array(
        'method'     => null,
        'tableAlias' => null
    );

    /**
     * List of keys has to be stored as data references. This set of keys is required by inner loader(s)
     * to quickly compile nested data.
     *
     * @var array
     */
    protected $referenceKeys = array();

    protected $columns = array();

    protected $offset = 0;

    protected $countColumns = 0;

    protected $references = array();

    protected $aggregatedReferences = array();

    protected $duplicates = array();

    protected $result = array();

    /**
     * Sub loaders.
     *
     * @var Loader[]
     */
    public $loaders = array();

    public function __construct(
        $container,
        array $relationDefinition = array(),
        ORM $orm,
        Loader $parent = null
    )
    {
        $this->container = $container;
        $this->relationDefinition = $relationDefinition;
        $this->orm = $orm;

        $this->schema = $orm->getSchema($relationDefinition[static::RELATION_TYPE]);
        $this->parent = $parent;

        //Compiling options
        $this->options['method'] = static::LOAD_METHOD;

        if ($this->parent instanceof Selector\Loaders\RootLoader)
        {
            $this->options['tableAlias'] = $container;
        }
        else
        {
            $this->options['tableAlias'] = $this->parent->getTableAlias() . '_' . $container;
        }

        $this->columns = array_keys($this->schema[ORM::E_COLUMNS]);
        $this->countColumns = count($this->schema[ORM::E_COLUMNS]);
    }

    /**
     * Reference key (from parent object) required to speed up data normalization.
     *
     * @return string
     */
    public function getReferenceKey()
    {
        //Fairly simple logic
        return $this->relationDefinition[Entity::INNER_KEY];
    }

    public function getReferenceName(array $data)
    {
        $definition = $this->relationDefinition;

        if (!isset($data[$definition[Entity::OUTER_KEY]]))
        {
            return null;
        }

        //Fairly simple logic
        return $definition[Entity::INNER_KEY] . '::' . $data[$definition[Entity::OUTER_KEY]];
    }

    /**
     * @param string $relation
     * @param array  $options
     * @return Loader
     */
    public function addLoader($relation, array $options = array())
    {
        if (($position = strpos($relation, '.')) !== false)
        {
            $parentRelation = substr($relation, 0, $position);

            //Recursively
            return $this->getLoader($parentRelation)->addLoader(
                substr($relation, $position + 1),
                $options
            );
        }

        if (!isset($this->schema[ORM::E_RELATIONS][$relation]))
        {
            throw new ORMException(
                "Undefined relation '{$relation}' under '{$this->container}'."
            );
        }

        if (isset($this->loaders[$relation]))
        {
            $this->loaders[$relation]->setOptions($options);

            return $this->loaders[$relation];
        }

        $relationOptions = $this->schema[ORM::E_RELATIONS][$relation];

        //Adding loader
        $loader = self::$loaderClasses[$relationOptions[ORM::R_TYPE]];

        /**
         * @var Loader $loader
         */
        $loader = new $loader(
            $relation,
            $relationOptions[ORM::R_DEFINITION],
            $this->orm,
            $this
        );

        $loader->setOptions($options);
        $this->loaders[$relation] = $loader;

        if ($loader->getReferenceKey())
        {
            $this->referenceKeys[] = $loader->getReferenceKey();
            $this->referenceKeys = array_unique($this->referenceKeys);
        }

        return $loader;
    }

    /**
     * Simple alias for addLoader().
     *
     * @param string $relation
     * @return Loader
     */
    public function getLoader($relation)
    {
        return $this->addLoader($relation);
    }

    public function getTable()
    {
        return $this->schema[ORM::E_TABLE];
    }

    public function getTableAlias()
    {
        return $this->options['tableAlias'];
    }

    /**
     * Update loader options.
     *
     * @param array $options
     * @return static
     */
    public function setOptions(array $options = array())
    {
        $this->options = $options + $this->options;

        return $this;
    }

    public function createSelector(Database $database)
    {
        $selector = new Selector($this->schema, $this->orm, $database, array(), $this);

        if (!empty($this->loaders))
        {
            $this->offset = $selector->addColumns(
                $this->getTableAlias(),
                array_keys($this->schema[ORM::E_COLUMNS])
            );
        }

        foreach ($this->loaders as $loader)
        {
            $loader->clarifySelector($selector);
        }

        return $selector;
    }

    public function clarifySelector(Selector $selector)
    {
        if ($this->options['method'] != Selector::INLOAD)
        {
            return;
        }

        //Mounting columns
        $this->offset = $selector->addColumns(
            $this->getTableAlias(),
            array_keys($this->schema[ORM::E_COLUMNS])
        );

        //Inload conditions and etc
        $this->clarifyQuery($selector);

        foreach ($this->loaders as $loader)
        {
            $loader->clarifySelector($selector);
        }
    }

    abstract protected function clarifyQuery(Selector $selector);

    /**
     * @param Database $database
     * @return Selector[]
     */
    public function getPostSelectors(Database $database)
    {
        $selectors = array();
        foreach ($this->loaders as $loader)
        {
            if ($loader->options['method'] == Selector::POSTLOAD)
            {
                $selectors[] = $loader->createSelector($database);
            }
            else
            {
                $selectors = array_merge($selectors, $loader->getPostSelectors($database));
            }
        }

        return $selectors;
    }


    protected function fetchData(array $row)
    {
        $row = array_slice($row, $this->offset, $this->countColumns);

        //Populating keys
        return array_combine($this->columns, $row);
    }

    protected function checkDuplicate(array &$data)
    {
        if (isset($this->schema[ORM::E_PRIMARY_KEY]))
        {
            $primaryKey = $this->schema[ORM::E_PRIMARY_KEY];

            if (isset($this->duplicates[$data[$primaryKey]]))
            {
                //Duplicate is presented, let's reduplicate
                $data = $this->duplicates[$data[$primaryKey]];

                return true;
            }

            $this->duplicates[$data[$primaryKey]] = &$data;
        }
        else
        {
            /**
             * It is recommended to use primary keys in every model as it will speed up deduplication.
             */
            $serialization = serialize($data);
            if (isset($this->duplicates[$serialization]))
            {
                //Duplicate is presented, let's reduplicate
                $data = $this->duplicates[$serialization];

                //Duplicate is presented
                return true;
            }

            $this->duplicates[$serialization] = &$data;
        }

        return false;
    }

    public function parseResult(QueryResult $result, &$rowsCount)
    {
        foreach ($result as $row)
        {
            $this->parseRow($row);
            $rowsCount++;
        }

        return $this->result;
    }

    public function getResult()
    {
        return $this->result;
    }

    abstract public function parseRow(array $row);

    protected function registerReferences(array &$data)
    {
        foreach ($this->referenceKeys as $key)
        {
            //Adding reference
            $this->references[$key . '::' . $data[$key]] = &$data;
            $this->aggregatedReferences[$key][$data[$key]][] = &$data;
        }
    }

    public function getAggregatedKeys($key)
    {
        //TODO: DISABLE WHEN NOT REQUIRED

        if (!isset($this->aggregatedReferences[$key]))
        {
            return array();
        }

        return array_keys($this->aggregatedReferences[$key]);
    }

    public function registerNestedParent($container, $key, $value, &$data)
    {
        foreach ($this->aggregatedReferences[$key][$value] as &$subset)
        {
            if (!isset($subset[$container]))
            {
                $subset[$container] = &$data;
            }

            unset($subset);
        }
    }

    public function registerNested($referenceName, $container, array &$data, $multiple = false)
    {
        if (!isset($this->references[$referenceName]))
        {
            //Nothing to do
            return;
        }

        if ($multiple)
        {
            $this->references[$referenceName][$container][] = &$data;
        }
        else
        {
            if (!isset($this->references[$referenceName][$container]))
            {
                /**
                 * There is very tricky spot where you have to be careful (i spend 2 hours for debugging).
                 * If you will reassign references it will loose previous references and some sets of
                 * data will be broken.
                 */
                $this->references[$referenceName][$container] = &$data;
            }
        }
    }

    protected function parseNested(array $row)
    {
        foreach ($this->loaders as $loader)
        {
            if ($loader->options['method'] == Selector::INLOAD)
            {
                $loader->parseRow($row);
            }
        }
    }

    public function clean()
    {
        $this->duplicates = array();
        $this->references = array();
        $this->result = array();
        foreach ($this->loaders as $loader)
        {
            $loader->clean();
        }
    }
}