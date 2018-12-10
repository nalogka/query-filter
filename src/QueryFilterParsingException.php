<?php

namespace Nalogka\QueryFilter;

use Throwable;

class QueryFilterParsingException extends QueryFilterException
{
    /**
     * @var string
     */
    private $condition;

    public function __construct(string $message, string $condition, Throwable $previous = null)
    {
        $this->condition = $condition;
        parent::__construct('Условие "' . $condition . '" построено некорректно. ' . $message, $previous);
    }

    /**
     * @return string
     */
    public function getCondition(): string
    {
        return $this->condition;
    }
}
