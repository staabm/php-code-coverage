<?php

class PHP_CodeCoverage_Util_Tokenizer {
    private $filename;
    private $functions = [];
    private $classes = [];
    private $traits = [];
    private $linesOfCode = array('loc' => 0, 'cloc' => 0, 'ncloc' => 0);

    public function __construct($filename) {
        $this->filename = $filename;
    }

    /**
     * @return string
     */
    private function getKeywords(array $tokens, $idx) {
        $keywords = array();

        for ($i = $idx - 2; $i > $idx - 7; $i -= 2) {
            if (isset($tokens[$i])) {
                $tconst = $tokens[$i]->tconst;

                if ($tconst === T_PRIVATE || $tconst === T_PROTECTED || $tconst === T_PUBLIC) {
                    continue;
                }

                if ($tconst === T_STATIC) {
                    $keywords[] = 'static';
                } else if ($tconst === T_FINAL) {
                    $keywords[] = 'final';
                } else if ($tconst === T_ABSTRACT) {
                    $keywords[] = 'abstract';
                }
            }
        }

        return implode(',', $keywords);
    }

    private function getParent(array $tokens, $i) {
        $parent = false;
        if ($tokens[$i+4]->tconst === T_EXTENDS) {
            $ci         = $i + 6;
            $className = $tokens[$ci]->tstring;

            while (isset($tokens[$ci+1]) && !($tokens[$ci+1]->tconst === T_WHITESPACE)) {
                $className .= $tokens[++$ci]->tstring;
            }

            $parent = $className;
        }
        return $parent;
    }

    private function getInterfaces(array $tokens, $i) {
        $interfaces = false;
        if (isset($tokens[$i + 4]) && $tokens[$i + 4]->tconst === T_IMPLEMENTS ||
            isset($tokens[$i + 8]) && $tokens[$i + 8]->tconst === T_IMPLEMENTS) {
            if ($tokens[$i + 4]->tconst === T_IMPLEMENTS) {
                $ii = $i + 3;
            } else {
                $ii = $i + 7;
            }

            while ($tokens[$ii+1]->tclass !== 'PHP_Token_OPEN_CURLY') {
                $ii++;

                if ($tokens[$ii]->tconst === T_STRING) {
                    $interfaces[] = $tokens[$ii]->tstring;
                }
            }
        }

        return $interfaces;
    }

    /**
     * @return string
     */
    private function getVisibility(array $tokens, $idx)
    {
        for ($i = $idx - 2; $i > $idx - 7; $i -= 2) {
            if (isset($tokens[$i])) {
                $tconst = $tokens[$i]->tconst;

                if ($tconst === T_PRIVATE) {
                    return "private";
                } else if($tconst === T_PROTECTED) {
                    return "protected";
                }else if ($tconst === T_PUBLIC) {
                    return "public";
                }

                if (!($tconst === T_STATIC || $tconst === T_FINAL || $tconst === T_ABSTRACT)) {
                    // no keywords; stop visibility search
                    break;
                }
            }
        }
    }

    /**
     * Get the docblock for this token
     *
     * This method will fetch the docblock belonging to the current token. The
     * docblock must be placed on the line directly above the token to be
     * recognized.
     *
     * @return string|null Returns the docblock as a string if found
     */
    private function getDocblock(array $tokens, $idx) {
        $currentLineNumber = $tokens[$idx]->tline;
        $prevLineNumber    = $currentLineNumber - 1;

        for ($i = $idx - 1; $i; $i--) {
            if (!isset($tokens[$i])) {
                return;
            }

            $token = $tokens[$i];
            $tconst = $token->tconst;

            if ($tconst === T_FUNCTION || $tconst === T_CLASS || $tconst === T_TRAIT) {
                // Some other trait, class or function, no docblock can be
                // used for the current token
                break;
            }

            $line = $tokens[$i]->tline;

            if ($line == $currentLineNumber || ($line == $prevLineNumber && $tconst === T_WHITESPACE)) {
                continue;
            }

            if ($line < $currentLineNumber && $tconst !== T_DOC_COMMENT) {
                break;
            }

            return $token->tstring;
        }
    }

    /**
     * @return integer
     */
    private function getEndTokenId(array $tokens, $idx)
    {
        $block  = 0;
        $i      = $idx;
        $endTokenId = null;

        while ($endTokenId === null && isset($tokens[$i])) {
            $token = $tokens[$i];
            $tclass = $token->tclass;
            $tconst = $token->tconst;
            if ($tclass === 'PHP_Token_OPEN_CURLY' || $tclass === 'PHP_Token_CURLY_OPEN') {
                $block++;
            } elseif ($tclass ===  'PHP_Token_CLOSE_CURLY') {
                $block--;

                if ($block === 0) {
                    $endTokenId = $i;
                }
            } elseif (($tconst === T_FUNCTION || $tconst === T_NAMESPACE) && $tclass === 'PHP_Token_SEMICOLON') {
                if ($block === 0) {
                    $endTokenId = $i;
                }
            }

            $i++;
        }

        if ($endTokenId === null) {
            $endTokenId = $idx;
        }

        return $endTokenId;
    }

