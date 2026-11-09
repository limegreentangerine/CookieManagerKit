<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use Concrete\Core\Page\Controller\DashboardPageController;
use CookieManagerKit\Support\{StatsRange, StatsReport, StatsStore};

/**
 * Read-only: it changes nothing, so it has no save action and no CSRF surface. The date range comes from the query
 * string and is only ever used after StatsRange has turned it into two real dates.
 */
class Statistics extends DashboardPageController
{
    public function view()
    {
        $range = StatsRange::parse($this->request->query->get('from'), $this->request->query->get('to'), gmdate('Y-m-d'));

        $this->set('range', $range);
        $this->set('report', StatsReport::summarise(StatsStore::rows($range['from'], $range['to'])));
    }
}
