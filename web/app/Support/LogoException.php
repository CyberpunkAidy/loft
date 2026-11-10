<?php

namespace App\Support;

use RuntimeException;

/** A logo upload Loft won't use (not an image it accepts, too small, too large). The message is for staff. */
class LogoException extends RuntimeException {}