    /**
     * @return integer
     */
    private function getEndLine(array $tokens, $idx)
    {
        return $tokens[$this->getEndTokenId($tokens, $idx)]->tline;
    }

    /**
     * @return array
     */
    private function getPackage(array $tokens, $idx)
    {
        $token = $tokens[$idx];
        $className  = $tokens[$idx]->tname;
        $docComment = $this->getDocblock($tokens, $idx);

        $result = array(
            'namespace'   => '',
            'fullPackage' => '',
            'category'    => '',
            'package'     => '',
            'subpackage'  => ''
        );

        for ($i = $idx; $i; --$i) {
            $tconst = $tokens[$i]->tconst;
            if ($tconst === T_NAMESPACE) {
                $result['namespace'] = $tokens[$i]->tname;
                break;
            }
        }

        if (preg_match('/@category[\s]+([\.\w]+)/', $docComment, $matches)) {
            $result['category'] = $matches[1];
        }

        if (preg_match('/@package[\s]+([\.\w]+)/', $docComment, $matches)) {
            $result['package']     = $matches[1];
            $result['fullPackage'] = $matches[1];
        }

        if (preg_match('/@subpackage[\s]+([\.\w]+)/', $docComment, $matches)) {
            $result['subpackage']   = $matches[1];
            $result['fullPackage'] .= '.' . $matches[1];
        }

        if (empty($result['fullPackage'])) {
            $result['fullPackage'] = $this->arrayToName(
                explode('_', str_replace('\\', '_', $className)),
                '.'
            );
        }

        return $result;
    }

    private function arrayToName(array $parts, $join = '\\')
    {
        $result = '';

        if (count($parts) > 1) {
            array_pop($parts);

            $result = join($join, $parts);
        }

        return $result;
    }

    /**
     * @return string
     */
    private function getSignature(array $tokens, $idx)
    {
        if ($tokens[$idx]->tname == 'anonymous function') {
            $signature = 'anonymous function';
            $i               = $idx + 1;
        } else {
            $signature = '';
            $i               = $idx + 2;
        }

        while (isset($tokens[$i]) &&
               $tokens[$i]->tclass !== 'PHP_Token_OPEN_CURLY' &&
               $tokens[$i]->tclass !== 'PHP_Token_SEMICOLON') {
            $signature .= $tokens[$i++]->tstring;
        }

        $signature = trim($signature);

        return $signature;
    }

    /**
     * @return integer
     */
    private function getCCN($tokens, $idx)
    {
        $ccn = 1;
        $end       = $this->getEndTokenId($tokens, $idx);

        $ccnTokens = array(
            T_IF,
            T_ELSEIF,
            T_FOR,
            T_FOREACH,
            T_WHILE,
            T_CASE,
            T_CATCH,
            T_BOOLEAN_AND,
            T_LOGICAL_AND,
            T_BOOLEAN_OR,
            T_LOGICAL_OR
        );
        for ($i = $idx; $i <= $end; $i++) {
            $tconst = $tokens[$i]->tconst;
            if (in_array($tconst, $ccnTokens)) {
                $ccn++;
            }
            $tclass = $tokens[$i]->tclass;
            if ($tclass === 'PHP_Token_QUESTION_MARK') {
                $ccn++;
            }
        }

        return $ccn;
    }

