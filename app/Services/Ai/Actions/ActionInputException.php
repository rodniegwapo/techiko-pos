<?php

namespace App\Services\Ai\Actions;

use RuntimeException;

/** A proposed change the assistant can't offer as given (missing field, unknown product…); the message goes back to the AI. */
class ActionInputException extends RuntimeException {}
