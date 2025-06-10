<?php

namespace Drupal\user_match\Plugin\views\filter;

use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Query\Expression; // Keep for potential future use, though not strictly needed now
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\views\Plugin\ViewsHandlerManager;

/**
 * Custom Views filter to exclude actions where a mutual match exists.
 *
 * This filter assumes the base table of the View is 'user_match_actions'.
 * It checks if the 'liker_uid' and 'liked_uid' from the current row
 * have a reciprocal 'like' action in the same table.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("user_match_exclude_mutual_matches")
 */
class ExcludeMutualMatches extends FilterPluginBase
{

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new ExcludeMutualMatches object.
   *
   * @param array $configuration
   * A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   * The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   * The plugin implementation definition.
   * @param \Drupal\Core\Database\Connection $database
   * The database connection.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Connection $database)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database') // Inject the database service
    );
  }


  /**
   * {@inheritdoc}
   * Define the form element for the filter configuration.
   * We just need a single checkbox.
   */
  protected function valueForm(&$form, FormStateInterface $form_state)
  {
    $form['value'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Exclude actions where users have mutually liked each other'),
      // Use the stored value for the checkbox state.
      '#default_value' => $this->value ?? 0, // Default to unchecked (0) if no value is stored
    ];
  }

  /**
   * {@inheritdoc}
   * Add the filtering logic to the query.
   */
  public function query()
  {
    // Only apply the filter logic if the checkbox is checked (value is 1).
    // $this->value holds the state of the checkbox defined in valueForm.
    if (!empty($this->value)) {

      // Ensure the main query object is available.
      if (!$this->query) {
        return;
      }

      // Get the alias for the base table (user_match_actions) in the main query.
      $base_table_alias = $this->ensureMyTable();

      // Construct a subquery to check for the existence of the reciprocal 'like'.
      // Use the injected database connection.
      $subquery = $this->database->select('user_match_actions', 'mutual_check');
      $subquery->fields('mutual_check', ['action_id']); // Select any field just to check existence

      // Correlate the subquery with the main query row using where().
      // Compare subquery liker_uid to main query liked_uid.
      $subquery->where('mutual_check.liker_uid = ' . $base_table_alias . '.liked_uid');
      // Compare subquery liked_uid to main query liker_uid.
      $subquery->where('mutual_check.liked_uid = ' . $base_table_alias . '.liker_uid');

      // The action in the subquery must be 'like'.
      $subquery->condition('mutual_check.action', 1, '=');
      $subquery->range(0, 1); // Optimize: only need existence check

      // **FIX:** Get arguments from the subquery.
      $subquery_arguments = $subquery->getArguments();

      // Add a WHERE NOT EXISTS condition to the main query.
      // Ensure the condition is added to the correct filter group.
      // **FIX:** Pass the subquery arguments to the main query expression.
      $this->query->addWhereExpression(
        $this->options['group'],
        'NOT EXISTS (' . $subquery . ')',
        $subquery_arguments
      );
    }
  }

  /**
   * {@inheritdoc}
   * Provide a summary for the filter in the Views UI.
   */
  public function adminSummary()
  {
    if (!empty($this->value)) {
      return $this->t('Excluding mutual matches');
    } else {
      return $this->t('Not excluding mutual matches');
    }
  }
}
