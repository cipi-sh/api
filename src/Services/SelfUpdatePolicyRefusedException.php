<?php

namespace CipiApi\Services;

/** A value the update policy does not take, or a signer whose fingerprint was not confirmed: a 422. */
class SelfUpdatePolicyRefusedException extends \RuntimeException {}
