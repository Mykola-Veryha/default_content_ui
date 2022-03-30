<?php

namespace Drupal\default_content_ui\Form;

use Drupal\Core\Archiver\Zip;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\default_content\ExporterInterface;
use Drupal\default_content\ImporterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\MimeTypeGuesserInterface;

/**
 * Export and import the default content.
 */
class DefaultContentForm extends FormBase {

  private const CONFIG_NAME_OF_ENTITY_TYPE_IDS = 'default_content_form.entity_type_ids';

  public const LOCK_ID = 'default_content_form_lock';

  private const DEFAULT_CONTENT_ZIP_URI = 'temporary://default-content-form/zip/content.zip';

  private const DEFAULT_CONTENT_DIRECTORY = 'temporary://default-content-form/content';

  /**
   * The MIME type guesser.
   *
   * @var \Symfony\Component\Mime\MimeTypeGuesserInterface
   */
  protected MimeTypeGuesserInterface $mimeTypeGuesser;

  /**
   * The state.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  private StateInterface $state;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The default content importer.
   *
   * @var \Drupal\default_content\ImporterInterface
   */
  private ImporterInterface $defaultContentImporter;

  /**
   * Batch Builder.
   *
   * @var \Drupal\Core\Batch\BatchBuilder
   */
  private BatchBuilder $batchBuilder;

