<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\ORM;

use Spiral\Components\DBAL\DatabaseManager;
use Spiral\Components\DBAL\Schemas\AbstractTableSchema;
use Spiral\Components\ORM\Schemas\EntitySchema;
use Spiral\Components\ORM\Schemas\RelationSchema;
use Spiral\Components\Tokenizer\Tokenizer;
use Spiral\Core\Component;
use Spiral\Core\Container;

class SchemaBuilder extends Component
{
    /**
     * ORM class names.
     */
    const DATA_ENTITY = 'Spiral\Components\DataEntity';
    const ENTITY      = 'Spiral\Components\ORM\Entity';

    /**
     * Mapping used to link relationship definition to relationship schemas.
     *
     * @var array
     */
    protected $relationships = array(
        Entity::BELONGS_TO         => 'Spiral\Components\ORM\Schemas\Relations\BelongsToSchema',
        Entity::BELONGS_TO_MORPHED => 'Spiral\Components\ORM\Schemas\Relations\BelongsToMorphedSchema',

        Entity::HAS_ONE            => 'Spiral\Components\ORM\Schemas\Relations\HasOneSchema',
        Entity::HAS_MANY           => 'Spiral\Components\ORM\Schemas\Relations\HasManySchema',

        Entity::MANY_TO_MANY       => 'Spiral\Components\ORM\Schemas\Relations\ManyToManySchema',
        Entity::MANY_TO_MORPHED    => 'Spiral\Components\ORM\Schemas\Relations\ManyToMorphedSchema',

        Entity::MANY_THOUGHT       => 'Spiral\Components\ORM\Schemas\Relations\ManyThoughtSchema',
    );

    /**
     * Schema generating configuration.
     *
     * @var array
     */
    protected $config = array();

    /**
     * DatabaseManager instance.
     *
     * @var DatabaseManager
     */
    protected $dbal = null;

    /**
     * Found entity schemas.
     *
     * @var EntitySchema[]
     */
    protected $entities = array();

    /**
     * All declared tables.
     *
     * @var array
     */
    public $tables = array();

    /**
     * New ORM Schema reader instance.
     *
     * @param array           $config
     * @param Tokenizer       $tokenizer
     * @param DatabaseManager $dbal
     */
    public function __construct(array $config, Tokenizer $tokenizer, DatabaseManager $dbal)
    {
        $this->config = $config;
        $this->dbal = $dbal;

        foreach ($tokenizer->getClasses(self::ENTITY) as $class => $definition)
        {
            if ($class == self::ENTITY)
            {
                continue;
            }

            $this->entities[$class] = EntitySchema::make(array(
                'class'     => $class,
                'ormSchema' => $this
            ));
        }

        $relations = array();
        foreach ($this->entities as $entity)
        {
            if (!$entity->isAbstract())
            {
                $entity->castRelations();

                foreach ($entity->getRelations() as $relation)
                {
                    if ($relation->hasBackReference())
                    {
                        $relations[] = $relation;
                    }
                }
            }
        }

        /**
         * @var RelationSchema $relation
         */
        foreach ($relations as $relation)
        {
            $backReference = $relation->getDefinition()[Entity::BACK_REF];

            if (is_array($backReference))
            {
                //[TYPE, NAME]
                $relation->revertRelation($backReference[1], $backReference[0]);
            }
            else
            {
                $relation->revertRelation($backReference);
            }
        }
    }

    /**
     * All fetched entity schemas.
     *
     * @return EntitySchema[]
     */
    public function getEntities()
    {
        return $this->entities;
    }

    /**
     * Get EntitySchema by class name.
     *
     * @param string $class Class name.
     * @return null|EntitySchema
     */
    public function getEntity($class)
    {
        if ($class == self::ENTITY)
        {
            return EntitySchema::make(array(
                'class'     => self::ENTITY,
                'ormSchema' => $this
            ));
        }

        if (!isset($this->entities[$class]))
        {
            return null;
        }

        return $this->entities[$class];
    }

