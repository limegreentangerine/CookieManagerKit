<?php

namespace CookieManagerKit\Log;

use ClassKit\Log\Logger;

class CookieManagerLogger extends Logger
{
    public function __construct()
    {
        parent::__construct('cookie_manager');
    }
}