  /**
   * Export and import the default content.
   */
  public function __construct(
    StateInterface $state,
    EntityTypeManagerInterface $entity_type_manager,
    MimeTypeGuesserInterface $mime_type_guesser,
    ImporterInterface $default_content_importer
  ) {
    $this->defaultContentImporter = $default_content_importer;
    $this->state = $state;
    $this->entityTypeManager = $entity_type_manager;
    $this->mimeTypeGuesser = $mime_type_guesser;
    $directory = str_replace(
      DRUPAL_ROOT . '/',
      '',
      $this->fileSystem()->realpath('temporary://default-content-form'),
    );
    $this->moduleExtensionList()->setPathname(
      'default_content_ui_directory',
      $directory
    );
    $this->batchBuilder = new BatchBuilder();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('state'),
      $container->get('entity_type.manager'),
      $container->get('file.mime_type.guesser'),
      $container->get('default_content.importer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'default_content_form';
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
      '#submit' => [[$this, 'exportSubmit']],
    ];

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
      '#submit' => ['::importSubmit'],
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
   * @throws \Drupal\Core\Archiver\ArchiverException
   *
   * @noinspection PhpUnusedParameterInspection
   */
  public function importSubmit(
    array &$form,
    FormStateInterface $form_state
  ) {
    $files = $this->getRequest()->files->get('files', []);
    $zip_file = $files['zip'] ?? NULL;
    if ($zip_file instanceof UploadedFile) {
      $zip_uri = self::DEFAULT_CONTENT_ZIP_URI;
      $this->prepareDirectory(dirname($zip_uri));

      $folder_uri = $this->defaultContentDirectory();
      $this->prepareDirectory($folder_uri);

      $archive_uri = $this->fileSystem()->copy($zip_file->getRealPath(), $zip_uri);
      $zip = new Zip($this->fileSystem()->realpath($archive_uri));
      $zip->extract($folder_uri);
      $this->state->set(self::LOCK_ID, TRUE);
      $this->defaultContentImporter->importContent('default_content_ui_directory');
      $this->state->set(self::LOCK_ID, FALSE);
    }
    else {
      $this->messenger()->addStatus($this->t('The archive is empty'));
    }
  }

  /**
   * Directory to store the default content.
   */
  private function defaultContentDirectory(): string {
    return self::DEFAULT_CONTENT_DIRECTORY;
  }

  /**
   * Prepare directory.
   */
  private function prepareDirectory(string $directory_uri) {
    if (file_exists($directory_uri)) {
      $this->fileSystem()->deleteRecursive($directory_uri);
    }
    $this->fileSystem()->prepareDirectory($directory_uri, FileSystemInterface::CREATE_DIRECTORY);
  }

  /**
   * Exports an entity and all its referenced entities.
   *
   * @noinspection PhpUnusedParameterInspection
   */
  public function exportSubmit(
    array &$form,
    FormStateInterface $form_state
  ) {
    try {
      $entity_type_ids_string = $form_state->getValue('entity_type_ids');
      $entity_type_ids = array_map('trim', explode(',', $entity_type_ids_string));

      $this->batchBuilder
        ->setTitle($this->t('Processing'))
        ->setInitMessage($this->t('Initializing.'))
        ->setProgressMessage($this->t('Completed @current of @total.'))
        ->setErrorMessage($this->t('An error has occurred.'));
      $this->batchBuilder->setFile($this->moduleExtensionList()->getPath('default_content_ui') . '/src/Form/DefaultContentForm.php');
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
   * Processor for batch operations.
   */
  public function processEntities(array $entity_ids, string $entity_type_id, array &$context) {
    // Elements per operation.
    $limit = 50;

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

      foreach ($context['sandbox']['entity_ids'] as $entity_id) {
        if ($counter != $limit) {
          $this->processItem($entity_id, $entity_type_id);

          $counter++;
          $context['sandbox']['progress']++;

          $context['message'] = $this->t('Now processing node :progress of :count', [
            ':progress' => $context['sandbox']['progress'],
            ':count' => $context['sandbox']['max'],
          ]);

          // Increment total processed item values. Will be used in finished
          // callback.
          $context['results']['processed'] = $context['sandbox']['progress'];
        }
      }
    }

    // If not finished all tasks, we count percentage of process. 1 = 100%.
    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  public function processItem(int $entity_id, string $entity_type_id) {
    $this->defaultContentExporter()->exportContentWithReferences(
      $entity_type_id,
      $entity_id,
      self::DEFAULT_CONTENT_DIRECTORY
    );
  }

  public function prepareExportDirectory() {
    $folder_uri = self::DEFAULT_CONTENT_DIRECTORY;
    $this->fileSystem()->deleteRecursive($folder_uri);
    $is_prepared = $this->fileSystem()->prepareDirectory($folder_uri, FileSystemInterface::CREATE_DIRECTORY);
    if (!$is_prepared) {
      $this->messenger()->addStatus($this->t('The directory "@directory" is not writable.', [
        '@directory' => $folder_uri,
      ]));
    }
    $this->fileSystem()->chmod($folder_uri, 0775);
  }

  /**
   * Finished callback for batch.
   *
   * @noinspection PhpUnusedParameterInspection
   */
  public function finished($success, $results, $operations) {
    $message = $this->t('Number of nodes exported by batch: @count, Url: @url', [
      '@count' => $results['processed'],
      '@url' => \Drupal::service('file_url_generator')->generateAbsoluteString(self::DEFAULT_CONTENT_ZIP_URI),
    ]);

    $this->messenger()
      ->addStatus($message);
  }

  /**
   * Create an archive.
   */
  public function createArchive(string $folder_uri): string {
    $zip_uri = self::DEFAULT_CONTENT_ZIP_URI;
    $zip_directory = dirname($zip_uri);
    $this->prepareDirectory($zip_directory);
    $zip_path = $this->fileSystem()->realpath($zip_uri);

    $zip = new \ZipArchive();
    $zip->open($zip_path, \ZipArchive::CREATE);
    $files = $this->fileSystem()->scanDirectory($folder_uri, '/.*/');
    foreach ($files as $file) {
      $file_relative_path = str_replace($folder_uri . '/', '', $file->uri);
      $zip->addFile(
        $this->fileSystem()->realpath($file->uri),
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
   * The module extension list.
   */
  public function moduleExtensionList(): ModuleExtensionList {
    return \Drupal::service('extension.list.module');
  }

  /**
   * The file system service.
   */
  public function fileSystem(): FileSystemInterface {
    return \Drupal::service('file_system');
  }

  /**
   * The default content exporter.
   */
  public function defaultContentExporter(): ExporterInterface {
    return \Drupal::service('default_content.exporter');
  }

}
