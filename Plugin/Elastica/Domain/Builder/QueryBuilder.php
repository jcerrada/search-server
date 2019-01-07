<?php

/*
 * This file is part of the Apisearch Server
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Feel free to edit as you please, and have fun.
 *
 * @author Marc Morera <yuhu@mmoreram.com>
 */

declare(strict_types=1);

namespace Apisearch\Plugin\Elastica\Domain\Builder;

use Apisearch\Geo\CoordinateAndDistance;
use Apisearch\Geo\LocationRange;
use Apisearch\Geo\Polygon;
use Apisearch\Geo\Square;
use Apisearch\Model\Coordinate;
use Apisearch\Model\Item;
use Apisearch\Query\Aggregation as QueryAggregation;
use Apisearch\Query\Filter;
use Apisearch\Query\Query;
use Apisearch\Query\Range;
use Apisearch\Query\ScoreStrategies;
use Apisearch\Query\ScoreStrategy;
use Apisearch\Query\SortBy;
use Elastica\Aggregation as ElasticaAggregation;
use Elastica\Query as ElasticaQuery;
use Elastica\Script\Script;

/**
 * File header placeholder.
 */
class QueryBuilder
{
    /**
     * Creates an elastic query given a model query.
     *
     * @param Query                   $query
     * @param ElasticaQuery           $mainQuery
     * @param ElasticaQuery\BoolQuery $boolQuery
     */
    public function buildQuery(
        Query $query,
        ElasticaQuery &$mainQuery,
        ElasticaQuery\BoolQuery $boolQuery
    ) {
        $this->selectFields(
            $query,
            $mainQuery
        );

        $this->addFilters(
            $query,
            $boolQuery,
            $query->getFilters(),
            $query->getFilterFields(),
            null,
            false
        );

        $this->addFilters(
            $query,
            $boolQuery,
            $query->getUniverseFilters(),
            $query->getFilterFields(),
            null,
            false
        );

        $mainQuery->setQuery($boolQuery);
        $minScore = $query->getMinScore();
        if ($minScore > 0) {
            $mainQuery->setMinScore($minScore);
        }

        $mainQuery = $this->setSortBy(
            $query,
            $mainQuery,
            $boolQuery
        );

        if ($query->areAggregationsEnabled()) {
            $this->addAggregations(
                $query,
                $mainQuery,
                $query->getAggregations(),
                $query->getUniverseFilters(),
                $query->getFilters(),
                $query->getFilterFields()
            );
        }
    }

    /**
     * Select fields.
     *
     * @param Query         $query
     * @param ElasticaQuery $mainQuery
     */
    private function selectFields(
        Query $query,
        ElasticaQuery $mainQuery
    ) {
        if (empty($query->getFields())) {
            return;
        }

        $fields = array_values($query->getFields());
        $fields[] = 'uuid.*';
        $fields = array_unique($fields);
        $mainQuery->setSource($fields);
    }

    /**
     * Add filters to a Query.
     *
     * @param Query                   $query
     * @param ElasticaQuery\BoolQuery $boolQuery
     * @param Filter[]                $filters
     * @param string[]                $filterFields
     * @param null|string             $filterToIgnore
     * @param bool                    $takeInAccountDefinedTermFilter
     */
    private function addFilters(
        Query $query,
        ElasticaQuery\BoolQuery $boolQuery,
        array $filters,
        array $filterFields,
        ? string $filterToIgnore,
        bool $takeInAccountDefinedTermFilter
    ) {
        foreach ($filters as $filterName => $filter) {
            $onlyAddDefinedTermFilter = (
                empty($filter->getValues()) ||
                $filterName === $filterToIgnore
            );

            $this->addFilter(
                $query,
                $boolQuery,
                $filter,
                $filterFields,
                $onlyAddDefinedTermFilter,
                $takeInAccountDefinedTermFilter
            );
        }
    }

