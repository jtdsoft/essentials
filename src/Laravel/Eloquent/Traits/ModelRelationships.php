<?php

namespace DeeToo\Essentials\Laravel\Eloquent\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use DeeToo\Essentials\Exceptions\Error;
use DeeToo\Essentials\Laravel\Eloquent\Types\RelationType;
use Illuminate\Support\Arr;
use ReflectionClass;
use ReflectionMethod;

/**
 * Class ModelRelationships
 *
 * @package DeeToo\Essentials\Laravel\Eloquent\Traits
 */
trait ModelRelationships
{
    /**
     * @var array
     */
    protected static $relationships = [];

    protected static function bootModelRelationships()
    {
        static::loadRelationships();
    }

    /**
     *
     */
    protected static function loadRelationships()
    {
        static::$relationships[static::class] = [];

        $model = new static;

        $public_methods = (new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($public_methods as $method) {
            if (!$method->getReturnType()) {
                continue;
            }

            $relationClass = $method->getReturnType()->getName();

            if (!is_subclass_of($relationClass, Relation::class)) {
                continue;
            }

            static::$relationships[static::class][$method->getName()] = $relationClass;
        }

        foreach ($model->getTypes() as $parameter => $type) {
            if (!($type instanceof RelationType)) {
                continue;
            }

            $relation = Str::camel(Str::replaceLast('_id', '', $parameter));

            static::$relationships[static::class][$relation] = BelongsTo::class;
        }
    }

    /**
     * @return array
     */
    public static function relationships()
    {
        if (isset(static::$relationships[static::class])) {
            return static::$relationships[static::class];
        }

        self::loadRelationships();

        return static::$relationships[static::class];
    }

    /**
     * @return array
     */
    public function getAvailableRelations()
    {
        return array_keys(static::relationships());
    }

    /**
     * @param $relation
     *
     * @return bool
     */
    public function isRelation($relation)
    {
        return isset(static::relationships()[$relation]);
    }

    /**
     * @param string $method
     * @param array $parameters
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo|mixed
     */
    public function __call($method, $parameters)
    {
        //CHECK IF THIS IS A RELATION TYPE -> FK AUTOMATIC RELATION GENERATION
        if (!method_exists($this, $method) && $this->isRelation($method)) {
            $field = Str::snake($method) . '_id';
            $type  = $this->getType($field);

            if ($type instanceof RelationType) {
                return $this->belongsTo($type->relation, $field, 'id', $method);
            }
        }

        return parent::__call($method, $parameters);
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    public function getRelationValue($key)
    {
        //CHECK IF THIS IS A RELATION TYPE -> FK AUTOMATIC RELATION GENERATION
        $field = Str::snake($key) . '_id';
        $type  = $this->getType($field);

        if ($type instanceof RelationType) {
            $relation = Str::camel($key);

            if (!method_exists($this, $relation)) {
                if ($this->relationLoaded($relation)) {
                    return $this->relations[$relation];
                }

                $relationObject = $this->belongsTo($type->relation, $field, 'id', $relation);

                return tap(
                    $relationObject->getResults(),
                    function ($results) use ($relation) {
                        $this->setRelation($relation, $results);
                    }
                );
            }
        }

        return parent::getRelationValue($key);
    }

    /**
     * Eager load relations on the model, clearing existing loaded relations
     *
     * @param  array|string $relations
     *
     * @return $this
     */
    public function relations($relations = [])
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        $this->relations = $relations;

        $this->load($relations);

        return $this;
    }

    /**
     * Eager load relations on the model.
     *
     * @param  array|string $relations
     *
     * @return $this
     */
    public function load($relations)
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        if (!$relations) {
            return $this;
        }

        $relations = Arr::flatten($relations);

        foreach ($relations as $relation) {
            $this->validateRelation($relation);
        }

        return parent::load($relations);
    }

    /**
     * @param $relation
     *
     * @throws Error
     */
    private function validateRelation($relation)
    {
        $childs = explode('.', $relation);

        $model = clone $this;

        foreach ($childs as $child) {
            if (!$model->isRelation($child)) {
                throw new Error('Invalid relation :relation', ['relation' => $child]);
            }

            $model = $model->{$child}()->getRelated();
        }
    }

    /**
     * Begin querying a model with eager loading.
     *
     * @param               $query
     * @param array|string $relations
     *
     * @return Builder|static
     * @throws Error
     */
    public function scopeWithRelations($query, $relations)
    {
        if (!$relations) {
            return $query;
        }

        if (!is_array($relations)) {
            throw new Error(
                'Invalid relations ":relations"! Array expected!',
                ['relations' => var_export($relations, true)]
            );
        }

        $relations = Arr::flatten($relations);

        foreach ($relations as $relation) {
            $query->getModel()->validateRelation($relation);
        }

        return $query->with($relations);
    }
}
