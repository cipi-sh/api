<?php

namespace CipiApi\Services;

/** The directives were refused — by the API's subset or by `nginx -t` — and nothing changed. */
class NginxConfigRefusedException extends \RuntimeException
{
    /** @param  list<string>  $reasons */
    public function __construct(protected array $reasons)
    {
        parent::__construct($reasons[0] ?? 'nginx refused the directives');
    }

    /** @return list<string> */
    public function reasons(): array
    {
        return $this->reasons;
    }
}
