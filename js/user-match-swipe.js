/**
 * @file
 * Handles swipe gestures and AJAX loading for the card deck (v4 - With Overlays).
 */
(function ($, Drupal, once, Hammer) {
  'use strict';

  console.log('DEBUG: user-match-swipe.js (v4) loaded.');

  // Function to update visual stack classes
  function updateStackClasses($stack) {
    console.log('DEBUG: Updating stack classes.');
    const $cards = $stack.find(
      '.user-match-card:not(.is-swiped-left):not(.is-swiped-right)'
    );
    $cards.removeClass('is-active is-next is-after-next');
    $cards.each(function (index) {
      if (index === $cards.length - 1) $(this).addClass('is-active');
      else if (index === $cards.length - 2) $(this).addClass('is-next');
      else if (index === $cards.length - 3) $(this).addClass('is-after-next');
    });
    console.log(
      'DEBUG: Active card is now:',
      $stack.find('.is-active').data('uid')
    );
    if ($cards.length === 0) $('#user-match-no-more-cards').show();
  }

  // Function to fetch a new card via AJAX
  function fetchNewCard() {
    console.log('DEBUG: Attempting to fetch new card...');
    const $stack = $('.user-match-card-stack');
    // Get UIDs of *all* cards currently in the DOM.
    // Ensure we only select actual cards, not other elements.
    const currentUids = $stack
      .find('.user-match-card')
      .map(function () {
        return $(this).data('uid');
      })
      .get();
    const fetchUrl = '/user-match/fetch-next';

    console.log('DEBUG: Fetching with excluded UIDs:', currentUids);

    fetch(fetchUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({ exclude_uids: currentUids }),
    })
      .then((response) => {
        if (!response.ok)
          throw new Error('Network response was not ok: ' + response.statusText);
        return response.json();
      })
      .then((data) => {
        console.log('DEBUG: Fetch response received:', data);
        if (data.status === 'success' && data.html) {
          const $newCard = $($.parseHTML(data.html.trim())).filter(
            '.user-match-card'
          );
          console.log('DEBUG: Parsed $newCard length:', $newCard.length);

          if ($newCard.length > 0) {
            $stack.prepend($newCard);
            Drupal.attachBehaviors(document);
            updateStackClasses($stack);
            console.log('DEBUG: New card added.');
          } else {
            console.error(
              'DEBUG: Failed to parse .user-match-card from AJAX HTML!'
            );
          }
        } else {
          console.log('DEBUG: No new card received or status not success.');
          updateStackClasses($stack);
        }
      })
      .catch((error) => console.error('DEBUG: Error fetching new card:', error));
  }

  // Function to handle the swipe action (AJAX + Animation)
  function handleSwipe($card, direction) {
    console.log(
      'DEBUG: handleSwipe called for UID',
      $card.data('uid'),
      'Direction:',
      direction
    );
    const url =
      direction === 'left'
        ? $card.data('dislike-url')
        : $card.data('like-url');
    const swipeClass =
      direction === 'left' ? 'is-swiped-left' : 'is-swiped-right';

    if (!url || !$card.hasClass('is-active')) {
      console.error('DEBUG: Swipe attempted on non-active or no-URL card.');
      return; // Only swipe active cards with URLs.
    }

    // Add swipe class and remove active class
    $card.addClass(swipeClass).removeClass('is-active');

    // Make AJAX call for Like/Dislike
    $.ajax({
      url: url,
      method: 'POST',
      dataType: 'json',
      success: function (response) {
        console.log('DEBUG: Like/Dislike successful:', response.message);
        if (response.match) alert("It's a Match!");
        fetchNewCard(); // Fetch a new card on success
      },
      error: function () {
        console.error('DEBUG: Failed to record action.');
        fetchNewCard(); // Still fetch so deck doesn't get stuck.
      },
    });

    // Update stack visually and REMOVE the card after animation
    setTimeout(function () {
      const $stack = $card.closest('.user-match-card-stack');
      $card.remove(); // Remove the swiped card
      updateStackClasses($stack); // Update classes on the remaining cards
    }, 500); // Delay should roughly match your CSS transition duration
  }

  // New function to be called by InvokeCommand from the controller
  // Handles post-swipe logic like fetching the next card and logging.
  window.userMatchHandleSwipeResponse = function(isMatch, isError = false) {
    console.log('DEBUG: userMatchHandleSwipeResponse called. isMatch:', isMatch, 'isError:', isError);
    if (isMatch) {
      // The toast already announced "It's a match!".
      // You could add further specific JS actions here if needed.
      console.log("DEBUG: Swipe resulted in a match!");
    }
    if (isError) {
      console.error("DEBUG: Swipe action reported an error via toast.");
    }
    // Always fetch a new card after swipe action is processed
    fetchNewCard();
  };

  Drupal.behaviors.userMatchDeck = {
    attach: function (context, settings) {
      const stacks = once('user-match-deck', '.user-match-card-stack', context);

      stacks.forEach(function (stackElement) {
        console.log('DEBUG: Attaching Hammer to stack element.');
        const $stack = $(stackElement);
        updateStackClasses($stack);

        if (typeof Hammer === 'undefined') {
          console.error('DEBUG: Hammer.js is not loaded.');
          return;
        }
        if ($(stackElement).data('hammer-attached')) {
          console.log('DEBUG: Hammer already attached, skipping.');
          return;
        }
        $(stackElement).data('hammer-attached', true);

        const mc = new Hammer.Manager(stackElement);
        mc.add(
          new Hammer.Pan({ direction: Hammer.DIRECTION_HORIZONTAL, threshold: 20 })
        );

        let activeCardElement = null; // Track the card being panned
        const threshold = 100; // Swipe distance threshold

        mc.on('panstart', function (ev) {
          activeCardElement = $stack.find('.is-active').get(0);
          if (
            activeCardElement &&
            ($.contains(activeCardElement, ev.target) ||
              activeCardElement === ev.target)
          ) {
            console.log('DEBUG: Pan started on active card.');
            $(activeCardElement).addClass('is-dragging');
            // Remove transition during drag for direct feedback & smoother overlay updates
            activeCardElement.style.transition = 'none';
          } else {
            console.log('DEBUG: Pan started, but not on active card. Ignoring.');
            activeCardElement = null;
          }
        });

        mc.on('panmove', function (ev) {
          if (activeCardElement) {
            let deltaX = ev.deltaX;
            let rotate = deltaX / 20;
            let progress = Math.min(Math.abs(deltaX) / threshold, 1);
            // Calculate opacity: Start at 0.2 and go up to 1
            let opacity = 0.2 + progress * 0.8;
            opacity = Math.min(opacity, 1); // Clamp at 1

            // Apply transform
            activeCardElement.style.transform =
              'translateX(' + deltaX + 'px) rotate(' + rotate + 'deg)';

            // Apply overlay opacity via CSS variables
            if (deltaX > 0) {
              // Swiping right (Like - Green)
              activeCardElement.style.setProperty('--like-opacity', opacity);
              activeCardElement.style.setProperty('--dislike-opacity', 0);
            } else if (deltaX < 0) {
              // Swiping left (Dislike - Red)
              activeCardElement.style.setProperty('--like-opacity', 0);
              activeCardElement.style.setProperty('--dislike-opacity', opacity);
            } else {
              // Near center, hide both
              activeCardElement.style.setProperty('--like-opacity', 0);
              activeCardElement.style.setProperty('--dislike-opacity', 0);
            }
          }
        });

        mc.on('panend', function (ev) {
          if (activeCardElement) {
            console.log('DEBUG: Pan ended. DeltaX:', ev.deltaX);
            $(activeCardElement).removeClass('is-dragging');
            // Reset transition for snap/swipe animation
            activeCardElement.style.transition = 'transform 0.3s ease-out';
            // Reset opacities before deciding to swipe or snap back
            activeCardElement.style.setProperty('--like-opacity', 0);
            activeCardElement.style.setProperty('--dislike-opacity', 0);


            if (ev.deltaX < -threshold) {
              handleSwipe($(activeCardElement), 'left');
            } else if (ev.deltaX > threshold) {
              handleSwipe($(activeCardElement), 'right');
            } else {
              // Not a swipe, snap back
              console.log('DEBUG: Snapping back.');
              activeCardElement.style.transform = '';
            }
          }
          activeCardElement = null; // ALWAYS reset after pan ends
        });

        // Button Clicks
        $stack
          .off('click.usermatch')
          .on('click.usermatch', '.action-like, .action-dislike', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $card = $(this).closest('.user-match-card');
            if ($card.hasClass('is-active')) {
              handleSwipe(
                $card,
                $(this).hasClass('action-like') ? 'right' : 'left'
              );
            }
          });
        $stack.on('click.usermatch', '.action-details', function (e) {
          e.stopPropagation();
        });
      });
    },
  };
})(jQuery, Drupal, once, window.Hammer);
