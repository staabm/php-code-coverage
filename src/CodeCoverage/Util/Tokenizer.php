<?php

class PHP_CodeCoverage_Util_Tokenizer {
    private $filename;
    private $functions = [];
    private $classes = [];
    private $traits = [];
    private $linesOfCode = array('loc' => 0, 'cloc' => 0, 'ncloc' => 0);

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

    public function __construct($filename) {
        $this->filename = $filename;
    }

    private function tconst($token) {
        if (is_array($token)) {
            return $token[0];
        }
        return null;
    }

    private function tclass($token) {
        if (is_array($token)) {
            $name = substr(token_name($token[0]), 2);
            $text = $token[1];

            return 'PHP_Token_' . $name;
        } else {
            $text       = $token;
            return self::$customTokens[$token];
        }
    }

    private function tline($token) {
        if (isset($token[3])) {
            return $token[3];
        }
        throw new Exception('Scalar tokens are not yet precalculated');
    }

    private function tname(array $tokens, $idx) {
        $tconst = $this->tconst($tokens[$idx]);

        if ($tconst === T_REQUIRE || $tconst === T_REQUIRE_ONCE || $tconst === T_INCLUDE || $tconst === T_INCLUDE_ONCE) {
            if ($this->tconst(tokens[$idx+2]) === T_CONSTANT_ENCAPSED_STRING) {
                return trim($tokens[$idx+2], "'\"");
            }
            return null;
        }

        if ($tconst === T_FUNCTION) {
            for ($i = $idx + 1; $i < count($tokens); $i++) {
                $token = $tokens[$i];
                $tconst = $this->tconst($token);
                $tclass = $this->tclass($token);

                if ($tconst === T_STRING) {
                    $name = (string)$token;
                    break;
                } elseif ($tconst === T_STRING && $tclass === 'PHP_Token_AMPERSAND' && $this->tconst($tokens[$i+1]) === T_STRING) {
                    $name = (string)$tokens[$i+1];
                    break;
                } elseif ($tclass === 'PHP_Token_OPEN_BRACKET') {
                    $name = 'anonymous function';
                    break;
                }
            }

            if ($name != 'anonymous function') {
                for ($i = $idx; $i; --$i) {
                    $tconst = $this->tconst($tokens[$i]);
                    if ($tconst === T_NAMESPACE) {
                        $name = $this->tname($tokens, $i) . '\\' . $name;
                        break;
                    }

                    if ($tconst === T_INTERFACE) {
                        break;
                    }
                }
            }

            return $name;
        }

        if ($tconst === T_CLASS || $tconst === T_TRAIT) {
            return (string) $tokens[$idx + 2];
        }

        if ($tconst === T_NAMESPACE) {
            $namespace = (string)$tokens[$idx+2];

            for ($i = $idx + 3;; $i += 2) {
                if (isset($tokens[$i]) && $this->tconst($tokens[$i]) === T_NS_SEPARATOR) {
                    $namespace .= '\\' . $tokens[$i+1];
                } else {
                    break;
                }
            }

            return $namespace;
        }
    }

