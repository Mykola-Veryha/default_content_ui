<?php

namespace Drupal\default_content_ui\Normalizer;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\default_content\Normalizer\ContentEntityNormalizer;

/**
 * Normalizes and denormalizes content entities.
 */
class CustomContentEntityNormalizer extends ContentEntityNormalizer {

  /**
   * Returns a list of fields to be normalized.
   */
  protected function getFieldsToNormalize(ContentEntityInterface $entity): array {
    $fields_names = parent::getFieldsToNormalize($entity);
    $entity_type = $entity->getEntityType();
    // Add the entity ID to export.
    $fields_names[] = $entity_type->getKey('id');

    return $fields_names;
  }

}
