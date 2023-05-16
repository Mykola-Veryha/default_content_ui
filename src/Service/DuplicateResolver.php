<?php

namespace Drupal\default_content_ui\Service;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * Delete entity duplicates.
 */
class DuplicateResolver {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Delete entity duplicates.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Resolve entity duplicates.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   *   Thrown if the entity type doesn't exist.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *   Thrown if the storage handler couldn't be loaded.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   In case of failures an exception is thrown.
   */
  public function resolveDuplicates(ContentEntityBase $entity) {
    if ($entity instanceof UserInterface) {
      $this->resolveUserDuplicates($entity);
    }
    else {
      $this->resolveEntityDuplicates($entity);
    }
  }

  /**
   * Resolve entity duplicates.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   *   Thrown if the entity type doesn't exist.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *   Thrown if the storage handler couldn't be loaded.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   In case of failures an exception is thrown.
   */
  public function resolveUserDuplicates(UserInterface $entity) {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $exist_entity_ids = [];
    if ($entity->hasField('uuid') || $entity->id()) {
      $query = $storage->getQuery();
      if ($entity->hasField('uuid')) {
        $query->condition('uuid', $entity->uuid());
      }
      if ($entity->id()) {
        $query->condition($entity->getEntityType()->getKey('id'), $entity->id());
      }
      $query->condition('name', $entity->getAccountName());
      $query->condition('mail', $entity->getEmail());
      $exist_entity_ids = $query->execute();
    }
    if (!empty($exist_entity_ids)) {
      $entity->enforceIsNew(FALSE);
      if (empty($entity->original)) {
        $entity->original = $entity;
      }
    }
    else {
      $this->deleteUserDuplicates($entity);
    }
  }

  /**
   * Resolve entity duplicates.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   *   Thrown if the entity type doesn't exist.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *   Thrown if the storage handler couldn't be loaded.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   In case of failures an exception is thrown.
   */
  public function resolveEntityDuplicates(ContentEntityBase $entity) {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $exist_entity_ids = [];
    if ($entity->hasField('uuid') || $entity->id()) {
      $query = $storage->getQuery();
      if ($entity->hasField('uuid')) {
        $query->condition('uuid', $entity->uuid());
      }
      if ($entity->id()) {
        $query->condition($entity->getEntityType()->getKey('id'), $entity->id());
      }
      $exist_entity_ids = $query->execute();
    }

    if (!empty($exist_entity_ids)) {
      $entity->enforceIsNew(FALSE);
      if (empty($entity->original)) {
        $entity->original = $entity;
      }
    }
    else {
      $this->deleteEntityDuplicates($entity);
    }
  }

  /**
   * Delete entity duplicates.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   *   Thrown if the entity type doesn't exist.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *   Thrown if the storage handler couldn't be loaded.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   In case of failures, an exception is thrown.
   */
  private function deleteEntityDuplicates(ContentEntityBase $entity) {
    $this->deleteDuplicateByField($entity, 'uuid');
    $this->deleteDuplicateByField($entity, $entity->getEntityType()->getKey('id'));
  }

  /**
   * Delete user duplicates.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   *   Thrown if the entity type doesn't exist.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *   Thrown if the storage handler couldn't be loaded.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   In case of failures, an exception is thrown.
   */
  private function deleteUserDuplicates(ContentEntityInterface $entity) {
    $this->deleteDuplicateByField($entity, 'uuid');
    $this->deleteDuplicateByField($entity, 'uid');
    $this->deleteDuplicateByField($entity, 'name');
    $this->deleteDuplicateByField($entity, 'mail');
  }

  /**
   * Delete entity duplicates by field.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   *   Thrown if the entity type doesn't exist.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *   Thrown if the storage handler couldn't be loaded.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   In case of failures, an exception is thrown.
   */
  private function deleteDuplicateByField(ContentEntityInterface $entity, string $field_name) {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $query = $storage->getQuery();
    if ($entity->hasField($field_name)) {
      $query->condition($field_name, $entity->get($field_name)->getString());
      $exist_entities_ids = $query->execute();
      if (!empty($exist_entities_ids)) {
        $entities = $storage->loadMultiple($exist_entities_ids);
        $storage->delete($entities);
      }
    }
  }

}
