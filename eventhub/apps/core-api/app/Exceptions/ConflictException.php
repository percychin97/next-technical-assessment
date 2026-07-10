<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when there is a resource conflict, e.g. duplicate idempotency key (maps to HTTP 409). */
class ConflictException extends RuntimeException {}
