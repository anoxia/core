<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\ORM\Selector\Loaders;

use Spiral\Components\DBAL\Database;
use Spiral\Components\ORM\ActiveRecord;
use Spiral\Components\ORM\ORM;
use Spiral\Components\ORM\Relation;
use Spiral\Components\ORM\Selector;
use Spiral\Components\ORM\Selector\Loader;

class HasOneLoader extends Loader
{
    /**
     * Relation type is required to correctly resolve foreign model.
     */
    const RELATION_TYPE = ActiveRecord::HAS_ONE;

    /**
     * Default load method (inload or postload).
     */
    const LOAD_METHOD = Selector::INLOAD;

    const MULTIPLE = false;

    protected function clarifyQuery(Selector $selector)
    {
        //Relation definition
        $definition = $this->relationDefinition;

        $outerKey = $this->options['tableAlias'] . '.' . $definition[ActiveRecord::OUTER_KEY];

        //Inner key has to be build based on parent table
        $innerKey = $this->parent->getTableAlias() . '.' . $definition[ActiveRecord::INNER_KEY];

        $selector->leftJoin(
            $definition[Relation::OUTER_TABLE] . ' AS ' . $this->options['tableAlias'],
            array($outerKey => $innerKey)
        );

        if (!empty($this->relationDefinition[ActiveRecord::MORPH_KEY]))
        {
            $morphKey = $this->options['tableAlias'] . '.' . $definition[ActiveRecord::MORPH_KEY];
            $selector->onWhere(array(
                $morphKey => $this->parent->schema[ORM::E_ROLE_NAME]
            ));
        }
    }

    public function parseRow(array $row)
    {
        $data = $this->fetchData($row);
        if (!$referenceName = $this->getReferenceName($data))
        {
            //Relation not loaded
            return;
        }

        //WHAT IF?
        if (!$this->checkDuplicate($data))
        {
            //Clarifying parent dataset
            $this->registerReferences($data);
            $this->parent->registerNested($referenceName, $this->container, $data, static::MULTIPLE);
        }

        $this->parseNested($row);
    }

    public function createSelector(Database $database)
    {
        $selector = parent::createSelector($database);

        //Relation definition
        $definition = $this->relationDefinition;

        //Adding condition
        $selector->where(
            $this->getTableAlias() . '.' . $definition[ActiveRecord::OUTER_KEY],
            'IN',
            array_unique($this->parent->getAggregatedKeys($this->getReferenceKey()))
        );

        if (!empty($this->relationDefinition[ActiveRecord::MORPH_KEY]))
        {
            $morphKey = $this->options['tableAlias'] . '.' . $definition[ActiveRecord::MORPH_KEY];
            $selector->where(array(
                $morphKey => $this->parent->schema[ORM::E_ROLE_NAME]
            ));
        }

        return $selector;
    }
}