<?php

// Declare the namespace for the controller.
namespace Drupal\user_match\Controller;

// Import necessary classes.
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Ajax\InvokeCommand; // To call JS functions
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Ajax\OpenModalDialogCommand; // <-- IMPORT THIS
use Symfony\Component\HttpFoundation\Request; // For handling requests
use Drupal\Core\Ajax\AjaxResponse; // Class for creating AJAX responses.
use Symfony\Component\HttpFoundation\JsonResponse; // For AJAX responses
use Drupal\Core\Ajax\HtmlCommand; // AJAX command to replace HTML content.
// use Drupal\user_match\Ajax\ShowUserMatchModalCommand; // NO LONGER NEEDED
use Drupal\Core\Ajax\ReplaceCommand; // AJAX command to replace an element.
use Drupal\Core\Session\AccountInterface; // Interface for the current user.
use Drupal\Core\Messenger\MessengerInterface; // Service for displaying messages.
use Drupal\Core\Render\RendererInterface; // Service for rendering arrays to HTML.
use Drupal\match_toasts\Ajax\ShowBootstrapToastsCommand; // Import the Toast Command
use Drupal\Core\Cache\CacheTagsInvalidatorInterface; // Import Cache Tags Invalidator
use Drupal\user_match\Service\UserMatchService; // Custom service for matching logic.

/**
 * Controller for handling the user matching page, actions, and AJAX requests.
 */
class UserMatchController extends ControllerBase
{

  /**
   * The user match service instance.
   *
   * @var \Drupal\user_match\Service\UserMatchService
   */
  protected $userMatchService;

  /**
   * The current user account instance.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The renderer service instance.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected $cacheTagsInvalidator;

  /**
   * Constructs a new UserMatchController object.
   *
   * Injects required services via dependency injection.
   *
   * @param \Drupal\user_match\Service\UserMatchService $user_match_service
   * The user match service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   * The current user service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * The messenger service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   * The renderer service.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cache_tags_invalidator
   * The cache tags invalidator service.
   */
  public function __construct(
    UserMatchService $user_match_service,
    AccountInterface $current_user,
    MessengerInterface $messenger,
    RendererInterface $renderer,
    CacheTagsInvalidatorInterface $cache_tags_invalidator
  ) {
    $this->userMatchService = $user_match_service;
    $this->currentUser = $current_user;
    $this->setMessenger($messenger);
    $this->renderer = $renderer;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
  }

