/**
 * @file
 * Handles Drupal modal interactions for user details on the user match page.
 *
 * Ensures trigger links have the necessary classes and attributes
 * for Drupal's AJAX Dialog system.
 */
(function ($, Drupal, once) {
  'use strict';

  /**
   * Drupal behavior to initialize the modal trigger links.
   */
  Drupal.behaviors.userMatchModalSetup = {
    attach: function (context, settings) {
      // Find all links meant to trigger the modal within the current context.
      const modalTriggers = once(
        'user-match-modal-trigger',
        'a.user-details-modal-trigger',
        context
      );

      // Process each trigger link found.
      modalTriggers.forEach(function (triggerElement) {
        const $trigger = $(triggerElement);

        console.log("Setting up Drupal modal for:", triggerElement.href);

        // Add 'use-ajax' to make Drupal's AJAX system aware of it.
        $trigger.addClass('use-ajax');

        // Specify 'modal' as the dialog type.
        $trigger.attr('data-dialog-type', 'modal');

        // Add dialog options for width and a custom class.
        $trigger.attr(
          'data-dialog-options',
          JSON.stringify({
            width: '70%',
            dialogClass: 'user-match-details-dialog',
          })
        );

        // Remove any old manual AJAX bindings if they exist.
        // This is crucial if a previous version attached manual handlers.
        // We rely on Drupal.attachBehaviors to handle 'use-ajax'.
        $trigger.off('click.drupal-ajax'); // Remove potential old core listeners before re-attaching

      });

      // After adding classes/attributes, ensure Drupal's AJAX system
      // scans the context and attaches its own handlers to 'use-ajax' links.
      // This is important, especially for content loaded via AJAX.
      // We process only the *new* triggers via `once`, but `attachBehaviors`
      // needs to run so Drupal can find them.
      // Drupal.attachBehaviors(context, settings); // This might cause infinite loops if not careful.
      // It's generally better to rely on Drupal's own calls, especially the one
      // in user-match-swipe.js after adding a card. If modals don't work
      // on initial load, uncommenting this might be needed, but needs testing.
    },
  };

  // Ensure the old custom command handler (if cached/present) doesn't interfere.
  if (Drupal && Drupal.AjaxCommands && Drupal.AjaxCommands.prototype.showUserMatchModal) {
    console.log("DEBUG: Removing old showUserMatchModal command.");
    delete Drupal.AjaxCommands.prototype.showUserMatchModal;
  }

})(jQuery, Drupal, once);
