<?php
/*
 * Doctrine Large Collections
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */

namespace DoctrineExtensions\LargeCollections;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\PersistentCollection;

class LargeCollection
{
    private $reflField = null;

    public function __construct()
    {
        $rc = new \ReflectionClass('Doctrine\ORM\PersistentCollection');
        $this->reflField = $rc->getProperty('em');
        $this->reflField->setAccessible(true);
    }

    /**
     * @param  PersistentCollection $collection
     * @return EntityManager
     */
    private function getEntityManager(PersistentCollection $collection)
    {
        return $this->reflField->getValue($collection);
    }

    /**
     * @param PersistentCollection $collection
     * @return int
     */
    public function count(PersistentCollection $collection)
    {
        $em = $this->getEntityManager($collection);

        $assoc = $collection->getMapping();
        $sourceMetadata = $em->getClassMetadata($assoc->sourceEntityName);
        $targetMetadata = $em->getClassMetadata($assoc->targetEntityName);

        if (count($targetMetadata->identifier) == 1) {
            $targetIdField = current($targetMetadata->identifier);
        } else {
            throw new \UnexpectedValueException("Only Relations with Entities using Single Primary Keys are supported.");
        }

        $dql = 'SELECT COUNT(r.' . $targetIdField . ') AS collectionCount '.
               'FROM ' . $sourceMetadata->name . ' o LEFT JOIN o.' . $assoc->sourceFieldName . ' r ' .
               'WHERE ' . $this->getWhereConditions($sourceMetadata);
        $query = $em->createQuery($dql);
        
        $this->setParameters($collection, $query);
        return $query->getSingleScalarResult();
    }

    /**
     * @param PersistentCollection $collection
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getSliceQuery(PersistentCollection $collection, $limit, $offset = 0)
    {
        $em = $this->getEntityManager($collection);

        $assoc = $collection->getMapping();
        $sourceMetadata = $em->getClassMetadata($assoc->sourceEntityName);
        $targetMetadata = $em->getClassMetadata($assoc->targetEntityName);

        if ($assoc->isOwningSide && !$assoc->inversedBy) {
            throw new \UnexpectedValueException("Only bi-directional collections can be sliced.");
        }

        if ($assoc->isOwningSide) {
            $assocField = $assoc->inversedBy;
        } else {
            $assocField = $assoc->mappedBy;
        }

        $dql = 'SELECT r FROM ' . $targetMetadata->name . ' r JOIN r.' . $assocField . ' o '.
               'WHERE ' . $this->getWhereConditions($sourceMetadata);
        $query = $em->createQuery($dql);

        $this->setParameters($collection, $query);
        $query->setFirstResult($offset)->setMaxResults($limit);

        return $query;
    }

    private function getWhereConditions($sourceMetadata)
    {
        $i = 0;
        $whereConditions = array_map(function($fieldName) use(&$i) {
            return 'o.' . $fieldName . ' = ?' . ++$i;
        }, $sourceMetadata->identifier);
        return implode(" AND ", $whereConditions);
    }

    private function setParameters($collection, $query)
    {
        $em = $this->getEntityManager($collection);

        $i = 0;
        foreach ($em->getUnitOfWork()->getEntityIdentifier($collection->getOwner()) AS $value) {
            $query->setParameter(++$i, $value);
        }
    }
}