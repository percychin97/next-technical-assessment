<?php

namespace App\Exceptions;

/** Thrown when a reservation has expired (maps to HTTP 409). */
class ReservationExpiredException extends ConflictException {}
