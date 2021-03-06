<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\DoctrineORMAdminBundle\Filter;

use Sonata\AdminBundle\Datagrid\ProxyQueryInterface;
use Sonata\AdminBundle\Form\Type\Filter\DateRangeType;
use Sonata\AdminBundle\Form\Type\Filter\DateTimeRangeType;
use Sonata\AdminBundle\Form\Type\Filter\DateTimeType;
use Sonata\AdminBundle\Form\Type\Filter\DateType;
use Sonata\AdminBundle\Form\Type\Operator\DateOperatorType;
use Sonata\AdminBundle\Form\Type\Operator\DateRangeOperatorType;

abstract class AbstractDateFilter extends Filter
{
    public const CHOICES = [
        DateOperatorType::TYPE_EQUAL => '=',
        DateOperatorType::TYPE_GREATER_EQUAL => '>=',
        DateOperatorType::TYPE_GREATER_THAN => '>',
        DateOperatorType::TYPE_LESS_EQUAL => '<=',
        DateOperatorType::TYPE_LESS_THAN => '<',
        DateOperatorType::TYPE_NULL => 'NULL',
        DateOperatorType::TYPE_NOT_NULL => 'NOT NULL',
    ];

    /**
     * Flag indicating that filter will have range.
     *
     * @var bool
     */
    protected $range = false;

    /**
     * Flag indicating that filter will filter by datetime instead by date.
     *
     * @var bool
     */
    protected $time = false;

    public function filter(ProxyQueryInterface $queryBuilder, $alias, $field, $value)
    {
        // check data sanity
        if (!$value || !\is_array($value) || !\array_key_exists('value', $value)) {
            return;
        }

        if ($this->range) {
            // additional data check for ranged items
            if (!\array_key_exists('start', $value['value']) || !\array_key_exists('end', $value['value'])) {
                return;
            }

            if (!$value['value']['start'] && !$value['value']['end']) {
                return;
            }

            // date filter should filter records for the whole days
            if (false === $this->time && ($value['value']['end'] instanceof \DateTime || $value['value']['end'] instanceof \DateTimeImmutable)) {
                // since the received `\DateTime` object  uses the model timezone to represent
                // the value submitted by the view (which can use a different timezone) and this
                // value is intended to contain a time in the begining of a date (IE, if the model
                // object is configured to use UTC timezone, the view object "2020-11-07 00:00:00.0-03:00"
                // is transformed to "2020-11-07 03:00:00.0+00:00" in the model object), we increment
                // the time part by adding "23:59:59" in order to cover the whole end date and get proper
                // results from queries like "o.created_at <= :date_end".
                $value['value']['end'] = $value['value']['end']->modify('+23 hours 59 minutes 59 seconds');
            }

            // transform types
            if ('timestamp' === $this->getOption('input_type')) {
                $value['value']['start'] = $value['value']['start'] instanceof \DateTimeInterface ? $value['value']['start']->getTimestamp() : 0;
                $value['value']['end'] = $value['value']['end'] instanceof \DateTimeInterface ? $value['value']['end']->getTimestamp() : 0;
            }

            // default type for range filter
            $value['type'] = !isset($value['type']) || !is_numeric($value['type']) ? DateRangeOperatorType::TYPE_BETWEEN : $value['type'];

            $startDateParameterName = $this->getNewParameterName($queryBuilder);
            $endDateParameterName = $this->getNewParameterName($queryBuilder);

            if (DateRangeOperatorType::TYPE_NOT_BETWEEN === $value['type']) {
                $this->applyWhere($queryBuilder, sprintf('%s.%s < :%s OR %s.%s > :%s', $alias, $field, $startDateParameterName, $alias, $field, $endDateParameterName));
            } else {
                if ($value['value']['start']) {
                    $this->applyWhere($queryBuilder, sprintf('%s.%s %s :%s', $alias, $field, '>=', $startDateParameterName));
                }

                if ($value['value']['end']) {
                    $this->applyWhere($queryBuilder, sprintf('%s.%s %s :%s', $alias, $field, '<=', $endDateParameterName));
                }
            }

            if ($value['value']['start']) {
                $queryBuilder->setParameter($startDateParameterName, $value['value']['start']);
            }

            if ($value['value']['end']) {
                $queryBuilder->setParameter($endDateParameterName, $value['value']['end']);
            }
        } else {
            if (!$value['value']) {
                return;
            }

            // default type for simple filter
            $value['type'] = !isset($value['type']) || !is_numeric($value['type']) ? DateOperatorType::TYPE_EQUAL : $value['type'];

            // just find an operator and apply query
            $operator = $this->getOperator($value['type']);

            // transform types
            if ('timestamp' === $this->getOption('input_type')) {
                $value['value'] = $value['value'] instanceof \DateTimeInterface ? $value['value']->getTimestamp() : 0;
            }

            // null / not null only check for col
            if (\in_array($operator, ['NULL', 'NOT NULL'], true)) {
                $this->applyWhere($queryBuilder, sprintf('%s.%s IS %s ', $alias, $field, $operator));

                return;
            }

            $parameterName = $this->getNewParameterName($queryBuilder);

            // date filter should filter records for the whole day
            if (false === $this->time && DateOperatorType::TYPE_EQUAL === $value['type']) {
                $this->applyWhere($queryBuilder, sprintf('%s.%s %s :%s', $alias, $field, '>=', $parameterName));
                $queryBuilder->setParameter($parameterName, $value['value']);

                $endDateParameterName = $this->getNewParameterName($queryBuilder);
                $this->applyWhere($queryBuilder, sprintf('%s.%s %s :%s', $alias, $field, '<', $endDateParameterName));
                if ('timestamp' === $this->getOption('input_type')) {
                    $endValue = strtotime('+1 day', $value['value']);
                } else {
                    $endValue = clone $value['value'];
                    $endValue->add(new \DateInterval('P1D'));
                }
                $queryBuilder->setParameter($endDateParameterName, $endValue);

                return;
            }

            $this->applyWhere($queryBuilder, sprintf('%s.%s %s :%s', $alias, $field, $operator, $parameterName));
            $queryBuilder->setParameter($parameterName, $value['value']);
        }
    }

    public function getDefaultOptions()
    {
        return [
            'input_type' => 'datetime',
        ];
    }

    public function getRenderSettings()
    {
        $name = DateType::class;

        if ($this->time && $this->range) {
            $name = DateTimeRangeType::class;
        } elseif ($this->time) {
            $name = DateTimeType::class;
        } elseif ($this->range) {
            $name = DateRangeType::class;
        }

        return [$name, [
            'field_type' => $this->getFieldType(),
            'field_options' => $this->getFieldOptions(),
            'label' => $this->getLabel(),
        ]];
    }

    /**
     * NEXT_MAJOR: Change the visibility for private.
     *
     * Resolves DateOperatorType:: constants to SQL operators.
     *
     * @param int $type
     *
     * @return string
     */
    protected function getOperator($type)
    {
        $type = (int) $type;

        return self::CHOICES[$type] ?? self::CHOICES[DateOperatorType::TYPE_EQUAL];
    }
}
