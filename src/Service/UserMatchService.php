<?php

namespace Drupal\user_match\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\user\UserInterface;
use Drupal\Core\Database\Query\Condition; // Keep this if needed elsewhere, not strictly for findMutualMatches

/**
 * Service for handling user matching logic.
 */
class UserMatchService
{

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The user storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $userStorage;

  /**
   * Constructs a new UserMatchService object.
   *
   * @param \Drupal\Core\Database\Connection $database
   * The database connection.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   * The current user account.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * The entity type manager.
   */
  public function __construct(Connection $database, AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager)
  {
    $this->database = $database;
    $this->currentUser = $current_user;
    $this->userStorage = $entity_type_manager->getStorage('user');
  }

  /**
   * Finds the next N potential user matches for the current user with reciprocal preference.
   *
   * @param int $limit
   * The maximum number of users to return.
   * @param array $exclude_uids_extra
   * Additional UIDs to exclude.
   *
   * @return \Drupal\user\UserInterface[]
   * An array of user entities or an empty array.
   */
  public function findNextPotentialMatches(int $limit = 5, array $exclude_uids_extra = []): array
  {
    $currentUserId = $this->currentUser->id();
    if (!$currentUserId) {
      return []; // Anonymous users cannot match.
    }

    $currentUserEntity = $this->userStorage->load($currentUserId);
    if (!$currentUserEntity) {
      return []; // Cannot load current user.
    }

    $preferredGenders = [];
    $genderPreferenceFieldName = 'field_gender_preference';
    $userOwnGenderFieldName = 'field_gender'; // Assuming 'field_gender' holds the user's own gender.

    // 1. Get current user's gender preferences.
    if ($currentUserEntity->hasField($genderPreferenceFieldName) && !$currentUserEntity->get($genderPreferenceFieldName)->isEmpty()) {
      $preference_items = $currentUserEntity->get($genderPreferenceFieldName)->getValue();
      foreach ($preference_items as $item) {
        $preferredGenders[] = $item['value'];
      }
    }

    // 2. Get current user's own gender.
    $currentUserOwnGender = NULL;
    if ($currentUserEntity->hasField($userOwnGenderFieldName) && !$currentUserEntity->get($userOwnGenderFieldName)->isEmpty()) {
      $currentUserOwnGender = $currentUserEntity->get($userOwnGenderFieldName)->value;
    }

    // 3. If either current user's gender or preference is missing, return empty.
    //    Reciprocal matching requires both pieces of information.
    if (empty($preferredGenders) || empty($currentUserOwnGender)) {
      \Drupal::logger('user_match')->warning('User @uid cannot match due to missing own gender (@gender_status) or preferences (@pref_status).', [
        '@uid' => $currentUserId,
        '@gender_status' => empty($currentUserOwnGender) ? 'missing' : 'present',
        '@pref_status' => empty($preferredGenders) ? 'missing' : 'present',
      ]);
      return [];
    }

    // 4. Find users already interacted with.
    $query_interacted = $this->database->select('user_match_actions', 'uma');
    $query_interacted->fields('uma', ['liked_uid']);
    $query_interacted->condition('uma.liker_uid', $currentUserId);
    $interacted_uids = $query_interacted->execute()->fetchCol();

    // 5. Build the list of users to exclude.
    $exclude_uids = array_merge([0, 1, $currentUserId], $interacted_uids, $exclude_uids_extra);
    $exclude_uids = array_unique(array_filter($exclude_uids));

    // 6. Build the EntityQuery.
    $query = $this->userStorage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1) // Active users only.
      ->condition('uid', $exclude_uids, 'NOT IN'); // Exclude specified UIDs.

    // 7. Condition A: Potential match's gender must be one the current user prefers.
    $query->condition($userOwnGenderFieldName, $preferredGenders, 'IN');

    // 8. Condition B: Potential match's preference must include the current user's gender.
    //    EntityQuery handles multi-value fields with '=' as if it were 'CONTAINS'.
    $query->condition($genderPreferenceFieldName, $currentUserOwnGender, '=');

