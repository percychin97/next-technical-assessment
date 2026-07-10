<?php

namespace App\Exceptions;

/** Thrown when a state transition is invalid (maps to HTTP 422). */
class InvalidStateTransitionException extends DomainException {}