    /**
     * Add filters to a Query.
     *
     * @param Query                   $query
     * @param ElasticaQuery\BoolQuery $boolQuery
     * @param Filter                  $filter
     * @param string[]                $filterFields
     * @param bool                    $onlyAddDefinedTermFilter
     * @param bool                    $takeInAccountDefinedTermFilter
     */
    private function addFilter(
        Query $query,
        ElasticaQuery\BoolQuery $boolQuery,
        Filter $filter,
        array $filterFields,
        bool $onlyAddDefinedTermFilter,
        bool $takeInAccountDefinedTermFilter
    ) {
        if (Filter::TYPE_QUERY === $filter->getFilterType()) {
            $queryString = $filter->getValues()[0];
            $match = $this->createMainQueryObject(
                $query,
                $queryString,
                $filterFields
            );
            $boolQuery->addMust($match);

            return;
        }

        if (Filter::TYPE_GEO === $filter->getFilterType()) {
            $boolQuery->addMust(
                $this->createLocationFilter($filter)
            );

            return;
        }

        $boolQuery->addFilter(
            $this->createQueryFilterByApplicationType(
                $filter,
                $onlyAddDefinedTermFilter,
                $takeInAccountDefinedTermFilter
            )
        );
    }

    /**
     * Create a filter and decide type of match.
     *
     * @param Filter $filter
     * @param bool   $onlyAddDefinedTermFilter
     * @param bool   $takeInAccountDefinedTermFilter
     * @param bool   $checkNested
     *
     * @return ElasticaQuery\AbstractQuery
     */
    private function createQueryFilterByApplicationType(
        Filter $filter,
        bool $onlyAddDefinedTermFilter,
        bool $takeInAccountDefinedTermFilter,
        bool $checkNested = true
    ) {
        $verb = 'addMust';
        switch ($filter->getApplicationType()) {
            case Filter::AT_LEAST_ONE:
                $verb = 'addShould';
                break;
            case Filter::EXCLUDE:
                $verb = 'addMustNot';
                break;
        }

        return $this->createQueryFilterByMethod(
            $filter,
            $verb,
            $onlyAddDefinedTermFilter,
            $takeInAccountDefinedTermFilter,
            $checkNested
        );
    }

    /**
     * Creates query filter by method.
     *
     * @param Filter $filter
     * @param string $method
     * @param bool   $onlyAddDefinedTermFilter
     * @param bool   $takeInAccountDefinedTermFilter
     * @param bool   $checkNested
     *
     * @return ElasticaQuery\AbstractQuery
     */
    private function createQueryFilterByMethod(
        Filter $filter,
        string $method,
        bool $onlyAddDefinedTermFilter,
        bool $takeInAccountDefinedTermFilter,
        bool $checkNested = true
    ) {
        $boolQueryFilter = new ElasticaQuery\BoolQuery();
        if (!$onlyAddDefinedTermFilter) {
            foreach ($filter->getValues() as $value) {
                $queryFilter = $this->createQueryFilter(
                    $filter,
                    $value,
                    $checkNested
                );

                if ($queryFilter instanceof ElasticaQuery\AbstractQuery) {
                    $boolQueryFilter->$method($queryFilter);
                }
            }
        }

        /*
         * This is specifically for Tags.
         * Because you can make subgroups of Tags, each aggregation must define
         * its values from this given subgroup.
         */
        if ($takeInAccountDefinedTermFilter && !empty($filter->getFilterTerms())) {
            list($field, $value) = $filter->getFilterTerms();
            $filteringFilter = Filter::create(
                $field, $value, Filter::AT_LEAST_ONE, $filter->getFilterType(), []
            );

            $boolQueryFilter->addFilter(
                $this
                    ->createQueryFilterByApplicationType(
                        $filteringFilter,
                        false,
                        false,
                        $checkNested
                    )
            );
        }

        return $boolQueryFilter;
    }

