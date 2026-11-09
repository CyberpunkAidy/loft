<?php

namespace App\Support;

use RuntimeException;

/** A sale that can't go through as it stands (out of stock, a plan with no member…). The message is for staff. */
class CheckoutException extends RuntimeException {}
