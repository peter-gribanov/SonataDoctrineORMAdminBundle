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

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\ClassMetadata;
use Sonata\AdminBundle\Datagrid\ProxyQueryInterface;
use Sonata\AdminBundle\Form\Type\Filter\DefaultType;
use Sonata\AdminBundle\Form\Type\Operator\EqualOperatorType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

/**
 * @final since sonata-project/doctrine-orm-admin-bundle 3.24
 */
class ModelFilter extends Filter
{
    public function filter(ProxyQueryInterface $queryBuilder, $alias, $field, $value)
    {
        if (!$value || !\is_array($value) || !\array_key_exists('value', $value) || empty($value['value'])) {
            return;
        }

        if ($value['value'] instanceof Collection) {
            $value['value'] = $value['value']->toArray();
        }

        if (!\is_array($value['value'])) {
            $value['value'] = [$value['value']];
        }

        $this->handleMultiple($queryBuilder, $alias, $value);
    }

    public function getDefaultOptions()
    {
        return [
            'mapping_type' => false,
            'field_name' => false,
            'field_type' => EntityType::class,
            'field_options' => [],
            'operator_type' => EqualOperatorType::class,
            'operator_options' => [],
        ];
    }

    public function getRenderSettings()
    {
        return [DefaultType::class, [
            'field_type' => $this->getFieldType(),
            'field_options' => $this->getFieldOptions(),
            'operator_type' => $this->getOption('operator_type'),
            'operator_options' => $this->getOption('operator_options'),
            'label' => $this->getLabel(),
        ]];
    }

    /**
     * For the record, the $alias value is provided by the association method (and the entity join method)
     *  so the field value is not used here.
     *
     * @param string  $alias
     * @param mixed[] $value
     *
     * @return void
     */
    protected function handleMultiple(ProxyQueryInterface $queryBuilder, $alias, $value)
    {
        if (0 === \count($value['value'])) {
            return;
        }

        $parameterName = $this->getNewParameterName($queryBuilder);

        if (isset($value['type']) && EqualOperatorType::TYPE_NOT_EQUAL === $value['type']) {
            $or = $queryBuilder->expr()->orX();

            $or->add($queryBuilder->expr()->notIn($alias, ':'.$parameterName));

            if (ClassMetadata::MANY_TO_MANY === $this->getOption('mapping_type')) {
                $or->add(
                    sprintf('%s.%s IS EMPTY', $this->getParentAlias($queryBuilder, $alias), $this->getFieldName())
                );
            } else {
                $or->add($queryBuilder->expr()->isNull(
                    sprintf('IDENTITY(%s.%s)', $this->getParentAlias($queryBuilder, $alias), $this->getFieldName())
                ));
            }

            $this->applyWhere($queryBuilder, $or);
        } else {
            $this->applyWhere($queryBuilder, $queryBuilder->expr()->in($alias, ':'.$parameterName));
        }

        $queryBuilder->setParameter($parameterName, $value['value']);
    }

    /**
     * @param mixed[] $value
     *
     * @return array
     *
     * @phpstan-return array{string, bool}
     */
    protected function association(ProxyQueryInterface $queryBuilder, $value)
    {
        $types = [
            ClassMetadata::ONE_TO_ONE,
            ClassMetadata::ONE_TO_MANY,
            ClassMetadata::MANY_TO_MANY,
            ClassMetadata::MANY_TO_ONE,
        ];

        if (!\in_array($this->getOption('mapping_type'), $types, true)) {
            throw new \RuntimeException('Invalid mapping type');
        }

        $associationMappings = $this->getParentAssociationMappings();
        $associationMappings[] = $this->getAssociationMapping();
        $alias = $queryBuilder->entityJoin($associationMappings);

        return [$alias, false];
    }

    /**
     * Retrieve the parent alias for given alias.
     * Root alias for direct association or entity joined alias for association depth >= 2.
     */
    private function getParentAlias(ProxyQueryInterface $queryBuilder, string $alias): string
    {
        $parentAlias = $rootAlias = current($queryBuilder->getRootAliases());
        $joins = $queryBuilder->getDQLPart('join');
        if (isset($joins[$rootAlias])) {
            foreach ($joins[$rootAlias] as $join) {
                if ($join->getAlias() === $alias) {
                    $parts = explode('.', $join->getJoin());
                    $parentAlias = $parts[0];

                    break;
                }
            }
        }

        return $parentAlias;
    }
}
