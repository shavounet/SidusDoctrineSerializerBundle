<?php
/*
 * This file is part of the Sidus/DoctrineSerializerBundle package.
 *
 * Copyright (c) 2015-2019 Vincent Chalnot
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sidus\DoctrineSerializerBundle\Serializer;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\MappingException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Handles denormalization of Doctrine entities
 *
 * @author Vincent Chalnot <vincent@sidus.fr>
 */
class EntityNormalizer implements DenormalizerInterface
{
    /** @var DenormalizerInterface */
    protected $baseDenormalizer;

    /** @var ManagerRegistry */
    protected $managerRegistry;

    /**
     * @param DenormalizerInterface $baseDenormalizer
     * @param ManagerRegistry       $managerRegistry
     */
    public function __construct(DenormalizerInterface $baseDenormalizer, ManagerRegistry $managerRegistry)
    {
        $this->baseDenormalizer = $baseDenormalizer;
        $this->managerRegistry = $managerRegistry;
    }

    /**
     * {@inheritdoc}
     */
    public function denormalize($data, $class, $format = null, array $context = [])
    {
        $result = $this->findPersistedInstance($class, $data);
        if ($result) {
            $context[AbstractNormalizer::OBJECT_TO_POPULATE] = $result;
        }

        return $this->baseDenormalizer->denormalize($data, $class, $format, $context);
    }

    /**
     * @param string $class
     * @param mixed  $data
     *
     * @return object|null
     */
    protected function findPersistedInstance(string $class, $data)
    {
        $entityManager = $this->managerRegistry->getManagerForClass($class);
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \UnexpectedValueException("No entity manager found for class {$class}");
        }

        // Test primary key(s)
        $classMetadata = $entityManager->getClassMetadata($class);
        if (is_scalar($data)) {
            return $entityManager->find($class, $data); // If data is scalar, tries to find it by primary identifier
        }
        if (!is_array($data)) {
            return null; // Throw exception?
        }

        $result = $this->findBy($entityManager, $class, $classMetadata->getIdentifierFieldNames(), $data);
        if ($result) {
            return $result;
        }

        // Test unique constraints
        foreach ($classMetadata->table['uniqueConstraints'] ?? [] as $uniqueConstraint) {
            $uniqueFields = $this->resolveUniqueFields($classMetadata, $uniqueConstraint);
            $result = $this->findBy($entityManager, $class, $uniqueFields, $data);
            if ($result) {
                return $result;
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsDenormalization($data, $type, $format = null): bool
    {
        return $this->baseDenormalizer->supportsDenormalization($data, $type, $format) && $this->isManagedClass($type);
    }

    /**
     * @param string $class
     *
     * @return bool
     */
    protected function isManagedClass(string $class): bool
    {
        return $this->managerRegistry->getManagerForClass($class) instanceof EntityManagerInterface;
    }

    /**
     * @param array $fieldNames
     * @param array $data
     *
     * @return array
     */
    protected function resolveFindByCriteria(
        array $fieldNames,
        array &$data
    ): array {
        $findByCriteria = [];
        foreach ($fieldNames as $fieldName) {
            if (!array_key_exists($fieldName, $data)) {
                return [];
            }
            $findByCriteria[$fieldName] = $data[$fieldName];
        }

        return $findByCriteria;
    }

    /**
     * @param EntityManagerInterface $entityManager
     * @param                        $class
     * @param array                  $findByCriteria
     *
     * @return object|null
     */
    protected function findByCriteria(EntityManagerInterface $entityManager, $class, array $findByCriteria)
    {
        if (count($findByCriteria) === 0) {
            return null;
        }

        $repository = $entityManager->getRepository($class);
        if (!$repository instanceof EntityRepository) {
            throw new \UnexpectedValueException("No repository found for class {$class}");
        }

        return $repository->findOneBy($findByCriteria);
    }

    /**
     * @param ClassMetadataInfo $classMetadata
     * @param array             $uniqueConstraint
     *
     * @return array
     */
    protected function resolveUniqueFields(ClassMetadataInfo $classMetadata, array $uniqueConstraint): array
    {
        $uniqueFields = [];
        foreach ($uniqueConstraint['columns'] ?? [] as $column) {
            try {
                $uniqueFields[] = $classMetadata->getFieldForColumn($column);
            } catch (MappingException $e) {
                return [];
            }
        }

        return $uniqueFields;
    }

    /**
     * @param EntityManagerInterface $entityManager
     * @param string                 $class
     * @param array                  $fieldNames
     * @param array                  $data
     *
     * @return object|null
     */
    protected function findBy(EntityManagerInterface $entityManager, string $class, array $fieldNames, array &$data)
    {
        $findByCriteria = $this->resolveFindByCriteria(
            $fieldNames,
            $data
        );

        return $this->findByCriteria($entityManager, $class, $findByCriteria);
    }
}
