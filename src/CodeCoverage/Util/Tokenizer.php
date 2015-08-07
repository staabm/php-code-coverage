<?php

class Tokenizer {
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

    public function tokenize() {
        $sourceCode     = file_get_contents($this->filename);
        $tokens = token_get_all($sourceCode);
        $numTokens = count($tokens);

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

            $token = new $tokenClass($text, $line, $this, $i);
            switch ($tokenClass) {
                case 'PHP_Token_HALT_COMPILER':
                    break;

                case 'PHP_Token_CLASS':
                case 'PHP_Token_TRAIT':
                    $tmp = array(
                        'methods'   => array(),
                        'parent'    => $token->getParent(),
                        'interfaces'=> $token->getInterfaces(),
                        'keywords'  => $token->getKeywords(),
                        'docblock'  => $token->getDocblock(),
                        'startLine' => $token->getLine(),
                        'endLine'   => $token->getEndLine(),
                        'package'   => $token->getPackage(),
                        'file'      => $this->filename
                    );

                    if ($token instanceof PHP_Token_CLASS) {
                        $class                 = (string)$tokens[$i + 2];
                        $classEndLine          = $token->getEndLine();
                        $this->classes[$class] = $tmp;
                    } else {
                        $trait                = (string)$tokens[$i + 2];
                        $traitEndLine         = $token->getEndLine();
                        $this->traits[$trait] = $tmp;
                    }
                    break;

                case 'PHP_Token_FUNCTION':
                    $name = $token->getName();
                    $tmp  = array(
                        'docblock'  => $token->getDocblock(),
                        'keywords'  => $token->getKeywords(),
                        'visibility'=> $token->getVisibility(),
                        'signature' => $token->getSignature(),
                        'startLine' => $token->getLine(),
                        'endLine'   => $token->getEndLine(),
                        'ccn'       => $token->getCCN(),
                        'file'      => $this->filename
                    );

                    if ($class === false &&
                        $trait === false &&
                        $interface === false) {
                            $this->functions[$name] = $tmp;

                            for ($line = $tmp['startLine']; $line <= $tmp['endLine']; $line++) {
                                $this->lineToFunctionMap[$line] = $name;
                            }
                        } elseif ($class !== false) {
                            $this->classes[$class]['methods'][$name] = $tmp;

                            for ($line = $tmp['startLine']; $line <= $tmp['endLine']; $line++) {
                                $this->lineToFunctionMap[$line] = $class . '::' . $name;
                            }
                        } elseif ($trait !== false) {
                            $this->traits[$trait]['methods'][$name] = $tmp;

                            for ($line = $tmp['startLine']; $line <= $tmp['endLine']; $line++) {
                                $this->lineToFunctionMap[$line] = $trait . '::' . $name;
                            }
                        } else {
                            $this->interfaces[$interface]['methods'][$name] = $tmp;
                        }
                        break;

                case 'PHP_Token_CLOSE_CURLY':
                    if ($classEndLine !== false &&
                    $classEndLine == $token->getLine()) {
                        $class        = false;
                        $classEndLine = false;
                    } elseif ($traitEndLine !== false &&
                        $traitEndLine == $token->getLine()) {
                            $trait        = false;
                            $traitEndLine = false;
                    } elseif ($interfaceEndLine !== false &&
                        $interfaceEndLine == $token->getLine()) {
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