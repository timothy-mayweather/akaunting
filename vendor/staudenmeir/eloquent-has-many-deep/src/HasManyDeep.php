<?php

namespace Staudenmeir\EloquentHasManyDeep;

use Closure;
use Exception;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Pagination\CursorPaginator;
use Staudenmeir\EloquentHasManyDeep\Eloquent\Relations\Traits\HasEagerLimit;
use Staudenmeir\EloquentHasManyDeep\Eloquent\Relations\Traits\IsConcatenable;
use Staudenmeir\EloquentHasManyDeep\Eloquent\Relations\Traits\JoinsThroughParents;
use Staudenmeir\EloquentHasManyDeep\Eloquent\Relations\Traits\RetrievesIntermediateTables;
use Staudenmeir\EloquentHasManyDeep\Eloquent\Relations\Traits\SupportsCompositeKeys;
use Staudenmeir\EloquentHasManyDeep\Eloquent\Relations\Traits\IsCustomizable;
use Staudenmeir\EloquentHasManyDeepContracts\Interfaces\ConcatenableRelation;

/**
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @extends \Illuminate\Database\Eloquent\Relations\Relation<TRelatedModel>
 */
class HasManyDeep extends HasManyThrough implements ConcatenableRelation
{
    use HasEagerLimit;
    use IsConcatenable;
    use IsCustomizable;
    use JoinsThroughParents;
    use RetrievesIntermediateTables;
    use SupportsCompositeKeys;

    /**
     * The "through" parent model instances.
     *
     * @var \Illuminate\Database\Eloquent\Model[]
     */
    protected $throughParents;

    /**
     * The foreign keys on the relationship.
     *
     * @var array
     */
    protected $foreignKeys;

    /**
     * The local keys on the relationship.
     *
     * @var array
     */
    protected $localKeys;

