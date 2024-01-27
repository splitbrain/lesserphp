<?php
/**
 * http://leafo.net/lessphp
 *
 * LESS CSS compiler, adapted from http://lesscss.org
 *
 * Copyright 2013, Leaf Corcoran <leafot@gmail.com>
 * Copyright 2016, Marcus Schwarz <github@maswaba.de>
 * Licensed under MIT or GPLv3, see LICENSE
 */

namespace LesserPHP;

use Exception;
use stdClass;

/**
 * The LESS compiler and parser.
 *
 * Converting LESS to CSS is a three stage process. The incoming file is parsed
 * by `Parser` into a syntax tree, then it is compiled into another tree
 * representing the CSS structure by `Lessc`. The CSS tree is fed into a
 * formatter, like `Formatter` which then outputs CSS as a string.
 *
 * During the first compile, all values are *reduced*, which means that their
 * types are brought to the lowest form before being dump as strings. This
 * handles math equations, variable dereferences, and the like.
 *
 * The `parse` function of `Lessc` is the entry point.
 *
 * In summary:
 *
 * The `Lessc` class creates an instance of the parser, feeds it LESS code,
 * then transforms the resulting tree to a CSS tree. This class also holds the
 * evaluation context, such as all available mixins and variables at any given
 * time.
 *
 * The `Parser` class is only concerned with parsing its input.
 *
 * The `Formatter` takes a CSS tree, and dumps it to a formatted string,
 * handling things like indentation.
 */
class Lessc
{
    public static $VERSION = "v0.6.0";

    public Parser $parser;
    public $env;
    public $scope;
    public FormatterClassic $formatter;

    public static $TRUE = ["keyword", "true"];
    public static $FALSE = ["keyword", "false"];

    protected $libFunctions = [];
    protected $registeredVars = [];
    protected $preserveComments = false;

    public $vPrefix = '@'; // prefix of abstract properties
    public $mPrefix = '$'; // prefix of abstract blocks
    public $parentSelector = '&';

    public static $lengths = ["px", "m", "cm", "mm", "in", "pt", "pc"];
    public static $times = ["s", "ms"];
    public static $angles = ["rad", "deg", "grad", "turn"];

    public static $lengths_to_base = [1, 3779.52755906, 37.79527559, 3.77952756, 96, 1.33333333, 16];
    public $importDisabled = false;
    public $importDir = [];

    protected $numberPrecision = null;

    protected $allParsedFiles = [];

    // set to the parser that generated the current line when compiling
    // so we know how to create error messages
    protected $sourceParser = null;
    protected $sourceLoc = null;

    protected static $nextImportId = 0; // uniquely identify imports
    protected $parseFile;
    protected $formatterName;

