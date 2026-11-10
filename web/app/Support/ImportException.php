<?php

namespace App\Support;

use RuntimeException;

/** An upload that can't be imported as it stands (not a CSV, empty, too big). The message is for staff. */
class ImportException extends RuntimeException {}
