<?php

namespace Nalogka\QueryFilter\Tests;

use Codeception\Stub;
use Codeception\Test\Unit;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;
use Nalogka\QueryFilter\QueryFilter;
use Nalogka\QueryFilter\QueryFilterDeniedParamException;
use Nalogka\QueryFilter\QueryFilterParsingException;
use Nalogka\QueryFilter\QueryStringParser;
use UnitTester;

class QueryFilterTest extends Unit
{
    /**
     * @var UnitTester
     */
    protected $tester;

    /**
     * @throws \Exception
     */
    public function testApply()
    {
        $qb = $this->createQueryBuilder(['param' => Type::INTEGER]);
        $qb->select('u')->from('User', 'u');
        $allowedParams = ['param', 'param2', 'param3', 'param4'];
        $filter = new QueryFilter($qb, $allowedParams);
        $filter->apply('param<100;param>14;param2=some*;param2=*string*;param3=done;param4=one;param4=two;param4=three');

        $this->assertEquals(
            'u.param < "100" AND u.param > "14"'
            .' AND (u.param2 LIKE "some%" OR u.param2 LIKE "%string%")'
            .' AND u.param3 = "done"'
            .' AND u.param4 IN ("one","two","three")',
            $this->populateCondition((string)$qb->getDQLPart('where'), $qb->getParameters())
        );
    }

    /**
     * @throws QueryFilterDeniedParamException
     * @throws QueryFilterParsingException
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Nalogka\QueryFilter\QueryFilterException
     * @throws \Exception
     */
    public function testApplyFiltered()
    {
        $this->expectException(QueryFilterDeniedParamException::class);

        $qb = $this->createQueryBuilder();
        $qb->select('u')->from('User', 'u');
        $filter = new QueryFilter($qb, ['param2']);
        $filter->apply('param<100;param2=some*;param3=done');
    }

    /**
     * @throws QueryFilterDeniedParamException
     * @throws QueryFilterParsingException
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Nalogka\QueryFilter\QueryFilterException
     * @throws \Exception
     */
    public function testApplyEmpty()
    {
        $qb = $this->createQueryBuilder();
        $qb->select('u')->from('User', 'u');
        $filter = new QueryFilter($qb, ['param']);
        $filter->apply('');

        $this->assertNull($qb->getDQLPart('where'));
    }

    /**
     * @noinspection PhpDocMissingThrowsInspection
     *
     * @dataProvider provideTestParsing
     *
     * @param array $expected
     * @param string $filterString
     * @throws QueryFilterParsingException
     */
    public function testParsing(string $filterString, array $expected)
    {
        $this->assertEquals($expected, QueryStringParser::parse($filterString));
    }

    /**
     * @param $erroneousFilterString
     *
     * @dataProvider provideParsingError
     *
     * @throws QueryFilterParsingException
     */
    public function testParsingError($erroneousFilterString)
    {
        $this->expectException(QueryFilterParsingException::class);
        QueryStringParser::parse($erroneousFilterString);
    }

    public function provideTestParsing()
    {
        return [
            [
                'param=value',
                [
                    [['param', '=', 'value']],
                ],
            ],
            [
                'param.1=value1; param.2 >value2;',
                [
                    [['param.1', '=', 'value1']],
                    [['param.2', '>', 'value2']],
                ],
            ],
            [
                'param.1<value1; param.1>value2;',
                [
                    [['param.1', '<', 'value1']],
                    [['param.1', '>', 'value2']],
                ],
            ],
            [
                ' param = ; ',
                [
                    [['param', '=', ' ' /* пробел */]],
                ],
            ],
            [
                'param\<1=\;;param\=2=\=',
                [
                    [['param<1', '=', ';']],
                    [['param=2', '=', '=']],
                ],
            ],
            [
                'param=',
                [
                    [['param', '=', '']],
                ],
            ],
            [
                'param=1;param=2',
                [
                    [['param', '=', '1'], ['param', '=', '2']],
                ],
            ],
            [
                'param!=1;param!=2',
                [
                    [['param', '!=', '1']],
                    [['param', '!=', '2']],
                ],
            ],
            [
                'param<=10;param>=2',
                [
                    [['param', '<=', '10']],
                    [['param', '>=', '2']],
                ],
            ],
        ];
    }

    /**
     * @param string                      $cond
     * @param ArrayCollection|Parameter[] $params
     *
     * @return string
     */
    private function populateCondition(string $cond, ArrayCollection $params)
    {
        $replaces = [];
        foreach ($params as $p) {
            if (is_array($value = $p->getValue())) {
                $value = '"' . implode('","', $value) . '"';
            } else {
                $value = '"' . $value . '"';
            }

            $replaces[':' . $p->getName()] = $value;
        }

        return strtr($cond, $replaces);
    }

    public function provideParsingError()
    {
        return [
            ['name'],
            ['=test'],
            [' =test'],
        ];
    }

    /**
     * @param array $fieldTypes
     *
     * @return QueryBuilder
     * @throws \Exception
     */
    private function createQueryBuilder(array $fieldTypes = []): QueryBuilder
    {
        return new QueryBuilder($this->createEntityManagerStub($fieldTypes));
    }

    /**
     * @param array $fieldTypes
     *
     * @return object|EntityManagerInterface
     * @throws \Exception
     */
    private function createEntityManagerStub(array $fieldTypes = []): EntityManagerInterface
    {
        $cm = Stub::makeEmpty(ClassMetadata::class, [
            'getTypeOfField' => function ($fieldName) use ($fieldTypes) {
                return Type::getType($fieldTypes[$fieldName] ?? Type::STRING);
            }
        ]);

        return Stub::makeEmpty(EntityManagerInterface::class, [
            'getClassMetadata' => $cm
        ]);
    }
}
