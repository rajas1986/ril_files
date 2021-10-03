<?php

namespace Drupal\views_current_path\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\UrlHelper;

/**
 * Default implementation of the base field plugin.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("current_path")
 */
class CurrentPath extends FieldPluginBase {

  /**
   * Implement query behaviour.
   *
   * @{inheritdoc}
   */
  public function query() {
    // Leave empty to avoid a query on this field.
  }

  /**
   * Define the available options.
   *
   * @return array
   *   An array of the available options.
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['path_format'] = ['default' => 'raw-internal'];
    $options['qs_support_fieldset'] = ['default' => 'default'];
    $options['qs_support_fieldset']['query_string_support'] = ['default' => 'bypass-query-string'];
    $options['caching']['cache_user'] = ['default' => FALSE];
    return $options;
  }

  /**
   * Provide the options form.
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    $moduleHandler = \Drupal::service('module_handler');
    $alias_required_message = $moduleHandler->moduleExists('path') ? '' : '<em>'
      . t('Note: the Path module must be enabled for the "Alias" options to work.') . '</em>';

    // Determine the URL prefix.
    $base_url = $GLOBALS['base_url'];
    global $base_path;

    $url_options = [];
    $raw_relative_prefix = $base_path . (isset($url_options['prefix']) ? $url_options['prefix'] : '');
    $raw_absolute_prefix = $base_url . $raw_relative_prefix;

    $raw_example = 'node/215';
    $alias_example = 'pages/example-path';
    $query_string_example = '?nid=357&tid=271';
    $query_string_valid_path_example = '[current_path]tid=[tid]';
    $query_string_invalid_path_example = '[current_path]?tid=[tid]';

    $form['path_format'] = [
      '#type' => 'radios',
      '#title' => t('Output style'),
      '#description' => $alias_required_message,
      '#options' => [
        'raw-internal' => t('Raw internal path (e.g. @example)', ['@example' => $raw_example]),
        'raw-relative' => t('Raw relative URL (e.g. @example)', ['@example' => $raw_relative_prefix . $raw_example]),
        'raw-absolute' => t('Raw absolute URL (e.g. @example)', ['@example' => $raw_absolute_prefix . $raw_example]),
        'alias-internal' => t('Alias internal path (e.g. @example)', ['@example' => $alias_example]),
        'alias-relative' => t('Alias relative URL (e.g. @example)', ['@example' => $raw_relative_prefix . $alias_example]),
        'alias-absolute' => t('Alias absolute URL (e.g. @example)', ['@example' => $raw_absolute_prefix . $alias_example]),
        'query-only' => t('Query string only (e.g. @example)', ['@example' => $query_string_example]),
      ],
      '#default_value' => $this->options['path_format'],
    ];

    $form['qs_support_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => t('Query string support'),
      '#dependency' => ['radio:options[path_format]' => ['alias-relative']],
      '#tree' => TRUE,
    ];
    $qs_message = t('<p>Query strings are included in the alias relative URL output style.</p>');
    $qs_message .= t('<p>Use query string support when creating a views path link rewrite and a query string is to be included.</p>');
    $qs_message .= t('For example, if building a link such as "@example" with multiple key-value pairs, the "concatentate" option would be required.',
      ['@example' => $raw_relative_prefix . $alias_example . $query_string_example]);
    $qs_message .= t('</p><p><em>Note: If using this feature, do not include a question mark (?) in the Views path link rewrite. A proper rewrite for query string support would be "@example_valid", and not "@example_invalid".',
      [
        '@example_valid' => $query_string_valid_path_example,
        '@example_invalid' => $query_string_invalid_path_example,
      ]);
    $qs_message .= t('A question mark will be added by the module if necessary.</em></p>');
    $form['qs_support_fieldset']['query_string_support'] = [
      '#type' => 'radios',
      '#title' => t('Select an option'),
      '#options' => [
        'bypass-query-string' => t('Bypass query string support'),
        'remove-query-string' => t('Remove existing query string on the current path'),
        'replace-query-string' => t('Replace existing query string on the current path with values passed through path rewrite'),
        'concat-query-string' => t('Concatenate existing query string on the current path with values passed through the path rewrite'),
      ],
      '#description' => $qs_message,
      '#default_value' => $this->options['qs_support_fieldset']['query_string_support'],
    ];

    $form['caching'] = [
      '#type' => 'fieldset',
      '#title' => t('Cache settings'),
      '#tree' => TRUE,
    ];

    $caching_message_user = '<p>' . t('Enable user-based caching to cache this view based on the currently logged in user. This will rebuild the view for each user') . '</p>';

    $form['caching']['cache_user'] = [
      '#type' => 'checkbox',
      '#title' => t('Use user-based caching'),
      '#description' => $caching_message_user,
      '#default_value' => $this->options['caching']['cache_user'],
    ];

    $form['view_edit_notice'] = [
      '#markup' => '<p>Note: ' . t('The placeholder @placeholder will be used for the field value while editing the view.',
        ['@placeholder' => '[' . $this->options['id'] . ']']) . '</p>',
      '#default_value' => '',
    ];

    parent::buildOptionsForm($form, $form_state);

  }

  /**
   * Submit handler for the options form.
   *
   * @{inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    $this->options['path_format'] = $form_state->getValue('path_format');
    $this->options['qs_support_fieldset']['query_string_support'] = $form_state->getValue('query_string_support');
    $this->options['caching']['cache_user'] = $form_state->getValue('cache_user');
    parent::buildOptionsForm($form, $form_state);
  }

  /**
   * Render the result.
   *
   * @{inheritdoc}
   */
  public function render(ResultRow $values) {
    $moduleHandler = \Drupal::service('module_handler');

    $current_path = \Drupal::service('path.current')->getPath();

    // Display a placeholder (i.e. field id) when editing the view.
    if (strpos($current_path, 'admin/structure/views/nojs/preview/' . $this->view->id() . '/') === 0) {
      return '[' . $this->options['id'] . ']';
    }

    $path_format = $this->options['path_format'];
    $query_string_support = $this->options['qs_support_fieldset']['query_string_support'];

    // In case the path module has been disabled, revert "alias" to "raw".
    if (strpos($path_format, 'alias') === 0 && !$moduleHandler->moduleExists('path')) {
      $path_format = str_replace('alias-', 'raw-', $path_format);
    }

    // Determine the URL prefix.
    global $base_url, $base_path;
    $url_options = [];
    $raw_relative_prefix = $base_path . (isset($url_options['prefix']) ? $url_options['prefix'] : '');
    $raw_absolute_prefix = $base_url . $raw_relative_prefix;

    // Determine the path.
    switch ($path_format) {
      case 'raw-internal':
        $output = $current_path;
        break;

      case 'raw-relative':
        $output = $raw_relative_prefix . $current_path;
        break;

      case 'raw-absolute':
        $output = $raw_absolute_prefix . $current_path;
        break;

      case 'alias-internal':
        $output = \Drupal::request()->getRequestUri();
        break;

      case 'alias-relative':
        $output = \Drupal::request()->getRequestUri();
        // If using alias-relative, process query string support setting.
        switch ($query_string_support) {
          // If bypass is selected, skip any changes.
          case 'bypass-query-string':
            break;

          case 'remove-query-string':
            if (stripos($output, '?') !== FALSE) {
              $output = strtok($output, '?');
            }
            break;

          case 'replace-query-string':
            if (stripos($output, '?') !== FALSE) {
              $output = strtok($output, '?') . '?';
            }
            break;

          case 'concat-query-string':
            if (stripos($output, '?') !== FALSE) {
              $output .= '&';
            }
            else {
              $output .= '?';
            }
            break;

          default:
            // Just as if bypass is selected -- skip any changes.
            break;

        }
        break;

      case 'alias-absolute':
        $option = [
          'absolute' => TRUE,
        ];
        $output = Url::fromUri('internal:' . $current_path, $option)->toString();
        break;

      case 'query-only':
        $q_items = [];
        parse_str($_SERVER["QUERY_STRING"], $q_items);
        // Don't include useless "q=" that some servers return.
        unset($q_items['q']);

        $output = UrlHelper::buildQuery($q_items);

        break;

      default:
        $output = $current_path;

        break;
    }

    $returnValue = [];
    $returnValue['#markup'] = $output;
    if ($this->options['caching']['cache_user']) {
      $returnValue['#cache']['tags'] = ['user:' . $this->user->id()];
    }
    return $returnValue;

  }

}
