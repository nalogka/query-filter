<?php

namespace Nalogka\QueryFilter;

class QueryFilterDeniedParamException extends QueryFilterException
{
    /**
     * @var array
     */
    private $allowedParams;
    /**
     * @var array
     */
    private $deniedParams;

    /**
     * QueryFilterDeniedParamException constructor.
     *
     * @param array $allowedParams Допустимые параметры фильтрации
     * @param array $deniedParams  Параметры, не найденные в списке допустимых
     */
    public function __construct(array $allowedParams, array $deniedParams)
    {
        $this->allowedParams = $allowedParams;
        $this->deniedParams = $deniedParams;

        parent::__construct(
            'В фильтре указаны недопустимые параметры: ' . implode(',', $deniedParams)
                . '. Разрешено использование: ' . implode(',', $allowedParams)
        );
    }
}
