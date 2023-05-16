<?php

namespace Drupal\default_content_ui\Form;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Archiver\Zip;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\default_content\ImporterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Import the default content.
 */
class ImportDefaultContentForm extends FormBase {

  public const LOCK_ID = 'default_content_form_lock';

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
   * Export and import the default content.
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
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'import_default_content_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['zip'] = [
      '#title' => $this->t('Zip archive'),
      '#type' => 'file',
      '#upload_validators' => [
        'file_validate_extensions' => ['zip'],
      ],
    ];
    $form['import'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import default content'),
      '#button_type' => 'primary',
      '#submit' => [[$this, 'importSubmit']],
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
  }

  /**
   * Exports an entity and all its referenced entities.
   *
   * @noinspection PhpUnusedParameterInspection
   */
  public function importSubmit(
    array &$form,
    FormStateInterface $form_state
  ) {
    try {
      $files = $this->getRequest()->files->get('files', []);
      $zip_file = $files['zip'] ?? NULL;
      if ($zip_file instanceof UploadedFile) {
        $folder_uri = self::DEFAULT_CONTENT_DIRECTORY;
        $this->prepareDirectory($folder_uri);
        $this->extractArchive($zip_file, $folder_uri);
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
        batch_set($this->batchBuilder->toArray());
      }
      else {
        $this->messenger()->addStatus($this->t('The archive is empty'));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($e->getMessage() . $e->getTraceAsString());
    }
  }

  /**
   * Extract archive.
   *
   * @throws \Drupal\Core\Archiver\ArchiverException
   */
  private function extractArchive(UploadedFile $zip_file, string $folder_uri) {
    $zip_uri = self::DEFAULT_CONTENT_ZIP_URI;
    $this->prepareDirectory(dirname($zip_uri));

    $archive_uri = $this->fileSystem->copy($zip_file->getRealPath(), $zip_uri);
    $zip = new Zip($this->fileSystem->realpath($archive_uri));
    $zip->extract($folder_uri);
  }

  /**
   * Enable import mode.
   *
   * @see default_content_ui_entity_presave
   */
  public function setLockStatus(bool $is_locked) {
    $this->state->set(self::LOCK_ID, $is_locked);
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
   * Export entity.
   */
  public function importContent(string $directory_uri, array &$context) {
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
      watchdog_exception('import_default_content_form', $e);
    }
  }

  /**
   * Finished callback for batch.
   *
   * @noinspection PhpUnusedParameterInspection
   */
  public function finished($success, $results, $operations) {
    $message = new TranslatableMarkup('Number of content imported by batch: @count', [
      '@count' => $results['processed'],
    ]);
    $this->messenger()->addStatus($message);
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
      'state' => 'state',
      'defaultContentImporter' => 'default_content.importer',
    ];
    parent::__wakeup();
  }

}
