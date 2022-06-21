<?php

declare(strict_types=1);

namespace Drupal\default_content_ui;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\default_content\ImporterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides default_content_ui service manager.
 */
class DefaultContentManager {

  use DependencySerializationTrait {
    __wakeup as defaultWakeup;
  }
  use MessengerTrait;
  use StringTranslationTrait;

  /**
   * Default folder for exported content.
   */
  public const DEFAULT_IMPORT_FOLDER = 'default_content_ui/stored-content';

  /**
   * The name used to identify the lock.
   */
  private const LOCK_ID = 'default_content_import_lock';

  /**
   * The state.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The default content importer.
   *
   * @var \Drupal\default_content\ImporterInterface
   */
  protected ImporterInterface $defaultContentImporter;

  /**
   * Batch Builder.
   *
   * @var \Drupal\Core\Batch\BatchBuilder
   */
  private BatchBuilder $batchBuilder;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected ModuleExtensionList $moduleExtensionList;

  /**
   * The UUID service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected UuidInterface $uuidService;

  /**
   * Import the default content.
   */
  public function __construct(
    StateInterface $state,
    EntityTypeManagerInterface $entity_type_manager,
    ImporterInterface $default_content_importer,
    FileSystemInterface $file_system,
    ModuleExtensionList $extension_list_module,
    UuidInterface $uuid_service
  ) {
    $this->defaultContentImporter = $default_content_importer;
    $this->state = $state;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->moduleExtensionList = $extension_list_module;
    $this->uuidService = $uuid_service;
    $this->batchBuilder = new BatchBuilder();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('state'),
      $container->get('entity_type.manager'),
      $container->get('default_content.importer'),
      $container->get('file_system'),
      $container->get('extension.list.module'),
      $container->get('uuid')
    );
  }

  /**
   * Import default content from specified folder.
   *
   * @param string|null $folder
   *   Folder URI.
   *
   * @return array|null
   *   A batch structure or FALSE if $files was empty.
   */
  public function import(string $folder = NULL): ?array {
    try {
      $folder_name = $folder ?? self::DEFAULT_IMPORT_FOLDER;
      $folder_uri = DRUPAL_ROOT . '/' . $folder_name;

      if (is_dir($folder_uri) && file_exists($folder_uri)) {
        $this->state->set(self::LOCK_ID, TRUE);

        $this->batchBuilder
          ->setTitle($this->t('Processing'))
          ->setInitMessage($this->t('Initializing.'))
          ->setProgressMessage($this->t('Completed @current of @total.'))
          ->setErrorMessage($this->t('An error has occurred.'));
        $sub_directories = array_diff(scandir($folder_uri), ['.', '..']);

        foreach ($sub_directories as $directory_name) {
          $sub_directory_uri = $folder_uri . "/$directory_name";
          if (is_dir($sub_directory_uri)) {
            $this->batchBuilder->addOperation([$this, 'importContent'], [$sub_directory_uri]);
          }
        }

        $this->batchBuilder->addOperation([$this, 'setLockStatus'], [FALSE]);
        $this->batchBuilder->setFinishCallback([$this, 'finished']);

        return $this->batchBuilder->toArray();
      }
      else {
        $this->messenger()->addStatus($this->t('Default content folder is not exist'));
      }
    }
    catch (\Exception $e) {
      watchdog_exception('default_content_service_import', $e);
    }

    return NULL;
  }

  /**
   * Enable import mode.
   *
   * @param bool $is_locked
   *   Lock status.
   *
   * @see default_content_ui_entity_presave
   */
  public function setLockStatus(bool $is_locked): void {
    $this->state->set(self::LOCK_ID, $is_locked);
  }

  /**
   * Import entities.
   *
   * @param string $directory_uri
   *   Source directory.
   * @param array $context
   *   The batch context.
   */
  public function importContent(string $directory_uri, array &$context): void {
    try {
      $directory_name = basename($directory_uri);
      // Set the subdirectory.
      $this->moduleExtensionList->setPathname(
        $directory_name,
        $directory_uri
      );
      $entities = $this->defaultContentImporter->importContent($directory_name);
      // Increment total processed item values.
      // Will be used in finished callback.
      $context['results']['processed'] += count($entities);
    }
    catch (\Exception $e) {
      watchdog_exception('default_content_service_import', $e);
    }
  }

  /**
   * Finished callback for batch.
   *
   * @param bool $success
   *   Indicate that the batch API tasks were all completed successfully.
   * @param array $results
   *   An array of all the results that were updated in update_do_one().
   * @param array $operations
   *   A list of the operations that had not been completed by the batch API.
   *
   * @noinspection PhpUnusedParameterInspection
   */
  public function finished(bool $success, array $results, array $operations): void {
    $message = $this->t('Number of content imported by batch: @count', [
      '@count' => $results['processed'],
    ]);
    $this->messenger()->addStatus($message);
  }

  /**
   * {@inheritdoc}
   */
  public function __wakeup(): void {
    $this->_serviceIds = [
      'fileSystem' => 'file_system',
      'defaultContentExporter' => 'default_content.exporter',
      'moduleExtensionList' => 'extension.list.module',
      'uuidService' => 'uuid',
      'state' => 'state',
      'defaultContentImporter' => 'default_content.importer',
    ];

    $this->defaultWakeup();
  }

}
