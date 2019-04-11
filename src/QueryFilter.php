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
        $parsedFilter = QueryStringParser::parse($filterString);
        $parsedPreset = QueryStringParser::parse($this->preset);
        $allowedParams = $this->fixAllowedFilterParams($parsedPreset);
        $this->validateFilter($allowedParams, $parsedFilter);
        $parsedConditions = self::applyPreset($parsedFilter, $parsedPreset);
        $paramIdx = 0;

        foreach ($parsedConditions as $conditions) {
            $orExpr = new Expr\Orx();
            $orParameters = []; //массив параметров, значения которых нужно будет забиндить после окончания формирования $orExpr
            $makeInExpression = false; //Флаг, указывающий что вместо совокупности OR операторов следует сделать один IN оператор
            if (count($conditions) > 1 && $conditions[0][1] == QueryStringParser::TOKEN_OPERATION_EQ) {
                $makeInExpression = true;
                $prevField = static::toCamelCase($conditions[0][0]);
                $values = [];
            }

            foreach ($conditions as $condition) {
                [$field, $operator, $value] = $condition;
                $field = static::toCamelCase($field);

                //Если поиск идет по одному и тому же полю с опертором =, то в конце выражение OR будет заменено на IN
                if ($makeInExpression) {
                    if ($field == $prevField && $operator == QueryStringParser::TOKEN_OPERATION_EQ) {
                        $values[] = $value;
                    } else {
                        $makeInExpression = false;
                    }
                }

                $fieldType = $entityMetadata->getTypeOfField($field);
                if (is_string($fieldType)) {
                    $fieldType = Type::getType($fieldType);
                }

                //если искомое значение - строка, которая начинается или заказнчивается на *, то вместо оператора = используем опреатор LIKE
                if ($fieldType instanceof StringType && Expr\Comparison::EQ === $operator) {
                    if (substr($value, 0, 1) === '*') {
                        $value = '%' . substr($value, 1);
                        $operator = 'LIKE';
                        $makeInExpression = false;
                    }

                    if (substr($value, -1) === '*') {
                        $value = substr($value, 0, -1) . '%';
                        $operator = 'LIKE';
                        $makeInExpression = false;
                    }
                }

                //Если поиск производится по полю - дискриминатору типа сущности, то вместо = нужно использовать оператор INSTANCE OF
                if (isset($entityMetadata->discriminatorColumn['name']) && $entityMetadata->discriminatorColumn['name'] === $field) {
                    if (empty($entityMetadata->discriminatorMap[$value])) {
                        throw new QueryFilterException(sprintf('Указан несуществующий тип (%s) для параметра %s', $value, $field));
                    }

                    $makeInExpression = false;
                    $orExpr->add(new Expr\Comparison($alias, 'INSTANCE OF', $entityMetadata->discriminatorMap[$value]));
                } else {
                    $paramName = ':' . strtolower(strtr($field, '.', '_')) . '_' . $paramIdx++;
                    $orExpr->add(new Expr\Comparison($alias . '.' . $field, $operator, $paramName));
                    $orParameters[] = [$paramName, $value];
                }

                if (!$makeInExpression && isset($values)) {
                    unset($values);
                }
            }

            if ($makeInExpression) {
                $paramName = ':' . strtolower(strtr($field, '.', '_')) . '_' . $paramIdx++;
                $inExpr = new Expr\Func($alias . '.' . $field . ' IN ', $paramName);
                $qb->setParameter($paramName, $values);
                $qb->andWhere($inExpr);
            } elseif ($orExpr->count() > 0) {
                $qb->andWhere($orExpr);
                foreach ($orParameters as $params) {
                    $qb->setParameter($params[0], $params[1]);
                }
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