  /**
   * {@inheritdoc}
   * Factory method for creating the controller instance.
   *
   * Allows Drupal's service container to inject the required services.
   */
  public static function create(ContainerInterface $container)
  {
    // Instantiate the controller, injecting services retrieved from the container.
    return new static(
      $container->get('user_match.service'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('renderer'),
      $container->get('cache_tags.invalidator')
    );
  }

  // In UserMatchController.php -> displayPage()

  public function displayPage()
  {
    // Fetch user data from the service.
    $potential_match_data = $this->userMatchService->findNextPotentialMatches(5);

    $potential_match_users = [];
    // Ensure you have data to process.
    if (!empty($potential_match_data)) {
      // Create an array of fully-loaded User objects.
      foreach ($potential_match_data as $user_data) {
        // Load the full user entity using its ID.
        $user_obj = User::load($user_data->id());
        if ($user_obj) {
          $potential_match_users[] = $user_obj;
        }
      }
    }

    // The rest of the function remains the same...
    $build = [];
    $message = NULL;

    if (empty($potential_match_users)) {
      $message = $this->t('No more users to match with at the moment. Check back later!');
    }

    $config = \Drupal::config('field.field.user.user.user_picture');
    $default_image = $config->get('settings.default_image');
    $file = \Drupal::service('entity.repository')
      ->loadEntityByUuid('file', $default_image['uuid']);
    $picture_uri = $file;
    $picture_url = \Drupal::service('file_url_generator')->generateAbsoluteString($picture_uri->getFileUri());

    $build['content'] = [
      '#theme' => 'user_match_page_deck',
      '#potential_match_users' => $potential_match_users, // Pass the array of full objects
      '#message' => $message,
      '#picture_uri' => $picture_uri,
      '#cache' => [
        'contexts' => ['user'],
        'max-age' => 0,
      ],
      '#attached' => [
        'library' => [
          'user_match/user-match-modal',
          'user_match/user-match-styles',
          'user_match/user-match-swipe',
        ],
      ],
    ];

    return $build;
  }

  /**
   * Prepares an array of AJAX command objects for JsonResponse.
   *
   * @param \Drupal\Core\Ajax\CommandInterface[] $commands_objects
   *   An array of AJAX command objects.
   *
   * @return array
   *   An array of command arrays suitable for JsonResponse.
   */
  protected function prepareAjaxCommands(array $commands_objects): array {
    $commands_array = [];
    foreach ($commands_objects as $command) {
      if ($command instanceof \Drupal\Core\Ajax\CommandInterface) {
        $commands_array[] = $command->render();
      }
    }
    return $commands_array;
  }

  /**
   * Handles the 'like' action, now with AJAX support.
   *
   * @param \Drupal\user\UserInterface $user_liked
   * The user entity being liked.
   * @param \Symfony\Component\HttpFoundation\Request $request
   * The current request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\JsonResponse
   * A response object.
   */
  public function likeUser(UserInterface $user_liked, Request $request)
  {
    $currentUserId = $this->currentUser->id();
    $likedUserId = $user_liked->id();

    $success = $this->userMatchService->recordAction($likedUserId, 1);
    $is_match = false;

    if ($success) {
      $is_match = $this->userMatchService->checkForMatch($currentUserId, $likedUserId);
      $tags_to_invalidate = [
        'user:' . $currentUserId,
        'user:' . $likedUserId,
        'user_match_actions:' . $currentUserId,
        'user_match_actions:' . $likedUserId,
      ];
      $this->cacheTagsInvalidator->invalidateTags($tags_to_invalidate);

      if ($is_match) {
        \Drupal::logger('user_match')->notice('New match between user @uid1 and user @uid2.', [
          '@uid1' => $currentUserId,
          '@uid2' => $likedUserId,
        ]);
      }
    }

    // Check if it's an AJAX request (from swipe or AJAX button).
    if ($request->isXmlHttpRequest()) {
      $commands = [];
      if ($success) {
        $toast_message_text = $is_match ?
          $this->t('It\'s a match with @username!', ['@username' => $user_liked->getDisplayName()]) :
          $this->t('You liked @username.', ['@username' => $user_liked->getDisplayName()]);
        $toast_title_text = $is_match ? $this->t('It\'s a Match!') : $this->t('Action Recorded');

        $commands[] = new ShowBootstrapToastsCommand(
          $toast_message_text->render(),
          $toast_title_text->render(),
          'text-bg-success', // toast_class
          $is_match ? $this->t('Match!')->render() : $this->t('Success')->render() // type_label
        );
        $commands[] = new InvokeCommand(NULL, 'userMatchHandleSwipeResponse', [$is_match, FALSE]);
      } else {
        $commands[] = new ShowBootstrapToastsCommand(
          $this->t('Could not record your like. Please try again.')->render(),
          $this->t('Error')->render(),
          'text-bg-danger', // toast_class
          $this->t('Error')->render() // type_label
        );
        $commands[] = new InvokeCommand(NULL, 'userMatchHandleSwipeResponse', [FALSE, TRUE]);
      }
      return new JsonResponse(['commands' => $this->prepareAjaxCommands($commands)]);

    } else {
      // Fallback for non-AJAX: Set message and redirect.
      if ($success) {
        $this->messenger()->addStatus($is_match ? $this->t('It\'s a match with @username!', ['@username' => $user_liked->getAccountName()]) : $this->t('You liked @username.', ['@username' => $user_liked->getAccountName()]));
      } else {
        $this->messenger()->addError($this->t('Could not record your action. Please try again.'));
      }
      return new RedirectResponse(Url::fromRoute('user_match.display_page')->toString());
    }
  }

  /**
   * Handles the 'dislike' action, now with AJAX support.
   *
   * @param \Drupal\user\UserInterface $user_disliked
   * The user entity being disliked.
   * @param \Symfony\Component\HttpFoundation\Request $request
   * The current request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\JsonResponse
   * A response object.
   */
  public function dislikeUser(UserInterface $user_disliked, Request $request)
  {
    $currentUserId = $this->currentUser->id();
    $dislikedUserId = $user_disliked->id();

    $success = $this->userMatchService->recordAction($dislikedUserId, 0);

    if ($success) {
      $tags_to_invalidate = [
        'user:' . $currentUserId,
        'user:' . $dislikedUserId,
        'user_match_actions:' . $currentUserId,
        'user_match_actions:' . $dislikedUserId,
      ];
      $this->cacheTagsInvalidator->invalidateTags($tags_to_invalidate);
    }

    // Check if it's an AJAX request.
    if ($request->isXmlHttpRequest()) {
      $commands = [];
      if ($success) {
        $commands[] = new ShowBootstrapToastsCommand(
          $this->t('You disliked @username.', ['@username' => $user_disliked->getDisplayName()])->render(),
          $this->t('Action Recorded')->render(),
          'text-bg-secondary', // toast_class
          $this->t('Info')->render() // type_label
        );
        $commands[] = new InvokeCommand(NULL, 'userMatchHandleSwipeResponse', [FALSE, FALSE]);
      } else {
        $commands[] = new ShowBootstrapToastsCommand(
          $this->t('Could not record your dislike. Please try again.')->render(),
          $this->t('Error')->render(),
          'text-bg-danger', // toast_class
          $this->t('Error')->render() // type_label
        );
        $commands[] = new InvokeCommand(NULL, 'userMatchHandleSwipeResponse', [FALSE, TRUE]);
      }
      return new JsonResponse(['commands' => $this->prepareAjaxCommands($commands)]);
    } else {
      // Fallback for non-AJAX: Set message and redirect.
      if ($success) {
        $this->messenger()->addStatus($this->t('You disliked @username.', ['@username' => $user_disliked->getAccountName()]));
      } else {
        $this->messenger()->addError($this->t('Could not record your action. Please try again.'));
      }
      return new RedirectResponse(Url::fromRoute('user_match.display_page')->toString());
    }
  }

  /**
   * AJAX callback to get user details HTML for the Drupal modal.
   *
   * @param \Drupal\user\UserInterface $user_to_view
   * The user entity whose details are being viewed.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   * An AJAX response object containing commands.
   */
  public function getUserDetailsAjax(UserInterface $user_to_view)
  {
    $response = new AjaxResponse();
    $modal_content_render_array = [
      '#theme' => 'user_match_details_modal',
      '#user_profile' => $user_to_view,
      // Ensure the modal theme gets cache contexts if it displays user data.
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['user:' . $user_to_view->id()],
      ],
    ];
    $content_html = $this->renderer->renderRoot($modal_content_render_array);
    $modal_title = $this->t('@username\'s Details', ['@username' => $user_to_view->getDisplayName()]);

    // Use Drupal's OpenModalDialogCommand instead of custom one
    $response->addCommand(new OpenModalDialogCommand(
      $modal_title,
      $content_html,
      [
        'width' => '70%', // Adjust width as needed
        'modal' => TRUE,
        'dialogClass' => 'user-match-details-dialog', // Add a class for styling
      ]
    ));

    return $response;
  }

