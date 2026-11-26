<?php

namespace CipiApi\Services;

/** A Valkey change was refused for a reason the caller can act on; nothing changed. */
class ValkeyRefusedException extends \RuntimeException {}