    // 9. Add random sort.
    if ($this->database->driver() == 'mysql') {
      $query->addTag('random_sort_mysql');
    } elseif ($this->database->driver() == 'pgsql') {
      $query->addTag('random_sort_pgsql');
    }

    // 10. Fetch users.
    $query->range(0, $limit);
    $result_uids = $query->execute();

    if (empty($result_uids)) {
      return [];
    }

    return $this->userStorage->loadMultiple($result_uids);
  }

  /**
   * Finds the next potential user match for the current user.
   *
   * Excludes the current user, uid 0, uid 1, users already interacted with,
   * and considers gender preferences RECIPROCALLY.
   *
   * @return \Drupal\user\UserInterface|null
   * The next user entity or NULL if no suitable user is found.
   */
  public function findNextPotentialMatch(): ?UserInterface
  {
    // This method now simply calls the plural version with a limit of 1.
    $matches = $this->findNextPotentialMatches(1);
    return $matches ? reset($matches) : NULL;
  }

  /**
   * Records a like or dislike action.
   */
  public function recordAction(int $likedUid, int $action): bool
  {
    $currentUserId = $this->currentUser->id();
    if (!$currentUserId || $currentUserId == $likedUid) {
      return FALSE;
    }
    $liked_user = $this->userStorage->load($likedUid);
    if (!$liked_user || !$liked_user->isActive()) {
      return FALSE;
    }
    try {
      $this->database->merge('user_match_actions')
        ->keys([
          'liker_uid' => $currentUserId,
          'liked_uid' => $likedUid,
        ])
        ->fields([
          'action' => $action,
          'created' => \Drupal::time()->getRequestTime(),
        ])
        ->execute();
      return TRUE;
    } catch (\Exception $e) {
      \Drupal::logger('user_match')->error('Failed to record user match action between @uid1 and @uid2: @message', [
        '@uid1' => $currentUserId,
        '@uid2' => $likedUid,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Checks if a match exists between two users (mutual like).
   */
  public function checkForMatch(int $user1_uid, int $user2_uid): bool
  {
    $user1_likes_user2 = (bool) $this->database->select('user_match_actions', 'uma1')
      ->fields('uma1', ['action_id'])
      ->condition('liker_uid', $user1_uid)
      ->condition('liked_uid', $user2_uid)
      ->condition('action', 1)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if (!$user1_likes_user2) {
      return FALSE;
    }

    $user2_likes_user1 = (bool) $this->database->select('user_match_actions', 'uma2')
      ->fields('uma2', ['action_id'])
      ->condition('liker_uid', $user2_uid)
      ->condition('liked_uid', $user1_uid)
      ->condition('action', 1)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return $user2_likes_user1;
  }

  /**
   * Finds all users who have mutually liked the given user.
   */
  public function findMutualMatches(int $userId): array
  {
    if (!$userId) {
      return [];
    }

    $query_i_liked = $this->database->select('user_match_actions', 'uma1');
    $query_i_liked->fields('uma1', ['liked_uid']);
    $query_i_liked->condition('uma1.liker_uid', $userId);
    $query_i_liked->condition('uma1.action', 1);
    $users_i_liked_uids = $query_i_liked->execute()->fetchCol();

    if (empty($users_i_liked_uids)) {
      return [];
    }

    $query_they_liked_me = $this->database->select('user_match_actions', 'uma2');
    $query_they_liked_me->fields('uma2', ['liker_uid']);
    $query_they_liked_me->condition('uma2.liked_uid', $userId);
    $query_they_liked_me->condition('uma2.action', 1);
    $query_they_liked_me->condition('uma2.liker_uid', $users_i_liked_uids, 'IN');

    $matched_uids = $query_they_liked_me->execute()->fetchCol();

    if (empty($matched_uids)) {
      return [];
    }

    return $this->userStorage->loadMultiple($matched_uids);
  }
}
