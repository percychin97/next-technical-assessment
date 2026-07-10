<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when a domain rule is violated (maps to HTTP 422). */
class DomainException extends RuntimeException {}
