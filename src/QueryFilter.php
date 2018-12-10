<?php

namespace Nalogka\QueryFilter;

use Doctrine\DBAL\Types\StringType;
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
    const TOKEN_ESCAPE = '\\';
    const TOKEN_CONDITION_DIVIDER = ';';
    const TOKEN_OPERATION_EQ = '=';
    const TOKEN_OPERATION_NEQ = '!=';
    const TOKEN_OPERATION_GT = '>';
    const TOKEN_OPERATION_GTE = '>=';
    const TOKEN_OPERATION_LT = '<';
    const TOKEN_OPERATION_LTE = '<=';

    const SPECIAL_TOKENS = [
        '_esc_' => self::TOKEN_ESCAPE,
        '_div_' => self::TOKEN_CONDITION_DIVIDER,
    ];
    const OPERATION_TOKENS = [
        '_eq_'  => self::TOKEN_OPERATION_EQ,
        '_neq_' => self::TOKEN_OPERATION_NEQ,
        '_gte_' => self::TOKEN_OPERATION_GTE,
        '_gt_'  => self::TOKEN_OPERATION_GT,
        '_lte_' => self::TOKEN_OPERATION_LTE,
        '_lt_'  => self::TOKEN_OPERATION_LT,
    ];

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
        $parsedFilter = self::parse($filterString);
        $parsedPreset = self::parse($this->preset);
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

    /**
     * Разбирает строку фильтра на отдельные условия.
     *
     * @param string $filterString
     *
     * @return array Каждый элемент массива - это набор условий, соединяемых по "ИЛИ".
     *               А сам массив соединяется по "И".
     * @throws QueryFilterParsingException
     */
    public static function parse(string $filterString): array
    {
        $conditions = [];
        $restoreEscaped = array_merge(self::SPECIAL_TOKENS, self::OPERATION_TOKENS);
        $escape = function ($token) {
            return self::TOKEN_ESCAPE . $token;
        };
        $safeFilterString = strtr($filterString, array_flip(array_map($escape, $restoreEscaped)));
        $operationsRegex = implode('|', array_map(function ($op) {
            return preg_quote($op, '#');
        }, self::OPERATION_TOKENS));
        $splitConditionRegex = '#^(.*?)(' . $operationsRegex . ')(.*)$#';
        foreach (explode(self::TOKEN_CONDITION_DIVIDER, $safeFilterString) as $condition) {
            $condition = ltrim($condition);
            if (!$condition) {
                continue;
            }
            if (!preg_match($splitConditionRegex, $condition, $matches)) {
                throw new QueryFilterParsingException('Не указана операция', $condition);
            }
            if (!$matches[1]) {
                throw new QueryFilterParsingException('Не указан параметр для фильтрации', $condition);
            }

            $param = rtrim(strtr($matches[1], $restoreEscaped));
            $operation = $matches[2];
            $value = strtr($matches[3], $restoreEscaped);

            if ($operation === self::TOKEN_OPERATION_NEQ) {
                $conditions[] = [[$param, $operation, $value]];
            } else {
                $conditions[$param.$operation][] = [$param, $operation, $value];
            }
        }

        return array_values($conditions);
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
