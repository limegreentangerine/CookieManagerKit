<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use CookieManagerKit\Entity\{Category, Service};
use Concrete\Core\Page\Controller\DashboardPageController;
use CookieManagerKit\Support\{Catalog, CookieForm, SiteLocales, Text};

class Services extends DashboardPageController
{
    private const URL = '/dashboard/cookie_manager/services';
    public $helpers = [
        'form',
        'concrete/ui',
    ];

    private function prepare(string $mode, ?Service $service = null): void
    {
        $default = SiteLocales::defaultLocale();
        $categories = [];

        foreach ($this->entityManager->getRepository(Category::class)->findBy([], ['sortOrder' => 'ASC', 'handle' => 'ASC']) as $category) {
            $content = $category->getContent($default);
            $categories[$category->getID()] = $content ? $content->getTitle() : $category->getHandle();
        }

        $this->set('mode', $mode);
        $this->set('service', $service);
        $this->set('categories', $categories);
        $this->set('locales', SiteLocales::options());
        $this->set('defaultLocale', $default);
        $this->set('services', $this->entityManager->getRepository(Service::class)->findBy([], ['name' => 'ASC']));
    }

    private function find(mixed $id): ?Service
    {
        if ((!is_string($id) && !is_int($id)) || preg_match('/^\d+\z/', (string) $id) !== 1) {
            return null;
        }

        $service = $this->entityManager->getRepository(Service::class)->find((int) $id);

        return $service instanceof Service ? $service : null;
    }

    public function view()
    {
        $this->prepare('list');
    }

    public function add()
    {
        $this->prepare('form');
    }

    public function edit($id = null)
    {
        $service = $this->find($id);

        if ($service === null) {
            $this->flash('error', t('That service could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $this->prepare('form', $service);
    }

    public function save()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $id = $this->post('id');
        $service = $id !== null && $id !== '' ? $this->find($id) : null;

        if ($id !== null && $id !== '' && $service === null) {
            $this->flash('error', t('That service could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $back = $service ? self::URL . '/edit/' . $service->getID() : self::URL . '/add';
        $name = Text::plain($this->post('name'));
        $categoryId = $this->post('categoryID');
        $category = is_string($categoryId) && preg_match('/^\d+\z/', $categoryId) === 1
            ? $this->entityManager->getRepository(Category::class)->find((int) $categoryId)
            : null;

        if ($name === '' || !$category instanceof Category) {
            $this->flash('error', t('A service needs a name and a category.'));

            return $this->buildRedirect($back)->send();
        }

        $domains = $this->post('domains');

        $service ??= new Service();
        $service->setName($name)
            ->setVendor(Text::plain($this->post('vendor')))
            ->setCategory($category)
            ->setDomainsText(is_string($domains) ? $domains : '')
            ->setActive($this->post('active') !== null);

        $posted = $this->post('content');
        $posted = is_array($posted) ? $posted : [];
        $default = SiteLocales::defaultLocale();

        foreach (array_keys(SiteLocales::options()) as $locale) {
            $input = is_array($posted[$locale] ?? null) ? $posted[$locale] : [];
            $description = Text::multiline($input['description'] ?? '', 1000);
            $existing = $service->getContent($locale);

            if ($description === '' && $locale !== $default) {
                if ($existing) {
                    $service->getContents()->removeElement($existing);
                }

                continue;
            }

            $service->setContent($locale, $description);
        }

        try {
            CookieForm::apply($service, CookieForm::parse($this->post('cookies'), array_keys(SiteLocales::options())));
        } catch (\InvalidArgumentException) {
            $this->flash('error', t('Two cookies cannot swap or reuse names in one save. Save one change at a time.'));

            return $this->buildRedirect($back)->send();
        }

        $this->entityManager->persist($service);
        $this->entityManager->flush();
        Catalog::flush();

        $this->flash('success', t('Service saved.'));

        return $this->buildRedirect(self::URL)->send();
    }

    public function delete()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $service = $this->find($this->post('id'));

        if ($service === null) {
            $this->flash('error', t('That service could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $this->entityManager->remove($service);
        $this->entityManager->flush();
        Catalog::flush();

        $this->flash('success', t('Service deleted.'));

        return $this->buildRedirect(self::URL)->send();
    }
}
