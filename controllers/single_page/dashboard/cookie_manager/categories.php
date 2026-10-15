<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use CookieManagerKit\Entity\Category;
use Concrete\Core\Page\Controller\DashboardPageController;
use CookieManagerKit\Support\{Catalog, SiteLocales, Text};

class Categories extends DashboardPageController
{
    private const URL = '/dashboard/cookie_manager/categories';
    public $helpers = [
        'form',
        'concrete/ui',
    ];

    private function prepare(string $mode, ?Category $category = null): void
    {
        $this->set('mode', $mode);
        $this->set('category', $category);
        $this->set('locales', SiteLocales::options());
        $this->set('defaultLocale', SiteLocales::defaultLocale());
        $this->set('categories', $this->entityManager->getRepository(Category::class)->findBy([], ['sortOrder' => 'ASC', 'handle' => 'ASC']));
    }

    private function find(mixed $id): ?Category
    {
        if (!is_string($id) && !is_int($id)) {
            return null;
        }

        if (preg_match('/^\d+\z/', (string) $id) !== 1) {
            return null;
        }

        $category = $this->entityManager->getRepository(Category::class)->find((int) $id);

        return $category instanceof Category ? $category : null;
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
        $category = $this->find($id);

        if ($category === null) {
            $this->flash('error', t('That category could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $this->prepare('form', $category);
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
        $category = $id !== null && $id !== '' ? $this->find($id) : null;

        if ($id !== null && $id !== '' && $category === null) {
            $this->flash('error', t('That category could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $locales = SiteLocales::options();
        $default = SiteLocales::defaultLocale();
        $posted = $this->post('content');
        $posted = is_array($posted) ? $posted : [];
        $content = [];

        foreach (array_keys($locales) as $locale) {
            $input = is_array($posted[$locale] ?? null) ? $posted[$locale] : [];
            $content[$locale] = [Text::plain($input['title'] ?? ''), Text::multiline($input['description'] ?? '', 1000)];
        }

        if (!isset($content[$default]) || $content[$default][0] === '') {
            $this->flash('error', t('The default language needs a title.'));

            return $this->buildRedirect($category ? self::URL . '/edit/' . $category->getID() : self::URL . '/add')->send();
        }

        if ($category === null) {
            $handle = $this->post('handle');

            if (!Text::isHandle($handle) || $handle === Category::NECESSARY || $this->entityManager->getRepository(Category::class)->findOneBy(['handle' => $handle])) {
                $this->flash('error', t('Choose a handle that is not already used: lowercase letters, numbers, hyphens and underscores, up to 32 characters.'));

                return $this->buildRedirect(self::URL . '/add')->send();
            }

            $category = (new Category())->setHandle($handle);
        }

        $sortOrder = $this->post('sortOrder');

        if (is_string($sortOrder) && preg_match('/^\d{1,4}\z/', $sortOrder) === 1) {
            $category->setSortOrder($sortOrder);
        }

        foreach ($content as $locale => [$title, $description]) {
            $existing = $category->getContent($locale);

            // A language with nothing filled in is removed, so visitors in that language get the default language.
            if ($title === '' && $description === '' && $locale !== $default) {
                if ($existing) {
                    $category->getContents()->removeElement($existing);
                }

                continue;
            }

            $category->setContent($locale, $title, $description);
        }

        $this->entityManager->persist($category);
        $this->entityManager->flush();
        Catalog::flush();

        $this->flash('success', t('Category saved.'));

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

        $category = $this->find($this->post('id'));

        if ($category === null || !$category->canBeDeleted()) {
            $this->flash('error', t('This category cannot be deleted. The necessary category is always kept, and a category with services must have them moved or removed first.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $this->entityManager->remove($category);
        $this->entityManager->flush();
        Catalog::flush();

        $this->flash('success', t('Category deleted.'));

        return $this->buildRedirect(self::URL)->send();
    }
}
