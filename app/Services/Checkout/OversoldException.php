<?php

namespace App\Services\Checkout;

use RuntimeException;

/** Internal: stock ran out between hold and payment; rolls back the confirmation transaction. */
final class OversoldException extends RuntimeException {}
