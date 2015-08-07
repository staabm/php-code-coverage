<?php

class Tokenizer {
    private $tokens;
    private $linesOfCode = array('loc' => 0, 'cloc' => 0, 'ncloc' => 0);

    public function __construct($filename) {
        $this->tokens = token_get_all($filename);
    }

    public function next() {
        $numTokens = count($this->tokens);

        for ($i = 0; $i < $numTokens; ++$i) {
            if (is_array($token)) {
                $name = substr(token_name($token[0]), 2);
                $text = $token[1];

                if ($lastNonWhitespaceTokenWasDoubleColon && $name == 'CLASS') {
                    $name = 'CLASS_NAME_CONSTANT';
                }

                $tokenClass = 'PHP_Token_' . $name;

            } else {
                $text       = $token;
                $tokenClass = self::$customTokens[$token];
            }

            if (false) {
                yield "class" => [];
            } else if (false) {
                yield "trait" => [];
            } else if (false) {
                yield "function" => [];
            }
        }
    }

    public function getLinesOfCode() {
        return $this->linesOfCode;
    }
}