<?php
/**
 * 2026 Moonivo
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to support@moonivo.com so we can send you a copy immediately.
 *
 * @author Moonivo <support@moonivo.com>
 * @copyright 2026 Moonivo
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *
 * @see https://moonivo.com
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Reads PHP files with the tokenizer (nothing is executed or included) and reports code
 * that PrestaShop 9 or PHP 8 no longer runs: removed core classes, methods, constants and
 * hooks, core methods redefined without the return type PrestaShop 9 added, and PHP
 * functions and syntax removed in PHP 8.
 *
 * Written in PHP 5.6 syntax: the stores that run this are the ones not upgraded yet.
 */
class MnvPs9Scanner
{
    /** Files larger than this are minified or generated; they are listed, not read. */
    const MAX_FILE_SIZE = 1500000;

    /** @var array rule data from data/rules.php */
    protected $rules;

    /** @var array PHP 8 removals from data/php8.php */
    protected $php8;

    /** @var array findings of the current scan */
    protected $findings = array();

    /** @var string path prefix stripped from reported file names */
    protected $base = '';

    public function __construct(array $rules, array $php8)
    {
        $this->rules = $rules;
        $this->php8 = $php8;
    }

    /**
     * Scan every PHP file under a directory.
     *
     * @param string $dir
     * @param string $base path prefix removed from file names in the findings
     * @param array $skipDirs directory names not to enter (for example vendor libraries)
     *
     * @return array [findings, scanned file count, skipped file count]
     */
    public function scanDirectory($dir, $base, array $skipDirs = array())
    {
        $this->findings = array();
        $this->base = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $files = 0;
        $skipped = 0;
        foreach ($this->listPhpFiles($dir, $skipDirs) as $file) {
            if (@filesize($file) > self::MAX_FILE_SIZE) {
                ++$skipped;
                continue;
            }
            $code = @file_get_contents($file);
            if ($code === false) {
                ++$skipped;
                continue;
            }
            ++$files;
            $this->scanCode($code, $file);
        }

        return array($this->findings, $files, $skipped);
    }

    /**
     * @param string $dir
     * @param array $skipDirs
     *
     * @return array
     */
    public function listPhpFiles($dir, array $skipDirs = array())
    {
        $out = array();
        if (!is_dir($dir)) {
            return $out;
        }
        $skip = array_map('strtolower', $skipDirs);
        $stack = array($dir);
        while ($stack) {
            $current = array_pop($stack);
            $entries = @scandir($current);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $current . '/' . $entry;
                if (is_dir($path)) {
                    if (!in_array(strtolower($entry), $skip, true) && !is_link($path)) {
                        $stack[] = $path;
                    }
                } elseif (strtolower(substr($entry, -4)) === '.php') {
                    $out[] = $path;
                }
            }
        }
        sort($out);