    /**
     * Create a new has many deep relationship instance.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \Illuminate\Database\Eloquent\Model $farParent
     * @param \Illuminate\Database\Eloquent\Model[] $throughParents
     * @param array $foreignKeys
     * @param array $localKeys
     * @return void
     */
    public function __construct(Builder $query, Model $farParent, array $throughParents, array $foreignKeys, array $localKeys)
    {
        $this->throughParents = $throughParents;
        $this->foreignKeys = $foreignKeys;
        $this->localKeys = $localKeys;

        $firstKey = is_array($foreignKeys[0])
            ? $foreignKeys[0][1]
            : ($this->hasLeadingCompositeKey() ? $foreignKeys[0]->columns[0] : $foreignKeys[0]);

        $localKey = $this->hasLeadingCompositeKey() ? $localKeys[0]->columns[0] : $localKeys[0];

        parent::__construct($query, $farParent, $throughParents[0], $firstKey, $foreignKeys[1], $localKey, $localKeys[1]);
    }

    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    public function getResults()
    {
        if ($this->firstKey instanceof Closure || $this->localKey instanceof Closure) {
            return $this->get();
        }

        return parent::getResults();
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints()
    {
        if ($this->firstKey instanceof Closure || $this->localKey instanceof Closure) {
            $this->performJoin();
        } else {
            parent::addConstraints();
        }

        if (static::$constraints) {
            if ($this->firstKey instanceof Closure) {
                ($this->firstKey)($this->query);
            } elseif ($this->localKey instanceof Closure) {
                ($this->localKey)($this->query);
            } elseif (is_array($this->foreignKeys[0])) {
                $this->query->where(
                    $this->throughParent->qualifyColumn($this->foreignKeys[0][0]),
                    '=',
                    $this->farParent->getMorphClass()
                );
            } elseif ($this->hasLeadingCompositeKey()) {
                $this->addConstraintsWithCompositeKey();
            }
        }
    }

    /**
     * Set the join clauses on the query.
     *
     * @param \Illuminate\Database\Eloquent\Builder|null $query
     * @return void
     */
    protected function performJoin(Builder $query = null)
    {
        $query = $query ?: $this->query;

        $throughParents = array_reverse($this->throughParents);
        $foreignKeys = array_reverse($this->foreignKeys);
        $localKeys = array_reverse($this->localKeys);

        $segments = explode(' as ', $query->getQuery()->from);

        $alias = $segments[1] ?? null;

        foreach ($throughParents as $i => $throughParent) {
            $predecessor = $throughParents[$i - 1] ?? $this->related;

            $prefix = $i === 0 && $alias ? $alias.'.' : '';

            $this->joinThroughParent($query, $throughParent, $predecessor, $foreignKeys[$i], $localKeys[$i], $prefix);
        }
    }

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param array $models
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        if ($this->customEagerConstraintsCallback) {
            ($this->customEagerConstraintsCallback)($this->query, $models);
            return;
        }

        if ($this->hasLeadingCompositeKey()) {
            $this->addEagerConstraintsWithCompositeKey($models);
        } else {
            parent::addEagerConstraints($models);

            if (is_array($this->foreignKeys[0])) {
                $this->query->where(
                    $this->throughParent->qualifyColumn($this->foreignKeys[0][0]),
                    '=',
                    $this->farParent->getMorphClass()
                );
            }
        }
    }

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param array $models
     * @param \Illuminate\Database\Eloquent\Collection $results
     * @param string $relation
     * @return array
     */
    public function match(array $models, Collection $results, $relation)
    {
        if ($this->customEagerMatchingCallbacks) {
            foreach ($this->customEagerMatchingCallbacks as $callback) {
                $models = $callback($models, $results, $relation);
            }

            return $models;
        }

        if ($this->hasLeadingCompositeKey()) {
            return $this->matchWithCompositeKey($models, $results, $relation);
        }

        return parent::match($models, $results, $relation);
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param array $columns
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function get($columns = ['*'])
    {
        $models = parent::get($columns);

        $this->hydrateIntermediateRelations($models->all());

        foreach ($this->postGetCallbacks as $postGetCallback) {
            $postGetCallback($models);
        }

        return $models;
    }

    /**
     * Get a paginator for the "select" statement.
     *
     * @param int $perPage
     * @param array $columns
     * @param string $pageName
     * @param int $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $columns = array_filter(
            $this->shouldSelect($columns),
            fn ($column) => !str_contains($column, 'laravel_through_key')
        );

        $this->query->addSelect($columns);

        return tap($this->query->paginate($perPage, $columns, $pageName, $page), function (Paginator $paginator) {
            $this->hydrateIntermediateRelations($paginator->items());
        });
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * @param int $perPage
     * @param array $columns
     * @param string $pageName
     * @param int|null $page
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $columns = array_filter(
            $this->shouldSelect($columns),
            fn ($column) => !str_contains($column, 'laravel_through_key')
        );

        $this->query->addSelect($columns);

        return tap($this->query->simplePaginate($perPage, $columns, $pageName, $page), function (Paginator $paginator) {
            $this->hydrateIntermediateRelations($paginator->items());
        });
    }

    /**
     * Paginate the given query into a cursor paginator.
     *
     * @param  int|null  $perPage
     * @param  array  $columns
     * @param  string  $cursorName
     * @param  string|null  $cursor
     * @return \Illuminate\Contracts\Pagination\CursorPaginator
     */
    public function cursorPaginate($perPage = null, $columns = ['*'], $cursorName = 'cursor', $cursor = null)
    {
        $columns = array_filter(
            $this->shouldSelect($columns),
            fn ($column) => !str_contains($column, 'laravel_through_key')
        );

        $this->query->addSelect($columns);

        return tap($this->query->cursorPaginate($perPage, $columns, $cursorName, $cursor), function (CursorPaginator $paginator) {
            $this->hydrateIntermediateRelations($paginator->items());
        });
    }

    /**
     * Set the select clause for the relation query.
     *
     * @param array $columns
     * @return array
     */
    protected function shouldSelect(array $columns = ['*'])
    {
        if ($columns == ['*']) {
            $columns = [$this->related->getTable().'.*'];
        }

        $alias = 'laravel_through_key';

        if ($this->customThroughKeyCallback) {
            $columns[] = ($this->customThroughKeyCallback)($alias);
        } else {
            $columns[] = $this->getQualifiedFirstKeyName() . " as $alias";
        }

        if ($this->hasLeadingCompositeKey()) {
            $columns = array_merge(
                $columns,
                $this->shouldSelectWithCompositeKey()
            );
        }

        return array_merge($columns, $this->intermediateColumns());
    }

    /**
     * Chunk the results of the query.
     *
     * @param int $count
     * @param callable $callback
     * @return bool
     */
    public function chunk($count, callable $callback)
    {
        return $this->prepareQueryBuilder()->chunk($count, function (Collection $results) use ($callback) {
            $this->hydrateIntermediateRelations($results->all());

            return $callback($results);
        });
    }

    /**
     * Add the constraints for a relationship query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \Illuminate\Database\Eloquent\Builder $parentQuery
     * @param array|mixed $columns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        foreach ($this->throughParents as $throughParent) {
            if ($throughParent->getTable() === $parentQuery->getQuery()->from) {
                if (!in_array(HasTableAlias::class, class_uses_recursive($throughParent))) {
                    $traitClass = HasTableAlias::class;
                    $parentClass = get_class($throughParent);

                    throw new Exception(
                        <<<EOT
This query requires an additional trait. Please add the $traitClass trait to $parentClass.
See https://github.com/staudenmeir/eloquent-has-many-deep/issues/137 for details.
EOT
                    );
                }

                $table = $throughParent->getTable() . ' as ' . $this->getRelationCountHash();

                $throughParent->setTable($table);

                break;
            }
        }

        if ($this->firstKey instanceof Closure || $this->localKey instanceof Closure) {
            $this->performJoin($query);

            $closureKey = $this->firstKey instanceof Closure ? $this->firstKey : $this->localKey;

            $closureKey($query, $parentQuery);

            return $query->select($columns);
        }

        $query = parent::getRelationExistenceQuery($query, $parentQuery, $columns);

        if (is_array($this->foreignKeys[0])) {
            $column = $this->throughParent->qualifyColumn($this->foreignKeys[0][0]);

            $query->where($column, '=', $this->farParent->getMorphClass());
        } elseif ($this->hasLeadingCompositeKey()) {
            $this->getRelationExistenceQueryWithCompositeKey($query);
        }

        return $query;
    }

    /**
     * Add the constraints for a relationship query on the same table.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \Illuminate\Database\Eloquent\Builder $parentQuery
     * @param array|mixed $columns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationExistenceQueryForSelfRelation(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        $hash = $this->getRelationCountHash();

        $query->from($query->getModel()->getTable().' as '.$hash);

        $this->performJoin($query);

        $query->getModel()->setTable($hash);

        return $query->select($columns)->whereColumn(
            $parentQuery->getQuery()->from.'.'.$this->localKey,
            '=',
            $this->getQualifiedFirstKeyName()
        );
    }

    /**
     * Restore soft-deleted models.
     *
     * @param array|string ...$columns
     * @return $this
     */
    public function withTrashed(...$columns)
    {
        if (empty($columns)) {
            $this->query->withTrashed();

            return $this;
        }

        if (is_array($columns[0])) {
            $columns = $columns[0];
        }

        foreach ($columns as $column) {
            $this->query->withoutGlobalScope(__CLASS__ . ":$column");
        }

        return $this;
    }

    /**
     * Get the far parent model instance.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getFarParent(): Model
    {
        return $this->farParent;
    }

    /**
     * Get the "through" parent model instances.
     *
     * @return \Illuminate\Database\Eloquent\Model[]
     */
    public function getThroughParents()
    {
        return $this->throughParents;
    }

    /**
     * Get the foreign keys on the relationship.
     *
     * @return array
     */
    public function getForeignKeys()
    {
        return $this->foreignKeys;
    }

    /**
     * Get the local keys on the relationship.
     *
     * @return array
     */
    public function getLocalKeys()
    {
        return $this->localKeys;
    }
}
