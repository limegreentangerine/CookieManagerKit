<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use CookieManagerKit\Entity\Settings;
use CookieManagerKit\Support\Catalog;
use Symfony\Component\HttpFoundation\Response;
use Concrete\Core\Page\Controller\DashboardPageController;
use CookieManagerKit\Consent\{ConsentLogCsv, ConsentLogFilter, ConsentTrigger, DoctrineConsentLogReader};

/**
 * The audit log. Listing and filtering read only (filters come from the query string and are vetted by
 * ConsentLogFilter before use); the export is a POST with a CSRF token because the log is personal-data-adjacent
 * and a GET link could be triggered by a third-party page. Nothing here changes data.
 */
class ConsentLog extends DashboardPageController
{
    private const URL = '/dashboard/cookie_manager/consent_log';
    private const PER_PAGE = 50;

    public $helpers = [
        'form',
        'concrete/ui',
    ];

    private function reader(): DoctrineConsentLogReader
    {
        return new DoctrineConsentLogReader($this->entityManager->getConnection());
    }

    public function view()
    {
        $query = $this->request->query;
        $filter = ConsentLogFilter::parse($query->get('from'), $query->get('to'), $query->get('trigger'), $query->get('version'), $query->get('category'), $query->get('id'), gmdate('Y-m-d'));
        $page = max(1, min(100000, (int) $query->get('page')));
        $rows = [];
        $total = 0;

        try {
            $result = $this->reader()->page($filter, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
            $rows = $result['rows'];
            $total = $result['total'];
        } catch (\Throwable) {
            // The tables do not exist until the package Update has run; show an empty log rather than an error.
        }

        $this->set('filter', $filter);
        $this->set('rows', $rows);
        $this->set('total', $total);
        $this->set('page', $page);
        $this->set('perPage', self::PER_PAGE);
        $this->set('triggers', ConsentTrigger::ALL);
        $this->set('categories', Catalog::handles());
        $this->set('retentionMonths', Settings::get()->getConsentLogRetentionMonths());
        $this->set('capped', $total > ConsentLogCsv::MAX_ROWS);
    }

    public function export()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $filter = ConsentLogFilter::parse(
            $this->post('from'),
            $this->post('to'),
            $this->post('trigger'),
            $this->post('version'),
            $this->post('category'),
            $this->post('id'),
            gmdate('Y-m-d'),
        );

        $rows = $this->reader()->export($filter, ConsentLogCsv::MAX_ROWS);

        return new Response(ConsentLogCsv::render($rows), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="consent-log-' . gmdate('Ymd') . '.csv"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }
}