    /**
     * Creates Term/Terms query depending on the elements value.
     *
     * @param Filter $filter
     * @param mixed  $value
     * @param bool   $checkNested
     *
     * @return null|ElasticaQuery\AbstractQuery
     */
    private function createQueryFilter(
        Filter $filter,
        $value,
        bool $checkNested = true
    ): ? ElasticaQuery\AbstractQuery {
        switch ($filter->getFilterType()) {
            case Filter::TYPE_FIELD:
                return $this->createTermFilter(
                    $filter,
                    $value,
                    $checkNested
                );
                break;

            case Filter::TYPE_RANGE:
            case Filter::TYPE_DATE_RANGE:
                return $this->createRangeFilter(
                    $filter,
                    $value
                );
                break;
        }
    }

    /**
     * Create and return Term filter
     * Returns null if no need to be applicable (true=true).
     *
     * @param Filter $filter
     * @param mixed  $value
     * @param bool   $checkNested
     *
     * @return ElasticaQuery\AbstractQuery
     */
    private function createTermFilter(
        Filter $filter,
        $value,
        bool $checkNested = true
    ): ? ElasticaQuery\AbstractQuery {
        return $this->createMultipleTermFilter(
            $filter->getField(),
            $value,
            $checkNested
        );
    }

    /**
     * Create multiple Term filter.
     *
     * @param string          $field
     * @param string|string[] $value
     * @param bool            $checkNested
     *
     * @return ElasticaQuery\AbstractQuery
     */
    private function createMultipleTermFilter(
        string $field,
        $value,
        bool $checkNested
    ): ElasticaQuery\AbstractQuery {
        if (!is_array($value)) {
            return $this->createTermFilterOrNestedFilterDependingOnTheField(
                $field,
                $value,
                $checkNested
            );
        }

        $multipleBoolQuery = new ElasticaQuery\BoolQuery();
        foreach ($value as $singleValue) {
            $multipleBoolQuery->addShould(
                $this->createTermFilterOrNestedFilterDependingOnTheField(
                    $field,
                    $singleValue,
                    $checkNested
                )
            );
        }

        return $multipleBoolQuery;
    }

    /**
     * Create term filter or nested depending on the field.
     *
     * @param string          $field
     * @param string|string[] $value
     * @param bool            $checkNested
     *
     * @return ElasticaQuery\AbstractQuery
     */
    private function createTermFilterOrNestedFilterDependingOnTheField(
        string $field,
        $value,
        bool $checkNested
    ): ElasticaQuery\AbstractQuery {
        $termFilter = new ElasticaQuery\Term([$field => $value]);
        $fieldParts = explode('.', $field, 3);
        if ($checkNested && 3 === count($fieldParts)) {
            $nested = new ElasticaQuery\Nested();
            $nested->setPath($fieldParts[0].'.'.$fieldParts[1]);
            $nested->setQuery($termFilter);

            return $nested;
        }

        return $termFilter;
    }

    /**
     * Create Range filter.
     *
     * @param Filter $filter
     * @param string $value
     *
     * @return null|ElasticaQuery\AbstractQuery
     */
    private function createRangeFilter(
        Filter $filter,
        string $value
    ): ? ElasticaQuery\AbstractQuery {
        list($from, $to) = Range::stringToArray($value);
        $rangeData = [];
        if ($from > Range::ZERO) {
            $rangeData = [
                'gte' => $from,
            ];
        }

        if (Range::INFINITE !== $to) {
            $rangeData['lt'] = $to;
        }

        $rangeClass = Filter::TYPE_DATE_RANGE === $filter->getFilterType()
            ? ElasticaQuery\Range::class
            : ElasticaQuery\Range::class;

        return empty($rangeData)
            ? null
            : new $rangeClass($filter->getField(), $rangeData);
    }

