<?php

namespace Nalogka\QueryFilter;

use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;

/**
 * Применение строки фильтра к запросу
 *
 * Запрос определяется QueryBuilder'ом, переданным в конструктор.
 *
 * Пример использования:
 * <code>
 *   $qb = new QueryBuilder($em)->select('p')->from(Person::class, 'p');
 *   QueryFilter::create($qb)->apply('name=Ivan*;age>20');
 *   $persons = $qb->getQuery()->getResult();
 * </code>
 */
class QueryFilter
{
    /**
     * @var QueryBuilder
     */
    protected $queryBuilder;
    /**
     * @var array Параметры, доступные для указания в условиях фильтрации
     */
    private $allowedParams;
    /**
     * @var string Условия фильтрации, которые будут принудительно применены
     */
    private $preset;

    /**
     * Создание экземпляра QueryFilter
     *
     * Пример использования:
     * <code>
     *   $qb = new QueryBuilder($em)->select('p')->from(Person::class, 'p');
     *   QueryFilter::create($qb)->apply('name=Ivan*;age>20');
     * </code>
     *
     * @param QueryBuilder $queryBuilder
     * @param array $allowedParams
     * @param string $preset
     *
     * @return QueryFilter
     */
    public static function create(QueryBuilder $queryBuilder, array $allowedParams, $preset = ''): QueryFilter
    {
        return new static($queryBuilder, $allowedParams, $preset);
    }

    /**
     * Конструктор QueryFilter
     *
     * @param QueryBuilder $queryBuilder
     * @param array $allowedParams
     * @param string $preset
     */
    public function __construct(QueryBuilder $queryBuilder, array $allowedParams, $preset = '')
    {
        $this->queryBuilder = $queryBuilder;
        $this->allowedParams = $allowedParams;
        $this->preset = $preset;
    }

    /**
     * Применяет строку фильтра к запросу
     *
     * @param string $filterString
     *
     * @return QueryFilter
     * @throws QueryFilterParsingException
     * @throws QueryFilterDeniedParamException
     * @throws \Doctrine\DBAL\DBALException
     * @throws QueryFilterException
     */
    public function apply(string $filterString): QueryFilter
    {
        $qb = $this->queryBuilder;
        /** @var Expr\From $fromPart */
        $fromPart = $qb->getDQLPart('from')[0];
        $entityMetadata = $qb->getEntityManager()->getClassMetadata($fromPart->getFrom());
        $alias = $fromPart->getAlias();
        $paramIdx = 0;
        $parsedFilter = QueryStringParser::parse($filterString);
        $parsedPreset = QueryStringParser::parse($this->preset);
        $allowedParams = $this->fixAllowedFilterParams($parsedPreset);
        $this->validateFilter($allowedParams, $parsedFilter);
        $parsedConditions = self::applyPreset($parsedFilter, $parsedPreset);

        foreach ($parsedConditions as $conditions) {
            $expr = new Expr\Orx();
            foreach ($conditions as $condition) {
                [$field, $operator, $value] = $condition;
                $field = static::toCamelCase($field);
                $fieldType = $entityMetadata->getTypeOfField($field);
                if (is_string($fieldType)) {
                    $fieldType = Type::getType($fieldType);
                }
                $paramName = ':' . strtolower(strtr($field, '.', '_')) . '_' . $paramIdx++;
                if ($fieldType instanceof StringType && Expr\Comparison::EQ === $operator) {
                    if (substr($value, 0, 1) === '*') {
                        $value = '%' . substr($value, 1);
                        $operator = 'LIKE';
                    }
                    if (substr($value, -1) === '*') {
                        $value = substr($value, 0, -1) . '%';
                        $operator = 'LIKE';
                    }
                }
                $expr->add(new Expr\Comparison($alias . '.' . $field, $operator, $paramName));
                $qb->setParameter($paramName, $value);
            }
            if ($expr->count() > 0) {
                $qb->andWhere($expr);
            }
        }

        return $this;
    }

    /**
     * @return QueryBuilder
     */
    public function getQueryBuilder(): QueryBuilder
    {
        return $this->queryBuilder;
    }

    private static function toCamelCase($paramName)
    {
        $camelCasedName = preg_replace_callback('/(^|_)+(.)/', function ($match) {
            return strtoupper($match[2]);
        }, $paramName);

        return lcfirst($camelCasedName);
    }

    private static function applyPreset(array $parsedFilter, array $parsedPreset): array
    {
        return array_merge($parsedFilter, $parsedPreset);
    }

    /**
     * Исключает из списка разрешенных параметров те, которые устанавливаются пресетом.
     *
     * @param $parsedPreset
     * @return array
     */
    private function fixAllowedFilterParams(array $parsedPreset): array
    {
        $presetParams = [];
        foreach ($parsedPreset as $conditionSuite) {
            foreach ($conditionSuite as $condition) {
                if (!in_array($condition[0], $presetParams)) {
                    $presetParams[] = $condition[0];
                }
            }
        }

        $totalAllowedParamsList = $this->allowedParams;
        foreach ($totalAllowedParamsList as $key => $param) {
            if (in_array($param, $presetParams)) {
                unset($totalAllowedParamsList[$key]);
            }
        }

        return $totalAllowedParamsList;
    }

    /**
     * Проверяет, все ли параметры, используемые в фильтре, разрешены.
     *
     * @param $allowedParams
     * @param $parsedFilter
     * @throws QueryFilterDeniedParamException
     */
    private function validateFilter(array $allowedParams, array $parsedFilter): void
    {
        $allowedParamsKeys = array_flip($allowedParams);
        $deniedParams = [];
        foreach ($parsedFilter as $conditions) {
            foreach ($conditions as $condition) {
                if (!isset($allowedParamsKeys[$condition[0]])) {
                    $deniedParams[$condition[0]] = $condition[0];
                }
            }
        }

        if ($deniedParams) {
            throw new QueryFilterDeniedParamException($this->allowedParams, array_values($deniedParams));
        }
    }
}
