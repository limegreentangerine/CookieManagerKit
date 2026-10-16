<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use Concrete\Core\Page\Controller\DashboardPageController;
use CookieManagerKit\Support\{Catalog, SiteLocales, Text};
use CookieManagerKit\Entity\{Category, ScanFinding, Service};
use CookieManagerKit\Scan\{ClassificationStore, Review, Services};

/**
 * Review queue. Listing and filtering read only; every action that changes data is a POST with a CSRF token, takes
 * entity IDs that are checked against the database, and redirects to this page. Approving a finding is the only way a
 * scan can change what the banner lists, so it always starts with an admin clicking.
 */
class Findings extends DashboardPageController
{
    private const URL = '/dashboard/cookie_manager/findings';
    private const PER_PAGE = 50;

    public $helpers = [
        'form',
        'concrete/ui',
    ];

    private function id(mixed $value): ?int
    {
        return (is_string($value) || is_int($value)) && preg_match('/^\d{1,9}\z/', (string) $value) === 1 ? (int) $value : null;
    }

    private function finding(mixed $id): ?ScanFinding
    {
        $id = $this->id($id);
        $finding = $id === null ? null : $this->entityManager->getRepository(ScanFinding::class)->find($id);

        return $finding instanceof ScanFinding ? $finding : null;
    }

    private function category(mixed $id): ?Category
    {
        $id = $this->id($id);
        $category = $id === null ? null : $this->entityManager->getRepository(Category::class)->find($id);

        return $category instanceof Category ? $category : null;
    }

    private function service(mixed $id): ?Service
    {
        $id = $this->id($id);
        $service = $id === null ? null : $this->entityManager->getRepository(Service::class)->find($id);

        return $service instanceof Service ? $service : null;
    }

