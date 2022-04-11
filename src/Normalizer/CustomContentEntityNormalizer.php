<?php

namespace Drupal\default_content_ui\Normalizer;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\default_content\Normalizer\ContentEntityNormalizer;
use Drupal\node\NodeInterface;

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

  /**
   * {@inheritdoc}
   */
  public function denormalize(array $data) {
    /** @var \Drupal\menu_link_content\Entity\MenuLinkContent $entity */
    $entity = parent::denormalize($data);
    // Exporting menu link with nodes.
    // @see https://www.drupal.org/project/default_content/issues/2885285
    if ($entity->getEntityTypeId() === 'menu_link_content') {
      $link = $entity->get('link')->getValue()[0] ?? '';
      if (empty($link['uri']) || empty($link['title'])) {
        $target_entity_uuid = $data['default']['link'][0]['target_uuid'] ?? '';
        $link = $this->generateLinkByNodeUuid($target_entity_uuid);
        $entity->set('link', [$link]);
      }
    }

    return $entity;
  }

  /**
   * Generate link by node UUID.
   */
  private function generateLinkByNodeUuid(string $target_entity_uuid): array {
    $link = [
      'title' => 'Not found link',
      'uri' => 'internal:/',
    ];
    try {
      if (!empty($target_entity_uuid)) {
        /** @var \Drupal\node\NodeInterface $node */
        $node = $this->entityRepository->loadEntityByUuid('node', $target_entity_uuid);
        if ($node instanceof NodeInterface) {
          $link = [
            'uri' => 'entity:node/' . $node->id(),
            'title' => $node->getTitle(),
          ];
        }
      }
    }
    catch (\Exception $e) {
      watchdog_exception('custom_content_entity_normalizer', $e);
    }

    return $link;
  }

}
