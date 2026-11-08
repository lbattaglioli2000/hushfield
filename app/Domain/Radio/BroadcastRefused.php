<?php

namespace App\Domain\Radio;

use RuntimeException;

/**
 * Why a recording can't go on WHSH's air, in words for the staff member who
 * uploaded it.
 */
final class BroadcastRefused extends RuntimeException {}