    public function tokenize() {
        $sourceCode     = file_get_contents($this->filename);
        $tokens = token_get_all($sourceCode);
        $numTokens = count($tokens);

        $line      = 1;
        $ccTokens = array();
        for ($i = 0; $i < $numTokens; ++$i) {
            $nativeToken = $tokens[$i];
            unset($tokens[$i]);

            $ccToken = new CodeCoverage_Token(
                $nativeToken,
                $i,
                $line
            );
            $ccToken->allTokens =& $ccTokens;
            $ccTokens[$i] = $ccToken;

            $text = $ccToken->tstring;

            $lines          = substr_count($text, "\n");
            $line          += $lines;
        }
        $tokens = $ccTokens;

        $class            = false;
        $classEndLine     = false;
        $trait            = false;
        $traitEndLine     = false;
        $interface        = false;
        $interfaceEndLine = false;
        $line      = 1;
        for ($i = 0; $i < $numTokens; ++$i) {
            $token = $tokens[$i];
            $text = $token->tstring;
            $tokenClass = $token->tclass;

            $lines          = substr_count($text, "\n");
            $line          += $lines;

            switch ($tokenClass) {
                case 'PHP_Token_HALT_COMPILER':
                    break 2;

                case 'PHP_Token_INTERFACE':
                    $interface        = $tokens[$i]->tname;
                    $interfaceEndLine = $this->getEndLine($tokens, $i);

                    $this->interfaces[$interface] = array(
                      'methods'   => array(),
                      'parent'    => $this->getParent($tokens, $i),
                      'keywords'  => $this->getKeywords($tokens, $i),
                      'docblock'  => $this->getDocblock($tokens, $i),
                      'startLine' => $token->tline,
                      'endLine'   => $interfaceEndLine,
                      'package'   => $this->getPackage($tokens, $i),
                      'file'      => $this->filename
                    );
                    break;

                case 'PHP_Token_CLASS':
                case 'PHP_Token_TRAIT':
                    $endLine = $this->getEndLine($tokens, $i);

                    $tmp = array(
                        'methods'   => array(),
                        'parent'    => $this->getParent($tokens, $i),
                        'interfaces'=> $this->getInterfaces($tokens, $i),
                        'keywords'  => $this->getKeywords($tokens, $i),
                        'docblock'  => $this->getDocblock($tokens, $i),
                        'startLine' => $token->tline,
                        'endLine'   => $endLine,
                        'package'   => $this->getPackage($tokens, $i),
                        'file'      => $this->filename
                    );

                    $tclass = $token->tclass;
                    if ($tclass === 'PHP_Token_CLASS') {
                        $class                 = $tokens[$i]->tname;
                        $classEndLine          = $endLine;
                        $this->classes[$class] = $tmp;
                    } else {
                        $trait                = $tokens[$i]->tname;
                        $traitEndLine         = $endLine;
                        $this->traits[$trait] = $tmp;
                    }
                    break;

                case 'PHP_Token_FUNCTION':
                    $tname = $tokens[$i]->tname;
                    $tmp  = array(
                        'docblock'  => $this->getDocblock($tokens, $i),
                        'keywords'  => $this->getKeywords($tokens, $i),
                        'visibility'=> $this->getVisibility($tokens, $i),
                        'signature' => $this->getSignature($tokens, $i),
                        'startLine' => $token->tline,
                        'endLine'   => $this->getEndLine($tokens, $i),
                        'ccn'       => $this->getCCN($tokens, $i),
                        'file'      => $this->filename
                    );

                    if ($class === false && $trait === false && $interface === false) {
                        $this->functions[$tname] = $tmp;
                    } elseif ($class !== false) {
                        $this->classes[$class]['methods'][$tname] = $tmp;
                    } elseif ($trait !== false) {
                        $this->traits[$trait]['methods'][$tname] = $tmp;
                    } else {
                        $this->interfaces[$interface]['methods'][$tname] = $tmp;
                    }
                    break;

                case 'PHP_Token_CLOSE_CURLY':
                    if ($classEndLine !== false && $classEndLine == $token->tline) {
                        $class        = false;
                        $classEndLine = false;
                    } elseif ($traitEndLine !== false && $traitEndLine == $token->tline) {
                        $trait        = false;
                        $traitEndLine = false;
                    } elseif ($interfaceEndLine !== false && $interfaceEndLine == $token->tline) {
                        $interface        = false;
                        $interfaceEndLine = false;
                    }
                    break;

                case 'PHP_Token_COMMENT':
                    // fall through
                case 'PHP_Token_DOC_COMMENT':
                    $this->linesOfCode['cloc'] += $lines + 1;
                    break;
            }
        }

        $this->linesOfCode['loc']   = substr_count($sourceCode, "\n");
        $this->linesOfCode['ncloc'] = $this->linesOfCode['loc'] - $this->linesOfCode['cloc'];
    }

    public function getLinesOfCode() {
        return $this->linesOfCode;
    }

    public function getClasses() {
        return $this->classes;
    }

    public function getTraits() {
        return $this->traits;
    }

    public function getFunctions() {
        return $this->functions;
    }
}

/**
 * @property tname
 */
class CodeCoverage_Token {
    /**
     * @var CodeCoverage_Token[] $allTokens
     */
    public $allTokens;
    /**
     * PHP native token provided by token_get_all().
     * @var string|array
     */
    private $nativeToken;
    private $idx;

    public $tclass;
    public $tconst;
    public $tstring;
    public $tline;

