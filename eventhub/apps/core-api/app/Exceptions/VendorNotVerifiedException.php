<?php

namespace App\Exceptions;

/** Thrown when a vendor is not verified and attempts to publish an event (maps to HTTP 403). */
class VendorNotVerifiedException extends DomainException {}