    /**
     * Declare table schema to be created.
     *
     * @param string $database
     * @param string $table
     * @return AbstractTableSchema
     */
    public function declareTable($database, $table)
    {
        if (isset($this->tables[$database . '/' . $table]))
        {
            return $this->tables[$database . '/' . $table];
        }

        $table = $this->dbal->db($database)->table($table)->schema();

        return $this->tables[$database . '/' . $table->getName()] = $table;
    }

    /**
     * Get list of all declared tables. Cascade parameter will sort tables in order of their self
     * dependencies.
     *
     * @param bool $cascade
     * @return AbstractTableSchema[]
     */
    public function getDeclaredTables($cascade = true)
    {
        if ($cascade)
        {
            $tables = $this->tables;
            uasort($tables, function (AbstractTableSchema $tableA, AbstractTableSchema $tableB)
            {
                return in_array($tableA->getName(), $tableB->getDependencies())
                || count($tableB->getDependencies()) > count($tableA->getDependencies());
            });

            return array_reverse($tables);
        }

        return $this->tables;
    }

    /**
     * Perform schema reflection to database(s). All declared tables will created or altered.
     */
    public function executeSchema()
    {
        foreach ($this->tables as $table)
        {
            //TODO: IS ABSTRACT
            foreach ($this->entities as $entity)
            {
                if ($entity->getTableSchema() == $table && !$entity->isActiveSchema())
                {
                    //TODO: BABDBAD!
                }
            }
        }

        foreach ($this->getDeclaredTables(true) as $table)
        {
            $table->save();
        }
    }

    /**
     * Get appropriate relation schema based on provided definition.
     *
     * @param EntitySchema $entitySchema
     * @param string       $name
     * @param array        $definition
     * @return RelationSchema
     */
    public function relationSchema(EntitySchema $entitySchema, $name, array $definition)
    {
        if (empty($definition))
        {
            throw new ORMException("Relation definition can not be empty.");
        }

        reset($definition);
        $type = key($definition);

        if (!isset($this->relationships[$type]))
        {
            throw new ORMException("Undefined relationship type {$type}.");
        }

        /**
         * @var RelationSchema $relationship
         */
        $relationship = Container::get($this->relationships[$type], array(
            'ormSchema'    => $this,
            'entitySchema' => $entitySchema,
            'name'         => $name,
            'definition'   => $definition
        ));

        if ($relationship->hasEquivalent())
        {
            return $this->relationSchema($entitySchema, $name, $relationship->getEquivalentDefinition());
        }

        return $relationship;
    }

    /**
     * Get mutators for column with specified abstract or column type.
     *
     * @param string $abstractType Column type.
     * @return array
     */
    public function getMutators($abstractType)
    {
        return isset($this->config['mutators'][$abstractType])
            ? $this->config['mutators'][$abstractType]
            : array();
    }

    /**
     * Normalize ODM schema and export it to be used by ODM component and all documents.
     *
     * @return array
     */
    public function normalizeSchema()
    {
        $schema = array();

        foreach ($this->entities as $entity)
        {
            if ($entity->isAbstract())
            {
                continue;
            }

            $entitySchema = array();

            $entitySchema[ORM::E_TABLE] = $entity->getTable();
            $entitySchema[ORM::E_DB] = $entity->getDatabase();
            $entitySchema[ORM::E_PRIMARY_KEY] = $entity->getPrimaryKey();

            $entitySchema[ORM::E_COLUMNS] = $entity->getColumns();
            $entitySchema[ORM::E_HIDDEN] = $entity->getHidden();
            $entitySchema[ORM::E_SECURED] = $entity->getSecured();
            $entitySchema[ORM::E_FILLABLE] = $entity->getFillable();

            $entitySchema[ORM::E_MUTATORS] = $entity->getMutators();
            $entitySchema[ORM::E_VALIDATES] = $entity->getValidates();
            $entitySchema[ORM::E_MESSAGES] = $entity->getMessages();

            //Relations
            foreach ($entity->getRelations() as $name => $relation)
            {
                $entitySchema[ORM::E_RELATIONS][$name] = $relation->normalizeSchema();
            }

            ksort($entitySchema);
            $schema[$entity->getClass()] = $entitySchema;
        }

        return $schema;
    }
}