<?php

namespace Drupal\user_match\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\UserInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountInterface; // To check permissions
use Drupal\Core\Field\FieldDefinitionInterface; // For checking field existence

/**
 * Form controller for the user matching settings tab on the user profile edit page.
 *
 * Allows users to set their matching preferences (e.g., gender preference, message settings).
 */
class UserMatchSettingsForm extends FormBase
{

  /**
   * The user storage handler.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $userStorage;

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new UserMatchSettingsForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   * The current user account.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user)
  {
    $this->userStorage = $entity_type_manager->getStorage('user');
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'user_match_user_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, UserInterface $user = NULL)
  {
    if (!$user) {
      $this->messenger()->addError($this->t('User account not found.'));
      return $form;
    }

    // Store the user object in the form state for access in submit handler.
    $form_state->set('user_account', $user);

    // Access check (redundant due to route _entity_access, but kept for defense)
    if ($this->currentUser->id() != $user->id() && !$this->currentUser->hasPermission('administer users')) {
      $this->messenger()->addError($this->t('You do not have permission to edit these settings.'));
      return $form;
    }

    // --- Gender Preference Field ---
    $form['matching_preferences'] = [
      '#type' => 'details',
      '#title' => $this->t('Matching Preferences'),
      '#open' => TRUE,
    ];

    $field_name_preference = 'field_gender_preference';
    if ($user->hasField($field_name_preference)) {
      $field_definition = $user->getFieldDefinition($field_name_preference);
      if ($field_definition instanceof FieldDefinitionInterface) {
        $allowed_options = options_allowed_values($field_definition->getFieldStorageDefinition());

        if (!empty($allowed_options)) {
          $form['matching_preferences'][$field_name_preference] = [
            '#type' => 'checkboxes',
            '#title' => $this->t('I am interested in matching with:'),
            '#options' => $allowed_options,
            '#default_value' => array_map(function ($item) {
              return $item['value'];
            }, $user->get($field_name_preference)->getValue()),
            '#description' => $this->t('Select the gender(s) you would like to see on the matching page.'),
          ];
        } else {
          $form['matching_preferences'][$field_name_preference . '_message'] = [
            '#markup' => $this->t('No allowed values found for the "Interested in Matching" field. Please configure them in the field settings.'),
            '#prefix' => '<p class="messages messages--warning">',
            '#suffix' => '</p>',
          ];
        }
      } else {
        $form['matching_preferences'][$field_name_preference . '_message'] = [
          '#markup' => $this->t('The field definition for "field_gender_preference" is invalid.'),
          '#prefix' => '<p class="messages messages--error">',
          '#suffix' => '</p>',
        ];
      }
    } else {
      $form['matching_preferences'][$field_name_preference . '_message'] = [
        '#markup' => $this->t('The required field "%field" is missing on the user account (user: @uid). Please configure it in Account settings.', ['%field' => $field_name_preference, '@uid' => $user->id()]),
        '#prefix' => '<p class="messages messages--warning">',
        '#suffix' => '</p>',
      ];
    }

    // --- Message Acceptance Field ---
    $form['message_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Message Settings'),
      '#open' => TRUE,
    ];

    $field_name_messages = 'field_accept_msg_from_matches';
    if ($user->hasField($field_name_messages)) {
      $form['message_settings'][$field_name_messages] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Only accept messages from mutual matches'),
        '#default_value' => $user->get($field_name_messages)->value ?? 0,
        '#description' => $this->t('If checked, only users you have mutually matched with will be able to send you messages (requires integration with a messaging system).'),
      ];
    } else {
      $form['message_settings'][$field_name_messages . '_message'] = [
        '#markup' => $this->t('The required field "%field" is missing on the user account. Please configure it in Account settings.', ['%field' => $field_name_messages]),
        '#prefix' => '<p class="messages messages--warning">',
        '#suffix' => '</p>',
      ];
    }

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save matching settings'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    /** @var \Drupal\user\UserInterface $user */
    // \Drupal::messenger()->deleteAll;
    $user = $form_state->get('user_account');
    $messenger = $this->messenger();

    if (!$user) {
      $messenger->addError($this->t('Could not save settings: User account not found.'));
      return;
    }

    $fields_saved = 0;
    $field_name_preference = 'field_gender_preference';
    $field_name_messages = 'field_accept_msg_from_matches';

    // Save Gender Preference
    if ($user->hasField($field_name_preference)) {
      $raw_form_value = $form_state->getValue($field_name_preference);
      $preference_values_filtered = array_filter((array) $raw_form_value);
      $preference_keys_to_save = array_keys($preference_values_filtered);
      $user->set($field_name_preference, $preference_keys_to_save);
      $fields_saved++;
    } else {
      $messenger->addWarning($this->t('Could not save setting: The field "%field" is missing on user @uid.', ['%field' => $field_name_preference, '@uid' => $user->id()]));
    }

    // Save Message Acceptance Preference
    if ($user->hasField($field_name_messages)) {
      $accept_value = $form_state->getValue($field_name_messages);
      $user->set($field_name_messages, $accept_value);
      $fields_saved++;
    } else {
      $messenger->addWarning($this->t('Could not save setting: The field "%field" is missing on user @uid.', ['%field' => $field_name_messages, '@uid' => $user->id()]));
    }

    if ($fields_saved > 0) {
      try {
        $user->save();
        $messenger->addStatus($this->t('Matching settings have been saved for user @uid.', ['@uid' => $user->id()]));
        if (\Drupal::hasService('cache_tags.invalidator')) {
          \Drupal::service('cache_tags.invalidator')->invalidateTags(['user:' . $user->id()]);
        }
      } catch (\Exception $e) {
        $messenger->addError($this->t('An error occurred while saving the settings. Please try again.'));
        \Drupal::logger('user_match')->error('Error saving user match settings for user @uid: @message. Trace: @trace', [
          '@uid' => $user->id(),
          '@message' => $e->getMessage(),
          '@trace' => $e->getTraceAsString(), // Optional: for detailed debugging if errors persist
        ]);
      }
    } else {
      // Only show warning if there were fields expected but not found for saving
      // This condition might need refinement based on whether fields are truly optional or mandatory
      if (!$user->hasField($field_name_preference) && !$user->hasField($field_name_messages)) {
        $messenger->addWarning($this->t('No matching settings fields were found to save. Please configure the fields in Account settings.'));
      }
    }
  }
}
