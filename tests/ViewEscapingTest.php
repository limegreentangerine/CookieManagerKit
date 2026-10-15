<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec §8.2: every dynamic value in a dashboard view is encoded for its context. An echoed expression is only
 * accepted when it is a string literal, a literal-only translation, a cast integer, the CSRF token field, a
 * Concrete form helper call, or ONE call to the package encoder. Everything else (a bare variable, a
 * concatenation that mixes raw and encoded parts, t() with variables) is reported.
 */
final class ViewEscapingTest extends TestCase
{
    private const ENCODER_PREFIXES = ['O::e(', 'O::json(', 'O::url('];
    private const HELPER_PREFIXES = ['$form->submit(', '$form->select(', '$form->hidden(', '$form->text(', '$form->textarea(', '$form->checkbox(', '$pageSelector->selectPage('];

    /**
     * @return list<string>
     */
    private static function views(): array
    {
        $files = [];
        $root = dirname(__DIR__);

        foreach (['single_pages', 'elements'] as $folder) {
            if (!is_dir($root . '/' . $folder)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<string> every expression handed to echo or <?=
     */
    public static function expressions(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_ECHO, T_OPEN_TAG_WITH_ECHO], true)) {
                continue;
            }

            $expression = '';
            $depth = 0;

            for ($i++; $i < $count; $i++) {
                $token = $tokens[$i];

                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if (is_array($token) && $token[0] === T_CLOSE_TAG) {
                    break;
                }

                if (!is_array($token)) {
                    if (in_array($token, ['(', '[', '{'], true)) {
                        $depth++;
                    } elseif (in_array($token, [')', ']', '}'], true)) {
                        $depth--;
                    } elseif ($depth === 0 && ($token === ';' || $token === ',')) {
                        $found[] = trim($expression);
                        $expression = '';

                        if ($token === ';') {
                            break;
                        }

                        continue;
                    }
                }

                $expression .= is_array($token) ? $token[1] : $token;
            }

            if (trim($expression) !== '') {
                $found[] = trim($expression);
            }
        }

