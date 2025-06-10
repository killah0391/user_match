<?php

/**
 * @file
 * Provide Views data integration for user_match module.
 */

/**
 * Implements hook_views_data().
 *
 * Tells Views about the user_match_actions table and its fields/relationships.
 */
function user_match_views_data()
{
  $data = [];

  // --- Base Table Definition: user_match_actions ---
  $data['user_match_actions']['table']['group'] = t('User Match');
  $data['user_match_actions']['table']['provider'] = 'user_match';
  $data['user_match_actions']['table']['base'] = [
    'field' => 'action_id',
    'title' => t('User Match Actions'),
    'help' => t('Stores like/dislike actions between users.'),
    'weight' => -10,
  ];

  // Define how this table can join to the users table (users_field_data)
  // This is useful if you start a View from Users and want to see actions.
  $data['user_match_actions']['table']['join'] = [
    // Join for actions performed BY a user
    'users_field_data_liker' => [ // Alias to distinguish joins
      'left_table' => 'users_field_data',
      'left_field' => 'uid',
      'field' => 'liker_uid',
      'table' => 'user_match_actions', // Explicitly state table
    ],
    // Join for actions performed ON a user
    'users_field_data_liked' => [ // Alias to distinguish joins
      'left_table' => 'users_field_data',
      'left_field' => 'uid',
      'field' => 'liked_uid',
      'table' => 'user_match_actions', // Explicitly state table
    ],
  ];

  // --- Field Definitions for user_match_actions Table ---

  // Action ID (Primary Key)
  $data['user_match_actions']['action_id'] = [
    'title' => t('Action ID'),
    'help' => t('The unique ID of the like/dislike action.'),
    'field' => ['id' => 'numeric'],
    'sort' => ['id' => 'standard'],
    'filter' => ['id' => 'numeric'],
    'argument' => ['id' => 'numeric'],
    'area' => ['id' => 'text'], // Allow in header/footer etc.
  ];

  // Liker User ID (User performing the action)
  $data['user_match_actions']['liker_uid'] = [
    'title' => t('Liker User ID'),
    'help' => t('The user ID of the user performing the action.'),
    'field' => ['id' => 'numeric'],
    'sort' => ['id' => 'standard'],
    'filter' => ['id' => 'numeric'],
    'argument' => [
      'id' => 'user_uid', // Use the user argument handler
      'name field' => 'name', // Use username for argument summary
      'numeric' => TRUE,
    ],
    // Define a relationship FROM this action TO the user who performed it.
    'relationship' => [
      'id' => 'standard',
      'base' => 'users_field_data', // Target table
      'base field' => 'uid',       // Target field
      'field' => 'liker_uid',      // Field on this table (user_match_actions)
      'label' => t('Liker User'),
      'title' => t('User who performed the action'),
      'help' => t('Relate the action to the user who performed it.'),
    ],
  ];

  // Liked User ID (User receiving the action)
  $data['user_match_actions']['liked_uid'] = [
    'title' => t('Liked User ID'),
    'help' => t('The user ID of the user receiving the action.'),
    'field' => ['id' => 'numeric'],
    'sort' => ['id' => 'standard'],
    'filter' => ['id' => 'numeric'],
    'argument' => [
      'id' => 'user_uid', // Use the user argument handler
      'name field' => 'name',
      'numeric' => TRUE,
    ],
    // Define a relationship FROM this action TO the user who received it.
    'relationship' => [
      'id' => 'standard',
      'base' => 'users_field_data', // Target table
      'base field' => 'uid',       // Target field
      'field' => 'liked_uid',      // Field on this table (user_match_actions)
      'label' => t('Liked User'),
      'title' => t('User who received the action'),
      'help' => t('Relate the action to the user who received it.'),
    ],
  ];

  // Action field (Like/Dislike)
  $data['user_match_actions']['action'] = [
    'title' => t('Action Type'),
    'help' => t('The type of action (1 = Like, 0 = Dislike).'),
    'field' => [
      'id' => 'boolean', // Use boolean field handler for better display
      'type' => 'yes-no', // Display options
      // 'type_custom_true' => t('Like'), // Custom text (optional)
      // 'type_custom_false' => t('Dislike'), // Custom text (optional)
    ],
    'sort' => ['id' => 'standard'],
    'filter' => [
      'id' => 'boolean', // Use boolean filter
      'label' => t('Action is Like'), // Label for the checkbox filter
      'type' => 'yes-no',
      'use_equal' => TRUE, // Filter where action = 1 (checked) or action = 0 (unchecked)
    ],
    'argument' => ['id' => 'numeric'], // Can argue by 1 or 0
  ];

  // Created timestamp field.
  $data['user_match_actions']['created'] = [
    'title' => t('Action Date'),
    'help' => t('When the like/dislike action was recorded.'),
    'field' => ['id' => 'date'],
    'sort' => ['id' => 'date'],
    'filter' => ['id' => 'date'],
  ];

  // --- New Filter Definition ---
  $data['user_match_actions']['exclude_mutual_matches'] = [
    'title' => t('Exclude Mutual Matches'),
    'help' => t('If checked, exclude actions where the other user has also liked the first user back.'),
    'filter' => [
      // **FIX:** Use the custom plugin ID instead of 'boolean'
      'id' => 'user_match_exclude_mutual_matches',
      'label' => t('Exclude if matched'), // Label for the checkbox in Views UI
      'type' => 'yes-no', // Still use yes-no for UI, but handler controls logic
      'use_equal' => TRUE, // We only act when the filter value is 1 (checked)
      // Specify our custom handler class
      'handler' => 'Drupal\user_match\Plugin\views\filter\ExcludeMutualMatches',
    ],
  ];


  // --- Additions to users_field_data Table ---
  // Allow joining FROM 'users_field_data' TO 'user_match_actions'

  // Relationship: Actions performed BY the user
  $data['users_field_data']['actions_performed_by_user'] = [
    'title' => t('User Match Actions Performed'),
    'help' => t('Relate to actions this user has performed on others.'),
    'group' => t('User Match'), // Group under User Match category
    'relationship' => [
      'id' => 'standard',
      'base' => 'user_match_actions', // Target table
      'base field' => 'liker_uid',    // Target field
      'field' => 'uid',              // Field on this table (users_field_data)
      'label' => t('Actions by this user'),
    ],
  ];

  // Relationship: Actions received BY the user
  $data['users_field_data']['actions_received_by_user'] = [
    'title' => t('User Match Actions Received'),
    'help' => t('Relate to actions performed on this user by others.'),
    'group' => t('User Match'), // Group under User Match category
    'relationship' => [
      'id' => 'standard',
      'base' => 'user_match_actions', // Target table
      'base field' => 'liked_uid',    // Target field
      'field' => 'uid',              // Field on this table (users_field_data)
      'label' => t('Actions on this user'),
    ],
  ];

  return $data;
}
