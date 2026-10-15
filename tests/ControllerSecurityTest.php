<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec §8.2: every dashboard action that changes data starts with a request-method guard and a CSRF guard that both
 * stop execution, no redirect target comes from the request, and controllers never write output themselves.
 *
 * All checks work on PHP tokens, so comments and string contents can neither satisfy nor break them.
 */
final class ControllerSecurityTest extends TestCase
{
    /** Public methods that are not state-changing actions. Every other public instance method is gated. */
    private const EXEMPT_ACTIONS = ['view', 'add', 'edit', 'on_start', '__construct'];

    private const OUTPUT_FUNCTIONS = [
        'printf', 'vprintf', 'fprintf', 'print_r', 'var_dump', 'var_export', 'readfile', 'fpassthru',
    ];

    /**
     * @return list<string>
     */
    private static function controllers(): array
    {
        $files = glob(dirname(__DIR__) . '/controllers/single_page/dashboard/cookie_manager/*.php') ?: [];
        sort($files);

        return $files;
    }

    /**
     * @return list<string|array{int, string, int}> tokens without whitespace and comments
     */
    private static function significant(string $source): array
    {
        $tokens = [];

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * @param string|array{int, string, int} $token
     */
    private static function id(string|array $token): int|string
    {
        return is_array($token) ? $token[0] : $token;
    }

    /**
     * @param string|array{int, string, int} $token
     */
    private static function text(string|array $token): string
    {
        return is_array($token) ? $token[1] : $token;
    }

    /**
     * @param list<string|array{int, string, int}> $tokens
     */
    private static function join(array $tokens): string
    {
        return implode(' ', array_map(static fn ($token): string => self::text($token), $tokens));
    }

    /**
     * @return list<array{name: string, public: bool, static: bool, params: list<string>, body: list<string|array{int, string, int}>}>
     */
    public static function methods(string $source): array
    {
        $tokens = self::significant($source);
        $count = count($tokens);
        $methods = [];

        for ($i = 0; $i < $count; $i++) {
            if (self::id($tokens[$i]) !== T_FUNCTION || $i + 1 >= $count || self::id($tokens[$i + 1]) !== T_STRING) {
                continue;
            }

            $public = true;
            $static = false;

            for ($j = $i - 1; $j >= 0 && in_array(self::id($tokens[$j]), [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT], true); $j--) {
                if (in_array(self::id($tokens[$j]), [T_PROTECTED, T_PRIVATE], true)) {
                    $public = false;
                }

                if (self::id($tokens[$j]) === T_STATIC) {
                    $static = true;
                }
            }

            $name = self::text($tokens[$i + 1]);
            $params = [];
            $depth = 0;
            $k = $i + 2;

            for (; $k < $count; $k++) {
                $id = self::id($tokens[$k]);

                if ($id === '(') {
                    $depth++;
                } elseif ($id === ')') {
                    if (--$depth === 0) {
                        $k++;
                        break;
                    }
                } elseif ($id === T_VARIABLE) {
                    $params[] = self::text($tokens[$k]);
                }
            }

            while ($k < $count && !in_array(self::id($tokens[$k]), ['{', ';'], true)) {
                $k++;
            }

            if ($k >= $count || self::id($tokens[$k]) === ';') {
                continue;
            }

            $depth = 1;
            $body = [];

            for ($k++; $k < $count; $k++) {
                $id = self::id($tokens[$k]);

                if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                } elseif ($id === '}' && --$depth === 0) {
                    break;
                }

                $body[] = $tokens[$k];
            }

            $methods[] = ['name' => $name, 'public' => $public, 'static' => $static, 'params' => $params, 'body' => $body];
            $i = $k;
        }

        return $methods;
    }

    /**
     * @param list<string|array{int, string, int}> $body
     */
    private static function compact(array $body): string
    {
        $compact = '';

        foreach ($body as $token) {
            $text = self::text($token);

            if (self::id($token) === T_CONSTANT_ENCAPSED_STRING) {
                $text = in_array(substr($text, 1, -1), ['submit'], true) ? "'submit'" : "'s'";
            }

            $compact .= $text;
        }

        return $compact;
    }

    private static function guardBlockStops(string $block): bool
    {
        return preg_match('/(?:^|;)return(?![A-Za-z0-9_])/', $block) === 1
            && preg_match('/(?:^|;)(?:if|else|elseif|while|for|foreach|switch|match|try)(?![A-Za-z0-9_])/', $block) !== 1;
    }

