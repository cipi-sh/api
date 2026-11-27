<?php

namespace CipiApi\Services;

/** The server's Cipi is older than what `cipi sync` needs for the panel: a 503. */
class SyncUnsupportedException extends \RuntimeException {}