    /**
     * Create Location filter.
     *
     * @param Filter $filter
     *
     * @return ElasticaQuery\AbstractQuery
     */
    private function createLocationFilter(Filter $filter): ElasticaQuery\AbstractQuery
    {
        $locationRange = LocationRange::createFromArray($filter->getValues());
        $locationRangeData = $locationRange->toFilterArray();
        switch (get_class($locationRange)) {
            case CoordinateAndDistance::class:

                return new ElasticaQuery\GeoDistance(
                    $filter->getField(),
                    $locationRangeData['coordinate'],
                    $locationRangeData['distance']
                );

            case Polygon::class:

                return new ElasticaQuery\GeoPolygon(
                    $filter->getField(),
                    $locationRangeData['coordinates']
                );

            case Square::class:

                return new ElasticaQuery\GeoBoundingBox(
                    $filter->getField(),
                    array_values($locationRangeData)
                );
        }
    }

    /**
     * Add aggregations.
     *
     * @param Query              $query
     * @param ElasticaQuery      $elasticaQuery
     * @param QueryAggregation[] $aggregations
     * @param Filter[]           $universeFilters
     * @param Filter[]           $filters
     * @param string[]           $filterFields
     */
    private function addAggregations(
        Query $query,
        ElasticaQuery $elasticaQuery,
        array $aggregations,
        array $universeFilters,
        array $filters,
        array $filterFields
    ) {
        $globalAggregation = new ElasticaAggregation\GlobalAggregation('all');
        $universeAggregation = new ElasticaAggregation\Filter('universe');
        $aggregationBoolQuery = new ElasticaQuery\BoolQuery();
        $this->addFilters(
            $query,
            $aggregationBoolQuery,
            $universeFilters,
            $filterFields,
            null,
            true
        );
        $universeAggregation->setFilter($aggregationBoolQuery);
        $globalAggregation->addAggregation($universeAggregation);

        foreach ($aggregations as $aggregation) {
            $filterType = $aggregation->getFilterType();
            switch ($filterType) {
                case Filter::TYPE_RANGE:
                case Filter::TYPE_DATE_RANGE:
                    $elasticaAggregation = $this->createRangeAggregation($aggregation);
                    break;
                default:
                    $elasticaAggregation = $this->createAggregation($aggregation);
                    break;
            }

            $filteredAggregation = new ElasticaAggregation\Filter($aggregation->getName());
            $boolQuery = new ElasticaQuery\BoolQuery();
            $this->addFilters(
                $query,
                $boolQuery,
                $filters,
                $filterFields,
                $aggregation->getApplicationType() & Filter::AT_LEAST_ONE
                    ? $aggregation->getName()
                    : null,
                true
            );

            $filteredAggregation->setFilter($boolQuery);
            $filteredAggregation->addAggregation($elasticaAggregation);
            $universeAggregation->addAggregation($filteredAggregation);
        }

        $elasticaQuery->addAggregation($globalAggregation);
    }

    /**
     * Create aggregation.
     *
     * @param QueryAggregation $aggregation
     *
     * @return ElasticaAggregation\AbstractAggregation
     */
    private function createAggregation(QueryAggregation $aggregation): ElasticaAggregation\AbstractAggregation
    {
        $termsAggregation = new ElasticaAggregation\Terms($aggregation->getName());
        $aggregationFields = explode('|', $aggregation->getField());
        $termsAggregation->setField($aggregationFields[0]);
        $termsAggregation->setSize(
            $aggregation->getLimit() > 0
                ? $aggregation->getLimit()
                : 1000
        );
        $termsAggregation->setOrder($aggregation->getSort()[0], $aggregation->getSort()[1]);

        return $termsAggregation;
    }

    /**
     * Create range aggregation.
     *
     * @param QueryAggregation $aggregation
     *
     * @return ElasticaAggregation\AbstractAggregation
     */
    private function createRangeAggregation(QueryAggregation $aggregation): ElasticaAggregation\AbstractAggregation
    {
        $rangeClass = Filter::TYPE_DATE_RANGE === $aggregation->getFilterType()
            ? ElasticaAggregation\DateRange::class
            : ElasticaAggregation\Range::class;

        $rangeAggregation = new $rangeClass($aggregation->getName());
        $rangeAggregation->setKeyedResponse();
        $rangeAggregation->setField($aggregation->getField());
        foreach ($aggregation->getSubgroup() as $range) {
            list($from, $to) = Range::stringToArray($range);
            $rangeAggregation->addRange($from, $to, $range);
        }

        return $rangeAggregation;
    }

