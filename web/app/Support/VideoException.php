<?php

namespace App\Support;

use RuntimeException;

/** A video can't be added: video isn't switched on, or the studio's minutes are used up. */
class VideoException extends RuntimeException {}
