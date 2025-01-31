<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Dotenv;

use Symfony\Component\Dotenv\Exception\FormatException;
use Symfony\Component\Dotenv\Exception\PathException;

/**
 * Manages .env files.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class Dotenv
{
    const VARNAME_REGEX = '(?i:[A-Z][A-Z0-9_]*+)';
    const STATE_VARNAME = 0;
    const STATE_VALUE = 1;

//    private $path;
    private $cursor;
    private $lineno;
    private $data;
    private $end;
    private $values = [];
    private $envKey;
    private $debugKey;
    private $prodEnvs = ['prod'];
    private $usePutenv = false;

    public function __construct($envKey = 'APP_ENV', $debugKey = 'APP_DEBUG')
    {
        $this->envKey = $envKey;
        $this->debugKey = $debugKey;
    }

    /**
     * @return $this
     */
    public function setProdEnvs(array $prodEnvs)
    {
        $this->prodEnvs = $prodEnvs;

        return $this;
    }

    /**
     * @param bool $usePutenv If `putenv()` should be used to define environment variables or not.
     *                        Beware that `putenv()` is not thread safe, that's why this setting defaults to false
     *
     * @return $this
     */
    public function usePutenv($usePutenv = true)
    {
        $this->usePutenv = $usePutenv;

        return $this;
    }

    /**
     * Loads one or several .env files.
     *
     * @param string $path A file to load
     * @param string[] ...$extraPaths A list of additional files to load
     *
     * @throws PathException   when a file does not exist or is not readable
     */
    public function load($path, ...$extraPaths)
    {
        $this->doLoad(false, func_get_args());
    }

    /**
     * Loads a .env file and the corresponding .env.local, .env.$env and .env.$env.local files if they exist.
     *
     * .env.local is always ignored in test env because tests should produce the same results for everyone.
     * .env.dist is loaded when it exists and .env is not found.
     *
     * @param string $path A file to load
     * @param string|null $envKey The name of the env vars that defines the app env
     * @param string $defaultEnv The app env to use when none is defined
     * @param array $testEnvs A list of app envs for which .env.local should be ignored
     *
     * @throws PathException   when a file does not exist or is not readable
     */
    public function loadEnv($path, $envKey = null, $defaultEnv = 'dev', array $testEnvs = ['test'], $overrideExistingVars = false)
    {
        $k = isset($envKey) && $envKey ? $envKey : $this->envKey;

        if (is_file($path) || !is_file($p = "$path.dist")) {
            $this->doLoad($overrideExistingVars, [$path]);
        } else {
            $this->doLoad($overrideExistingVars, [$p]);
        }

        if (null === $env = (isset($_SERVER[$k]) && $_SERVER[$k] ? $_SERVER[$k] :
                (isset($_ENV[$k]) && $_ENV[$k] ? $_ENV[$k] : null))) {
            $this->populate([$k => $env = $defaultEnv], $overrideExistingVars);
        }

        if (!in_array($env, $testEnvs, true) && is_file($p = "$path.local")) {
            $this->doLoad($overrideExistingVars, [$p]);
            $env = (isset($_SERVER[$k]) && $_SERVER[$k] ? $_SERVER[$k] :
                (isset($_ENV[$k]) && $_ENV[$k] ? $_ENV[$k] : $env));
        }

        if ('local' === $env) {
            return;
        }

        if (is_file($p = "$path.$env")) {
            $this->doLoad($overrideExistingVars, [$p]);
        }

        if (is_file($p = "$path.$env.local")) {
            $this->doLoad($overrideExistingVars, [$p]);
        }
    }

    /**
     * Loads env vars from .env.local.php if the file exists or from the other .env files otherwise.
     *
     * This method also configures the APP_DEBUG env var according to the current APP_ENV.
     *
     * See method loadEnv() for rules related to .env files.
     */
    public function bootEnv($path, $defaultEnv = 'dev', array $testEnvs = ['test'], $overrideExistingVars = false)
    {
        $p = $path . '.local.php';
        $env = is_file($p) ? include $p : null;
        $k = $this->envKey;

        $kv = isset($_SERVER[$k]) && $_SERVER[$k] ? $_SERVER[$k] :
            (isset($_ENV[$k]) && $_ENV[$k] ? $_ENV[$k] : $env[$k]);

        if (is_array($env) && ($overrideExistingVars || !isset($env[$k]) || $kv === $env[$k])) {
            $this->populate($env, $overrideExistingVars);
        } else {
            $this->loadEnv($path, $k, $defaultEnv, $testEnvs, $overrideExistingVars);
        }

        $_SERVER += $_ENV;

        $k = $this->debugKey;
        $debug = isset($_SERVER[$k]) && $_SERVER[$k] ? $_SERVER[$k] : !in_array($_SERVER[$this->envKey], $this->prodEnvs, true);
        $_SERVER[$k] = $_ENV[$k] = (int)$debug || (!is_bool($debug) && filter_var($debug, FILTER_VALIDATE_BOOLEAN)) ? '1' : '0';
    }

    /**
     * Loads one or several .env files and enables override existing vars.
     *
     * @param string $path A file to load
     * @param string[] ...$extraPaths A list of additional files to load
     *
     * @throws PathException   when a file does not exist or is not readable
     */
    public function overload($path, ...$extraPaths)
    {
        $this->doLoad(true, func_get_args());
    }

    /**
     * Sets values as environment variables (via putenv, $_ENV, and $_SERVER).
     *
     * @param array $values An array of env variables
     * @param bool $overrideExistingVars true when existing environment variables must be overridden
     */
    public function populate(array $values, $overrideExistingVars = false)
    {
        $updateLoadedVars = false;
        $loadedVars = array_flip(explode(',',
            isset($_SERVER['SYMFONY_DOTENV_VARS']) && $_SERVER['SYMFONY_DOTENV_VARS'] ? $_SERVER['SYMFONY_DOTENV_VARS'] :
                (isset($_ENV['SYMFONY_DOTENV_VARS']) && $_ENV['SYMFONY_DOTENV_VARS'] ? $_ENV['SYMFONY_DOTENV_VARS'] : '')));

        $sn = 'HTTP_';
        $strlenSn = strlen($sn);

        foreach ($values as $name => $value) {
            $startsSn = strlen($name) >= $strlenSn && substr($name, 0, $strlenSn) == $sn;
            $notHttpName = !$startsSn;

            if (isset($_SERVER[$name]) && $notHttpName && !isset($_ENV[$name])) {
                $_ENV[$name] = $_SERVER[$name];
            }

            // don't check existence with getenv() because of thread safety issues
            if (!isset($loadedVars[$name]) && !$overrideExistingVars && isset($_ENV[$name])) {
                continue;
            }

            if ($this->usePutenv) {
                putenv("$name=$value");
            }

            $_ENV[$name] = $value;
            if ($notHttpName) {
                $_SERVER[$name] = $value;
            }

            if (!isset($loadedVars[$name])) {
                $loadedVars[$name] = $updateLoadedVars = true;
            }
        }

        if ($updateLoadedVars) {
            unset($loadedVars['']);
            $loadedVars = implode(',', array_keys($loadedVars));
            $_ENV['SYMFONY_DOTENV_VARS'] = $_SERVER['SYMFONY_DOTENV_VARS'] = $loadedVars;

            if ($this->usePutenv) {
                putenv('SYMFONY_DOTENV_VARS=' . $loadedVars);
            }
        }
    }

    /**
     * Parses the contents of an .env file.
     *
     * @param string $data The data to be parsed
     * @param string $path The original file name where data where stored (used for more meaningful error messages)
     *
     * @throws FormatException when a file has a syntax error
     */
    public function parse($data, $path = '.env')
    {
//        $this->path = $path;
        $this->data = str_replace(["\r\n", "\r"], "\n", $data);
        $this->lineno = 1;
        $this->cursor = 0;
        $this->end = strlen($this->data);
        $state = self::STATE_VARNAME;
        $this->values = [];
        $name = '';

        $this->skipEmptyLines();

        while ($this->cursor < $this->end) {
            switch ($state) {
                case self::STATE_VARNAME:
                    $name = $this->lexVarname();
                    $state = self::STATE_VALUE;
                    break;

                case self::STATE_VALUE:
                    $this->values[$name] = $this->lexValue();
                    $state = self::STATE_VARNAME;
                    break;
            }
        }

        if (self::STATE_VALUE === $state) {
            $this->values[$name] = '';
        }

        try {
            return $this->values;
        } finally {
            $this->values = [];
            unset($this->path, $this->cursor, $this->lineno, $this->data, $this->end);
        }
    }

    private function lexVarname()
    {
        // var name + optional export
        if (!preg_match('/(export[ \t]++)?(' . self::VARNAME_REGEX . ')/A', $this->data, $matches, 0, $this->cursor)) {
            throw $this->createFormatException('Invalid character in variable name');
        }
        $this->moveCursor($matches[0]);

        if ($this->cursor === $this->end || "\n" === $this->data[$this->cursor] || '#' === $this->data[$this->cursor]) {
            if ($matches[1]) {
                throw $this->createFormatException('Unable to unset an environment variable');
            }

            throw $this->createFormatException('Missing = in the environment variable declaration');
        }

        if (' ' === $this->data[$this->cursor] || "\t" === $this->data[$this->cursor]) {
            throw $this->createFormatException('Whitespace characters are not supported after the variable name');
        }

        if ('=' !== $this->data[$this->cursor]) {
            throw $this->createFormatException('Missing = in the environment variable declaration');
        }
        ++$this->cursor;

        return $matches[2];
    }

    private function lexValue()
    {
        if (preg_match('/[ \t]*+(?:#.*)?$/Am', $this->data, $matches, 0, $this->cursor)) {
            $this->moveCursor($matches[0]);
            $this->skipEmptyLines();

            return '';
        }

        if (' ' === $this->data[$this->cursor] || "\t" === $this->data[$this->cursor]) {
            throw $this->createFormatException('Whitespace are not supported before the value');
        }

        $loadedVars = array_flip(explode(',', (isset($_SERVER['SYMFONY_DOTENV_VARS']) && $_SERVER['SYMFONY_DOTENV_VARS'] ?
            $_SERVER['SYMFONY_DOTENV_VARS'] : (isset($_ENV['SYMFONY_DOTENV_VARS']) &&
            $_ENV['SYMFONY_DOTENV_VARS'] ? $_ENV['SYMFONY_DOTENV_VARS'] : ''))));

        unset($loadedVars['']);
        $v = '';

        do {
            if ("'" === $this->data[$this->cursor]) {
                $len = 0;

                do {
                    if ($this->cursor + ++$len === $this->end) {
                        $this->cursor += $len;

                        throw $this->createFormatException('Missing quote to end the value');
                    }
                } while ("'" !== $this->data[$this->cursor + $len]);

                $v .= substr($this->data, 1 + $this->cursor, $len - 1);
                $this->cursor += 1 + $len;
            } elseif ('"' === $this->data[$this->cursor]) {
                $value = '';

                if (++$this->cursor === $this->end) {
                    throw $this->createFormatException('Missing quote to end the value');
                }

                while ('"' !== $this->data[$this->cursor] || ('\\' === $this->data[$this->cursor - 1] && '\\' !== $this->data[$this->cursor - 2])) {
                    $value .= $this->data[$this->cursor];
                    ++$this->cursor;

                    if ($this->cursor === $this->end) {
                        throw $this->createFormatException('Missing quote to end the value');
                    }
                }
                ++$this->cursor;
                $value = str_replace(['\\"', '\r', '\n'], ['"', "\r", "\n"], $value);
                $resolvedValue = $value;
                $resolvedValue = $this->resolveVariables($resolvedValue, $loadedVars);
//                $resolvedValue = $this->resolveCommands($resolvedValue, $loadedVars);
                $resolvedValue = str_replace('\\\\', '\\', $resolvedValue);
                $v .= $resolvedValue;
            } else {
                $value = '';
                $prevChr = $this->data[$this->cursor - 1];
                while ($this->cursor < $this->end && !in_array($this->data[$this->cursor], ["\n", '"', "'"], true) && !((' ' === $prevChr || "\t" === $prevChr) && '#' === $this->data[$this->cursor])) {
                    if ('\\' === $this->data[$this->cursor] && isset($this->data[$this->cursor + 1]) && ('"' === $this->data[$this->cursor + 1] || "'" === $this->data[$this->cursor + 1])) {
                        ++$this->cursor;
                    }

                    $value .= $prevChr = $this->data[$this->cursor];

                    if ('$' === $this->data[$this->cursor] && isset($this->data[$this->cursor + 1]) && '(' === $this->data[$this->cursor + 1]) {
                        ++$this->cursor;
                        $value .= '(' . $this->lexNestedExpression() . ')';
                    }

                    ++$this->cursor;
                }
                $value = rtrim($value);
                $resolvedValue = $value;
                $resolvedValue = $this->resolveVariables($resolvedValue, $loadedVars);
//                $resolvedValue = $this->resolveCommands($resolvedValue, $loadedVars);
                $resolvedValue = str_replace('\\\\', '\\', $resolvedValue);

                if ($resolvedValue === $value && preg_match('/\s+/', $value)) {
                    throw $this->createFormatException('A value containing spaces must be surrounded by quotes');
                }

                $v .= $resolvedValue;

                if ($this->cursor < $this->end && '#' === $this->data[$this->cursor]) {
                    break;
                }
            }
        } while ($this->cursor < $this->end && "\n" !== $this->data[$this->cursor]);

        $this->skipEmptyLines();

        return $v;
    }

    private function lexNestedExpression()
    {
        ++$this->cursor;
        $value = '';

        while ("\n" !== $this->data[$this->cursor] && ')' !== $this->data[$this->cursor]) {
            $value .= $this->data[$this->cursor];

            if ('(' === $this->data[$this->cursor]) {
                $value .= $this->lexNestedExpression() . ')';
            }

            ++$this->cursor;

            if ($this->cursor === $this->end) {
                throw $this->createFormatException('Missing closing parenthesis.');
            }
        }

        if ("\n" === $this->data[$this->cursor]) {
            throw $this->createFormatException('Missing closing parenthesis.');
        }

        return $value;
    }

    private function skipEmptyLines()
    {
        if (preg_match('/(?:\s*+(?:#[^\n]*+)?+)++/A', $this->data, $match, 0, $this->cursor)) {
            $this->moveCursor($match[0]);
        }
    }