    private function choice(string $key, array $allowed): string
    {
        $value = $this->request->query->get($key);

        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * The category filter: '' (all), 'none' (no suggestion) or the handle of a category that exists.
     */
    private function categoryFilter(): string
    {
        $value = $this->request->query->get('category');

        if (!is_string($value) || $value === '') {
            return '';
        }

        if ($value === 'none') {
            return 'none';
        }

        foreach ($this->entityManager->getRepository(Category::class)->findAll() as $category) {
            if ($category->getHandle() === $value) {
                return $value;
            }
        }

        return '';
    }

    public function view()
    {
        $status = $this->choice('status', ScanFinding::STATUSES);
        $kind = $this->choice('kind', ScanFinding::KINDS);
        $categoryFilter = $this->categoryFilter();
        $criteria = array_filter(['status' => $status, 'kind' => $kind], static fn(string $value): bool => $value !== '');

        if ($categoryFilter === 'none') {
            $criteria['suggestedCategory'] = null;
        } elseif ($categoryFilter !== '') {
            $criteria['suggestedCategory'] = $categoryFilter;
        }

        $repository = $this->entityManager->getRepository(ScanFinding::class);
        $total = $repository->count($criteria);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = $this->id($this->request->query->get('page')) ?? 1;
        $page = max(1, min($pages, $page));

        $default = SiteLocales::defaultLocale();
        $categories = [];

        foreach ($this->entityManager->getRepository(Category::class)->findBy([], ['sortOrder' => 'ASC', 'handle' => 'ASC']) as $category) {
            $content = $category->getContent($default);
            $categories[$category->getHandle()] = ['id' => $category->getID(), 'title' => $content ? $content->getTitle() : $category->getHandle()];
        }

        $this->set('findings', $repository->findBy($criteria, ['lastSeen' => 'DESC', 'id' => 'DESC'], self::PER_PAGE, ($page - 1) * self::PER_PAGE));
        $this->set('status', $status);
        $this->set('kind', $kind);
        $this->set('categoryFilter', $categoryFilter);
        $this->set('page', $page);
        $this->set('pages', $pages);
        $this->set('total', $total);
        $this->set('categories', $categories);
        $this->set('services', $this->entityManager->getRepository(Service::class)->findBy([], ['name' => 'ASC']));
    }

    public function approve()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $finding = $this->finding($this->post('id'));
        $serviceId = $this->post('serviceID');
        $existing = is_string($serviceId) && $serviceId !== '';
        $service = $existing ? $this->service($serviceId) : null;
        // The category only matters when a new service is created.
        $category = $existing ? null : $this->category($this->post('categoryID'));

        if ($finding === null || ($existing && $service === null) || (!$existing && $category === null)) {
            $this->flash('error', t('That finding, category or service could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        try {
            $service = Review::approve($finding, $category, $service, Text::plain($this->post('name')), Text::plain($this->post('vendor')));
        } catch (\DomainException) {
            $this->flash('error', t('That finding has already been reviewed.'));

            return $this->buildRedirect(self::URL)->send();
        } catch (\InvalidArgumentException) {
            $this->flash('error', t('A new service needs a name.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $this->entityManager->persist($service);
        $this->entityManager->flush();
        Catalog::flush();

        $this->flash('success', t('Finding approved.'));

        return $this->buildRedirect(self::URL)->send();
    }

    public function ignore()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $finding = $this->finding($this->post('id'));

        if ($finding === null) {
            $this->flash('error', t('That finding could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        Review::ignore($finding);
        $this->entityManager->flush();

        $this->flash('success', t('Finding ignored.'));

        return $this->buildRedirect(self::URL)->send();
    }

    public function unignore()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $finding = $this->finding($this->post('id'));

        if ($finding === null) {
            $this->flash('error', t('That finding could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        Review::unignore($finding, Services::classifier());
        $this->entityManager->flush();

        $this->flash('success', t('Finding restored to the review queue.'));

        return $this->buildRedirect(self::URL)->send();
    }

    public function reassign()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $finding = $this->finding($this->post('id'));
        $category = $this->category($this->post('categoryID'));

        if ($finding === null || $category === null) {
            $this->flash('error', t('That finding or category could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        Review::reassign($finding, $category->getHandle());
        $this->entityManager->flush();

        $this->flash('success', t('Suggested category changed.'));

        return $this->buildRedirect(self::URL)->send();
    }

    public function override()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $finding = $this->finding($this->post('id'));
        $category = $this->category($this->post('categoryID'));
        $rule = $finding === null ? null : Review::overrideRule($finding);

        if ($finding === null || $category === null) {
            $this->flash('error', t('That finding or category could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        if (!$finding->isReviewable()) {
            $this->flash('error', t('That finding has already been reviewed.'));

            return $this->buildRedirect(self::URL)->send();
        }

        if ($rule === null) {
            $this->flash('error', t('This name has unusual characters, so it cannot be saved as a rule. Approve it instead.'));

            return $this->buildRedirect(self::URL)->send();
        }

        (new ClassificationStore($this->entityManager))->addOverride($rule['kind'], $rule['pattern'], $category->getHandle(), Text::plain($this->post('vendor')));

        $classifier = Services::classifier();

        foreach ($this->entityManager->getRepository(ScanFinding::class)->findBy(['status' => [ScanFinding::MATCHED, ScanFinding::UNCLASSIFIED]]) as $open) {
            Review::reclassify($open, $classifier);
        }

        $this->entityManager->flush();

        $this->flash('success', t('Rule saved. It overrides the built-in data from now on.'));

        return $this->buildRedirect(self::URL)->send();
    }

    public function bulkApprove()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $categories = [];

        foreach ($this->entityManager->getRepository(Category::class)->findAll() as $category) {
            $categories[$category->getHandle()] = $category;
        }

        $matched = $this->entityManager->getRepository(ScanFinding::class)->findBy(['status' => ScanFinding::MATCHED]);
        $created = Review::approveMatched(array_values($matched), $categories, array_values($this->entityManager->getRepository(Service::class)->findAll()));

        foreach ($created as $service) {
            $this->entityManager->persist($service);
        }

        $this->entityManager->flush();
        Catalog::flush();

        $this->flash('success', t('Approved matched findings. %s new services were created.', count($created)));

        return $this->buildRedirect(self::URL)->send();
    }
}