    /**
     * attempts to find the path of an import url, returns null for css files
     */
    protected function findImport($url)
    {
        foreach ((array)$this->importDir as $dir) {
            $full = $dir . (substr($dir, -1) != '/' ? '/' : '') . $url;
            if ($this->fileExists($file = $full . '.less') || $this->fileExists($file = $full)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Check if a given file exists and is actually a file
     *
     * @param string $name file path
     * @return bool
     */
    protected function fileExists(string $name): bool
    {
        return is_file($name);
    }

    public static function compressList($items, $delim)
    {
        if (!isset($items[1]) && isset($items[0])) return $items[0];
        else return ['list', $delim, $items];
    }

    /**
     * @todo maybe move to utils class
     */
    public static function preg_quote(string $what): string
    {
        return preg_quote($what, '/');
    }

    /**
     * @throws Exception
     */
    protected function tryImport($importPath, $parentBlock, $out)
    {
        if ($importPath[0] == "function" && $importPath[1] == "url") {
            $importPath = $this->flattenList($importPath[2]);
        }

        $str = $this->coerceString($importPath);
        if ($str === null) return false;

        $url = $this->compileValue($this->lib_e($str));

        // don't import if it ends in css
        if (substr_compare($url, '.css', -4, 4) === 0) return false;

        $realPath = $this->findImport($url);

        if ($realPath === null) return false;

        if ($this->importDisabled) {
            return [false, "/* import disabled */"];
        }

        if (isset($this->allParsedFiles[realpath($realPath)])) {
            return [false, null];
        }

        $this->addParsedFile($realPath);
        $parser = $this->makeParser($realPath);
        $root = $parser->parse(file_get_contents($realPath));

        // set the parents of all the block props
        foreach ($root->props as $prop) {
            if ($prop[0] == "block") {
                $prop[1]->parent = $parentBlock;
            }
        }

        // copy mixins into scope, set their parents
        // bring blocks from import into current block
        // TODO: need to mark the source parser these came from this file
        foreach ($root->children as $childName => $child) {
            if (isset($parentBlock->children[$childName])) {
                $parentBlock->children[$childName] = array_merge(
                    $parentBlock->children[$childName],
                    $child);
            } else {
                $parentBlock->children[$childName] = $child;
            }
        }

        $pi = pathinfo($realPath);
        $dir = $pi["dirname"];

        [$top, $bottom] = $this->sortProps($root->props, true);
        $this->compileImportedProps($top, $parentBlock, $out, $parser, $dir);

        return [true, $bottom, $parser, $dir];
    }

    /**
     * @throws Exception
     */
    protected function compileImportedProps($props, $block, $out, $sourceParser, $importDir)
    {
        $oldSourceParser = $this->sourceParser;

        $oldImport = $this->importDir;

        // TODO: this is because the importDir api is stupid
        $this->importDir = (array)$this->importDir;
        array_unshift($this->importDir, $importDir);

        foreach ($props as $prop) {
            $this->compileProp($prop, $block, $out);
        }

        $this->importDir = $oldImport;
        $this->sourceParser = $oldSourceParser;
    }

    /**
     * Recursively compiles a block.
     *
     * A block is analogous to a CSS block in most cases. A single LESS document
     * is encapsulated in a block when parsed, but it does not have parent tags
     * so all of it's children appear on the root level when compiled.
     *
     * Blocks are made up of props and children.
     *
     * Props are property instructions, array tuples which describe an action
     * to be taken, eg. write a property, set a variable, mixin a block.
     *
     * The children of a block are just all the blocks that are defined within.
     * This is used to look up mixins when performing a mixin.
     *
     * Compiling the block involves pushing a fresh environment on the stack,
     * and iterating through the props, compiling each one.
     *
     * @see compileProp()
     * @throws Exception
     */
    protected function compileBlock($block)
    {
        switch ($block->type) {
            case "root":
                $this->compileRoot($block);
                break;
            case null:
                $this->compileCSSBlock($block);
                break;
            case "media":
                $this->compileMedia($block);
                break;
            case "directive":
                $name = "@" . $block->name;
                if (!empty($block->value)) {
                    $name .= " " . $this->compileValue($this->reduce($block->value));
                }

                $this->compileNestedBlock($block, [$name]);
                break;
            default:
                $block->parser->throwError("unknown block type: $block->type\n", $block->count);
        }
    }

    /**
     * @throws Exception
     */
    protected function compileCSSBlock($block)
    {
        $env = $this->pushEnv();

        $selectors = $this->compileSelectors($block->tags);
        $env->selectors = $this->multiplySelectors($selectors);
        $out = $this->makeOutputBlock(null, $env->selectors);

        $this->scope->children[] = $out;
        $this->compileProps($block, $out);

        $block->scope = $env; // mixins carry scope with them!
        $this->popEnv();
    }

    /**
     * @throws Exception
     */
    protected function compileMedia($media)
    {
        $env = $this->pushEnv($media);
        $parentScope = $this->mediaParent($this->scope);

        $query = $this->compileMediaQuery($this->multiplyMedia($env));

        $this->scope = $this->makeOutputBlock($media->type, [$query]);
        $parentScope->children[] = $this->scope;

        $this->compileProps($media, $this->scope);

        if (count($this->scope->lines) > 0) {
            $orphanSelelectors = $this->findClosestSelectors();
            if (!is_null($orphanSelelectors)) {
                $orphan = $this->makeOutputBlock(null, $orphanSelelectors);
                $orphan->lines = $this->scope->lines;
                array_unshift($this->scope->children, $orphan);
                $this->scope->lines = [];
            }
        }

        $this->scope = $this->scope->parent;
        $this->popEnv();
    }

    protected function mediaParent($scope)
    {
        while (!empty($scope->parent)) {
            if (!empty($scope->type) && $scope->type != "media") {
                break;
            }
            $scope = $scope->parent;
        }

        return $scope;
    }

    /**
     * @throws Exception
     */
    protected function compileNestedBlock($block, $selectors)
    {
        $this->pushEnv($block);
        $this->scope = $this->makeOutputBlock($block->type, $selectors);
        $this->scope->parent->children[] = $this->scope;

        $this->compileProps($block, $this->scope);

        $this->scope = $this->scope->parent;
        $this->popEnv();
    }

    /**
     * @throws Exception
     */
    protected function compileRoot($root)
    {
        $this->pushEnv();
        $this->scope = $this->makeOutputBlock($root->type);
        $this->compileProps($root, $this->scope);
        $this->popEnv();
    }

    /**
     * @throws Exception
     */
    protected function compileProps($block, $out)
    {
        foreach ($this->sortProps($block->props) as $prop) {
            $this->compileProp($prop, $block, $out);
        }
        $out->lines = $this->deduplicate($out->lines);
    }

    /**
     * Deduplicate lines in a block. Comments are not deduplicated. If a
     * duplicate rule is detected, the comments immediately preceding each
     * occurrence are consolidated.
     */
    protected function deduplicate($lines)
    {
        $unique = [];
        $comments = [];

        foreach ($lines as $line) {
            if (strpos($line, '/*') === 0) {
                $comments[] = $line;
                continue;
            }
            if (!in_array($line, $unique)) {
                $unique[] = $line;
            }
            array_splice($unique, array_search($line, $unique), 0, $comments);
            $comments = [];
        }
        return array_merge($unique, $comments);
    }

    protected function sortProps($props, $split = false)
    {
        $vars = [];
        $imports = [];
        $other = [];
        $stack = [];

        foreach ($props as $prop) {
            switch ($prop[0]) {
                case "comment":
                    $stack[] = $prop;
                    break;
                case "assign":
                    $stack[] = $prop;
                    if (isset($prop[1][0]) && $prop[1][0] == $this->vPrefix) {
                        $vars = array_merge($vars, $stack);
                    } else {
                        $other = array_merge($other, $stack);
                    }
                    $stack = [];
                    break;
                case "import":
                    $id = self::$nextImportId++;
                    $prop[] = $id;
                    $stack[] = $prop;
                    $imports = array_merge($imports, $stack);
                    $other[] = ["import_mixin", $id];
                    $stack = [];
                    break;
                default:
                    $stack[] = $prop;
                    $other = array_merge($other, $stack);
                    $stack = [];
                    break;
            }
        }
        $other = array_merge($other, $stack);

        if ($split) {
            return [array_merge($vars, $imports, $vars), $other];
        } else {
            return array_merge($vars, $imports, $vars, $other);
        }
    }

    /**
     * @throws Exception
     */
    protected function compileMediaQuery($queries)
    {
        $compiledQueries = [];
        foreach ($queries as $query) {
            $parts = [];
            foreach ($query as $q) {
                switch ($q[0]) {
                    case "mediaType":
                        $parts[] = implode(" ", array_slice($q, 1));
                        break;
                    case "mediaExp":
                        if (isset($q[2])) {
                            $parts[] = "($q[1]: " .
                                $this->compileValue($this->reduce($q[2])) . ")";
                        } else {
                            $parts[] = "($q[1])";
                        }
                        break;
                    case "variable":
                        $parts[] = $this->compileValue($this->reduce($q));
                        break;
                }
            }

            if (count($parts) > 0) {
                $compiledQueries[] = implode(" and ", $parts);
            }
        }

        $out = "@media";
        if (!empty($parts)) {
            $out .= " " .
                implode($this->formatter->selectorSeparator, $compiledQueries);
        }
        return $out;
    }

    protected function multiplyMedia($env, $childQueries = null)
    {
        if (is_null($env) ||
            !empty($env->block->type) && $env->block->type != "media") {
            return $childQueries;
        }

        // plain old block, skip
        if (empty($env->block->type)) {
            return $this->multiplyMedia($env->parent, $childQueries);
        }

        $out = [];
        $queries = $env->block->queries;
        if (is_null($childQueries)) {
            $out = $queries;
        } else {
            foreach ($queries as $parent) {
                foreach ($childQueries as $child) {
                    $out[] = array_merge($parent, $child);
                }
            }
        }

        return $this->multiplyMedia($env->parent, $out);
    }

    protected function expandParentSelectors(&$tag, $replace)
    {
        $parts = explode("$&$", $tag);
        $count = 0;
        foreach ($parts as &$part) {
            $part = str_replace($this->parentSelector, $replace, $part, $c);
            $count += $c;
        }
        $tag = implode($this->parentSelector, $parts);
        return $count;
    }

    protected function findClosestSelectors()
    {
        $env = $this->env;
        $selectors = null;
        while ($env !== null) {
            if (isset($env->selectors)) {
                $selectors = $env->selectors;
                break;
            }
            $env = $env->parent;
        }

        return $selectors;
    }


    // multiply $selectors against the nearest selectors in env
    protected function multiplySelectors($selectors)
    {
        // find parent selectors

        $parentSelectors = $this->findClosestSelectors();
        if (is_null($parentSelectors)) {
            // kill parent reference in top level selector
            foreach ($selectors as &$s) {
                $this->expandParentSelectors($s, "");
            }

            return $selectors;
        }

        $out = [];
        foreach ($parentSelectors as $parent) {
            foreach ($selectors as $child) {
                $count = $this->expandParentSelectors($child, $parent);

                // don't prepend the parent tag if & was used
                if ($count > 0) {
                    $out[] = trim($child);
                } else {
                    $out[] = trim($parent . ' ' . $child);
                }
            }
        }

        return $out;
    }

    /**
     * reduces selector expressions
     * @throws Exception
     */
    protected function compileSelectors($selectors)
    {
        $out = [];

        foreach ($selectors as $s) {
            if (is_array($s)) {
                [, $value] = $s;
                $out[] = trim($this->compileValue($this->reduce($value)));
            } else {
                $out[] = $s;
            }
        }

        return $out;
    }

    protected function eq($left, $right)
    {
        return $left == $right;
    }

    /**
     * @return bool
     * @throws Exception
     */
    protected function patternMatch($block, $orderedArgs, $keywordArgs)
    {
        // match the guards if it has them
        // any one of the groups must have all its guards pass for a match
        if (!empty($block->guards)) {
            $groupPassed = false;
            foreach ($block->guards as $guardGroup) {
                foreach ($guardGroup as $guard) {
                    $this->pushEnv();
                    $this->zipSetArgs($block->args, $orderedArgs, $keywordArgs);

                    $negate = false;
                    if ($guard[0] == "negate") {
                        $guard = $guard[1];
                        $negate = true;
                    }

                    $passed = $this->reduce($guard) == self::$TRUE;
                    if ($negate) $passed = !$passed;

                    $this->popEnv();

                    if ($passed) {
                        $groupPassed = true;
                    } else {
                        $groupPassed = false;
                        break;
                    }
                }

                if ($groupPassed) break;
            }

            if (!$groupPassed) {
                return false;
            }
        }

        if (empty($block->args)) {
            return $block->isVararg || empty($orderedArgs) && empty($keywordArgs);
        }

        $remainingArgs = $block->args;
        if ($keywordArgs) {
            $remainingArgs = [];
            foreach ($block->args as $arg) {
                if ($arg[0] == "arg" && isset($keywordArgs[$arg[1]])) {
                    continue;
                }

                $remainingArgs[] = $arg;
            }
        }

        $i = -1; // no args
        // try to match by arity or by argument literal
        foreach ($remainingArgs as $i => $arg) {
            switch ($arg[0]) {
                case "lit":
                    if (empty($orderedArgs[$i]) || !$this->eq($arg[1], $orderedArgs[$i])) {
                        return false;
                    }
                    break;
                case "arg":
                    // no arg and no default value
                    if (!isset($orderedArgs[$i]) && !isset($arg[2])) {
                        return false;
                    }
                    break;
                case "rest":
                    $i--; // rest can be empty
                    break 2;
            }
        }

        if ($block->isVararg) {
            return true; // not having enough is handled above
        } else {
            $numMatched = $i + 1;
            // greater than because default values always match
            return $numMatched >= count($orderedArgs);
        }
    }

    /**
     * @throws Exception
     */
    protected function patternMatchAll($blocks, $orderedArgs, $keywordArgs, $skip = [])
    {
        $matches = null;
        foreach ($blocks as $block) {
            // skip seen blocks that don't have arguments
            if (isset($skip[$block->id]) && !isset($block->args)) {
                continue;
            }

            if ($this->patternMatch($block, $orderedArgs, $keywordArgs)) {
                $matches[] = $block;
            }
        }

        return $matches;
    }

    /**
     * attempt to find blocks matched by path and args
     * @throws Exception
     */
    protected function findBlocks($searchIn, $path, $orderedArgs, $keywordArgs, $seen = [])
    {
        if ($searchIn == null) return null;
        if (isset($seen[$searchIn->id])) return null;
        $seen[$searchIn->id] = true;

        $name = $path[0];

        if (isset($searchIn->children[$name])) {
            $blocks = $searchIn->children[$name];
            if (count($path) == 1) {
                $matches = $this->patternMatchAll($blocks, $orderedArgs, $keywordArgs, $seen);
                if (!empty($matches)) {
                    // This will return all blocks that match in the closest
                    // scope that has any matching block, like lessjs
                    return $matches;
                }
            } else {
                $matches = [];
                foreach ($blocks as $subBlock) {
                    $subMatches = $this->findBlocks($subBlock,
                        array_slice($path, 1), $orderedArgs, $keywordArgs, $seen);

                    if (!is_null($subMatches)) {
                        foreach ($subMatches as $sm) {
                            $matches[] = $sm;
                        }
                    }
                }

                return count($matches) > 0 ? $matches : null;
            }
        }
        if ($searchIn->parent === $searchIn) return null;
        return $this->findBlocks($searchIn->parent, $path, $orderedArgs, $keywordArgs, $seen);
    }

    /**
     * sets all argument names in $args to either the default value
     * or the one passed in through $values
     *
     * @throws Exception
     */
    protected function zipSetArgs($args, $orderedValues, $keywordValues)
    {
        $assignedValues = [];

        $i = 0;
        foreach ($args as $a) {
            if ($a[0] == "arg") {
                if (isset($keywordValues[$a[1]])) {
                    // has keyword arg
                    $value = $keywordValues[$a[1]];
                } elseif (isset($orderedValues[$i])) {
                    // has ordered arg
                    $value = $orderedValues[$i];
                    $i++;
                } elseif (isset($a[2])) {
                    // has default value
                    $value = $a[2];
                } else {
                    $this->throwError("Failed to assign arg " . $a[1]);
                }

                $value = $this->reduce($value);
                $this->set($a[1], $value);
                $assignedValues[] = $value;
            } else {
                // a lit
                $i++;
            }
        }

        // check for a rest
        $last = end($args);
        if ($last !== false && $last[0] === "rest") {
            $rest = array_slice($orderedValues, count($args) - 1);
            $this->set($last[1], $this->reduce(["list", " ", $rest]));
        }

        // wow is this the only true use of PHP's + operator for arrays?
        $this->env->arguments = $assignedValues + $orderedValues;
    }

    /**
     * compile a prop and update $lines or $blocks appropriately
     * @throws Exception
     */
    protected function compileProp($prop, $block, $out)
    {
        // set error position context
        $this->sourceLoc = $prop[-1] ?? -1;

        switch ($prop[0]) {
            case 'assign':
                [, $name, $value] = $prop;
                if ($name[0] == $this->vPrefix) {
                    $this->set($name, $value);
                } else {
                    $out->lines[] = $this->formatter->property($name,
                        $this->compileValue($this->reduce($value)));
                }
                break;
            case 'block':
                [, $child] = $prop;
                $this->compileBlock($child);
                break;
            case 'ruleset':
            case 'mixin':
                [, $path, $args, $suffix] = $prop;

                $orderedArgs = [];
                $keywordArgs = [];
                foreach ((array)$args as $arg) {
                    switch ($arg[0]) {
                        case "arg":
                            if (!isset($arg[2])) {
                                $orderedArgs[] = $this->reduce(["variable", $arg[1]]);
                            } else {
                                $keywordArgs[$arg[1]] = $this->reduce($arg[2]);
                            }
                            break;

                        case "lit":
                            $orderedArgs[] = $this->reduce($arg[1]);
                            break;
                        default:
                            $this->throwError("Unknown arg type: " . $arg[0]);
                    }
                }

                $mixins = $this->findBlocks($block, $path, $orderedArgs, $keywordArgs);

                if ($mixins === null) {
                    $block->parser->throwError("{$prop[1][0]} is undefined", $block->count);
                }

                if (strpos($prop[1][0], "$") === 0) {
                    //Use Ruleset Logic - Only last element
                    $mixins = [array_pop($mixins)];
                }

                foreach ($mixins as $mixin) {
                    if ($mixin === $block && !$orderedArgs) {
                        continue;
                    }

                    $haveScope = false;
                    if (isset($mixin->parent->scope)) {
                        $haveScope = true;
                        $mixinParentEnv = $this->pushEnv();
                        $mixinParentEnv->storeParent = $mixin->parent->scope;
                    }

                    $haveArgs = false;
                    if (isset($mixin->args)) {
                        $haveArgs = true;
                        $this->pushEnv();
                        $this->zipSetArgs($mixin->args, $orderedArgs, $keywordArgs);
                    }

                    $oldParent = $mixin->parent;
                    if ($mixin != $block) $mixin->parent = $block;

                    foreach ($this->sortProps($mixin->props) as $subProp) {
                        if ($suffix !== null &&
                            $subProp[0] == "assign" &&
                            is_string($subProp[1]) &&
                            $subProp[1][0] != $this->vPrefix) {
                            $subProp[2] = ['list', ' ', [$subProp[2], ['keyword', $suffix]]];
                        }

                        $this->compileProp($subProp, $mixin, $out);
                    }

                    $mixin->parent = $oldParent;

                    if ($haveArgs) $this->popEnv();
                    if ($haveScope) $this->popEnv();
                }

                break;
            case 'raw':
            case "comment":
                $out->lines[] = $prop[1];
                break;
            case "directive":
                [, $name, $value] = $prop;
                $out->lines[] = "@$name " . $this->compileValue($this->reduce($value)) . ';';
                break;
            case "import":
                [, $importPath, $importId] = $prop;
                $importPath = $this->reduce($importPath);

                if (!isset($this->env->imports)) {
                    $this->env->imports = [];
                }

                $result = $this->tryImport($importPath, $block, $out);

                $this->env->imports[$importId] = $result === false ?
                    [false, "@import " . $this->compileValue($importPath) . ";"] :
                    $result;

                break;
            case "import_mixin":
                [, $importId] = $prop;
                $import = $this->env->imports[$importId];
                if ($import[0] === false) {
                    if (isset($import[1])) {
                        $out->lines[] = $import[1];
                    }
                } else {
                    [, $bottom, $parser, $importDir] = $import;
                    $this->compileImportedProps($bottom, $block, $out, $parser, $importDir);
                }

                break;
            default:
                $block->parser->throwError("unknown op: $prop[0]\n", $block->count);
        }
    }


    /**
     * Compiles a primitive value into a CSS property value.
     *
     * Values in lessphp are typed by being wrapped in arrays, their format is
     * typically:
     *
     *     array(type, contents [, additional_contents]*)
     *
     * The input is expected to be reduced. This function will not work on
     * things like expressions and variables.
     * @throws Exception
     */
    public function compileValue($value)
    {
        switch ($value[0]) {
            case 'list':
                // [1] - delimiter
                // [2] - array of values
                return implode($value[1], array_map([$this, 'compileValue'], $value[2]));
            case 'raw_color':
                if (!empty($this->formatter->compressColors)) {
                    return $this->compileValue($this->coerceColor($value));
                }
                return $value[1];
            case 'keyword':
                // [1] - the keyword
                return $value[1];
            case 'number':
                [, $num, $unit] = $value;
                // [1] - the number
                // [2] - the unit
                if ($this->numberPrecision !== null) {
                    $num = round($num, $this->numberPrecision);
                }
                return $num . $unit;
            case 'string':
                // [1] - contents of string (includes quotes)
                [, $delim, $content] = $value;
                foreach ($content as &$part) {
                    if (is_array($part)) {
                        $part = $this->compileValue($part);
                    }
                }
                return $delim . implode($content) . $delim;
            case 'color':
                // [1] - red component (either number or a %)
                // [2] - green component
                // [3] - blue component
                // [4] - optional alpha component
                [, $r, $g, $b] = $value;
                $r = round($r);
                $g = round($g);
                $b = round($b);

                if (count($value) == 5 && $value[4] != 1) { // rgba
                    return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . $value[4] . ')';
                }

                $h = sprintf("#%02x%02x%02x", $r, $g, $b);

                if (!empty($this->formatter->compressColors)) {
                    // Converting hex color to short notation (e.g. #003399 to #039)
                    if ($h[1] === $h[2] && $h[3] === $h[4] && $h[5] === $h[6]) {
                        $h = '#' . $h[1] . $h[3] . $h[5];
                    }
                }

                return $h;

            case 'function':
                [, $name, $args] = $value;
                return $name . '(' . $this->compileValue($args) . ')';
            default: // assumed to be unit
                $this->throwError("unknown value type: $value[0]");
        }
    }

    /**
     * Returns the value of the first argument raised to the power of the second argument.
     *
     * @link https://lesscss.org/functions/#math-functions-pow
     * @throws Exception
     */
    protected function lib_pow(array $args): array
    {
        [$base, $exp] = $this->assertArgs($args, 2, "pow");
        return ["number", $this->assertNumber($base) ** $this->assertNumber($exp), $args[2][0][2]];
    }

    /**
     * Return the value of pi
     *
     * @link https://lesscss.org/functions/#math-functions-pi
     */
    protected function lib_pi(): float
    {
        return pi();
    }

    /**
     * Returns the value of the first argument modulus second argument.
     *
     * @link https://lesscss.org/functions/#math-functions-mod
     * @throws Exception
     */
    protected function lib_mod(array $args): array
    {
        [$a, $b] = $this->assertArgs($args, 2, "mod");
        return ["number", $this->assertNumber($a) % $this->assertNumber($b), $args[2][0][2]];
    }

    /**
     * Convert a number from one unit into another
     *
     * @link https://lesscss.org/functions/#misc-functions-convert
     * @throws Exception
     */
    protected function lib_convert(array $args): array
    {
        [$value, $to] = $this->assertArgs($args, 2, "convert");

        // If it's a keyword, grab the string version instead
        if (is_array($to) && $to[0] == "keyword") {
            $to = $to[1];
        }

        return $this->convert($value, $to);
    }

    /**
     * Calculates absolute value of a number. Keeps units as they are.
     *
     * @link https://lesscss.org/functions/#math-functions-abs
     * @throws Exception
     */
    protected function lib_abs(array $num): array
    {
        return ["number", abs($this->assertNumber($num)), $num[2]];
    }

    /**
     * Returns the lowest of one or more values
     *
     * @link https://lesscss.org/functions/#math-functions-min
     * @throws Exception
     */
    protected function lib_min(array $args): array
    {
        $values = $this->assertMinArgs($args, 1, "min");

        $first_format = $values[0][2];

        $min_index = 0;
        $min_value = $values[0][1];

        for ($a = 0; $a < sizeof($values); $a++) {
            $converted = $this->convert($values[$a], $first_format);

            if ($converted[1] < $min_value) {
                $min_index = $a;
                $min_value = $values[$a][1];
            }
        }

        return $values[$min_index];
    }

    /**
     * Returns the highest of one or more values
     *
     * @link https://lesscss.org/functions/#math-functions-max
     * @throws Exception
     */
    protected function lib_max(array $args): array
    {
        $values = $this->assertMinArgs($args, 1, "max");

        $first_format = $values[0][2];

        $max_index = 0;
        $max_value = $values[0][1];

        for ($a = 0; $a < sizeof($values); $a++) {
            $converted = $this->convert($values[$a], $first_format);

            if ($converted[1] > $max_value) {
                $max_index = $a;
                $max_value = $values[$a][1];
            }
        }

        return $values[$max_index];
    }

    /**
     * Calculates tangent function
     *
     * @link https://lesscss.org/functions/#math-functions-tan
     * @throws Exception
     */
    protected function lib_tan(array $num): float
    {
        return tan($this->assertNumber($num));
    }

    /**
     * Calculates sine function
     *
     * @link https://lesscss.org/functions/#math-functions-sin
     * @throws Exception
     */
    protected function lib_sin(array $num): float
    {
        return sin($this->assertNumber($num));
    }

    /**
     * Calculates cosine function
     *
     * @link https://lesscss.org/functions/#math-functions-cos
     * @throws Exception
     */
    protected function lib_cos(array $num): float
    {
        return cos($this->assertNumber($num));
    }

    /**
     * Calculates arctangent function
     *
     * @link https://lesscss.org/functions/#math-functions-atan
     * @throws Exception
     */
    protected function lib_atan(array $num): array
    {
        $num = atan($this->assertNumber($num));
        return ["number", $num, "rad"];
    }

    /**
     * Calculates arcsine function
     *
     * @link https://lesscss.org/functions/#math-functions-asin
     * @throws Exception
     */
    protected function lib_asin(array $num): array
    {
        $num = asin($this->assertNumber($num));
        return ["number", $num, "rad"];
    }

    /**
     * Calculates arccosine function
     *
     * @link https://lesscss.org/functions/#math-functions-acos
     * @throws Exception
     */
    protected function lib_acos(array $num): array
    {
        $num = acos($this->assertNumber($num));
        return ["number", $num, "rad"];
    }

    /**
     * Calculates square root of a number
     *
     * @link https://lesscss.org/functions/#math-functions-sqrt
     * @throws Exception
     */
    protected function lib_sqrt(array $num): float
    {
        return sqrt($this->assertNumber($num));
    }

    /**
     * Returns the value at a specified position in a list
     *
     * @link https://lesscss.org/functions/#list-functions-extract
     * @throws Exception
     */
    protected function lib_extract(array $value)
    {
        [$list, $idx] = $this->assertArgs($value, 2, "extract");
        $idx = $this->assertNumber($idx);
        // 1 indexed
        if ($list[0] == "list" && isset($list[2][$idx - 1])) {
            return $list[2][$idx - 1];
        }

        // FIXME what is the expected behavior here? Apparently it's not an error?
    }

    /**
     * Returns true if a value is a number, false otherwise
     *
     * @link https://lesscss.org/functions/#type-functions-isnumber
     */
    protected function lib_isnumber(array $value): array
    {
        return $this->toBool($value[0] == "number");
    }

    /**
     * Returns true if a value is a string, false otherwise
     *
     * @link https://lesscss.org/functions/#type-functions-isstring
     */
    protected function lib_isstring(array $value): array
    {
        return $this->toBool($value[0] == "string");
    }

    /**
     * Returns true if a value is a color, false otherwise
     *
     * @link https://lesscss.org/functions/#type-functions-iscolor
     */
    protected function lib_iscolor(array $value): array
    {
        return $this->toBool($this->coerceColor($value));
    }

    /**
     * Returns true if a value is a keyword, false otherwise
     *
     * @link https://lesscss.org/functions/#type-functions-iskeyword
     */
    protected function lib_iskeyword(array $value): array
    {
        return $this->toBool($value[0] == "keyword");
    }

    /**
     * Returns true if a value is a number in pixels, false otherwise
     *
     * @link https://lesscss.org/functions/#type-functions-ispixel
     */
    protected function lib_ispixel(array $value): array
    {
        return $this->toBool($value[0] == "number" && $value[2] == "px");
    }

    /**
     * Returns true if a value is a percentage, false otherwise
     *
     * @link https://lesscss.org/functions/#type-functions-ispercentage
     */
    protected function lib_ispercentage(array $value): array
    {
        return $this->toBool($value[0] == "number" && $value[2] == "%");
    }

    /**
     * Returns true if a value is an em value, false otherwise
     *
     * @link https://lesscss.org/functions/#type-functions-isem
     */
    protected function lib_isem(array $value): array
    {
        return $this->toBool($value[0] == "number" && $value[2] == "em");
    }

    /**
     * Returns true if a value is an rem value, false otherwise
     *
     * This method does not exist in the official less.js implementation
     */
    protected function lib_isrem(array $value): array
    {
        return $this->toBool($value[0] == "number" && $value[2] == "rem");
    }

    /**
     * Creates a hex representation of a color in #AARRGGBB format (NOT #RRGGBBAA!)
     *
     * This method does not exist in the official less.js implementation
     * @see lib_argb
     * @throws Exception
     */
    protected function lib_rgbahex(array $color): string
    {
        $color = $this->coerceColor($color);
        if (is_null($color))
            $this->throwError("color expected for rgbahex");

        return sprintf("#%02x%02x%02x%02x",
            isset($color[4]) ? $color[4] * 255 : 255,
            $color[1], $color[2], $color[3]);
    }

    /**
     * Creates a hex representation of a color in #AARRGGBB format (NOT #RRGGBBAA!)
     *
     * @https://lesscss.org/functions/#color-definition-argb
     * @throws Exception
     */
    protected function lib_argb(array $color): string
    {
        return $this->lib_rgbahex($color);
    }

    /**
     * Given an url, decide whether to output a regular link or the base64-encoded contents of the file
     *
     * @param array $value either an argument list (two strings) or a single string
     * @return string        formatted url(), either as a link or base64-encoded
     */
    protected function lib_data_uri(array $value): string
    {
        $mime = ($value[0] === 'list') ? $value[2][0][2] : null;
        $url = ($value[0] === 'list') ? $value[2][1][2][0] : $value[2][0];

        $fullpath = $this->findImport($url);

        if ($fullpath && ($fsize = filesize($fullpath)) !== false) {
            // IE8 can't handle data uris larger than 32KB
            if ($fsize / 1024 < 32) {
                if (is_null($mime)) {
                    if (class_exists('finfo')) { // php 5.3+
                        $finfo = new \finfo(FILEINFO_MIME);
                        $mime = explode('; ', $finfo->file($fullpath));
                        $mime = $mime[0];
                    } elseif (function_exists('mime_content_type')) { // PHP 5.2
                        $mime = mime_content_type($fullpath);
                    }
                }

                if (!is_null($mime)) // fallback if the mime type is still unknown
                    $url = sprintf('data:%s;base64,%s', $mime, base64_encode(file_get_contents($fullpath)));
            }
        }

        return 'url("' . $url . '")';
    }

    /**
     * Utility func to unquote a string
     *
     * @link https://lesscss.org/functions/#string-functions-e
     * @throws Exception
     */
    protected function lib_e(array $arg): array
    {
        switch ($arg[0]) {
            case "list":
                $items = $arg[2];
                if (isset($items[0])) {
                    return $this->lib_e($items[0]);
                }
                $this->throwError("unrecognised input");
            case "string":
                $arg[1] = "";
                return $arg;
            case "keyword":
                return $arg;
            default:
                return ["keyword", $this->compileValue($arg)];
        }
    }

    /**
     * Formats a string
     *
     * @link https://lesscss.org/functions/#string-functions--format
     * @throws Exception
     */
    protected function lib__sprintf(array $args) : array
    {
        if ($args[0] != "list") return $args;
        $values = $args[2];
        $string = array_shift($values);
        $template = $this->compileValue($this->lib_e($string));

        $i = 0;
        if (preg_match_all('/%[dsa]/', $template, $m)) {
            foreach ($m[0] as $match) {
                $val = isset($values[$i]) ?
                    $this->reduce($values[$i]) : ['keyword', ''];

                // lessjs compat, renders fully expanded color, not raw color
                if ($color = $this->coerceColor($val)) {
                    $val = $color;
                }

                $i++;
                $rep = $this->compileValue($this->lib_e($val));
                $template = preg_replace('/' . self::preg_quote($match) . '/',
                    $rep, $template, 1);
            }
        }

        $d = $string[0] == "string" ? $string[1] : '"';
        return ["string", $d, [$template]];
    }

    /**
     * Rounds down to the next lowest integer
     *
     * @link https://lesscss.org/functions/#math-functions-floor
     * @throws Exception
     */
    protected function lib_floor(array $arg): array
    {
        $value = $this->assertNumber($arg);
        return ["number", floor($value), $arg[2]];
    }

    /**
     * Rounds up to the next highest integer
     *
     * @link https://lesscss.org/functions/#math-functions-ceil
     * @throws Exception
     */
    protected function lib_ceil(array $arg): array
    {
        $value = $this->assertNumber($arg);
        return ["number", ceil($value), $arg[2]];
    }

    /**
     * Applies rounding
     *
     * @link https://lesscss.org/functions/#math-functions-round
     * @throws Exception
     */
    protected function lib_round(array $arg): array
    {
        if ($arg[0] != "list") {
            $value = $this->assertNumber($arg);
            return ["number", round($value), $arg[2]];
        } else {
            $value = $this->assertNumber($arg[2][0]);
            $precision = $this->assertNumber($arg[2][1]);
            return ["number", round($value, $precision), $arg[2][0][2]];
        }
    }

    /**
     * Remove or change the unit of a dimension
     *
     * @link https://lesscss.org/functions/#misc-functions-unit
     * @throws Exception
     */
    protected function lib_unit(array $arg): array
    {
        if ($arg[0] == "list") {
            [$number, $newUnit] = $arg[2];
            return [
                "number",
                $this->assertNumber($number),
                $this->compileValue($this->lib_e($newUnit))
            ];
        } else {
            return ["number", $this->assertNumber($arg), ""];
        }
    }

    /**
     * Helper function to get arguments for color manipulation functions.
     * takes a list that contains a color like thing and a percentage
     *
     * @fixme explanation needs to be improved
     * @throws Exception
     */
    public function colorArgs(array $args): array
    {
        if ($args[0] != 'list' || count($args[2]) < 2) {
            return [['color', 0, 0, 0], 0];
        }
        [$color, $delta] = $args[2];
        $color = $this->assertColor($color);
        $delta = floatval($delta[1]);

        return [$color, $delta];
    }

    /**
     * Decrease the lightness of a color in the HSL color space by an absolute amount
     *
     * @link https://lesscss.org/functions/#color-operations-darken
     * @throws Exception
     */
    protected function lib_darken(array $args): array
    {
        [$color, $delta] = $this->colorArgs($args);

        $hsl = $this->toHSL($color);
        $hsl[3] = $this->clamp($hsl[3] - $delta, 100);
        return $this->toRGB($hsl);
    }

    /**
     * Increase the lightness of a color in the HSL color space by an absolute amount
     *
     * @link https://lesscss.org/functions/#color-operations-lighten
     * @throws Exception
     */
    protected function lib_lighten(array $args): array
    {
        [$color, $delta] = $this->colorArgs($args);

        $hsl = $this->toHSL($color);
        $hsl[3] = $this->clamp($hsl[3] + $delta, 100);
        return $this->toRGB($hsl);
    }

    /**
     * Increase the saturation of a color in the HSL color space by an absolute amount
     *
     * @link https://lesscss.org/functions/#color-operations-saturate
     * @throws Exception
     */
    protected function lib_saturate(array $args): array
    {
        [$color, $delta] = $this->colorArgs($args);

        $hsl = $this->toHSL($color);
        $hsl[2] = $this->clamp($hsl[2] + $delta, 100);
        return $this->toRGB($hsl);
    }

    /**
     * Decrease the saturation of a color in the HSL color space by an absolute amount
     *
     * @link https://lesscss.org/functions/#color-operations-desaturate
     * @throws Exception
     */
    protected function lib_desaturate(array $args): array
    {
        [$color, $delta] = $this->colorArgs($args);

        $hsl = $this->toHSL($color);
        $hsl[2] = $this->clamp($hsl[2] - $delta, 100);
        return $this->toRGB($hsl);
    }

    /**
     * Rotate the hue angle of a color in either direction
     *
     * @link https://lesscss.org/functions/#color-operations-spin
     * @throws Exception
     */
    protected function lib_spin(array $args): array
    {
        [$color, $delta] = $this->colorArgs($args);

        $hsl = $this->toHSL($color);

        $hsl[1] = $hsl[1] + $delta % 360;
        if ($hsl[1] < 0) $hsl[1] += 360;

        return $this->toRGB($hsl);
    }

    /**
     * Increase the transparency (or decrease the opacity) of a color, making it less opaque
     *
     * @link https://lesscss.org/functions/#color-operations-fadeout
     * @throws Exception
     */
    protected function lib_fadeout(array $args): array
    {
        [$color, $delta] = $this->colorArgs($args);
        $color[4] = $this->clamp(($color[4] ?? 1) - $delta / 100);
        return $color;
    }

    /**
     * Decrease the transparency (or increase the opacity) of a color, making it more opaque
     *
     * @link https://lesscss.org/functions/#color-operations-fadein
     * @throws Exception
     */
    protected function lib_fadein(array $args): array
    {
        [$color, $delta] = $this->colorArgs($args);
        $color[4] = $this->clamp(($color[4] ?? 1) + $delta / 100);
        return $color;
    }

    /**
     * Extracts the hue channel of a color object in the HSL color space
     *
     * @link https://lesscss.org/functions/#color-channel-hue
     * @throws Exception
     */
    protected function lib_hue(array $color): int
    {
        $hsl = $this->toHSL($this->assertColor($color));
        return round($hsl[1]);
    }

    /**
     * Extracts the saturation channel of a color object in the HSL color space
     *
     * @link https://lesscss.org/functions/#color-channel-saturation
     * @throws Exception
     */
    protected function lib_saturation(array $color): int
    {
        $hsl = $this->toHSL($this->assertColor($color));
        return round($hsl[2]);
    }

    /**
     * Extracts the lightness channel of a color object in the HSL color space
     *
     * @link https://lesscss.org/functions/#color-channel-lightness
     * @throws Exception
     */
    protected function lib_lightness(array $color): int
    {
        $hsl = $this->toHSL($this->assertColor($color));
        return round($hsl[3]);
    }

    /**
     * Extracts the alpha channel of a color object
     *
     * defaults to 1 for colors without an alpha
     * non-colors return null
     * @link https://lesscss.org/functions/#color-channel-alpha
     */
    protected function lib_alpha(array $value): ?float
    {
        if (!is_null($color = $this->coerceColor($value))) {
            return $color[4] ?? 1;
        }
        return null;
    }

    /**
     * Set the absolute opacity of a color.
     * Can be applied to colors whether they already have an opacity value or not.
     *
     * @link https://lesscss.org/functions/#color-operations-fade
     * @throws Exception
     */
    protected function lib_fade(array $args): array
    {
        [$color, $alpha] = $this->colorArgs($args);
        $color[4] = $this->clamp($alpha / 100.0);
        return $color;
    }

    /**
     * Converts a floating point number into a percentage string
     *
     * @link https://lesscss.org/functions/#math-functions-percentage
     * @throws Exception
     */
    protected function lib_percentage($arg)
    {
        $num = $this->assertNumber($arg);
        return ["number", $num * 100, "%"];
    }

    /**
     * Mix color with white in variable proportion.
     *
     * It is the same as calling `mix(#ffffff, @color, @weight)`.
     *
     *     tint(@color, [@weight: 50%]);
     *
     * @link https://lesscss.org/functions/#color-operations-tint
     * @throws Exception
     * @return array Color
     */
    protected function lib_tint(array $args): array
    {
        $white = ['color', 255, 255, 255];
        if ($args[0] == 'color') {
            return $this->lib_mix(['list', ',', [$white, $args]]);
        } elseif ($args[0] == "list" && count($args[2]) == 2) {
            return $this->lib_mix([$args[0], $args[1], [$white, $args[2][0], $args[2][1]]]);
        } else {
            $this->throwError("tint expects (color, weight)");
        }
    }

    /**
     * Mix color with black in variable proportion.
     *
     * It is the same as calling `mix(#000000, @color, @weight)`
     *
     *     shade(@color, [@weight: 50%]);
     *
     * @link http://lesscss.org/functions/#color-operations-shade
     * @return array Color
     * @throws Exception
     */
    protected function lib_shade(array $args): array
    {
        $black = ['color', 0, 0, 0];
        if ($args[0] == 'color') {
            return $this->lib_mix(['list', ',', [$black, $args]]);
        } elseif ($args[0] == "list" && count($args[2]) == 2) {
            return $this->lib_mix([$args[0], $args[1], [$black, $args[2][0], $args[2][1]]]);
        } else {
            $this->throwError("shade expects (color, weight)");
        }
    }

    /**
     * mixes two colors by weight
     * mix(@color1, @color2, [@weight: 50%]);
     *
     * @link https://lesscss.org/functions/#color-operations-mix
     * @throws Exception
     */
    protected function lib_mix(array $args): array
    {
        if ($args[0] != "list" || count($args[2]) < 2)
            $this->throwError("mix expects (color1, color2, weight)");

        [$first, $second] = $args[2];
        $first = $this->assertColor($first);
        $second = $this->assertColor($second);

        $first_a = $this->lib_alpha($first);
        $second_a = $this->lib_alpha($second);

        if (isset($args[2][2])) {
            $weight = $args[2][2][1] / 100.0;
        } else {
            $weight = 0.5;
        }

        $w = $weight * 2 - 1;
        $a = $first_a - $second_a;

        $w1 = (($w * $a == -1 ? $w : ($w + $a) / (1 + $w * $a)) + 1) / 2.0;
        $w2 = 1.0 - $w1;

        $new = [
            'color',
            $w1 * $first[1] + $w2 * $second[1],
            $w1 * $first[2] + $w2 * $second[2],
            $w1 * $first[3] + $w2 * $second[3],
        ];

        if ($first_a != 1.0 || $second_a != 1.0) {
            $new[] = $first_a * $weight + $second_a * ($weight - 1);
        }

        return $this->fixColor($new);
    }

    /**
     * Choose which of two colors provides the greatest contrast with another
     *
     * @link https://lesscss.org/functions/#color-operations-contrast
     * @throws Exception
     */
    protected function lib_contrast(array $args): array
    {
        $darkColor = ['color', 0, 0, 0];
        $lightColor = ['color', 255, 255, 255];
        $threshold = 0.43;

        if ($args[0] == 'list') {
            $inputColor = (isset($args[2][0])) ? $this->assertColor($args[2][0]) : $lightColor;
            $darkColor = (isset($args[2][1])) ? $this->assertColor($args[2][1]) : $darkColor;
            $lightColor = (isset($args[2][2])) ? $this->assertColor($args[2][2]) : $lightColor;
            if (isset($args[2][3])) {
                if (isset($args[2][3][2]) && $args[2][3][2] == '%') {
                    $args[2][3][1] /= 100;
                    unset($args[2][3][2]);
                }
                $threshold = $this->assertNumber($args[2][3]);
            }
        } else {
            $inputColor = $this->assertColor($args);
        }

        $inputColor = $this->coerceColor($inputColor);
        $darkColor = $this->coerceColor($darkColor);
        $lightColor = $this->coerceColor($lightColor);

        //Figure out which is actually light and dark!
        if ( $this->toLuma($darkColor) > $this->toLuma($lightColor) ) {
            $t = $lightColor;
            $lightColor = $darkColor;
            $darkColor = $t;
        }

        $inputColor_alpha = $this->lib_alpha($inputColor);
        if ( ( $this->toLuma($inputColor) * $inputColor_alpha) < $threshold) {
            return $lightColor;
        }
        return $darkColor;
    }

    /**
     * Calculate the perceptual brightness of a color object
     */
    protected function toLuma(array $color): float
    {
        [, $r, $g, $b] = $this->coerceColor($color);

        $r = $r / 255;
        $g = $g / 255;
        $b = $b / 255;

        $r = ($r <= 0.03928) ? $r / 12.92 : (($r + 0.055) / 1.055) ** 2.4;
        $g = ($g <= 0.03928) ? $g / 12.92 : (($g + 0.055) / 1.055) ** 2.4;
        $b = ($b <= 0.03928) ? $b / 12.92 : (($b + 0.055) / 1.055) ** 2.4;

        return (0.2126 * $r) + (0.7152 * $g) + (0.0722 * $b);
    }

    /**
     * Calculates the luma (perceptual brightness) of a color object
     *
     * @link https://lesscss.org/functions/#color-channel-luma
     * @todo this seems not to check if the color is actually a color
     */
    protected function lib_luma(array $color): array
    {
        return ["number", round($this->toLuma($color) * 100, 8), "%"];
    }

    /**
     * @throws Exception
     */
    public function assertColor($value, $error = "expected color value")
    {
        $color = $this->coerceColor($value);
        if (is_null($color)) $this->throwError($error);
        return $color;
    }

    /**
     * Checks that the value is a number and returns it as float
     *
     * @param array $value The parsed value triplet
     * @param string $error The error message to throw
     * @throws Exception
     */
    public function assertNumber(array $value, string $error = "expecting number") : float
    {
        if ($value[0] == "number") return (float) $value[1];
        $this->throwError($error);
    }

    /**
     * @throws Exception
     */
    public function assertArgs($value, $expectedArgs, $name = "")
    {
        if ($expectedArgs == 1) {
            return $value;
        } else {
            if ($value[0] !== "list" || $value[1] != ",") $this->throwError("expecting list");
            $values = $value[2];
            $numValues = count($values);
            if ($expectedArgs != $numValues) {
                if ($name) {
                    $name = $name . ": ";
                }

                $this->throwError("{$name}expecting $expectedArgs arguments, got $numValues");
            }

            return $values;
        }
    }

    /**
     * @throws Exception
     */
    public function assertMinArgs($value, $expectedMinArgs, $name = "")
    {
        if ($value[0] !== "list" || $value[1] != ",") $this->throwError("expecting list");
        $values = $value[2];
        $numValues = count($values);
        if ($expectedMinArgs > $numValues) {
            if ($name) {
                $name = $name . ": ";
            }

            $this->throwError("${name}expecting at least $expectedMinArgs arguments, got $numValues");
        }

        return $values;
    }

    protected function toHSL($color)
    {
        if ($color[0] == 'hsl') return $color;

        $r = $color[1] / 255;
        $g = $color[2] / 255;
        $b = $color[3] / 255;

        $min = min($r, $g, $b);
        $max = max($r, $g, $b);

        $L = ($min + $max) / 2;
        if ($min == $max) {
            $S = $H = 0;
        } else {
            if ($L < 0.5) {
                $S = ($max - $min) / ($max + $min);
            } else {
                $S = ($max - $min) / (2.0 - $max - $min);
            }

            if ($r == $max) {
                $H = ($g - $b) / ($max - $min);
            } elseif ($g == $max) {
                $H = 2.0 + ($b - $r) / ($max - $min);
            } elseif ($b == $max) {
                $H = 4.0 + ($r - $g) / ($max - $min);
            } else {
                $H = 0;
            }
        }

        $out = [
            'hsl',
            ($H < 0 ? $H + 6 : $H) * 60,
            $S * 100,
            $L * 100,
        ];

        if (count($color) > 4) $out[] = $color[4]; // copy alpha
        return $out;
    }

    protected function toRGB_helper($comp, $temp1, $temp2)
    {
        if ($comp < 0) $comp += 1.0;
        elseif ($comp > 1) $comp -= 1.0;

        if (6 * $comp < 1) return $temp1 + ($temp2 - $temp1) * 6 * $comp;
        if (2 * $comp < 1) return $temp2;
        if (3 * $comp < 2) return $temp1 + ($temp2 - $temp1) * ((2 / 3) - $comp) * 6;

        return $temp1;
    }

    /**
     * Converts a hsl array into a color value in rgb.
     * Expects H to be in range of 0 to 360, S and L in 0 to 100
     */
    protected function toRGB(array $color): array
    {
        if ($color[0] == 'color') return $color;

        $H = $color[1] / 360;
        $S = $color[2] / 100;
        $L = $color[3] / 100;

        if ($S == 0) {
            $r = $g = $b = $L;
        } else {
            $temp2 = $L < 0.5 ?
                $L * (1.0 + $S) :
                $L + $S - $L * $S;

            $temp1 = 2.0 * $L - $temp2;

            $r = $this->toRGB_helper($H + 1 / 3, $temp1, $temp2);
            $g = $this->toRGB_helper($H, $temp1, $temp2);
            $b = $this->toRGB_helper($H - 1 / 3, $temp1, $temp2);
        }

        // $out = array('color', round($r*255), round($g*255), round($b*255));
        $out = ['color', $r * 255, $g * 255, $b * 255];
        if (count($color) > 4) $out[] = $color[4]; // copy alpha
        return $out;
    }

    protected function clamp($v, $max = 1, $min = 0)
    {
        return min($max, max($min, $v));
    }

    /**
     * Convert the rgb, rgba, hsl color literals of function type
     * as returned by the parser into values of color type.
     *
     * @throws Exception
     */
    protected function funcToColor($func)
    {
        $fname = $func[1];
        if ($func[2][0] != 'list') return false; // need a list of arguments
        $rawComponents = $func[2][2];

        if ($fname == 'hsl' || $fname == 'hsla') {
            $hsl = ['hsl'];
            $i = 0;
            foreach ($rawComponents as $c) {
                $val = $this->reduce($c);
                $val = isset($val[1]) ? floatval($val[1]) : 0;

                if ($i == 0) $clamp = 360;
                elseif ($i < 3) $clamp = 100;
                else $clamp = 1;

                $hsl[] = $this->clamp($val, $clamp);
                $i++;
            }

            while (count($hsl) < 4) $hsl[] = 0;
            return $this->toRGB($hsl);

        } elseif ($fname == 'rgb' || $fname == 'rgba') {
            $components = [];
            $i = 1;
            foreach ($rawComponents as $c) {
                $c = $this->reduce($c);
                if ($i < 4) {
                    if ($c[0] == "number" && $c[2] == "%") {
                        $components[] = 255 * ($c[1] / 100);
                    } else {
                        $components[] = floatval($c[1]);
                    }
                } elseif ($i == 4) {
                    if ($c[0] == "number" && $c[2] == "%") {
                        $components[] = 1.0 * ($c[1] / 100);
                    } else {
                        $components[] = floatval($c[1]);
                    }
                } else break;

                $i++;
            }
            while (count($components) < 3) $components[] = 0;
            array_unshift($components, 'color');
            return $this->fixColor($components);
        }

        return false;
    }

    /**
     * @throws Exception
     */
    protected function reduce($value, $forExpression = false)
    {
        switch ($value[0]) {
            case "interpolate":
                $reduced = $this->reduce($value[1]);
                $var = $this->compileValue($reduced);
                $res = $this->reduce(["variable", $this->vPrefix . $var]);

                if ($res[0] == "raw_color") {
                    $res = $this->coerceColor($res);
                }

                if (empty($value[2])) $res = $this->lib_e($res);

                return $res;
            case "variable":
                $key = $value[1];
                if (is_array($key)) {
                    $key = $this->reduce($key);
                    $key = $this->vPrefix . $this->compileValue($this->lib_e($key));
                }

                $seen =& $this->env->seenNames;

                if (!empty($seen[$key])) {
                    $this->throwError("infinite loop detected: $key");
                }

                $seen[$key] = true;
                $out = $this->reduce($this->get($key));
                $seen[$key] = false;
                return $out;
            case "list":
                foreach ($value[2] as &$item) {
                    $item = $this->reduce($item, $forExpression);
                }
                return $value;
            case "expression":
                return $this->evaluate($value);
            case "string":
                foreach ($value[2] as &$part) {
                    if (is_array($part)) {
                        $strip = $part[0] == "variable";
                        $part = $this->reduce($part);
                        if ($strip) $part = $this->lib_e($part);
                    }
                }
                return $value;
            case "escape":
                [, $inner] = $value;
                return $this->lib_e($this->reduce($inner));
            case "function":
                $color = $this->funcToColor($value);
                if ($color) return $color;

                [, $name, $args] = $value;
                if ($name == "%") $name = "_sprintf";

                $f = $this->libFunctions[$name] ?? [$this, 'lib_' . str_replace('-', '_', $name)];

                if (is_callable($f)) {
                    if ($args[0] == 'list')
                        $args = self::compressList($args[2], $args[1]);

                    $ret = call_user_func($f, $this->reduce($args, true), $this);

                    if (is_null($ret)) {
                        return ["string", "", [$name, "(", $args, ")"]];
                    }

                    // convert to a typed value if the result is a php primitive
                    if (is_numeric($ret)) $ret = ['number', $ret, ""];
                    elseif (!is_array($ret)) $ret = ['keyword', $ret];

                    return $ret;
                }

                // plain function, reduce args
                $value[2] = $this->reduce($value[2]);
                return $value;
            case "unary":
                [, $op, $exp] = $value;
                $exp = $this->reduce($exp);

                if ($exp[0] == "number") {
                    switch ($op) {
                        case "+":
                            return $exp;
                        case "-":
                            $exp[1] *= -1;
                            return $exp;
                    }
                }
                return ["string", "", [$op, $exp]];
        }

        if ($forExpression) {
            switch ($value[0]) {
                case "keyword":
                    if ($color = $this->coerceColor($value)) {
                        return $color;
                    }
                    break;
                case "raw_color":
                    return $this->coerceColor($value);
            }
        }

        return $value;
    }


    /**
     * coerce a value for use in color operation
     * returns null if the value can't be used in color operations
     */
    protected function coerceColor(array $value): ?array
    {
        switch ($value[0]) {
            case 'color':
                return $value;
            case 'raw_color':
                $c = ["color", 0, 0, 0];
                $colorStr = substr($value[1], 1);
                $num = hexdec($colorStr);
                $width = strlen($colorStr) == 3 ? 16 : 256;

                for ($i = 3; $i > 0; $i--) { // 3 2 1
                    $t = $num % $width;
                    $num /= $width;

                    $c[$i] = $t * (256 / $width) + $t * floor(16 / $width);
                }

                return $c;
            case 'keyword':
                $name = $value[1];
                if (isset(self::$cssColors[$name])) {
                    $rgba = explode(',', self::$cssColors[$name]);

                    if (isset($rgba[3]))
                        return ['color', $rgba[0], $rgba[1], $rgba[2], $rgba[3]];

                    return ['color', $rgba[0], $rgba[1], $rgba[2]];
                }
                return null;
        }
        return null;
    }

    // make something string like into a string
    protected function coerceString($value)
    {
        switch ($value[0]) {
            case "string":
                return $value;
            case "keyword":
                return ["string", "", [$value[1]]];
        }
        return null;
    }

    // turn list of length 1 into value type
    protected function flattenList($value)
    {
        if ($value[0] == "list" && count($value[2]) == 1) {
            return $this->flattenList($value[2][0]);
        }
        return $value;
    }

    /**
     * Return a boolean type triplet for a given boolean value
     */
    public function toBool($a) : array
    {
        if ($a) return self::$TRUE;
        return self::$FALSE;
    }

    /**
     * evaluate an expression
     * @throws Exception
     */
    protected function evaluate($exp)
    {
        [, $op, $left, $right, $whiteBefore, $whiteAfter] = $exp;

        $left = $this->reduce($left, true);
        $right = $this->reduce($right, true);

        if ($leftColor = $this->coerceColor($left)) {
            $left = $leftColor;
        }

        if ($rightColor = $this->coerceColor($right)) {
            $right = $rightColor;
        }

        $ltype = $left[0];
        $rtype = $right[0];

        // operators that work on all types
        if ($op == "and") {
            return $this->toBool($left == self::$TRUE && $right == self::$TRUE);
        }

        if ($op == "=") {
            return $this->toBool($this->eq($left, $right));
        }

        if ($op == "+" && !is_null($str = $this->stringConcatenate($left, $right))) {
            return $str;
        }

        // type based operators
        $fname = sprintf('op_%s_%s', $ltype, $rtype);
        if (is_callable([$this, $fname])) {
            $out = $this->$fname($op, $left, $right);
            if (!is_null($out)) return $out;
        }

        // make the expression look it did before being parsed
        $paddedOp = $op;
        if ($whiteBefore) $paddedOp = " " . $paddedOp;
        if ($whiteAfter) $paddedOp .= " ";

        return ["string", "", [$left, $paddedOp, $right]];
    }

    protected function stringConcatenate($left, $right)
    {
        if ($strLeft = $this->coerceString($left)) {
            if ($right[0] == "string") {
                $right[1] = "";
            }
            $strLeft[2][] = $right;
            return $strLeft;
        }

        if ($strRight = $this->coerceString($right)) {
            array_unshift($strRight[2], $left);
            return $strRight;
        }
    }

    /**
     * @throws Exception
     */
    protected function convert($number, $to) : array
    {
        $value = $this->assertNumber($number);
        $from = $number[2];

        // easy out
        if ($from == $to)
            return $number;

        // check if the from value is a length
        if (($from_index = array_search($from, self::$lengths)) !== false) {
            // make sure to value is too
            if (in_array($to, self::$lengths)) {
                // do the actual conversion
                $to_index = array_search($to, self::$lengths);
                $px = $value * self::$lengths_to_base[$from_index];
                $result = $px * (1 / self::$lengths_to_base[$to_index]);

                $result = round($result, 8);
                return ["number", $result, $to];
            }
        }

        // do the same check for times
        if (in_array($from, self::$times)) {
            if (in_array($to, self::$times)) {
                // currently only ms and s are valid
                if ($to == "ms")
                    $result = $value * 1000;
                else $result = $value / 1000;

                $result = round($result, 8);
                return ["number", $result, $to];
            }
        }

        // lastly check for an angle
        if (in_array($from, self::$angles)) {
            // convert whatever angle it is into degrees
            if ($from == "rad")
                $deg = rad2deg($value);

            elseif ($from == "turn")
                $deg = $value * 360;

            elseif ($from == "grad")
                $deg = $value / (400 / 360);

            else $deg = $value;

            // Then convert it from degrees into desired unit
            if ($to == "deg")
                $result = $deg;

            if ($to == "rad")
                $result = deg2rad($deg);

            if ($to == "turn")
                $result = $value / 360;

            if ($to == "grad")
                $result = $value * (400 / 360);

            $result = round($result, 8);
            return ["number", $result, $to];
        }

        // we don't know how to convert these
        $this->throwError("Cannot convert $from to $to");
    }

    // make sure a color's components don't go out of bounds
    protected function fixColor($c)
    {
        foreach (range(1, 3) as $i) {
            if ($c[$i] < 0) $c[$i] = 0;
            if ($c[$i] > 255) $c[$i] = 255;
        }

        return $c;
    }

    /**
     * @throws Exception
     */
    protected function op_number_color($op, $lft, $rgt)
    {
        if ($op == '+' || $op == '*') {
            return $this->op_color_number($op, $rgt, $lft);
        }
    }

    /**
     * @throws Exception
     */
    protected function op_color_number($op, $lft, $rgt)
    {
        if ($rgt[0] == '%') $rgt[1] /= 100;

        return $this->op_color_color($op, $lft,
            array_fill(1, count($lft) - 1, $rgt[1]));
    }

    /**
     * @throws Exception
     */
    protected function op_color_color($op, $left, $right)
    {
        $out = ['color'];
        $max = count($left) > count($right) ? count($left) : count($right);
        foreach (range(1, $max - 1) as $i) {
            $lval = $left[$i] ?? 0;
            $rval = $right[$i] ?? 0;
            switch ($op) {
                case '+':
                    $out[] = $lval + $rval;
                    break;
                case '-':
                    $out[] = $lval - $rval;
                    break;
                case '*':
                    $out[] = $lval * $rval;
                    break;
                case '%':
                    $out[] = $lval % $rval;
                    break;
                case '/':
                    if ($rval == 0) $this->throwError("evaluate error: can't divide by zero");
                    $out[] = $lval / $rval;
                    break;
                default:
                    $this->throwError('evaluate error: color op number failed on op ' . $op);
            }
        }
        return $this->fixColor($out);
    }

    /**
     * @throws Exception
     */
    public function lib_red($color)
    {
        $color = $this->coerceColor($color);
        if (is_null($color)) {
            $this->throwError('color expected for red()');
        }

        return $color[1];
    }

    /**
     * @throws Exception
     */
    public function lib_green($color)
    {
        $color = $this->coerceColor($color);
        if (is_null($color)) {
            $this->throwError('color expected for green()');
        }

        return $color[2];
    }

    /**
     * @throws Exception
     */
    public function lib_blue($color)
    {
        $color = $this->coerceColor($color);
        if (is_null($color)) {
            $this->throwError('color expected for blue()');
        }

        return $color[3];
    }


    /**
     * operator on two numbers
     * @throws Exception
     */
    protected function op_number_number($op, $left, $right)
    {
        $unit = empty($left[2]) ? $right[2] : $left[2];

        $value = 0;
        switch ($op) {
            case '+':
                $value = $left[1] + $right[1];
                break;
            case '*':
                $value = $left[1] * $right[1];
                break;
            case '-':
                $value = $left[1] - $right[1];
                break;
            case '%':
                $value = $left[1] % $right[1];
                break;
            case '/':
                if ($right[1] == 0) $this->throwError('parse error: divide by zero');
                $value = $left[1] / $right[1];
                break;
            case '<':
                return $this->toBool($left[1] < $right[1]);
            case '>':
                return $this->toBool($left[1] > $right[1]);
            case '>=':
                return $this->toBool($left[1] >= $right[1]);
            case '=<':
                return $this->toBool($left[1] <= $right[1]);
            default:
                $this->throwError('parse error: unknown number operator: ' . $op);
        }

        return ["number", $value, $unit];
    }


    /* environment functions */

    protected function makeOutputBlock($type, $selectors = null)
    {
        $b = new stdclass;
        $b->lines = [];
        $b->children = [];
        $b->selectors = $selectors;
        $b->type = $type;
        $b->parent = $this->scope;
        return $b;
    }

    // the state of execution
    protected function pushEnv($block = null)
    {
        $e = new stdclass;
        $e->parent = $this->env;
        $e->store = [];
        $e->block = $block;

        $this->env = $e;
        return $e;
    }

    // pop something off the stack
    protected function popEnv()
    {
        $old = $this->env;
        $this->env = $this->env->parent;
        return $old;
    }

    // set something in the current env
    protected function set($name, $value)
    {
        $this->env->store[$name] = $value;
    }


    /**
     * get the highest occurrence entry for a name
     * @throws Exception
     */
    protected function get($name)
    {
        $current = $this->env;

        // track scope to evaluate
        $scope_secondary = [];

        $isArguments = $name == $this->vPrefix . 'arguments';
        while ($current) {
            if ($isArguments && isset($current->arguments)) {
                return ['list', ' ', $current->arguments];
            }

            if (isset($current->store[$name]))
                return $current->store[$name];
            // has secondary scope?
            if (isset($current->storeParent))
                $scope_secondary[] = $current->storeParent;

            $current = $current->parent ?? null;
        }

        while (count($scope_secondary)) {
            // pop one off
            $current = array_shift($scope_secondary);
            while ($current) {
                if ($isArguments && isset($current->arguments)) {
                    return ['list', ' ', $current->arguments];
                }

                if (isset($current->store[$name])) {
                    return $current->store[$name];
                }

                // has secondary scope?
                if (isset($current->storeParent)) {
                    $scope_secondary[] = $current->storeParent;
                }

                $current = $current->parent ?? null;
            }
        }

        $this->throwError("variable $name is undefined");
    }

    /**
     * inject array of unparsed strings into environment as variables
     * @throws Exception
     */
    protected function injectVariables($args)
    {
        $this->pushEnv();
        $parser = new Parser($this, __METHOD__);
        foreach ($args as $name => $strValue) {
            if ($name[0] != '@') $name = '@' . $name;
            $parser->count = 0;
            $parser->buffer = (string)$strValue;
            if (!$parser->propertyValue($value)) {
                throw new Exception("failed to parse passed in variable $name: $strValue");
            }

            $this->set($name, $value);
        }
    }

    /**
     * Initialize any static state, can initialize parser for a file
     * $opts isn't used yet
     */
    public function __construct($fname = null)
    {
        if ($fname !== null) {
            // used for deprecated parse method
            $this->parseFile = $fname;
        }
    }

    /**
     * @throws Exception
     */
    public function compile($string, $name = null)
    {
        $locale = setlocale(LC_NUMERIC, 0);
        setlocale(LC_NUMERIC, "C");

        $this->parser = $this->makeParser($name);
        $root = $this->parser->parse($string);

        $this->env = null;
        $this->scope = null;
        $this->allParsedFiles = [];

        $this->formatter = $this->newFormatter();

        if (!empty($this->registeredVars)) {
            $this->injectVariables($this->registeredVars);
        }

        $this->sourceParser = $this->parser; // used for error messages
        $this->compileBlock($root);

        ob_start();
        $this->formatter->block($this->scope);
        $out = ob_get_clean();
        setlocale(LC_NUMERIC, $locale);
        return $out;
    }

    /**
     * @throws Exception
     */
    public function compileFile($fname, $outFname = null)
    {
        if (!is_readable($fname)) {
            throw new Exception('load error: failed to find ' . $fname);
        }

        $pi = pathinfo($fname);

        $oldImport = $this->importDir;

        $this->importDir = (array)$this->importDir;
        $this->importDir[] = $pi['dirname'] . '/';

        $this->addParsedFile($fname);

        $out = $this->compile(file_get_contents($fname), $fname);

        $this->importDir = $oldImport;

        if ($outFname !== null) {
            return file_put_contents($outFname, $out);
        }

        return $out;
    }

    /**
     * Based on explicit input/output files does a full change check on cache before compiling.
     *
     * @param string $in
     * @param string $out
     * @param boolean $force
     * @return string Compiled CSS results
     * @throws Exception
     */
    public function checkedCachedCompile($in, $out, $force = false)
    {
        if (!is_file($in) || !is_readable($in)) {
            throw new Exception('Invalid or unreadable input file specified.');
        }
        if (is_dir($out) || !is_writable(file_exists($out) ? $out : dirname($out))) {
            throw new Exception('Invalid or unwritable output file specified.');
        }

        $outMeta = $out . '.meta';
        $metadata = null;
        if (!$force && is_file($outMeta)) {
            $metadata = unserialize(file_get_contents($outMeta));
        }

        $output = $this->cachedCompile($metadata ?: $in);

        if (!$metadata || $metadata['updated'] != $output['updated']) {
            $css = $output['compiled'];
            unset($output['compiled']);
            file_put_contents($out, $css);
            file_put_contents($outMeta, serialize($output));
        } else {
            $css = file_get_contents($out);
        }

        return $css;
    }

    /**
     * compile only if changed input has changed or output doesn't exist
     * @throws Exception
     */
    public function checkedCompile($in, $out)
    {
        if (!is_file($out) || filemtime($in) > filemtime($out)) {
            $this->compileFile($in, $out);
            return true;
        }
        return false;
    }

    /**
     * Execute lessphp on a .less file or a lessphp cache structure
     *
     * The lessphp cache structure contains information about a specific
     * less file having been parsed. It can be used as a hint for future
     * calls to determine whether or not a rebuild is required.
     *
     * The cache structure contains two important keys that may be used
     * externally:
     *
     * compiled: The final compiled CSS
     * updated: The time (in seconds) the CSS was last compiled
     *
     * The cache structure is a plain-ol' PHP associative array and can
     * be serialized and unserialized without a hitch.
     *
     * @param mixed $in Input
     * @param bool $force Force rebuild?
     * @return array lessphp cache structure
     * @throws Exception
     */
    public function cachedCompile($in, $force = false)
    {
        // assume no root
        $root = null;

        if (is_string($in)) {
            $root = $in;
        } elseif (is_array($in) and isset($in['root'])) {
            if ($force or !isset($in['files'])) {
                // If we are forcing a recompile or if for some reason the
                // structure does not contain any file information we should
                // specify the root to trigger a rebuild.
                $root = $in['root'];
            } elseif (isset($in['files']) and is_array($in['files'])) {
                foreach ($in['files'] as $fname => $ftime) {
                    if (!file_exists($fname) or filemtime($fname) > $ftime) {
                        // One of the files we knew about previously has changed
                        // so we should look at our incoming root again.
                        $root = $in['root'];
                        break;
                    }
                }
            }
        } else {
            // TODO: Throw an exception? We got neither a string nor something
            // that looks like a compatible lessphp cache structure.
            return null;
        }

        if ($root !== null) {
            // If we have a root value which means we should rebuild.
            $out = [];
            $out['root'] = $root;
            $out['compiled'] = $this->compileFile($root);
            $out['files'] = $this->allParsedFiles();
            $out['updated'] = time();
            return $out;
        } else {
            // No changes, pass back the structure
            // we were given initially.
            return $in;
        }

    }

    /**
     * parse and compile buffer
     * @deprecated
     * @throws Exception
     */
    public function parse($str = null, $initialVariables = null)
    {
        if (is_array($str)) {
            $initialVariables = $str;
            $str = null;
        }

        $oldVars = $this->registeredVars;
        if ($initialVariables !== null) {
            $this->setVariables($initialVariables);
        }

        if ($str == null) {
            if (empty($this->parseFile)) {
                throw new Exception("nothing to parse");
            }

            $out = $this->compileFile($this->parseFile);
        } else {
            $out = $this->compile($str);
        }

        $this->registeredVars = $oldVars;
        return $out;
    }

    protected function makeParser($name)
    {
        $parser = new Parser($this, $name);
        $parser->writeComments = $this->preserveComments;

        return $parser;
    }

    public function setFormatter($name)
    {
        $this->formatterName = $name;
    }

    protected function newFormatter()
    {
        $className = FormatterLessJs::class;
        if (!empty($this->formatterName)) {
            if (!is_string($this->formatterName))
                return $this->formatterName; // FIXME this seems weird? Does formatterName contain a class instance?
            $className =  '\\LesserPHP\\Formatter'.ucfirst($this->formatterName);
        }

        return new $className;
    }

    public function setPreserveComments($preserve)
    {
        $this->preserveComments = $preserve;
    }

    public function registerFunction($name, $func)
    {
        $this->libFunctions[$name] = $func;
    }

    public function unregisterFunction($name)
    {
        unset($this->libFunctions[$name]);
    }

    public function setVariables($variables)
    {
        $this->registeredVars = array_merge($this->registeredVars, $variables);
    }

    public function unsetVariable($name)
    {
        unset($this->registeredVars[$name]);
    }

    public function setImportDir($dirs)
    {
        $this->importDir = (array)$dirs;
    }

    public function addImportDir($dir)
    {
        $this->importDir = (array)$this->importDir;
        $this->importDir[] = $dir;
    }

    public function allParsedFiles()
    {
        return $this->allParsedFiles;
    }

    public function addParsedFile($file)
    {
        $this->allParsedFiles[realpath($file)] = filemtime($file);
    }

    /**
     * Uses the current value of $this->count to show line and line number
     * @throws Exception
     */
    public function throwError($msg = null)
    {
        if ($this->sourceLoc >= 0) {
            $this->sourceParser->throwError($msg, $this->sourceLoc);
        }
        throw new Exception($msg);
    }

    /**
     * compile file $in to file $out if $in is newer than $out
     * returns true when it compiles, false otherwise
     * @throws Exception
     */
    public static function ccompile($in, $out, $less = null)
    {
        if ($less === null) {
            $less = new self;
        }
        return $less->checkedCompile($in, $out);
    }

    /**
     * @throws Exception
     */
    public static function cexecute($in, $force = false, $less = null)
    {
        if ($less === null) {
            $less = new self;
        }
        return $less->cachedCompile($in, $force);
    }

    protected static $cssColors = [
        'aliceblue' => '240,248,255',
        'antiquewhite' => '250,235,215',
        'aqua' => '0,255,255',
        'aquamarine' => '127,255,212',
        'azure' => '240,255,255',
        'beige' => '245,245,220',
        'bisque' => '255,228,196',
        'black' => '0,0,0',
        'blanchedalmond' => '255,235,205',
        'blue' => '0,0,255',
        'blueviolet' => '138,43,226',
        'brown' => '165,42,42',
        'burlywood' => '222,184,135',
        'cadetblue' => '95,158,160',
        'chartreuse' => '127,255,0',
        'chocolate' => '210,105,30',
        'coral' => '255,127,80',
        'cornflowerblue' => '100,149,237',
        'cornsilk' => '255,248,220',
        'crimson' => '220,20,60',
        'cyan' => '0,255,255',
        'darkblue' => '0,0,139',
        'darkcyan' => '0,139,139',
        'darkgoldenrod' => '184,134,11',
        'darkgray' => '169,169,169',
        'darkgreen' => '0,100,0',
        'darkgrey' => '169,169,169',
        'darkkhaki' => '189,183,107',
        'darkmagenta' => '139,0,139',
        'darkolivegreen' => '85,107,47',
        'darkorange' => '255,140,0',
        'darkorchid' => '153,50,204',
        'darkred' => '139,0,0',
        'darksalmon' => '233,150,122',
        'darkseagreen' => '143,188,143',
        'darkslateblue' => '72,61,139',
        'darkslategray' => '47,79,79',
        'darkslategrey' => '47,79,79',
        'darkturquoise' => '0,206,209',
        'darkviolet' => '148,0,211',
        'deeppink' => '255,20,147',
        'deepskyblue' => '0,191,255',
        'dimgray' => '105,105,105',
        'dimgrey' => '105,105,105',
        'dodgerblue' => '30,144,255',
        'firebrick' => '178,34,34',
        'floralwhite' => '255,250,240',
        'forestgreen' => '34,139,34',
        'fuchsia' => '255,0,255',
        'gainsboro' => '220,220,220',
        'ghostwhite' => '248,248,255',
        'gold' => '255,215,0',
        'goldenrod' => '218,165,32',
        'gray' => '128,128,128',
        'green' => '0,128,0',
        'greenyellow' => '173,255,47',
        'grey' => '128,128,128',
        'honeydew' => '240,255,240',
        'hotpink' => '255,105,180',
        'indianred' => '205,92,92',
        'indigo' => '75,0,130',
        'ivory' => '255,255,240',
        'khaki' => '240,230,140',
        'lavender' => '230,230,250',
        'lavenderblush' => '255,240,245',
        'lawngreen' => '124,252,0',
        'lemonchiffon' => '255,250,205',
        'lightblue' => '173,216,230',
        'lightcoral' => '240,128,128',
        'lightcyan' => '224,255,255',
        'lightgoldenrodyellow' => '250,250,210',
        'lightgray' => '211,211,211',
        'lightgreen' => '144,238,144',
        'lightgrey' => '211,211,211',
        'lightpink' => '255,182,193',
        'lightsalmon' => '255,160,122',
        'lightseagreen' => '32,178,170',
        'lightskyblue' => '135,206,250',
        'lightslategray' => '119,136,153',
        'lightslategrey' => '119,136,153',
        'lightsteelblue' => '176,196,222',
        'lightyellow' => '255,255,224',
        'lime' => '0,255,0',
        'limegreen' => '50,205,50',
        'linen' => '250,240,230',
        'magenta' => '255,0,255',
        'maroon' => '128,0,0',
        'mediumaquamarine' => '102,205,170',
        'mediumblue' => '0,0,205',
        'mediumorchid' => '186,85,211',
        'mediumpurple' => '147,112,219',
        'mediumseagreen' => '60,179,113',
        'mediumslateblue' => '123,104,238',
        'mediumspringgreen' => '0,250,154',
        'mediumturquoise' => '72,209,204',
        'mediumvioletred' => '199,21,133',
        'midnightblue' => '25,25,112',
        'mintcream' => '245,255,250',
        'mistyrose' => '255,228,225',
        'moccasin' => '255,228,181',
        'navajowhite' => '255,222,173',
        'navy' => '0,0,128',
        'oldlace' => '253,245,230',
        'olive' => '128,128,0',
        'olivedrab' => '107,142,35',
        'orange' => '255,165,0',
        'orangered' => '255,69,0',
        'orchid' => '218,112,214',
        'palegoldenrod' => '238,232,170',
        'palegreen' => '152,251,152',
        'paleturquoise' => '175,238,238',
        'palevioletred' => '219,112,147',
        'papayawhip' => '255,239,213',
        'peachpuff' => '255,218,185',
        'peru' => '205,133,63',
        'pink' => '255,192,203',
        'plum' => '221,160,221',
        'powderblue' => '176,224,230',
        'purple' => '128,0,128',
        'red' => '255,0,0',
        'rosybrown' => '188,143,143',
        'royalblue' => '65,105,225',
        'saddlebrown' => '139,69,19',
        'salmon' => '250,128,114',
        'sandybrown' => '244,164,96',
        'seagreen' => '46,139,87',
        'seashell' => '255,245,238',
        'sienna' => '160,82,45',
        'silver' => '192,192,192',
        'skyblue' => '135,206,235',
        'slateblue' => '106,90,205',
        'slategray' => '112,128,144',
        'slategrey' => '112,128,144',
        'snow' => '255,250,250',
        'springgreen' => '0,255,127',
        'steelblue' => '70,130,180',
        'tan' => '210,180,140',
        'teal' => '0,128,128',
        'thistle' => '216,191,216',
        'tomato' => '255,99,71',
        'transparent' => '0,0,0,0',
        'turquoise' => '64,224,208',
        'violet' => '238,130,238',
        'wheat' => '245,222,179',
        'white' => '255,255,255',
        'whitesmoke' => '245,245,245',
        'yellow' => '255,255,0',
        'yellowgreen' => '154,205,50'
    ];
}