//    private function resolveCommands($value, array $loadedVars)
//    {
//        if (!stristr($value, '$')) {
//            return $value;
//        }
//
//        $regex = '/
//            (\\\\)?               # escaped with a backslash?
//            \$
//            (?<cmd>
//                \(                # require opening parenthesis
//                ([^()]|\g<cmd>)+  # allow any number of non-parens, or balanced parens (by nesting the <cmd> expression recursively)
//                \)                # require closing paren
//            )
//        /x';
//
//        return preg_replace_callback($regex, function ($matches) use ($loadedVars) {
//            if ('\\' === $matches[1]) {
//                return substr($matches[0], 1);
//            }
//
//            if ('\\' === \DIRECTORY_SEPARATOR) {
//                throw new \Exception('Resolving commands is not supported on Windows.');
//            }
//
//            if (!class_exists(Process::class)) {
//                throw new \Exception('Resolving commands requires the Symfony Process component.');
//            }
//
//            $process = method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline('echo ' . $matches[0]) : new Process('echo ' . $matches[0]);
//
//            if (!method_exists(Process::class, 'fromShellCommandline') && method_exists(Process::class, 'inheritEnvironmentVariables')) {
//                // Symfony 3.4 does not inherit env vars by default:
//                $process->inheritEnvironmentVariables();
//            }
//
//            $sn = 'HTTP_';
//            $strlenSn = strlen($sn);
//
//            $env = [];
//            foreach ($this->values as $name => $value) {
//                $startsSn = strlen($name) >= $strlenSn && substr($name, 0, $strlenSn) == $sn;
//                $notHttpName = !$startsSn;
//
//                if (isset($loadedVars[$name]) || (!isset($_ENV[$name]) && !(isset($_SERVER[$name]) && $notHttpName))) {
//                    $env[$name] = $value;
//                }
//            }
//            $process->setEnv($env);
//
//            try {
//                $process->mustRun();
//            } catch (ProcessException $e) {
//                throw $this->createFormatException(sprintf('Issue expanding a command (%s)', $process->getErrorOutput()));
//            }
//
//            return preg_replace('/[\r\n]+$/', '', $process->getOutput());
//        }, $value);
//    }

    private function resolveVariables($value, array $loadedVars)
    {
        if (!stristr($value, '$')) {
            return $value;
        }

        $regex = '/
            (?<!\\\\)
            (?P<backslashes>\\\\*)             # escaped with a backslash?
            \$
            (?!\()                             # no opening parenthesis
            (?P<opening_brace>\{)?             # optional brace
            (?P<name>' . self::VARNAME_REGEX . ')? # var name
            (?P<default_value>:[-=][^\}]++)?   # optional default value
            (?P<closing_brace>\})?             # optional closing brace
        /x';

        return preg_replace_callback($regex, function ($matches) use ($loadedVars) {
            // odd number of backslashes means the $ character is escaped
            if (1 === strlen($matches['backslashes']) % 2) {
                return substr($matches[0], 1);
            }

            // unescaped $ not followed by variable name
            if (!isset($matches['name'])) {
                return $matches[0];
            }

            if ('{' === $matches['opening_brace'] && !isset($matches['closing_brace'])) {
                throw $this->createFormatException('Unclosed braces on variable expansion');
            }

            $name = $matches['name'];
            $sn = 'HTTP_';
            $strlenSn = strlen($sn);
            $startsSn = strlen($name) >= $strlenSn && substr($name, 0, $strlenSn) == $sn;
            $notHttpName = !$startsSn;

            if (isset($loadedVars[$name]) && isset($this->values[$name])) {
                $value = $this->values[$name];
            } elseif (isset($_ENV[$name])) {
                $value = $_ENV[$name];
            } elseif (isset($_SERVER[$name]) && $notHttpName) {
                $value = $_SERVER[$name];
            } elseif (isset($this->values[$name])) {
                $value = $this->values[$name];
            } else {
                $value = (string)getenv($name);
            }

            if ('' === $value && isset($matches['default_value']) && '' !== $matches['default_value']) {
                $unsupportedChars = strpbrk($matches['default_value'], '\'"{$');
                if (false !== $unsupportedChars) {
                    throw $this->createFormatException(sprintf('Unsupported character "%s" found in the default value of variable "$%s".', $unsupportedChars[0], $name));
                }

                $value = substr($matches['default_value'], 2);

                if ('=' === $matches['default_value'][1]) {
                    $this->values[$name] = $value;
                }
            }

            if (!$matches['opening_brace'] && isset($matches['closing_brace'])) {
                $value .= '}';
            }

            return $matches['backslashes'] . $value;
        }, $value);
    }

    private function moveCursor($text)
    {
        $this->cursor += strlen($text);
        $this->lineno += substr_count($text, "\n");
    }

    private function createFormatException($message)
    {
        return new FormatException($message);
    }

    private function doLoad($overrideExistingVars, array $paths)
    {
        foreach ($paths as $path) {
            if (!is_readable($path) || is_dir($path)) {
                throw new PathException($path);
            }

            $this->populate($this->parse(file_get_contents($path), $path), $overrideExistingVars);
        }
    }
}
