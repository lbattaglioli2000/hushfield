<?php

namespace App\Domain\World;

/**
 * The client-server contract version. Every broadcast, snapshot, and world JSON
 * response carries it; clients on an older protocol are asked to reload.
 *
 * Bump whenever a payload changes shape. Must match resources/js/game/protocol.ts.
 */
final class Protocol
{
    public const int VERSION = 1;
}
