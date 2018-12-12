<?php

namespace Nalogka\QueryFilter;

class QueryStringParser
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
}