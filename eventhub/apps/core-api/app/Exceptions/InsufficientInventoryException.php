<?php

namespace App\Exceptions;

/** Thrown when inventory is insufficient for a reservation (maps to HTTP 409). */
class InsufficientInventoryException extends ConflictException {}
