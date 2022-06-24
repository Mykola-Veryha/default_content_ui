# Default Content UI

## Introduction
A default content UI solution for Drupal 9.

Used as extension of default_content contrib module by adding simple UI forms for content export and import.

Export form: _/admin/content/default-content/export_

Import form: _/admin/content/default-content/import_

###  Features

* Supports entity-references between content

* Supports files if you have File entity

* Easily export your content and its dependencies to yml using drupal admin interface

## Requirements
* Drupal 9
* default_content (https://www.drupal.org/project/default_content)
* default_content_extra (https://www.drupal.org/project/default_content_extra)

## Installation
Install as you would normally install a contributed Drupal module. Visit:
https://www.drupal.org/docs/8/extending-drupal-8/installing-drupal-8-modules
for further information.

## Usage
User allowed to export content with Export Form `/admin/content/default-content/export`
1. Define Entity type IDs (by default `node, taxonomy_term, media, file, menu_link_content, block_content`)
2. Also user can define Entity IDs to select Entities to export
3. After form submit downloadable link with archived content will be generated
4. To import default content on site install (in case of deployment website to the new environment or auto-test implementation) developer should:
   * extract previously archived content to the `stored-content` folder in this module directory
   * add new task to the `hook_install_tasks` in profile file:
```
/**
 * Implements hook_install_tasks().
 */
function SUBSCRIPTION_profile_install_tasks($install_state) {
  $tasks = [
    'SUBSCRIPTION_profile_default_content_import' => [
      'display_name' => t('Default content import'),
      'type' => 'batch',
    ],
  ];

  return $tasks;
}
```
   * define function for previously created task
```
/**
 * Default content installation..
 */
function SUBSCRIPTION_profile_default_content_import() {
  $module_handler = \Drupal::service('module_handler');
  $module_path = $module_handler->getModule('default_content_ui')->getPath();
  $default_content_folder = $module_path . '/stored-content';

  if (
    !$module_handler->moduleExists('default_content_ui') ||
    !is_dir($default_content_folder)
  ) {
    return;
  }

  $default_content_service = \Drupal::service('default_content_ui.manager');
  $batch = $default_content_service->import($default_content_folder);

  if (!empty($batch)) {
    return $batch;
  }
}
```
  * Run site install via drush `drush si -y`

### N.B.: Issue with import default content by Import Form in the admin interface
As default_content contribe module in `2.0-alpha1` version has several issues with import content on the site with already existed content, please be careful in this case.

*Use only on fresh install*

See https://www.drupal.org/project/default_content/issues/2698425
