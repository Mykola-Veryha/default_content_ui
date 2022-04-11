<?php

namespace Drupal\default_content_ui;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\default_content_ui\Normalizer\CustomContentEntityNormalizer;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Alter services.
 */
class DefaultContentUiServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('default_content.content_entity_normalizer')) {
      $definition = $container->getDefinition('default_content.content_entity_normalizer');
      $definition->setClass(CustomContentEntityNormalizer::class);
      $definition->addArgument(new Reference('entity_type.manager'));
      $definition->addArgument(new Reference('module_handler'));
      $definition->addArgument(new Reference('entity.repository'));
      $definition->addArgument(new Reference('language_manager'));
    }
  }

}