        return $found;
    }

    private static function isSingleCall(string $expression): bool
    {
        $open = strpos($expression, '(');

        if ($open === false) {
            return false;
        }

        $length = strlen($expression);
        $depth = 0;
        $quote = null;

        for ($i = $open; $i < $length; $i++) {
            $char = $expression[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i === $length - 1;
                }
            }
        }

        return false;
    }

    public static function isSafe(string $expression): bool
    {
        $expression = trim($expression);

        if ($expression === '') {
            return true;
        }

        $patterns = [
            '~^(?:\'[^\']*\'|"[^"$\\\\{]*")\z~s',                                  // string literal
            '~^t\((?:\'(?:[^\'\\\\]|\\\\.)*\'|"[^"$\\\\{]*")\)\z~s',               // t('literal') only
            '~^\(int\)\s*\$[A-Za-z_]\w*(?:(?:->|::)\w+(?:\(\))?)*\z~',             // (int) $a->b()
            '~^\$token->output\(\'[a-z_]+\'\)\z~',                                  // CSRF field
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $expression) === 1) {
                return true;
            }
        }

        foreach ([...self::ENCODER_PREFIXES, ...self::HELPER_PREFIXES] as $prefix) {
            if (str_starts_with($expression, $prefix) && self::isSingleCall($expression)) {
                return true;
            }
        }

        return false;
    }

    public function testEveryEchoedValueInAViewIsEncodedForItsContext(): void
    {
        $offenders = [];

        foreach (self::views() as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::expressions($source) as $expression) {
                if (!self::isSafe($expression)) {
                    $offenders[] = substr($file, strlen(dirname(__DIR__)) + 1) . ': ' . $expression;
                }
            }
        }

        $this->assertSame([], $offenders, "Encode with O::e() / O::json() (see spec §8.2) or use a literal:\n" . implode("\n", $offenders));
    }

    private const FORBIDDEN_FUNCTIONS = [
        'printf', 'vprintf', 'fprintf', 'print_r', 'var_dump', 'var_export', 'readfile', 'fpassthru',
        'passthru', 'system', 'exec', 'shell_exec', 'popen', 'proc_open',
    ];

    /**
     * @return list<string> print/eval/exit and output or process functions used in a view (the standard guard is allowed)
     */
    public static function outputConstructProblems(string $source): array
    {
        // The standard Concrete direct-access guard is the one permitted use of die.
        $tokens = [];

        foreach (token_get_all(str_replace("defined('C5_EXECUTE') or die('Access Denied.')", '', $source)) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = $token;
            }
        }

        $problems = [];

        foreach ($tokens as $at => $token) {
            if (!is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_PRINT, T_EVAL, T_EXIT], true)) {
                $problems[] = $token[1];
            } elseif (in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                && in_array(ltrim($token[1], '\\'), self::FORBIDDEN_FUNCTIONS, true)
                && ($tokens[$at + 1] ?? null) === '(') {
                $problems[] = $token[1] . '()';
            }
        }

        return $problems;
    }

    public function testViewsNeverUseOtherOutputConstructs(): void
    {
        $offenders = [];

        foreach (self::views() as $file) {
            foreach (self::outputConstructProblems((string) file_get_contents($file)) as $problem) {
                $offenders[] = substr($file, strlen(dirname(__DIR__)) + 1) . ': ' . $problem;
            }
        }

        $this->assertSame([], $offenders);
    }

    public function testOutputFunctionsInAViewAreReported(): void
    {
        $this->assertSame(['printf()'], self::outputConstructProblems("<?php printf('<b>%s</b>', \$name) ?>"));
        $this->assertSame(['print_r()'], self::outputConstructProblems('<?php print_r($a); ?>'));
        $this->assertSame(['var_dump()'], self::outputConstructProblems('<?php var_dump($a); ?>'));
        $this->assertSame(['\\printf()'], self::outputConstructProblems('<?php \\printf($a); ?>'));
        $this->assertSame(['die'], self::outputConstructProblems('<?php die("x"); ?>'));
        $this->assertSame([], self::outputConstructProblems("<?php defined('C5_EXECUTE') or die('Access Denied.'); ?>"));
        $this->assertSame([], self::outputConstructProblems('<?php // print_r($a) ?><?php echo O::e(\'printf(\'); ?>'));
    }

    public function testCommentsCannotHideRawOutput(): void
    {
        $source = "<?= O::e(\$a /* ( */) . \$b // )\n?>";

        $this->assertSame(['O::e($a ) . $b'], self::expressions($source));
        $this->assertFalse(self::isSafe(self::expressions($source)[0]));
        $this->assertSame(['O::e($a)'], self::expressions('<?= O::e($a) /* note; */ ?>'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function checkerProvider(): array
    {
        return [
            'encoded variable' => ['O::e($name)', true],
            'encoded expression' => ['O::e($a . $b)', true],
            'encoded translation with variables' => ['O::e(t(\'Hello %s\', $name))', true],
            'json' => ['O::json([\'a\' => $b])', true],
            'literal translation' => ['t(\'Save (draft)\')', true],
            'csrf field' => ['$token->output(\'submit\')', true],
            'int cast of a getter' => ['(int) $settings->getRadius()', true],
            'int cast of a constant' => ['(int) $settings::MIN_LIFETIME_DAYS', true],
            'string literal' => ["'<br>'", true],
            'form helper' => ['$form->submit(\'save\', t(\'Save\'), [\'class\' => \'btn\'])', true],
            'bare variable' => ['$name', false],
            'raw then encoded' => ['$a . O::e($b)', false],
            'encoded then raw' => ['O::e($a) . $b', false],
            'translation with variable' => ['t(\'Hello %s\', $name)', false],
            'cast then concatenation' => ['(int) $a . $b', false],
            'interpolated string' => ['"Hello $name"', false],
            'unwrapped action url' => ['$view->action(\'save\')', false],
            'getter without encoding' => ['$category->getTitle()', false],
        ];
    }

    #[DataProvider('checkerProvider')]
    public function testTheCheckerItself(string $expression, bool $safe): void
    {
        $this->assertSame($safe, self::isSafe($expression), $expression);
    }

    public function testExpressionExtractionHandlesShortTagsMultilineAndLists(): void
    {
        $source = "<?php echo O::e(\n    \$a\n) ?>\n<?= \$b ?>\n<?php echo 'x', \$c; ?>";

        $this->assertSame(["O::e(\n    \$a\n)", '$b', "'x'", '$c'], self::expressions($source));
    }
}