  /**
   * Displays the page listing mutual matches for the current user (/my-matches).
   *
   * @return array
   * A render array for the matches page.
   */
  public function displayMatchesPage()
  {
    $currentUserId = $this->currentUser->id();
    $matches = [];
    if ($currentUserId) {
      $matches = $this->userMatchService->findMutualMatches($currentUserId);
    }

    $build = [];
    $build['content'] = [
      '#theme' => 'user_matches_list_page',
      '#matches' => $matches,
      '#cache' => [
        'tags' => [
          'user:' . $currentUserId,
          'user_match_actions:' . $currentUserId,
        ],
        'contexts' => ['user'],
      ],
    ];

    return $build;
  }

  /**
   * AJAX callback to fetch the HTML for the next user card.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   * The current request, containing UIDs to exclude.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   * A JSON response containing the new card's HTML or an empty status.
   */
  public function fetchNextUserAjax(Request $request)
  {
    $content = $request->getContent();
    $data = json_decode($content, TRUE);
    $exclude_uids = $data['exclude_uids'] ?? [];

    $new_users = $this->userMatchService->findNextPotentialMatches(1, $exclude_uids);

    if (!empty($new_users)) {
      $new_user = reset($new_users);

      // Check if we got a *valid* user object.
      if ($new_user instanceof UserInterface) {
        \Drupal::logger('user_match')->info('AJAX fetch: Found user @uid. Attempting to render.', ['@uid' => $new_user->id()]);

        $card_render_array = [
          '#theme' => 'user_match_single_card',
          '#user' => $new_user,
        ];
        $card_html = $this->renderer->renderRoot($card_render_array);

        if (!empty(trim(strip_tags($card_html, '<div><img><a><i><h5><button>')))) {
          return new JsonResponse(['status' => 'success', 'html' => $card_html]);
        } else {
          \Drupal::logger('user_match')->warning('AJAX fetch: Rendered HTML is empty for user @uid. Check template and user data.', ['@uid' => $new_user->id()]);
          return new JsonResponse(['status' => 'empty']);
        }
      } else {
        \Drupal::logger('user_match')->error('AJAX fetch: Service returned non-user object.');
        return new JsonResponse(['status' => 'error', 'message' => 'Invalid user data.']);
      }
    }

    \Drupal::logger('user_match')->info('AJAX fetch: Service found no more users.');
    return new JsonResponse(['status' => 'empty']);
  }

  /**
   * Renders the user privacy settings form.
   *
   * @param \Drupal\user\UserInterface $user
   * The user account.
   *
   * @return array
   * A render array representing the user edit form.
   */
  public function privacySettingsFormMode(UserInterface $user) {
    $form_builder = $this->entityTypeManager()->getFormObject('user', 'privacy_settings');
    $form_builder->setEntity($user);
    return $this->formBuilder()->getForm($form_builder);
  }
}