    /**
     * Create main query object.
     *
     * @param Query  $query
     * @param string $queryString
     * @param array  $filterFields
     *
     * @return ElasticaQuery\AbstractQuery
     */
    private function createMainQueryObject(
        Query $query,
        string $queryString,
        array $filterFields
    ): ElasticaQuery\AbstractQuery {
        if (empty($queryString)) {
            $match = new ElasticaQuery\MatchAll();
        } else {
            $fuzziness = $query->getFuzziness();
            $filterFields = empty($filterFields)
                ? [
                    'searchable_metadata.*',
                    'exact_matching_metadata^5',
                ]
                : $filterFields;

            $match = is_array($fuzziness)
                ? $this->createMainQueryObjectAsFuzzy(
                    $queryString,
                    $filterFields,
                    $fuzziness
                )
                : $this->createMainQueryObjectAsMatchAll(
                    $queryString,
                    $filterFields,
                    $fuzziness
                );
        }

        $match = $this->setScoreStrategies(
            $query,
            $match
        );

        return $match;
    }

    /**
     * Create main query object as a multimatch.
     *
     * @param string            $queryString
     * @param array             $filterFields
     * @param null|float|string $fuzziness
     *
     * @return ElasticaQuery\AbstractQuery
     */
    private function createMainQueryObjectAsMatchAll(
        string $queryString,
        array $filterFields,
        $fuzziness
    ): ElasticaQuery\AbstractQuery {
        $match = new ElasticaQuery\MultiMatch();
        $match
            ->setFields($filterFields)
            ->setQuery($queryString);

        if (!is_null($fuzziness)) {
            $match->setFuzziness($fuzziness);
        }

        return $match;
    }

    /**
     * Create main query object as a multimatch.
     *
     * @param string $queryString
     * @param array  $filterFields
     * @param array  $fuzziness
     *
     * @return ElasticaQuery\AbstractQuery
     */
    private function createMainQueryObjectAsFuzzy(
        string $queryString,
        array $filterFields,
        array $fuzziness
    ): ElasticaQuery\AbstractQuery {
        $boolQuery = new ElasticaQuery\BoolQuery();
        foreach ($filterFields as $filterField) {
            $filterFieldParts = explode('^', $filterField, 2);
            $filterFieldWithoutWeight = $filterFieldParts[0];
            $specificFuzziness = $fuzziness[$filterFieldWithoutWeight] ?? false;

            $match = $specificFuzziness
                ? new ElasticaQuery\Match($filterFieldWithoutWeight)
                : new ElasticaQuery\MatchPhrase($filterFieldWithoutWeight);

            $match->setFieldQuery($filterFieldWithoutWeight, $queryString);

            if (isset($filterFieldParts[1])) {
                $match->setFieldBoost(
                    $filterFieldWithoutWeight,
                    (float) ($filterFieldParts[1])
                );
            }

            if ($specificFuzziness) {
                $match->setFieldFuzziness(
                    $filterFieldWithoutWeight,
                    $specificFuzziness
                );
            }

            $boolQuery->addShould($match);
        }

        return $boolQuery;
    }