        return $out;
    }

    /**
     * Scan one file's source.
     *
     * @param string $code
     * @param string $file
     *
     * @return array findings of this file
     */
    public function scanCode($code, $file)
    {
        $before = count($this->findings);
        $tokens = @token_get_all($code);
        $n = count($tokens);
        $ns = '';
        $uses = array();
        // Class stack: name, parent (lowercase, as written resolved), brace depth.
        $class = null;
        $classParent = null;
        $classDepth = -1;
        $depth = 0;
        $guards = $this->guardedSymbols($code);

        for ($i = 0; $i < $n; ++$i) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                if ($t === '{') {
                    ++$depth;
                } elseif ($t === '}') {
                    --$depth;
                    if ($class !== null && $depth === $classDepth) {
                        $class = null;
                        $classParent = null;
                        $classDepth = -1;
                    }
                }
                continue;
            }
            $id = $t[0];
            $line = $t[2];

            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                ++$depth;
                continue;
            }

            if ($id === T_NAMESPACE) {
                $ns = $this->readName($tokens, $i + 1);
                $uses = array();
                continue;
            }

            // use Foo\Bar [as Baz]; at file level only (not closures' use, not traits).
            if ($id === T_USE && $class === null && $depth <= ($ns === '' ? 0 : 1)) {
                $this->readUses($tokens, $i, $uses, $file, $guards);
                continue;
            }

            if (($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT) && $class === null) {
                $prev = $this->prevId($tokens, $i);
                if ($prev === T_DOUBLE_COLON || $prev === T_NEW) {
                    continue;
                }
                $name = $this->nextString($tokens, $i);
                if ($name === null) {
                    continue;
                }
                $class = $name;
                $classDepth = $depth;
                for ($j = $i + 1; $j < $n && $tokens[$j] !== '{'; ++$j) {
                    if (is_array($tokens[$j]) && ($tokens[$j][0] === T_EXTENDS || $tokens[$j][0] === T_IMPLEMENTS)) {
                        $isExtends = $tokens[$j][0] === T_EXTENDS;
                        // extends A / implements A, B
                        for ($k = $j + 1; $k < $n && $tokens[$k] !== '{'; ++$k) {
                            if (is_array($tokens[$k]) && ($tokens[$k][0] === T_IMPLEMENTS || $tokens[$k][0] === T_EXTENDS) && $k !== $j) {
                                break;
                            }
                            if ($this->isNameToken($tokens[$k])) {
                                $ref = $this->readName($tokens, $k);
                                $resolved = $this->resolve($ref, $ns, $uses);
                                $this->checkClass($resolved, $file, $tokens[$k][2], $guards, 'extends');
                                if ($isExtends && $classParent === null) {
                                    $classParent = $this->coreName($resolved);
                                }
                                $k = $this->skipName($tokens, $k);
                            }
                        }
                    }
                }
                continue;
            }

            if ($id === T_FUNCTION) {
                $name = $this->nextString($tokens, $i);
                if ($name === null) {
                    continue;
                }
                if ($class !== null && $depth === $classDepth + 1) {
                    $this->checkMethodDeclaration($name, $classParent, $tokens, $i, $file, $line);
                    if (stripos($name, 'hook') === 0 && strlen($name) > 4) {
                        $this->checkHook(substr($name, 4), $file, $line, $guards);
                    }
                } elseif ($class === null && strtolower($name) === '__autoload') {
                    $this->add('error', 'php8_autoload', '__autoload()', $file, $line);
                }
                continue;
            }

            if ($id === T_NEW || $id === T_INSTANCEOF) {
                $j = $this->nextIndex($tokens, $i);
                if ($j !== null && $this->isNameToken($tokens[$j])) {
                    $this->checkClass($this->resolve($this->readName($tokens, $j), $ns, $uses), $file, $line, $guards, 'new');
                }
                continue;
            }

            // Name::member
            if ($id === T_DOUBLE_COLON || (defined('T_PAAMAYIM_NEKUDOTAYIM') && $id === T_PAAMAYIM_NEKUDOTAYIM)) {
                $this->checkStaticAccess($tokens, $i, $ns, $uses, $class, $classParent, $file, $line, $guards);
                continue;
            }

            // $this->method(
            if ($id === T_VARIABLE && $t[1] === '$this' && $classParent !== null) {
                $j = $this->nextIndex($tokens, $i);
                if ($j !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_OBJECT_OPERATOR) {
                    $k = $this->nextIndex($tokens, $j);
                    if ($k !== null && is_array($tokens[$k]) && $tokens[$k][0] === T_STRING) {
                        $after = $this->nextIndex($tokens, $k);
                        if ($after !== null && $tokens[$after] === '(') {
                            $this->checkInheritedMethod($classParent, $tokens[$k][1], $file, $tokens[$k][2], $guards, $tokens, $class);
                        }
                    }
                }
                continue;
            }

            // Plain function calls and registerHook('name').
            if ($id === T_STRING) {
                $j = $this->nextIndex($tokens, $i);
                $prev = $this->prevId($tokens, $i);
                $lc = strtolower($t[1]);
                if ($lc === 'controller' && $this->isObjectOperator($prev)) {
                    $this->checkControllerCall($tokens, $i, $file, $guards);
                }
                if ($j === null || $tokens[$j] !== '(') {
                    continue;
                }
                if ($lc === 'registerhook' || $lc === 'unregisterhook') {
                    foreach ($this->stringArgs($tokens, $j) as $hook) {
                        $this->checkHook($hook, $file, $line, $guards);
                    }
                    continue;
                }
                if ($prev === T_OBJECT_OPERATOR || $prev === T_DOUBLE_COLON || $prev === T_FUNCTION || $prev === T_NEW) {
                    continue;
                }
                if (isset($this->php8['functions'][$lc]) && !isset($guards['fn:' . $lc])) {
                    $this->add('error', 'php8_function', $t[1] . '()', $file, $line, $this->php8['functions'][$lc]);
                }
                continue;
            }

            // 'prestashop.some.service' strings: service IDs the Symfony container lost in 9.0.
            if ($id === T_CONSTANT_ENCAPSED_STRING && strlen($t[1]) > 14 && strpos($t[1], 'prestashop.') === 1) {
                $sid = substr($t[1], 1, -1);
                if (isset($this->rules['services'][$sid])) {
                    $this->add('warning', 'removed_service', $sid, $file, $line);
                }
                continue;
            }

            if ($id === T_DOUBLE_CAST && stripos($t[1], 'real') !== false) {
                $this->add('error', 'php8_syntax', '(real)', $file, $line, 'cast');
            } elseif ($id === T_UNSET_CAST) {
                $this->add('error', 'php8_syntax', '(unset)', $file, $line, 'cast');
            } elseif ($id === T_VARIABLE) {
                // $str{0}: string offsets with braces are a parse error in PHP 8.
                $j = $this->nextIndex($tokens, $i, false);
                if ($j !== null && $tokens[$j] === '{' && $this->prevId($tokens, $i) !== T_DOLLAR_OPEN_CURLY_BRACES) {
                    $this->add('error', 'php8_syntax', '$var{…}', $file, $line, 'offset');
                }
            }
        }

        return array_slice($this->findings, $before);
    }

    /**
     * Symbols the file itself tests before using (class_exists, method_exists,
     * function_exists, is_callable, defined) or code behind a version_compare on the
     * PrestaShop version: such code usually already runs on both versions, so it is
     * reported as a warning to check, not an error.
     *
     * @param string $code
     *
     * @return array
     */
    protected function guardedSymbols($code)
    {
        $g = array();
        if (preg_match_all('/class_exists\s*\(\s*[\'"]\\\\?([\w\\\\]+)[\'"]/i', $code, $m)) {
            foreach ($m[1] as $c) {
                // class_exists('AttributeCore') guards new \AttributeCore and Attribute alike.
                $g['class:' . strtolower($this->coreName($c))] = true;
            }
        }
        // $x instanceof Swift_Message is false (not an error) when the class is gone, so the
        // code behind it only runs where the class exists.
        if (preg_match_all('/\binstanceof\s+\\\\?([\w\\\\]+)/i', $code, $m)) {
            foreach ($m[1] as $c) {
                $g['class:' . strtolower($this->coreName($c))] = true;
            }
        }
        if (preg_match_all('/(?:method_exists|is_callable)\s*\(\s*(?:array\s*\(|\[)?\s*[\'"]?([\w\\\\]+)[\'"]?\s*(?:::class)?\s*,\s*[\'"](\w+)[\'"]/i', $code, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $g['method:' . strtolower($hit[2])] = true;
            }
        }
        if (preg_match_all('/function_exists\s*\(\s*[\'"](\w+)[\'"]/i', $code, $m)) {
            foreach ($m[1] as $f) {
                $g['fn:' . strtolower($f)] = true;
            }
        }
        // version_compare(_PS_VERSION_, ...), _PS_VERSION_ >= '9', and the helpers modules
        // write for it: isPsVersionGreaterOrEqualTo('9.0.0'), isPs8(), isPrestashop9().
        $versionChecks = array(
            '/version_compare\s*\([^)]*_PS_VERSION_/i',
            '/_PS_VERSION_\s*(?:[<>]=?|[=!]==?)/',
            // Not 'max' => _PS_VERSION_ (ps_versions_compliancy).
            '/(?<![=\-])(?:[<>]=?|[=!]==?)\s*_PS_VERSION_/',
            '/\bis\w*Version\w*\s*\(\s*[\'"]\d/i',
            '/\bis(?:Ps|Prestashop)_?\d+\w*\s*\(/i',
        );
        foreach ($versionChecks as $pattern) {
            if (preg_match($pattern, $code)) {
                $g['psversion'] = true;
                break;
            }
        }

        return $g;
    }

    /**
     * @param string $how use|new|static|extends: how the code refers to the class
     */
    protected function checkClass($name, $file, $line, array $guards, $how)
    {
        $lc = strtolower(ltrim($name, '\\'));
        $core = strtolower($this->coreName($lc));
        // PHP 8 has its own global Attribute class (#[Attribute]); only the old PrestaShop
        // product attribute class is a problem: new Attribute(...), Attribute::...
        if ($core === 'attribute' && $how !== 'new' && $how !== 'static') {
            return;
        }
        if (isset($this->rules['classes'][$core])) {
            $severity = isset($guards['class:' . $core]) || isset($guards['psversion']) ? 'warning' : 'error';
            $this->add($severity, 'removed_class', $this->rules['classes'][$core][0], $file, $line, $this->rules['classes'][$core][1]);

            return;
        }
        foreach ($this->rules['libraries'] as $prefix => $lib) {
            if (strpos($lc, $prefix) === 0 && !$this->bundles($lib[1])) {
                $this->add($this->libraryGuarded($prefix, $guards) ? 'warning' : 'error', 'library', $lib[0], $file, $line);
            }
        }
    }

    /** Does the file test for a class of this library (class_exists, instanceof) before using it? */
    protected function libraryGuarded($prefix, array $guards)
    {
        foreach ($guards as $key => $unused) {
            if (strpos($key, 'class:' . $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @var string|null module root of the current scan, to look for bundled libraries */
    protected $moduleRoot;

    public function setModuleRoot($dir)
    {
        $this->moduleRoot = $dir;
    }

    /** Does the scanned module ship its own copy of a library (vendor/<name>)? */
    protected function bundles($vendorDir)
    {
        if ($this->moduleRoot === null) {
            return false;
        }
        foreach (array('vendor/' . $vendorDir, 'vendor/' . $vendorDir . '/' . $vendorDir, 'lib/' . $vendorDir, 'libraries/' . $vendorDir) as $sub) {
            if (is_dir($this->moduleRoot . '/' . $sub)) {
                return true;
            }
        }

        return false;
    }

    protected function checkStaticAccess($tokens, $i, $ns, $uses, $class, $classParent, $file, $line, array $guards)
    {
        $left = $this->prevNameIndex($tokens, $i);
        $right = $this->nextIndex($tokens, $i);
        if ($left === null || $right === null || !is_array($tokens[$right])) {
            return;
        }
        $member = $tokens[$right][1];
        $leftName = $this->readNameBackwards($tokens, $left);
        $lcLeft = strtolower($leftName);
        if ($lcLeft === 'self' || $lcLeft === 'static') {
            // Own methods first; anything not declared here falls through to the parent.
            return;
        }
        if ($lcLeft === 'parent') {
            if ($classParent === null) {
                return;
            }
            $target = $classParent;
        } else {
            $target = $this->coreName($this->resolve($leftName, $ns, $uses));
        }
        $lcTarget = strtolower(ltrim($target, '\\'));
        $isCall = false;
        $after = $this->nextIndex($tokens, $right);
        if ($after !== null && $tokens[$after] === '(') {
            $isCall = true;
        }
        if ($tokens[$right][0] === T_CLASS) {
            if ($lcTarget !== 'attribute') {
                $this->checkClass($target, $file, $line, $guards, 'static');
            }

            return;
        }
        if ($tokens[$right][0] !== T_STRING) {
            return;
        }
        // Attribute::TARGET_CLASS and the like belong to PHP 8's own Attribute class; the old
        // PrestaShop class was used through its static methods.
        if ($lcTarget === 'attribute' && !$isCall) {
            return;
        }
        if (isset($this->rules['classes'][$lcTarget])) {
            $this->checkClass($target, $file, $line, $guards, 'static');

            return;
        }
        if ($isCall) {
            $method = $this->findRemovedMethod($lcTarget, strtolower($member));
            if ($method !== null) {
                $severity = isset($guards['method:' . strtolower($member)]) || isset($guards['psversion']) ? 'warning' : 'error';
                $this->add($severity, 'removed_method', $method[0], $file, $line, $method[1]);
            }
        } elseif (isset($this->rules['consts'][$lcTarget][$member])) {
            $severity = isset($guards['psversion']) ? 'warning' : 'error';
            $this->add($severity, 'removed_const', $this->displayClass($lcTarget) . '::' . $member, $file, $line, $this->rules['consts'][$lcTarget][$member]);
        }
    }

    /**
     * ...->controller->method(): the page controller reached through the context
     * ($this->context->controller, Context::getContext()->controller), as module hooks
     * use it. It is a front or a back-office controller, so both trees are looked up.
     */
    protected function checkControllerCall($tokens, $i, $file, array $guards)
    {
        $j = $this->nextIndex($tokens, $i);
        if ($j === null || !is_array($tokens[$j]) || !$this->isObjectOperator($tokens[$j][0])) {
            return;
        }
        $k = $this->nextIndex($tokens, $j);
        if ($k === null || !is_array($tokens[$k]) || $tokens[$k][0] !== T_STRING) {
            return;
        }
        $after = $this->nextIndex($tokens, $k);
        if ($after === null || $tokens[$after] !== '(') {
            return;
        }
        foreach (array('frontcontroller', 'admincontroller') as $tree) {
            if ($this->findRemovedMethod($tree, strtolower($tokens[$k][1])) !== null) {
                $this->checkInheritedMethod($tree, $tokens[$k][1], $file, $tokens[$k][2], $guards, $tokens, null);

                return;
            }
        }
    }

    protected function isObjectOperator($id)
    {
        return $id === T_OBJECT_OPERATOR || (defined('T_NULLSAFE_OBJECT_OPERATOR') && $id === T_NULLSAFE_OBJECT_OPERATOR);
    }

    protected function checkInheritedMethod($parent, $method, $file, $line, array $guards, $tokens, $class)
    {
        $found = $this->findRemovedMethod(strtolower($parent), strtolower($method));
        if ($found !== null) {
            $severity = isset($guards['method:' . strtolower($method)]) || isset($guards['psversion']) ? 'warning' : 'error';
            $this->add($severity, 'removed_method', $found[0], $file, $line, $found[1]);
        }
    }

    /**
     * Walk up the core class tree; return "Class::method" if the method was removed there.
     *
     * @param string $class lowercase
     * @param string $method lowercase
     *
     * @return array|null ["Class::method", removed in version]
     */
    protected function findRemovedMethod($class, $method)
    {
        $seen = 0;
        while ($class !== null && $seen++ < 12) {
            if (isset($this->rules['methods'][$class][$method])) {
                $m = $this->rules['methods'][$class][$method];

                return array($this->displayClass($class) . '::' . $m[0], $m[1]);
            }
            if (isset($this->rules['returnTypes'][$class][$method])) {
                // Still exists in PrestaShop 9.
                return null;
            }
            $class = isset($this->rules['parents'][$class]) ? $this->rules['parents'][$class] : null;
        }

        return null;
    }

    /**
     * A method redefined in a class extending a core class must keep the return type
     * PrestaShop 9 declares, or PHP stops with a fatal error when the class loads.
     */
    protected function checkMethodDeclaration($name, $parent, $tokens, $i, $file, $line)
    {
        if ($parent === null) {
            return;
        }
        $lc = strtolower($name);
        $class = strtolower($parent);
        $type = null;
        $seen = 0;
        while ($class !== null && $seen++ < 12) {
            if (isset($this->rules['returnTypes'][$class][$lc])) {
                $type = $this->rules['returnTypes'][$class][$lc];
                break;
            }
            $class = isset($this->rules['parents'][$class]) ? $this->rules['parents'][$class] : null;
        }
        // Overrides of legacy back-office pages that PrestaShop 9 moved to Symfony: the
        // method still exists but is no longer called, so the override silently stops working.
        if (isset($this->rules['silentAdmin'][$lc]) && $this->isLegacyAdminPage(strtolower($parent))) {
            $this->add('warning', 'admin_silent', $parent . '::' . $name . '()', $file, $line);
        }
        if ($type === null) {
            return;
        }
        if ($this->declaredReturnType($tokens, $i) === null) {
            $this->add('error', 'return_type', $this->displayClass($class) . '::' . $name . '(): ' . $type, $file, $line);
        }
    }

    /** A core back-office controller (AdminFooController), not a module's own admin page. */
    protected function isLegacyAdminPage($class)
    {
        return $class !== 'admincontroller' && $class !== 'moduleadmincontroller'
            && strpos($class, 'admin') === 0 && substr($class, -10) === 'controller';
    }

    protected function checkHook($hook, $file, $line, array $guards)
    {
        $lc = strtolower($hook);
        if (isset($this->rules['hooks'][$lc])) {
            $this->add(isset($guards['psversion']) ? 'warning' : 'error', 'removed_hook', $this->rules['hooks'][$lc][0], $file, $line, $this->rules['hooks'][$lc][1]);
        }
    }

    protected function add($severity, $code, $subject, $file, $line, $extra = null)
    {
        $rel = str_replace('\\', '/', $file);
        if ($this->base !== '' && $this->base !== '/' && strpos($rel, $this->base) === 0) {
            $rel = substr($rel, strlen($this->base));
        }
        // Bundled third-party libraries (vendor/) run their own compatibility checks and
        // often hold code paths for other platforms: only PrestaShop API use is reported,
        // and as something to check, not a certain failure.
        $library = strpos('/' . $rel, '/vendor/') !== false;
        if ($library) {
            if (strpos($code, 'php8_') === 0 || $code === 'library') {
                return;
            }
            $severity = 'warning';
        }
        $key = $code . '|' . $subject . '|' . $rel;
        // One finding per symbol and file; keep the count and the first lines.
        if (isset($this->findings[$key])) {
            ++$this->findings[$key]['count'];
            if (count($this->findings[$key]['lines']) < 5) {
                $this->findings[$key]['lines'][] = (int) $line;
            }

            return;
        }
        $this->findings[$key] = array(
            'severity' => $severity,
            'code' => $code,
            'subject' => $subject,
            'file' => $rel,
            'lines' => array((int) $line),
            'count' => 1,
            'extra' => $extra,
            'library' => $library,
        );
    }

    /** Legacy core classes are declared FooCore and used as Foo. */
    protected function coreName($name)
    {
        $name = ltrim($name, '\\');
        if (strpos($name, '\\') === false && strlen($name) > 4 && strtolower(substr($name, -4)) === 'core') {
            return substr($name, 0, -4);
        }

        return $name;
    }

    protected function displayClass($lc)
    {
        if (isset($this->rules['classNames'][$lc])) {
            return $this->rules['classNames'][$lc];
        }

        return $lc;
    }

    protected function resolve($name, $ns, array $uses)
    {
        if ($name === '') {
            return $name;
        }
        if ($name[0] === '\\') {
            return substr($name, 1);
        }
        $parts = explode('\\', $name);
        $first = strtolower($parts[0]);
        if (isset($uses[$first])) {
            $parts[0] = $uses[$first];

            return implode('\\', $parts);
        }
        // Legacy core classes live in the global namespace; PHP falls back only for
        // functions, but module code in a namespace refers to them with a leading \ or a use.
        if ($ns !== '' && count($parts) === 1) {
            return $ns . '\\' . $name;
        }

        return $name;
    }

    protected function readUses($tokens, $i, array &$uses, $file, array $guards)
    {
        $n = count($tokens);
        $j = $i + 1;
        while ($j < $n && $tokens[$j] !== ';') {
            if (is_array($tokens[$j]) && ($tokens[$j][0] === T_FUNCTION || $tokens[$j][0] === T_CONST)) {
                return;
            }
            if ($this->isNameToken($tokens[$j])) {
                $name = ltrim($this->readName($tokens, $j), '\\');
                $line = $tokens[$j][2];
                $j = $this->skipName($tokens, $j) + 1;
                $alias = substr($name, strrpos('\\' . $name, '\\'));
                $k = $this->nextIndex($tokens, $j - 1);
                if ($k !== null && is_array($tokens[$k]) && $tokens[$k][0] === T_AS) {
                    $a = $this->nextIndex($tokens, $k);
                    $alias = $tokens[$a][1];
                    $j = $a + 1;
                }
                // An import alone runs nothing (code often imports both old and new classes
                // and picks one at runtime); the class is checked where the code uses it.
                $uses[strtolower($alias)] = $name;
                continue;
            }
            ++$j;
        }
    }

    protected function isNameToken($t)
    {
        if (!is_array($t)) {
            return false;
        }
        $ids = array(T_STRING, T_NS_SEPARATOR);
        if (defined('T_NAME_QUALIFIED')) {
            $ids[] = T_NAME_QUALIFIED;
            $ids[] = T_NAME_FULLY_QUALIFIED;
            $ids[] = T_NAME_RELATIVE;
        }

        return in_array($t[0], $ids, true);
    }

    protected function readName($tokens, $j)
    {
        $name = '';
        $n = count($tokens);
        for (; $j < $n; ++$j) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_WHITESPACE && $name === '') {
                continue;
            }
            if ($this->isNameToken($t)) {
                $name .= $t[1];
                continue;
            }
            break;
        }

        return $name;
    }

    protected function skipName($tokens, $j)
    {
        $n = count($tokens);
        while ($j + 1 < $n && $this->isNameToken($tokens[$j + 1])) {
            ++$j;
        }

        return $j;
    }

    protected function readNameBackwards($tokens, $j)
    {
        $name = '';
        for (; $j >= 0; --$j) {
            if ($this->isNameToken($tokens[$j])) {
                $name = $tokens[$j][1] . $name;
                continue;
            }
            break;
        }

        return $name;
    }

    protected function prevNameIndex($tokens, $i)
    {
        for ($j = $i - 1; $j >= 0; --$j) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }

            return $this->isNameToken($tokens[$j]) || (is_array($tokens[$j]) && $tokens[$j][0] === T_STATIC) ? $j : null;
        }

        return null;
    }

    protected function nextIndex($tokens, $i, $skipWhitespace = true)
    {
        $n = count($tokens);
        for ($j = $i + 1; $j < $n; ++$j) {
            if ($skipWhitespace && is_array($tokens[$j]) && in_array($tokens[$j][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }

            return $j;
        }

        return null;
    }

    protected function prevId($tokens, $i)
    {
        for ($j = $i - 1; $j >= 0; --$j) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_ABSTRACT, T_FINAL, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC), true)) {
                continue;
            }

            return is_array($tokens[$j]) ? $tokens[$j][0] : $tokens[$j];
        }

        return null;
    }

    protected function nextString($tokens, $i)
    {
        $n = count($tokens);
        for ($j = $i + 1; $j < $n; ++$j) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                return $tokens[$j][1];
            }
            if ($tokens[$j] === '(' || $tokens[$j] === '{' || $tokens[$j] === '&') {
                if ($tokens[$j] === '&') {
                    continue;
                }

                return null;
            }
        }

        return null;
    }

    /** Return type declared after the parameter list of the function at token $i. */
    protected function declaredReturnType($tokens, $i)
    {
        $n = count($tokens);
        $level = 0;
        for ($j = $i; $j < $n; ++$j) {
            if ($tokens[$j] === '(') {
                ++$level;
            } elseif ($tokens[$j] === ')') {
                --$level;
                if ($level === 0) {
                    break;
                }
            }
        }
        $k = $this->nextIndex($tokens, $j);

        return $k !== null && $tokens[$k] === ':' ? true : null;
    }

    /** String literals passed to a call, including array('a', 'b') and ['a', 'b']. */
    protected function stringArgs($tokens, $open)
    {
        $out = array();
        $level = 0;
        $n = count($tokens);
        for ($j = $open; $j < $n; ++$j) {
            if ($tokens[$j] === '(' || $tokens[$j] === '[') {
                ++$level;
            } elseif ($tokens[$j] === ')' || $tokens[$j] === ']') {
                --$level;
                if ($level === 0) {
                    break;
                }
            } elseif (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                $out[] = substr($tokens[$j][1], 1, -1);
            }
        }

        return $out;
    }
}
