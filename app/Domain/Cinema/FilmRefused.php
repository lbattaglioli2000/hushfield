<?php

namespace App\Domain\Cinema;

use RuntimeException;

/**
 * Why a video can't go on the VFA's programme, in words for the staff member
 * who tried to queue it.
 */
final class FilmRefused extends RuntimeException {}
