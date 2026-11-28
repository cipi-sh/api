<?php

namespace CipiApi\Services;

/** The server's Cipi has no `cipi app env --raw|--stdin` yet: 501, so a panel falls back. */
class EnvRawUnsupportedException extends \RuntimeException {}
