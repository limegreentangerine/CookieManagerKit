<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard;

use Concrete\Core\Page\Controller\DashboardPageController;

class CookieManager extends DashboardPageController
{
    public function view()
    {
        $this->buildRedirect('/dashboard/cookie_manager/banner')->send();
    }
}
