<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A change to a user account that the business rules refuse (last active Admin,
 * changing your own role, ...). The message is written for the person using the
 * screen and is safe to show as is.
 */
class AccountRuleViolation extends RuntimeException {}
