<?php

namespace Drupal\default_content_ui\Form;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\default_content\ExporterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Export the default content.
 */
class ExportDefaultContentForm extends FormBase {

  private const CONFIG_NAME_OF_ENTITY_TYPE_IDS = 'default_content_form.entity_type_ids';

  private const DEFAULT_CONTENT_ZIP_URI = 'temporary://default-content-form/zip/content.zip';

  private const DEFAULT_CONTENT_DIRECTORY = 'temporary://default-content-form';

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
   * The default content exporter.
   *
   * @var \Drupal\default_content\ExporterInterface
   */
  protected ExporterInterface $defaultContentExporter;

  /**
   * The UUID service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected UuidInterface $uuidService;

  /**
   * Export and import the default content.
   */
  public function __construct(
    StateInterface $state,
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    ModuleExtensionList $extension_list_module,
    ExporterInterface $default_content_exporter,
    UuidInterface $uuid_service
  ) {
    $this->state = $state;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->defaultContentExporter = $default_content_exporter;
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
      $container->get('file_system'),
      $container->get('extension.list.module'),
      $container->get('default_content.exporter'),
      $container->get('uuid')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'export_default_content_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $entity_type_ids = $this->state->get(
      self::CONFIG_NAME_OF_ENTITY_TYPE_IDS,
      'node, taxonomy_term, media, file, menu_link_content'
    );
    $form['entity_type_ids'] = [
      '#title' => $this->t('Entity type IDs'),
      '#type' => 'textfield',
      '#default_value' => $entity_type_ids,
    ];
    $form['export'] = [
      '#type' => 'submit',
      '#value' => $this->t('Export default content'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(
    array &$form,
    FormStateInterface $form_state
  ): void {
    try {
      $entity_type_ids_string = $form_state->getValue('entity_type_ids');
      $this->state->set(self::CONFIG_NAME_OF_ENTITY_TYPE_IDS, $entity_type_ids_string);
      $entity_type_ids = array_map('trim', explode(',', $entity_type_ids_string));

      $this->batchBuilder
        ->setTitle($this->t('Processing'))
        ->setInitMessage($this->t('Initializing.'))
        ->setProgressMessage($this->t('Completed @current of @total.'))
        ->setErrorMessage($this->t('An error has occurred.'));
      $this->batchBuilder->addOperation([$this, 'prepareExportDirectory'], []);
      foreach ($entity_type_ids as $entity_type_id) {
        $entity_ids = $this->entityTypeManager->getStorage($entity_type_id)->getQuery()->execute();
        $this->batchBuilder->addOperation([$this, 'processEntities'], [$entity_ids, $entity_type_id]);
      }
      $this->batchBuilder->addOperation([$this, 'createArchive'], [self::DEFAULT_CONTENT_DIRECTORY]);
      $this->batchBuilder->setFinishCallback([$this, 'finished']);
      batch_set($this->batchBuilder->toArray());
    }
    catch (\Exception $e) {
      $this->messenger()->addError($e->getMessage() . $e->getTraceAsString());
    }
  }

  /**
   * Prepare directory.
   */
  private function prepareDirectory(string $directory_uri) {
    if (file_exists($directory_uri)) {
      $this->fileSystem->deleteRecursive($directory_uri);
    }
    $this->fileSystem->prepareDirectory($directory_uri, FileSystemInterface::CREATE_DIRECTORY);
  }

  /**
   * Processor for batch operations.
   */
  public function processEntities(array $entity_ids, string $entity_type_id, array &$context) {
    // Elements per operation.
    $limit = 20;

    // Set default progress values.
    if (empty($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = count($entity_ids);
    }

    // Save items to array which will be changed during processing.
    if (empty($context['sandbox']['entity_ids'])) {
      $context['sandbox']['entity_ids'] = $entity_ids;
    }

    $counter = 0;

    if (!empty($context['sandbox']['entity_ids'])) {
      // Remove already processed items.
      if ($context['sandbox']['progress'] != 0) {
        array_splice($context['sandbox']['entity_ids'], 0, $limit);
      }

      $directory = $this->uuidService->generate();
      foreach ($context['sandbox']['entity_ids'] as $entity_id) {
        if ($counter >= $limit) {
          break;
        }
        $this->processItem($entity_id, $entity_type_id, $directory);

        $counter++;
        $context['sandbox']['progress']++;

        $context['message'] = $this->t('Now processing node :progress of :count', [
          ':progress' => $context['sandbox']['progress'],
          ':count' => $context['sandbox']['max'],
        ]);

        // Increment total processed item values.
        // Will be used in finished callback.
        $context['results']['processed'] = $context['sandbox']['progress'];
      }
    }

    // If not finished all tasks, we count percentage of process. 1 = 100%.
    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Export entity.
   */
  public function processItem(int $entity_id, string $entity_type_id, $directory): array {
    $entity_directory = self::DEFAULT_CONTENT_DIRECTORY . "/$directory/content";
    $this->prepareDirectory($entity_directory);

    return $this->defaultContentExporter->exportContentWithReferences($entity_type_id, $entity_id, $entity_directory);
  }

  /**
   * Prepare export directory.
   */
  public function prepareExportDirectory() {
    $folder_uri = self::DEFAULT_CONTENT_DIRECTORY;
    $this->fileSystem->deleteRecursive($folder_uri);
    $is_prepared = $this->fileSystem->prepareDirectory($folder_uri, FileSystemInterface::CREATE_DIRECTORY);
    if (!$is_prepared) {
      $this->messenger()->addStatus($this->t('The directory "@directory" is not writable.', [
        '@directory' => $folder_uri,
      ]));
    }
    $this->fileSystem->chmod($folder_uri, 0775);
  }

  /**
   * Finished callback for batch.
   *
   * @noinspection PhpUnusedParameterInspection
   */
  public function finished($success, $results, $operations) {
    $message = new TranslatableMarkup('Number of nodes exported by batch: @count, Url: <a href="@url">@url</a>', [
      '@count' => $results['processed'],
      '@url' => \Drupal::service('file_url_generator')->generateAbsoluteString(self::DEFAULT_CONTENT_ZIP_URI),
    ]);
    $this->messenger()->addStatus($message);
  }

  /**
   * Create an archive.
   */
  public function createArchive(string $folder_uri): string {
    $zip_uri = self::DEFAULT_CONTENT_ZIP_URI;
    $zip_directory = dirname($zip_uri);
    $this->prepareDirectory($zip_directory);
    $zip_path = $this->fileSystem->realpath($zip_uri);

    $zip = new \ZipArchive();
    $zip->open($zip_path, \ZipArchive::CREATE);
    $files = $this->fileSystem->scanDirectory($folder_uri, '/.*/');
    foreach ($files as $file) {
      $file_relative_path = str_replace($folder_uri . '/', '', $file->uri);
      $zip->addFile(
        $this->fileSystem->realpath($file->uri),
        $file_relative_path
      );
    }
    $zip->close();
    if (!file_exists($zip_path)) {
      throw new FileException('An archive with the content was not created.');
    }

    return $zip_uri;
  }

  /**
   * {@inheritdoc}
   */
  public function __wakeup() {
    $this->_serviceIds = [
      'fileSystem' => 'file_system',
      'defaultContentExporter' => 'default_content.exporter',
      'moduleExtensionList' => 'extension.list.module',
      'uuidService' => 'uuid',
    ];
    parent::__wakeup();
  }

}