    /**
     * @return string
     */
    private function getKeywords(array $tokens, $idx) {
        $keywords = array();

        for ($i = $idx - 2; $i > $idx - 7; $i -= 2) {
            if (isset($tokens[$i])) {
                $tconst = $this->tconst($tokens[$i]);

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
        if ($this->tconst($tokens[$i+4]) === T_EXTENDS) {
            $ci         = $i + 6;
            $className = (string)$tokens[$ci];

            while (isset($tokens[$ci+1]) && !($this->tconst($tokens[$ci+1]) === T_WHITESPACE)) {
                $className .= (string)$tokens[++$ci];
            }

            $parent = $className;
        }
        return $parent;
    }

    private function getInterfaces(array $tokens, $i) {
        $interfaces = false;
        if (isset($tokens[$i + 4]) && $this->tid($tokens[$i + 4]) === T_IMPLEMENTS ||
            isset($tokens[$i + 8]) && $this->tid($tokens[$i + 8]) === T_IMPLEMENTS) {
            if ($this->tid($tokens[$i + 4]) === T_IMPLEMENTS) {
                $ii = $i + 3;
            } else {
                $ii = $i + 7;
            }

            while (!$this->tclass($tokens[$ii+1]) === 'PHP_Token_OPEN_CURLY') {
                $ii++;

                if ($this->tconst($tokens[$ii]) === T_STRING) {
                    $interfaces[] = (string)$tokens[$ii];
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
                $tconst = $this->tconst($tokens[$i]);

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

    public function tokenize() {
        $sourceCode     = file_get_contents($this->filename);
        $tokens = token_get_all($sourceCode);
        $numTokens = count($tokens);

        // precalculate in which line the tokens reside, for later lookaheads
        $line      = 1;
        for ($i = 0; $i < $numTokens; ++$i) {
            $token =& $tokens[$i];

            if (is_array($token)) {
                $name = substr(token_name($token[0]), 2);
                $text = $token[1];

                $token[2] = $line;
            } else {
                $text       = $token;
            }

            $lines          = substr_count($text, "\n");
            $line          += $lines;
        }

        $line      = 1;
        for ($i = 0; $i < $numTokens; ++$i) {
            $token = $tokens[$i];

            if (is_array($token)) {
                $name = substr(token_name($token[0]), 2);
                $text = $token[1];

                $tokenClass = 'PHP_Token_' . $name;
            } else {
                $text       = $token;
                $tokenClass = self::$customTokens[$token];
            }

            switch ($tokenClass) {
                case 'PHP_Token_HALT_COMPILER':
                    break;

                case 'PHP_Token_CLASS':
                case 'PHP_Token_TRAIT':
                    $tmp = array(
                        'methods'   => array(),
                        'parent'    => $this->getParent($tokens, $i),
                        'interfaces'=> $this->getInterfaces($tokens, $i),
                        'keywords'  => $this->getKeywords($tokens, $i),
                        'docblock'  => $token->getDocblock(),
                        'startLine' => $this->tline($token),
                        'endLine'   => $token->getEndLine(),
                        'package'   => $token->getPackage(),
                        'file'      => $this->filename
                    );

                    if ($this->tconst($token) === T_CLASS) {
                        $class                 = $this->tname($tokens, $i + 2);
                        $classEndLine          = $token->getEndLine();
                        $this->classes[$class] = $tmp;
                    } else {
                        $trait                = $this->tname($tokens, $i + 2);
                        $traitEndLine         = $token->getEndLine();
                        $this->traits[$trait] = $tmp;
                    }
                    break;

                case 'PHP_Token_FUNCTION':
                    $tname = $this->tname($tokens, $idx);
                    $tmp  = array(
                        'docblock'  => $token->getDocblock(),
                        'keywords'  => $this->getKeywords($tokens, $i),
                        'visibility'=> $this->getVisibility($tokens, $i),
                        'signature' => $token->getSignature(),
                        'startLine' => $this->tline($token),
                        'endLine'   => $token->getEndLine(),
                        'ccn'       => $token->getCCN(),
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
                    if ($classEndLine !== false && $classEndLine == $this->tline($token)) {
                        $class        = false;
                        $classEndLine = false;
                    } elseif ($traitEndLine !== false && $traitEndLine == $this->tline($token)) {
                        $trait        = false;
                        $traitEndLine = false;
                    } elseif ($interfaceEndLine !== false && $interfaceEndLine == $this->tline($token)) {
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

            $lines          = substr_count($text, "\n");
            $line          += $lines;
        }

        $this->linesOfCode['loc']   = substr_count($sourceCode, "\n");
        $this->linesOfCode['ncloc'] = $this->linesOfCode['loc'] -
        $this->linesOfCode['cloc'];
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