    /**
     * @return list<string> problems with one action; the CSRF and method guards must be its first statements and stop execution
     */
    public static function actionProblems(string $source, string $method): array
    {
        foreach (self::methods($source) as $candidate) {
            if ($candidate['name'] !== $method) {
                continue;
            }

            $post = '!\$this->request->isPost\(\)';
            $token = '!\$this->token->validate\(\'submit\'\)';
            $headers = [
                'if\(' . $post . '\)' => ['post'],
                'if\(' . $token . '\)' => ['token'],
                'if\(' . $post . '\|\|' . $token . '\)' => ['post', 'token'],
                'if\(' . $token . '\|\|' . $post . '\)' => ['post', 'token'],
            ];
            $rest = self::compact($candidate['body']);
            $have = [];
            $problems = [];

            while (count($have) < 2) {
                $matched = false;

                foreach ($headers as $header => $covers) {
                    if (preg_match('/^' . $header . '\{([^{}]*)\}/', $rest, $match) !== 1) {
                        continue;
                    }

                    if (!self::guardBlockStops($match[1])) {
                        $problems[] = $method . ': a guard does not end in an unconditional return';
                    }

                    foreach ($covers as $cover) {
                        $have[$cover] = true;
                    }

                    $rest = substr($rest, strlen($match[0]));
                    $matched = true;
                    break;
                }

                if (!$matched) {
                    break;
                }
            }

            if (!isset($have['post'])) {
                $problems[] = $method . ': must start with `if (!$this->request->isPost()) { ...return...; }`';
            }

            if (!isset($have['token'])) {
                $problems[] = $method . ': must start with `if (!$this->token->validate(\'submit\')) { ...return...; }`';
            }

            return $problems;
        }

        return [$method . ': method not found'];
    }

    /**
     * @return list<string> the actions (public instance methods other than view/add/edit/on_start/__construct)
     */
    public static function actions(string $source): array
    {
        $actions = [];

        foreach (self::methods($source) as $method) {
            if ($method['public'] && !$method['static'] && !in_array($method['name'], self::EXEMPT_ACTIONS, true)) {
                $actions[] = $method['name'];
            }
        }

        return $actions;
    }

    private static function isRedirectCallee(string $text): bool
    {
        return preg_match('/(?:^|\\\\)Redirect$/', $text) === 1;
    }

