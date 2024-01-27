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
use LesserPHP\Functions\AbstractFunctionCollection;
use LesserPHP\Utils\Color;
use LesserPHP\Utils\Util;
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

    protected $libFunctions = [];
    protected $registeredVars = [];
    protected $preserveComments = false;

    public $vPrefix = '@'; // prefix of abstract properties
    public $mPrefix = '$'; // prefix of abstract blocks
    public $parentSelector = '&';

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
     * Initialize the LESS Parser
     */
    public function __construct($fname = null)
    {
        if ($fname !== null) {
            // used for deprecated parse method
            $this->parseFile = $fname;
        }

        $this->registerLibraryFunctions();
    }

    /**
     * Register all the default functions
     */
    protected function registerLibraryFunctions()
    {
        $files = glob(__DIR__ . '/Functions/*.php');
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (substr($name, 0, 8) == 'Abstract') continue;
            $class = '\\LesserPHP\\Functions\\' . $name;
            $funcObj = new $class($this);
            if ($funcObj instanceof AbstractFunctionCollection) {
                foreach ($funcObj->getFunctions() as $name => $callback) {
                    $this->registerFunction($name, $callback);
                }
            }
        }
    }

    /**
     * attempts to find the path of an import url, returns null for css files
     */
    public function findImport(string $url): ?string
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
     * @throws Exception
     */
    protected function tryImport($importPath, $parentBlock, $out)
    {
        if ($importPath[0] == "function" && $importPath[1] == "url") {
            $importPath = $this->flattenList($importPath[2]);
        }

        $str = $this->coerceString($importPath);
        if ($str === null) return false;

        $url = $this->compileValue($this->unwrap($str));

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
     * @throws Exception
     * @see compileProp()
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

                    $passed = $this->reduce($guard) == Constants::TRUE;
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
                    return $this->compileValue(Color::coerceColor($value));
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
     * Utility func to unquote a string
     *
     * @todo this not really a good name for this function
     */
    public function unwrap(array $arg): array
    {
        switch ($arg[0]) {
            case "list":
                $items = $arg[2];
                if (isset($items[0])) {
                    return self::unwrap($items[0]);
                }
                throw new Exception("unrecognised input");
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

                $hsl[] = Util::clamp($val, $clamp);
                $i++;
            }

            while (count($hsl) < 4) $hsl[] = 0;
            return Color::toRGB($hsl);

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
            return Color::fixColor($components);
        }

        return false;
    }

    /**
     * @throws Exception
     */
    public function reduce($value, $forExpression = false)
    {
        switch ($value[0]) {
            case "interpolate":
                $reduced = $this->reduce($value[1]);
                $var = $this->compileValue($reduced);
                $res = $this->reduce(["variable", $this->vPrefix . $var]);

                if ($res[0] == "raw_color") {
                    $res = Color::coerceColor($res);
                }

                if (empty($value[2])) $res = $this->unwrap($res);

                return $res;
            case "variable":
                $key = $value[1];
                if (is_array($key)) {
                    $key = $this->reduce($key);
                    $key = $this->vPrefix . $this->compileValue($this->unwrap($key));
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
                        if ($strip) $part = $this->unwrap($part);
                    }
                }
                return $value;
            case "escape":
                [, $inner] = $value;
                return $this->unwrap($this->reduce($inner));
            case "function":
                $color = $this->funcToColor($value);
                if ($color) return $color;

                [, $name, $args] = $value;

                $f = $this->libFunctions[$name] ?? null;

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
                    if ($color = Color::coerceColor($value)) {
                        return $color;
                    }
                    break;
                case "raw_color":
                    return Color::coerceColor($value);
            }
        }

        return $value;
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
     * evaluate an expression
     * @throws Exception
     */
    protected function evaluate($exp)
    {
        [, $op, $left, $right, $whiteBefore, $whiteAfter] = $exp;

        $left = $this->reduce($left, true);
        $right = $this->reduce($right, true);

        if ($leftColor = Color::coerceColor($left)) {
            $left = $leftColor;
        }

        if ($rightColor = Color::coerceColor($right)) {
            $right = $rightColor;
        }

        $ltype = $left[0];
        $rtype = $right[0];

        // operators that work on all types
        if ($op == "and") {
            return Util::toBool($left == Constants::TRUE && $right == Constants::TRUE);
        }

        if ($op == "=") {
            return Util::toBool($this->eq($left, $right));
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
        return Color::fixColor($out);
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
                return Util::toBool($left[1] < $right[1]);
            case '>':
                return Util::toBool($left[1] > $right[1]);
            case '>=':
                return Util::toBool($left[1] >= $right[1]);
            case '=<':
                return Util::toBool($left[1] <= $right[1]);
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
     * @throws Exception
     * @deprecated
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
            $className = '\\LesserPHP\\Formatter' . ucfirst($this->formatterName);
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

}
