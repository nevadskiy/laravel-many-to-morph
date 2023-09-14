<?php

namespace Nevadskiy\MorphAny;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin MorphAny
 */
trait GetResults
{
	/**
	 * Get the results of the relationship.
	 */
	public function getResults(): Collection
	{
		if ($this->parent->getAttribute($this->parentKey) === null) {
			return $this->newCollection();
		}

		$pivotResults = $this->query->get();

		// @todo no need to build this dictionary...
		$this->buildDictionary($pivotResults);

		$morphsDictionary = $this->getMorphResults();

		$models = [];

		foreach ($pivotResults as $pivotResult) {
			$morphTypeKey = $this->getDictionaryKey($pivotResult->{$this->pivotMorphTypeKey});
			$foreignKeyKey = $this->getDictionaryKey($pivotResult->{$this->pivotMorphForeignKey});
			$model = $morphsDictionary[$morphTypeKey][$foreignKeyKey]; // @todo handle missing model...

			$this->hydratePivotModel($model, $pivotResult);

			$models[] = $model;
		}

		return $this->newCollection($models);
	}

	/**
	 * @see MorphTo::getEager
	 */
	protected function getMorphResults(): array
	{
		$morphs = [];

		foreach (array_keys($this->dictionary) as $type) {
			$morphs[$type] = $this->getResultsByType($type)->getDictionary();
		}

		return $morphs;
	}

	/**
	 * @see MorphTo::buildDictionary
	 */
	protected function buildDictionary(Collection $pivotResults): void
	{
		foreach ($pivotResults as $pivotResult) {
			if ($pivotResult->{$this->pivotMorphTypeKey}) {
				$morphTypeKey = $this->getDictionaryKey($pivotResult->{$this->pivotMorphTypeKey});
				$foreignKeyKey = $this->getDictionaryKey($pivotResult->{$this->pivotMorphForeignKey});

				$this->dictionary[$morphTypeKey][$foreignKeyKey][] = $pivotResult;
			}
		}
	}

	/**
	 * @see MorphTo::getResultsByType
	 */
	protected function getResultsByType($type)
	{
		/** @var Model $instance */
		$instance = $this->createModelByType($type);

		$ownerKey = $this->ownerKey ?? $instance->getKeyName();

		$query = $instance->newQuery()
			// ->mergeConstraintsFrom($this->getQuery())
			// @todo add hook to modify relation.
			->with(array_merge(
				$this->getQuery()->getEagerLoads(),
				(array)($this->morphableEagerLoads[get_class($instance)] ?? [])
			))
			->withCount(
				(array)($this->morphableEagerLoadCounts[get_class($instance)] ?? [])
			);

		if ($callback = ($this->morphableConstraints[get_class($instance)] ?? null)) {
			$callback($query);
		}

		$whereIn = $this->whereInMethod($instance, $ownerKey);

		return $query->{$whereIn}(
			$instance->getTable() . '.' . $ownerKey, $this->gatherKeysByType($type, $instance->getKeyType())
		)->get();
	}

	/**
	 * @see MorphTo::createModelByType
	 */
	public function createModelByType($type)
	{
		$class = Model::getActualClassNameForMorph($type);

		return tap(new $class, function ($instance) {
			if (!$instance->getConnectionName()) {
				$instance->setConnection($this->getConnection()->getName());
			}
		});
	}

	/**
	 * @see MorphTo::createModelByType
	 */
	protected function gatherKeysByType($type, $keyType): array
	{
		return $keyType !== 'string'
			? array_keys($this->dictionary[$type])
			: array_map(function ($modelId) {
				return (string)$modelId;
			}, array_filter(array_keys($this->dictionary[$type])));
	}

	protected function hydratePivotModel($model, $pivotResult)
	{
		$model->setRelation($this->accessor, $this->parent->newPivot(
			$this->parent, $pivotResult->getAttributes(), $this->pivotTable, true, $this->using
		));
	}

	/**
	 * @todo ability to configure collection class.
	 */
	protected function newCollection(array $models = []): Collection
	{
		return new Collection($models);
	}
}