    /**
     * @var array
     */
    protected static $customTokens = array(
        '(' => 'PHP_Token_OPEN_BRACKET',
        ')' => 'PHP_Token_CLOSE_BRACKET',
        '[' => 'PHP_Token_OPEN_SQUARE',
        ']' => 'PHP_Token_CLOSE_SQUARE',
        '{' => 'PHP_Token_OPEN_CURLY',
        '}' => 'PHP_Token_CLOSE_CURLY',
        ';' => 'PHP_Token_SEMICOLON',
        '.' => 'PHP_Token_DOT',
        ',' => 'PHP_Token_COMMA',
        '=' => 'PHP_Token_EQUAL',
        '<' => 'PHP_Token_LT',
        '>' => 'PHP_Token_GT',
        '+' => 'PHP_Token_PLUS',
        '-' => 'PHP_Token_MINUS',
        '*' => 'PHP_Token_MULT',
        '/' => 'PHP_Token_DIV',
        '?' => 'PHP_Token_QUESTION_MARK',
        '!' => 'PHP_Token_EXCLAMATION_MARK',
        ':' => 'PHP_Token_COLON',
        '"' => 'PHP_Token_DOUBLE_QUOTES',
        '@' => 'PHP_Token_AT',
        '&' => 'PHP_Token_AMPERSAND',
        '%' => 'PHP_Token_PERCENT',
        '|' => 'PHP_Token_PIPE',
        '$' => 'PHP_Token_DOLLAR',
        '^' => 'PHP_Token_CARET',
        '~' => 'PHP_Token_TILDE',
        '`' => 'PHP_Token_BACKTICK'
    );

    public function __construct($nativeToken, $idx, $tline) {
        $this->nativeToken = $nativeToken;
        $this->idx = $idx;
        $this->tline = $tline;
        $this->tclass  = is_array($nativeToken) ? 'PHP_Token_' . substr(token_name($nativeToken[0]), 2) : self::$customTokens[$nativeToken];
        $this->tconst  = is_array($nativeToken) ? $nativeToken[0] : $nativeToken;
        $this->tstring = is_array($nativeToken) ? $nativeToken[1] : $nativeToken;
    }

    public function __destruct() {
        $this->nativeToken = null;
    }

    public function __get($key) {
        // lazy init properties which are not that likely to be required
        // and cost us too much to preload
        switch($key) {
            case "tname":
                $val = $this->$key($this->allTokens, $this->idx);
                // free this maybe big array as soon as possible
                $this->nativeToken = null;
                break;
            default: throw new Exception("undefined property ". $key);
        }

        $this->$key = $val;
        return $val;
    }

    private function tname(array $tokens, $idx) {
        $tconst = $this->tconst;

        if ($tconst === T_REQUIRE || $tconst === T_REQUIRE_ONCE || $tconst === T_INCLUDE || $tconst === T_INCLUDE_ONCE) {
            if ($tokens[$idx+2]->tconst === T_CONSTANT_ENCAPSED_STRING) {
                return trim($tokens[$idx+2]->tstring, "'\"");
            }
            return null;
        }

        if ($tconst === T_FUNCTION) {
            for ($i = $idx + 1; $i < count($tokens); $i++) {
                $token = $tokens[$i];
                $tconst = $token->tconst;
                $tclass = $token->tclass;

                if ($tconst === T_STRING) {
                    $name = $token->tstring;
                    break;
                } elseif ($tclass === 'PHP_Token_AMPERSAND' && $tokens[$i+1]->tconst === T_STRING) {
                    $name = $tokens[$i+1]->tstring;
                    break;
                } elseif ($tclass === 'PHP_Token_OPEN_BRACKET') {
                    $name = 'anonymous function';
                    break;
                }
            }

            if ($name != 'anonymous function') {
                for ($i = $idx; $i; --$i) {
                    $tconst = $tokens[$i]->tconst;
                    if ($tconst === T_NAMESPACE) {
                        $name = $tokens[$i]->tname . '\\' . $name;
                        break;
                    }

                    if ($tconst === T_INTERFACE || $tconst === T_CLASS || $tconst === T_TRAIT) {
                        break;
                    }
                }
            }

            return $name;
        }

        if ($tconst === T_INTERFACE || $tconst === T_CLASS || $tconst === T_TRAIT) {
            return $tokens[$idx + 2]->tstring;
        }

        if ($tconst === T_NAMESPACE) {
            $namespace = $tokens[$idx+2]->tstring;

            for ($i = $idx + 3;; $i += 2) {
                if (isset($tokens[$i]) && $tokens[$i]->tconst === T_NS_SEPARATOR) {
                    $namespace .= '\\' . $tokens[$i+1]->tstring;
                } else {
                    break;
                }
            }

            return $namespace;
        }
    }
}