    /**
     * Set score strategies.
     *
     * @param Query                       $query
     * @param ElasticaQuery\AbstractQuery $elasticaQuery
     *
     * @return ElasticaQuery\AbstractQuery
     */
    private function setScoreStrategies(
        Query $query,
        ElasticaQuery\AbstractQuery $elasticaQuery
    ): ElasticaQuery\AbstractQuery {
        $scoreStrategies = $query->getScoreStrategies();
        if (
            !($scoreStrategies instanceof ScoreStrategies) ||
            empty($scoreStrategies->getScoreStrategies())
        ) {
            return $elasticaQuery;
        }

        $newQuery = new ElasticaQuery\FunctionScore();
        $boolQuery = new ElasticaQuery\BoolQuery();
        $boolQuery->addMust($elasticaQuery);
        $newQuery->setQuery($boolQuery);
        $newQuery->setScoreMode($scoreStrategies->getScoreMode());
        $newQuery->setBoostMode($scoreStrategies->getScoreMode());

        /*
         * @var ScoreStrategy
         */
        foreach ($scoreStrategies->getScoreStrategies() as $scoreStrategy) {
            $filter = $scoreStrategy->getFilter() instanceof Filter
                ? $this->createQueryFilterByApplicationType(
                        $scoreStrategy->getFilter(),
                        false,
                        false,
                        false
                    )
                : null;

            $field = Item::getPathByField(
                (string) $scoreStrategy->getConfigurationValue('field')
            );
            $fieldParts = explode('.', $field);
            $nestedFunctionQuery = null;

            if (count($fieldParts) >= 3) {
                array_pop($fieldParts);
                $nestedQuery = new ElasticaQuery\Nested();
                $boolQuery->addShould($nestedQuery);
                $nestedQuery->setPath(implode('.', $fieldParts));
                $nestedQuery->setScoreMode($scoreStrategy->getScoreMode());
                $nestedFunctionQuery = new ElasticaQuery\FunctionScore();
                $nestedQuery->setQuery($nestedFunctionQuery);
            }

            switch ($scoreStrategy->getType()) {
                case ScoreStrategy::BOOSTING_FIELD_VALUE:
                    $this->addBoostingFieldValueScoreStrategy(
                        $scoreStrategy,
                        $nestedFunctionQuery instanceof ElasticaQuery\FunctionScore
                            ? $nestedFunctionQuery
                            : $newQuery,
                        $filter
                    );
                    break;
                case ScoreStrategy::DECAY:
                    $this->addDecayFunctionScoreStrategy(
                        $scoreStrategy,
                        $nestedFunctionQuery instanceof ElasticaQuery\FunctionScore
                            ? $nestedFunctionQuery
                            : $newQuery,
                        $filter
                    );
                    break;
                case ScoreStrategy::CUSTOM_FUNCTION:
                    $this->addCustomFunctionScoreStrategy(
                        $scoreStrategy,
                        $newQuery,
                        $filter
                    );
                    break;
            }
        }

        return $newQuery;
    }

    /**
     * Create score strategy by using a custom function.
     *
     * @param ScoreStrategy                    $scoreStrategy
     * @param ElasticaQuery\FunctionScore      $functionScore
     * @param ElasticaQuery\AbstractQuery|null $filter
     */
    private function addCustomFunctionScoreStrategy(
        ScoreStrategy $scoreStrategy,
        ElasticaQuery\FunctionScore $functionScore,
        ?ElasticaQuery\AbstractQuery $filter
    ) {
        $functionScore->addScriptScoreFunction(
            new Script($scoreStrategy->getConfigurationValue('function')),
            $filter,
            $scoreStrategy->getWeight()
        );
    }

    /**
     * Create score strategy by field value.
     *
     * @param ScoreStrategy                    $scoreStrategy
     * @param ElasticaQuery\FunctionScore      $functionScore
     * @param ElasticaQuery\AbstractQuery|null $filter
     */
    private function addBoostingFieldValueScoreStrategy(
        ScoreStrategy $scoreStrategy,
        ElasticaQuery\FunctionScore $functionScore,
        ?ElasticaQuery\AbstractQuery $filter
    ) {
        $functionScore->addFieldValueFactorFunction(
            Item::getPathByField(
                (string) $scoreStrategy->getConfigurationValue('field')
            ),
            (float) $scoreStrategy->getConfigurationValue('factor'),
            (string) $scoreStrategy->getConfigurationValue('modifier'),
            (float) $scoreStrategy->getConfigurationValue('missing'),
            $scoreStrategy->getWeight(),
            $filter
        );
    }

