<?php

namespace Drupal\user_match\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the Age constraint.
 */
class AgeConstraintValidator extends ConstraintValidator
{

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint)
  {
    if (isset($value->value)) {
      $birthday = new \DateTime($value->value);
      $now = new \DateTime();
      $interval = $now->diff($birthday);
      $age = $interval->y;

      if ($age < 18) {
        $this->context->addViolation($constraint->notOldEnough);
      }
    }
  }
}