    /**
     * @param list<string|array{int, string, int}> $tokens
     * @return list<list<string|array{int, string, int}>>
     */
    private static function splitTop(array $tokens, string $separator): array
    {
        $parts = [[]];
        $depth = 0;

        foreach ($tokens as $token) {
            $id = self::id($token);

            if (in_array($id, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($id, [')', ']', '}'], true)) {
                $depth--;
            }

            if ($depth === 0 && $id === $separator) {
                $parts[] = [];

                continue;
            }

            $parts[count($parts) - 1][] = $token;
        }

        return $parts;
    }

    /**
     * @param list<string|array{int, string, int}> $tokens
     * @param array{params: list<string>, body: list<string|array{int, string, int}>} $method
     */
    private static function redirectExpressionSafe(array $tokens, array $method, int $level = 0): bool
    {
        if ($tokens === [] || $level > 5) {
            return false;
        }

        $ternary = self::splitTop($tokens, '?');

        if (count($ternary) > 2) {
            return false;
        }

        if (count($ternary) === 2) {
            $branches = self::splitTop($ternary[1], ':');

            return count($branches) === 2
                && self::redirectExpressionSafe($branches[0], $method, $level + 1)
                && self::redirectExpressionSafe($branches[1], $method, $level + 1);
        }

        foreach (self::splitTop($tokens, '.') as $term) {
            if (!self::redirectTermSafe($term, $method, $level)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string|array{int, string, int}> $term
     * @param array{params: list<string>, body: list<string|array{int, string, int}>} $method
     */
    private static function redirectTermSafe(array $term, array $method, int $level): bool
    {
        $ids = array_map(static fn ($token): int|string => self::id($token), $term);
        $texts = array_map(static fn ($token): string => self::text($token), $term);

        if ($ids === [T_CONSTANT_ENCAPSED_STRING] || $ids === [T_LNUMBER]) {
            return true;
        }

        if ($ids === [T_STRING, T_DOUBLE_COLON, T_STRING] && in_array($texts[0], ['self', 'static'], true)) {
            return true;
        }

        if ($ids === [T_VARIABLE, T_OBJECT_OPERATOR, T_STRING, '(', ')'] && $texts[0] !== '$this' && $texts[2] === 'getID') {
            return true;
        }

        if ($ids === [T_VARIABLE] && !in_array($texts[0], $method['params'], true)) {
            $assignments = 0;
            $body = $method['body'];

            foreach ($body as $at => $token) {
                if (self::text($token) !== $texts[0] || !isset($body[$at + 1]) || !in_array(self::id($body[$at + 1]), ['=', T_CONCAT_EQUAL], true)) {
                    continue;
                }

                $assignments++;
                $value = [];

                for ($next = $at + 2; $next < count($body) && self::id($body[$next]) !== ';'; $next++) {
                    $value[] = $body[$next];
                }

                if (!self::redirectExpressionSafe($value, $method, $level + 1)) {
                    return false;
                }
            }

            return $assignments > 0;
        }

        return false;
    }

    /**
     * @return list<string> redirects whose target (any argument) is not built only from literals, class constants and entity IDs
     */
    public static function redirectProblems(string $source): array
    {
        $problems = [];

        foreach (self::methods($source) as $method) {
            $body = $method['body'];
            $count = count($body);

            for ($i = 0; $i < $count; $i++) {
                $id = self::id($body[$i]);
                $text = self::text($body[$i]);
                $open = null;

                if ($id === T_STRING && in_array($text, ['buildRedirect', 'redirect'], true) && ($body[$i + 1] ?? null) === '(') {
                    $open = $i + 1;
                } elseif (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && self::isRedirectCallee($text)
                    && self::id($body[$i + 1] ?? '') === T_DOUBLE_COLON && in_array(self::text($body[$i + 2] ?? ''), ['to', 'url'], true) && ($body[$i + 3] ?? null) === '(') {
                    $open = $i + 3;
                } elseif ($id === T_NEW && preg_match('/(?:^|\\\\)RedirectResponse$/', self::text($body[$i + 1] ?? '')) === 1 && ($body[$i + 2] ?? null) === '(') {
                    $open = $i + 2;
                }

                if ($open === null) {
                    continue;
                }

                $depth = 0;
                $arguments = [];

                for ($k = $open; $k < $count; $k++) {
                    if ($body[$k] === '(') {
                        $depth++;
                    } elseif ($body[$k] === ')' && --$depth === 0) {
                        break;
                    }

                    if ($k > $open) {
                        $arguments[] = $body[$k];
                    }
                }

                if ($arguments === []) {
                    continue;
                }

                foreach (self::splitTop($arguments, ',') as $argument) {
                    if ($argument !== [] && !self::redirectExpressionSafe($argument, $method)) {
                        $problems[] = $method['name'] . ': redirect target `' . self::join($argument) . '` is not a fixed route';
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * @return list<string> echo/print/exit/die and output-writing function calls (comments and strings are ignored)
     */
    public static function outputProblems(string $source): array
    {
        $tokens = self::significant($source);
        $problems = [];

        foreach ($tokens as $at => $token) {
            $id = self::id($token);

            if (in_array($id, [T_ECHO, T_OPEN_TAG_WITH_ECHO, T_PRINT, T_EXIT], true)) {
                $problems[] = self::text($token);
            } elseif (in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                && in_array(ltrim(self::text($token), '\\'), self::OUTPUT_FUNCTIONS, true)
                && ($tokens[$at + 1] ?? null) === '(') {
                $problems[] = self::text($token) . '()';
            }
        }

        return $problems;
    }

    public function testThereAreDashboardControllersToCheck(): void
    {
        $this->assertNotSame([], self::controllers());
    }

    public function testEveryWriteActionStartsWithMethodAndTokenGuardsThatReturn(): void
    {
        $offenders = [];

        foreach (self::controllers() as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::actions($source) as $action) {
                foreach (self::actionProblems($source, $action) as $problem) {
                    $offenders[] = basename($file) . '::' . $problem;
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function testNoRedirectTargetComesFromTheRequest(): void
    {
        $offenders = [];

        foreach (self::controllers() as $file) {
            foreach (self::redirectProblems((string) file_get_contents($file)) as $problem) {
                $offenders[] = basename($file) . '::' . $problem;
            }
        }

        $this->assertSame([], $offenders, 'redirect to fixed routes only');
    }

    public function testControllersNeverEchoOrRenderRequestDataDirectly(): void
    {
        $offenders = [];

        foreach (self::controllers() as $file) {
            foreach (self::outputProblems((string) file_get_contents($file)) as $problem) {
                $offenders[] = basename($file) . ': ' . $problem;
            }
        }

        $this->assertSame([], $offenders, 'controllers set view variables and flash constant messages only');
    }

    /**
     * @return array<string, array{string, bool}> method source => passes
     */
    public static function actionProvider(): array
    {
        return [
            'task 15 shape' => [<<<'PHP'
public function save()
{
    if (!$this->request->isPost()) {
        return $this->buildRedirect(self::URL)->send();
    }

    if (!$this->token->validate('submit')) {
        $this->flash('error', $this->token->getErrorMessage());

        return $this->buildRedirect(self::URL)->send();
    }
    $this->entityManager->flush();
}
PHP, true],
            'combined guard' => [<<<'PHP'
public function save()
{
    if (!$this->request->isPost() || !$this->token->validate('submit')) {
        return $this->buildRedirect(self::URL)->send();
    }
    $this->entityManager->flush();
}
PHP, true],
            'combined guard other order' => [<<<'PHP'
public function save()
{
    if (!$this->token->validate("submit") || !$this->request->isPost()) {
        return;
    }
}
PHP, true],
            'separate guards token first' => [<<<'PHP'
public function delete($id = null)
{
    if (!$this->token->validate('submit')) {
        return $this->buildRedirect(self::URL)->send();
    }
    if (!$this->request->isPost()) {
        return $this->buildRedirect(self::URL)->send();
    }
    $this->entityManager->remove($x);
}
PHP, true],
            'braces in strings and comments' => [<<<'PHP'
public function save()
{
    // a comment with a { brace
    if (!$this->request->isPost()) {
        return $this->buildRedirect(self::URL)->send();
    }
    if (!$this->token->validate('submit')) {
        $this->flash('error', 'please return { later');

        return $this->buildRedirect(self::URL)->send();
    }
    $this->flash('success', 'saved } {');
    /* } */
    $this->entityManager->flush();
}
PHP, true],
            'post guard without return' => [<<<'PHP'
public function save()
{
    if (!$this->request->isPost()) {
        $this->flash('error', 'x');
    }
    if (!$this->token->validate('submit')) {
        return $this->buildRedirect(self::URL)->send();
    }
    $this->entityManager->flush();
}
PHP, false],
            'token guard without return' => [<<<'PHP'
public function save()
{
    if (!$this->request->isPost()) {
        return $this->buildRedirect(self::URL)->send();
    }
    if (!$this->token->validate('submit')) {
        $this->flash('error', 'x');
    }
    $this->entityManager->flush();
}
PHP, false],
            'return only in a string' => [<<<'PHP'
public function save()
{
    if (!$this->request->isPost()) {
        $this->flash('error', 'return');
    }
    if (!$this->token->validate('submit')) {
        return;
    }
}
PHP, false],
            'conditional return in guard' => [<<<'PHP'
public function save()
{
    if (!$this->request->isPost()) {
        if ($x) { return; }
    }
    if (!$this->token->validate('submit')) {
        return;
    }
}
PHP, false],
            'validate result ignored' => [<<<'PHP'
public function save()
{
    if (!$this->request->isPost()) {
        return;
    }
    $this->token->validate('submit');
    $this->entityManager->flush();
}
PHP, false],
            'validate in a comment' => [<<<'PHP'
public function save()
{
    if (!$this->request->isPost()) {
        return;
    }
    // if (!$this->token->validate('submit')) { return; }
    $this->entityManager->flush();
}
PHP, false],
            'token guard missing' => [<<<'PHP'
public function save()
{
    if (!$this->request->isPost()) {
        return;
    }
    $this->entityManager->flush();
}
PHP, false],
            'post guard missing' => [<<<'PHP'
public function save()
{
    if (!$this->token->validate('submit')) {
        return;
    }
    $this->entityManager->flush();
}
PHP, false],
            'helper write before guards' => [<<<'PHP'
public function save()
{
    $this->persistRows();
    if (!$this->request->isPost()) {
        return;
    }
    if (!$this->token->validate('submit')) {
        return;
    }
}
PHP, false],
            'config save before guards' => [<<<'PHP'
public function save()
{
    $config->save('a', 1);
    if (!$this->request->isPost() || !$this->token->validate('submit')) {
        return;
    }
}
PHP, false],
            'first statement before guards' => [<<<'PHP'
public function save()
{
    $x = 1;
    if (!$this->request->isPost() || !$this->token->validate('submit')) {
        return;
    }
}
PHP, false],
            'wrong token key' => [<<<'PHP'
public function save()
{
    if (!$this->request->isPost() || !$this->token->validate('other')) {
        return;
    }
}
PHP, false],
        ];
    }

    #[DataProvider('actionProvider')]
    public function testActionGuardChecker(string $method, bool $ok): void
    {
        preg_match('/function\s+(\w+)/', $method, $name);

        $this->assertSame($ok, self::actionProblems("<?php class C {\n" . $method . "\n}", $name[1]) === [], $method);
    }

    public function testOnlyPublicInstanceActionsOtherThanTheExemptOnesAreGated(): void
    {
        $source = '<?php class C { public function view() {} public function add() {} public function edit($id) {} public function on_start() {} public function __construct() {}'
            . ' public function save() {} public function reorder() {} public function delete($id) {} private function helper() {} protected function other() {} public static function make() {} }';

        $this->assertSame(['save', 'reorder', 'delete'], self::actions($source));
    }

    /**
     * @return array<string, array{string, bool}> method source => passes
     */
    public static function redirectProvider(): array
    {
        return [
            'constant' => ['public function a() { return $this->buildRedirect(self::URL); }', true],
            'constant plus entity id' => ['public function a($category) { return $this->buildRedirect(self::URL . \'/edit/\' . $category->getID()); }', true],
            'literal' => ['public function a() { return $this->redirect(\'/dashboard/cookie_manager/banner\'); }', true],
            'assigned ternary' => ['public function a($service) { $back = $service ? self::URL . \'/edit/\' . $service->getID() : self::URL . \'/add\'; return $this->buildRedirect($back); }', true],
            'post value via variable' => ['public function a() { $to = $this->post(\'return\'); return $this->buildRedirect($to); }', false],
            'post value directly' => ['public function a() { return Redirect::to($this->post(\'x\')); }', false],
            'qualified Redirect url' => ['public function a() { return \\Concrete\\Core\\Routing\\Redirect::url($this->get(\'x\')); }', false],
            'superglobal' => ['public function a() { return new RedirectResponse($_GET[\'u\']); }', false],
            'url parameter' => ['public function a($id) { return $this->buildRedirect(\'/x/\' . $id); }', false],
            'unassigned variable' => ['public function a() { return $this->buildRedirect($nowhere); }', false],
            'second argument from request' => ['public function a() { return $this->buildRedirect(self::URL, $this->request->get(\'x\')); }', false],
            'one unsafe assignment' => ['public function a() { $to = self::URL; $to = $this->post(\'x\'); return $this->buildRedirect($to); }', false],
        ];
    }

    #[DataProvider('redirectProvider')]
    public function testRedirectChecker(string $method, bool $ok): void
    {
        $this->assertSame($ok, self::redirectProblems("<?php class C {\n" . $method . "\n}") === [], $method);
    }

    /**
     * @return array<string, array{string, bool}> method source => passes
     */
    public static function outputProvider(): array
    {
        return [
            'echo' => ['public function a() { echo $x; }', false],
            'print' => ['public function a() { print $x; }', false],
            'die' => ['public function a() { die(\'x\'); }', false],
            'exit' => ['public function a() { exit; }', false],
            'printf' => ['public function a() { printf(\'%s\', $x); }', false],
            'print_r' => ['public function a() { print_r($x); }', false],
            'var_dump' => ['public function a() { var_dump($x); }', false],
            'readfile' => ['public function a() { readfile($x); }', false],
            'qualified printf' => ['public function a() { \\printf(\'%s\', $x); }', false],
            'flash message mentioning die' => ['public function a() { $this->flash(\'error\', \'Could not die or exit or echo\'); }', true],
            'comment mentioning exit' => ["public function a() { // exit early\n /* print_r(\$x) */ return; }", true],
            'method named like a function' => ['public function a() { return $this->printf; }', true],
        ];
    }

    #[DataProvider('outputProvider')]
    public function testOutputChecker(string $method, bool $ok): void
    {
        $this->assertSame($ok, self::outputProblems("<?php class C {\n" . $method . "\n}") === [], $method);
    }
}
