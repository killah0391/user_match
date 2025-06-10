<?php

namespace Drupal\user_match\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Checks that the submitted value is a date that is at least 18 years in the past.
 *
 * @Constraint(
 * id = "Age",
 * label = @Translation("Age", context = "Validation"),
 * type = "string"
 * )
 */
class AgeConstraint extends Constraint
{
  public $notOldEnough = 'You must be at least 18 years old.';
}