    /**
     * Create score strategy by using a decay function.
     *
     * @param ScoreStrategy                    $scoreStrategy
     * @param ElasticaQuery\FunctionScore      $functionScore
     * @param ElasticaQuery\AbstractQuery|null $filter
     */
    private function addDecayFunctionScoreStrategy(
        ScoreStrategy $scoreStrategy,
        ElasticaQuery\FunctionScore $functionScore,
        ?ElasticaQuery\AbstractQuery $filter
    ) {
        $functionScore->addDecayFunction(
            (string) $scoreStrategy->getConfigurationValue('type'),
            Item::getPathByField(
                (string) $scoreStrategy->getConfigurationValue('field')
            ),
            (string) $scoreStrategy->getConfigurationValue('origin'),
            (string) $scoreStrategy->getConfigurationValue('scale'),
            (string) $scoreStrategy->getConfigurationValue('offset'),
            (float) $scoreStrategy->getConfigurationValue('decay'),
            $scoreStrategy->getWeight(),
            $filter
        );
    }

    /**
     * Build sort.
     *
     * @param Query                   $query
     * @param ElasticaQuery           $mainQuery
     * @param ElasticaQuery\BoolQuery $boolQuery
     *
     * @return ElasticaQuery
     */
    private function setSortBy(
        Query $query,
        ElasticaQuery $mainQuery,
        ElasticaQuery\BoolQuery $boolQuery
    ): ElasticaQuery {
        $sortBy = $query->getSortBy();
        if ($sortBy->hasRandomSort()) {
            /**
             * Random elements in Elasticsearch need a wrapper in order to
             * apply a random score per each result.
             */
            $functionScore = new ElasticaQuery\FunctionScore();
            $functionScore->addRandomScoreFunction(uniqid());
            $functionScore->setQuery($boolQuery);
            $newMainQuery = new ElasticaQuery();
            $newMainQuery->setQuery($functionScore);
            $mainQuery = $newMainQuery;

            return $mainQuery;
        }

        $sortByElements = $sortBy->all();

        /*
         * Because elasticsearch, by default, sorts by score, if score is the
         * only applied sortBy (or by default, because no sortBy elements were
         * added) we will skip this step
         */
        if (
            1 === count($sortByElements) &&
            SortBy::SCORE === $sortByElements[0]
        ) {
            return $mainQuery;
        }

        $sortByElements = array_map(function (array $sortBy) {
            $type = $sortBy['type'] ?? SortBy::TYPE_FIELD;
            $mode = $sortBy['mode'] ?? SortBy::MODE_AVG;
            unset($sortBy['type']);
            unset($sortBy['mode']);

            if (SortBy::TYPE_NESTED === $type) {
                $filter = null;
                if (
                    isset($sortBy['filter']) &&
                    ($sortBy['filter'] instanceof Filter)
                ) {
                    $filter = $sortBy['filter'];
                    unset($sortBy['filter']);
                }

                $key = array_keys($sortBy)[0];
                $path = explode('.', $key);
                array_pop($path);
                $sortBy[$key]['mode'] = $mode;
                $sortBy[$key]['nested'] = [
                    'path' => implode('.', $path),
                ];

                if (!is_null($filter)) {
                    $sortBy[$key]['nested']['filter'] = $this->createQueryFilterByApplicationType(
                        $filter,
                        false,
                        false,
                        false
                    );
                }
            }

            if (
                isset($sortBy['_geo_distance']) &&
                isset($sortBy['_geo_distance']['coordinate']) &&
                ($sortBy['_geo_distance']['coordinate'] instanceof Coordinate)
            ) {
                $sortBy['_geo_distance']['coordinate'] = $sortBy['_geo_distance']['coordinate']->toArray();
            }

            return $sortBy;
        }, $sortByElements);
        $mainQuery->setSort($sortByElements);

        return $mainQuery;
    }
}
