<?php

namespace App\Domain\Assets;

use RuntimeException;

/** A source file the build refuses, with a message the artist can act on. */
final class InvalidAssetException extends RuntimeException {}
