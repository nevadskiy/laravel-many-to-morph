<?php

namespace Nevadskiy\ManyToMorph;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

/**
 * @mixin ManyToMorph
 */
trait LazyLoading
{
	/**
	 * @see BelongsToMany::addConstraints()
	 */
	public function addConstraints(): void
	{
		if (static::$constraints) {
			$this->addWhereConstraints();
		}
	}

	/**
	 * @see BelongsToMany::addWhereConstraints()
	 */
	protected function addWhereConstraints(): void
	{
		$this->query->where([
			$this->qualifyPivotColumn($this->pivotForeignKeyName) => $this->parent->getAttribute($this->parentKeyName)
		]);
	}

	/**
	 * @see BelongsToMany::getResults()
	 */
	public function getResults(): Collection
	{
		if ($this->parent->getAttribute($this->parentKeyName) === null) {
			return $this->newCollection();
		}

		return $this->get();
	}

	public function get($columns = ['*']): Collection
	{
		$pivotModels = $this->query->get();

		$keysByMorphType = $this->getKeysByMorphType($pivotModels);

		$modelsByMorphType = [];

		foreach ($keysByMorphType as $morphType => $keys) {
			$modelsByMorphType[$morphType] = $this->getModelsByMorphType($morphType, $keys)->getDictionary();
		}

		$models = [];

		foreach ($pivotModels as $pivotModel) {
			$models[] = $this->mapModelForPivot($pivotModel, $modelsByMorphType);
		}

		return $this->newCollection($models);
	}

	protected function getKeysByMorphType(Collection $pivotModels): array
	{
		$keyMap = [];

		foreach ($pivotModels as $pivotModel) {
			$morphType = $this->getDictionaryKey($pivotModel->getAttribute($this->pivotMorphTypeName));
			$morphKey = $this->getDictionaryKey($pivotModel->getAttribute($this->pivotMorphKeyName));

			$keyMap[$morphType][$morphKey] = true;
		}

		$keysByMorphType = [];

		foreach ($keyMap as $morphType => $keys) {
			$keysByMorphType[$morphType] = array_keys($keys);
		}

		return $keysByMorphType;
	}

	/**
	 * @see MorphTo::getResultsByType
	 */
	protected function getModelsByMorphType(string $morphType, array $keys): Collection
	{
		$instance = $this->newModelByMorphType($morphType);

		$query = $instance->newQuery();

		$this->applyMorphableConstraints($query, get_class($instance));

		$keyName = $instance->getKeyName();

		return $query->{$this->whereInMethod($instance, $keyName)}($instance->qualifyColumn($keyName), $keys)->get();
	}

	/**
	 * @see MorphTo::createModelByType
	 */
	public function newModelByMorphType(string $morphType)
	{
		$class = Model::getActualClassNameForMorph($morphType);

		return tap(new $class, function ($instance) {
			if (! $instance->getConnectionName()) {
				$instance->setConnection($this->getConnection()->getName());
			}
		});
	}

	protected function mapModelForPivot(MorphPivot $pivotModel, array $modelsByMorphType): Model
	{
		$morphType = $this->getDictionaryKey($pivotModel->getAttribute($this->pivotMorphTypeName));
		$morphKey = $this->getDictionaryKey($pivotModel->getAttribute($this->pivotMorphKeyName));

		$model = $modelsByMorphType[$morphType][$morphKey];

		$model->setRelation($this->accessor, $pivotModel);

		return $model;
	}
}
