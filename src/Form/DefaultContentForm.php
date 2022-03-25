<?php

namespace Drupal\default_content_ui\Form;

use Drupal\Core\Archiver\Zip;
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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\MimeTypeGuesserInterface;

/**
 * Export and import the default content.
 */
class DefaultContentForm extends FormBase {

  private const CONFIG_NAME_OF_ENTITY_TYPE_IDS = 'default_content_form.entity_type_ids';

  public const LOCK_ID = 'default_content_form_lock';

  private const DEFAULT_CONTENT_ZIP_URI = 'temporary://default-content-form/content.zip';

  /**
   * The default content exporter.
   *
   * @var \Drupal\default_content\ExporterInterface
   */
  private ExporterInterface $defaultContentExporter;

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
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  private FileSystemInterface $fileSystem;

  /**
   * The default content importer.
   *
   * @var \Drupal\default_content\ImporterInterface
   */
  private ImporterInterface $defaultContentImporter;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  private ModuleExtensionList $moduleExtensionList;

  /**
   * Export and import the default content.
   */
  public function __construct(
    ExporterInterface $default_content_exporter,
    StateInterface $state,
    EntityTypeManagerInterface $entity_type_manager,
    MimeTypeGuesserInterface $mime_type_guesser,
    FileSystemInterface $file_system,
    ImporterInterface $default_content_importer,
    ModuleExtensionList $extension_list_module
  ) {
    $this->defaultContentExporter = $default_content_exporter;
    $this->defaultContentImporter = $default_content_importer;
    $this->state = $state;
    $this->entityTypeManager = $entity_type_manager;
    $this->mimeTypeGuesser = $mime_type_guesser;
    $this->fileSystem = $file_system;
    $this->moduleExtensionList = $extension_list_module;
    $directory = str_replace(
      DRUPAL_ROOT . '/',
      '',
      $this->fileSystem->realpath('temporary://default-content-form'),
    );
    $this->moduleExtensionList->setPathname(
      'default_content_ui_directory',
      $directory
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('default_content.exporter'),
      $container->get('state'),
      $container->get('entity_type.manager'),
      $container->get('file.mime_type.guesser'),
      $container->get('file_system'),
      $container->get('default_content.importer'),
      $container->get('extension.list.module')
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
  public function buildForm(array $form,
    FormStateInterface $form_state): array {
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
      '#submit' => ['::exportSubmit'],
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

      $archive_uri = $this->fileSystem->copy($zip_file->getRealPath(), $zip_uri);
      $zip = new Zip($this->fileSystem->realpath($archive_uri));
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
    return $this->moduleExtensionList->getPath('default_content_ui_directory') . '/content';
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
      $folder_uri = $this->exportContent($entity_type_ids);
      $zip_uri = $this->createArchive($folder_uri);

      $headers = [
        'Content-Type' => $this->mimeTypeGuesser->guessMimeType($zip_uri),
        'Content-Length' => filesize($zip_uri),
      ];
      $response = new BinaryFileResponse($zip_uri, 200, $headers);
      $form_state->setResponse($response);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($e->getMessage() . $e->getTraceAsString());
    }
  }

  /**
   * Exports default content by entity type IDs.
   */
  private function exportContent(array $entity_type_ids): string {
    $folder_uri = $this->defaultContentDirectory();
    $this->fileSystem->deleteRecursive($folder_uri);
    $is_prepared = $this->fileSystem->prepareDirectory($folder_uri, FileSystemInterface::CREATE_DIRECTORY);
    if (!$is_prepared) {
      $this->messenger()->addStatus($this->t('The directory "@directory" is not writable.', [
        '@directory' => $folder_uri,
      ]));
    }
    $this->fileSystem->chmod($folder_uri, 0775);

    foreach ($entity_type_ids as $entity_type_id) {
      try {
        $entity_ids = $this->entityTypeManager->getStorage($entity_type_id)->getQuery()->execute();
        foreach ($entity_ids as $entity_id) {
          $this->defaultContentExporter->exportContentWithReferences($entity_type_id, $entity_id, $folder_uri);
        }
      }
      catch (\Exception $e) {
        $this->messenger()->addStatus($this->t('The entity type "@entity_type_id" was not found.', [
          '@entity_type_id' => $entity_type_id,
        ]));
      }
    }

    return $folder_uri;
  }

  /**
   * Create an archive.
   */
  private function createArchive(string $folder_uri): string {
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

}
