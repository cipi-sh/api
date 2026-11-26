<?php

namespace CipiApi\Services;

/** The page cache was not changed, for a reason the caller can act on: a Node app, a value, nginx's own verdict. */
class PageCacheRefusedException extends \RuntimeException {}
