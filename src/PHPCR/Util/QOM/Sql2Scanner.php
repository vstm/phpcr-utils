<?php

namespace PHPCR\Util\QOM;

use PHPCR\Query\InvalidQueryException;

/**
 * Split an SQL2 statement into string tokens. Allows lookup and fetching of tokens.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 */
class Sql2Scanner
{
    /**
     * The SQL2 query currently being parsed
     *
     * @var string
     */
    protected $sql2;

    /**
     * Token scanning result of the SQL2 string
     *
     * @var array
     */
    protected $tokens;

    /**
     * Delimiters between tokens
     *
     * @var array
     */
    protected $delimiters;

    /**
     * Parsing position in the SQL string
     *
     * @var int
     */
    protected $curpos = 0;

    /**
     * Construct a scanner with the given SQL2 statement
     *
     * @param string $sql2
     */
    public function __construct($sql2)
    {
        $this->sql2 = $sql2;
        $this->tokens = $this->scan($this->sql2);
    }

    /**
     * Get the next token without removing it from the queue.
     * Return an empty string when there are no more tokens.
     *
     * @param int $offset number of tokens to look ahead - defaults to 0, the current token
     *
     * @return string
     */
    public function lookupNextToken($offset = 0)
    {
        if ($this->curpos + $offset < count($this->tokens)) {
            return trim($this->tokens[$this->curpos + $offset]);
        }

        return '';
    }

    /**
     * Get the delimiter that separated the two previous tokens
     *
     * @return string
     */
    public function getPreviousDelimiter()
    {

        return isset($this->delimiters[$this->curpos - 1]) ? $this->delimiters[$this->curpos - 1] : ' ';
    }

    /**
     * Get the next token and remove it from the queue.
     * Return an empty string when there are no more tokens.
     *
     * @return string
     */
    public function fetchNextToken()
    {
        $token = $this->lookupNextToken();
        if ($token !== '') {
            $this->curpos += 1;
        }

        return trim($token);
    }

    /**
     * Expect the next token to be the given one and throw an exception if it's
     * not the case. The equality test is done case sensitively/insensitively
     * depending on the second parameter.
     *
     * @param string  $token            The expected token
     * @param boolean $case_insensitive
     */
    public function expectToken($token, $case_insensitive = true)
    {
        $nextToken = $this->fetchNextToken();
        if (! $this->tokenIs($nextToken, $token, $case_insensitive)) {
            throw new InvalidQueryException("Syntax error: Expected '$token', found '$nextToken' in {$this->sql2}");
        }
    }

    /**
     * Expect the next tokens to be the one given in the array of tokens and
     * throws an exception if it's not the case.
     * @see expectToken
     *
     * @param array   $tokens
     * @param boolean $case_insensitive
     */
    public function expectTokens($tokens, $case_insensitive = true)
    {
        foreach ($tokens as $token) {
            $this->expectToken($token, $case_insensitive);
        }
    }

    /**
     * Test the equality of two tokens
     *
     * @param  string  $token
     * @param  string  $value
     * @param  boolean $case_insensitive
     * @return boolean
     */
    public function tokenIs($token, $value, $case_insensitive = true)
    {
        if ($case_insensitive) {
            $test = strtoupper($token) === strtoupper($value);
        } else {
            $test = $token === $value;
        }

        return $test;
    }

    /**
     * Scan a SQL2 string a extract the tokens
     *
     * @param  string $sql2
     * @return array
     */
    protected function scan($sql2)
    {
        $tokens = array();

        $readOffset = 0;
        $sql2Length = strlen($sql2);

        // read until end-of-string
        while ($readOffset < $sql2Length) {
            // skip whitespace
            $wsLen = strspn($sql2, " \n\r\t", $readOffset);
            if ($wsLen > 0) {
                $this->delimiters[] = substr($sql2, $readOffset, $wsLen);
            }
            $readOffset += $wsLen;

            if ($readOffset >= $sql2Length) {
                break;
            }


            $currentChar = substr($sql2, $readOffset, 1);
            if (strpos('0123456789', $currentChar) !== false) {
                $readOffset = $this->scanNumber($sql2, $readOffset, $tokens);
            } elseif ($currentChar === '"' || $currentChar === '\'') {
                $readOffset = $this->scanString($sql2, $readOffset, $tokens);
            } elseif (strpos('/-(){}*,.;+%?', $currentChar) !== false) {
                ++$readOffset;
                $tokens[] = $currentChar;
            } elseif (strpos('!<>|=:', $currentChar) !== false) {
                if(($readOffset + 1) < $sql2Length &&
                    strpos('=|>', substr($sql2, $readOffset + 1, 1))) {
                    $tokens[] = substr($sql2, $readOffset, 2);
                    $readOffset += 2;
                } else {
                    ++$readOffset;
                    $tokens[] = $currentChar;
                }
            } elseif ($currentChar === '[') {
                $readOffset = $this->scanQuotedIdentifier($sql2, $readOffset, $tokens);
            } elseif ($currentChar !== '' && $currentChar !== false) {
                $readOffset = $this->scanIdentifier($sql2, $readOffset, $tokens);
            }
        }

        return $tokens;
    }

    protected function scanNumber($sql2, $readOffset, &$tokens) {
        $sql2Length = strlen($sql2);
        $newOffset = $readOffset;
        $newOffset += strspn($sql2, '0123456789', $readOffset);

        if (($newOffset + 1) < $sql2Length && '.' === substr($sql2, $newOffset, 1)) {
            ++$newOffset;
            $newOffset += strspn($sql2, '0123456789', $newOffset);
        }

        $expChar = substr($sql2, $newOffset, 1);

        if ('E' === $expChar || 'e' === $expChar) {
            ++$newOffset;
            $newOffset += strspn($sql2, '0123456789', $newOffset);
        }

        $tokens[] = substr($sql2, $readOffset, $newOffset - $readOffset);

        return $newOffset;
    }

    protected function scanString($sql2, $readOffset, &$tokens) {
        $sql2Length = strlen($sql2);

        $stringChar = substr($sql2, $readOffset++, 1);
        $result = $stringChar;
        $terminatedString = false;

        while ($readOffset < $sql2Length) {
            $current = substr($sql2, $readOffset++, 1);
            if ($stringChar === $current) {
                $result .= $current;
                $tokens[] = $result;
                $terminatedString = true;
                break;
            } elseif('\\' == $current &&
                $stringChar == substr($sql2, $readOffset, 1)) {
                $result .= substr($sql2, $readOffset, 1);
                ++$readOffset;
            } else {
                $result .= $current;
            }
        }

        if(!$terminatedString) {
            throw new InvalidQueryException("Syntax error: unterminated quoted string '{$result}' in '{$sql2}'");
        }

        return $readOffset;
    }

    protected function scanIdentifier($sql2, $readOffset, &$tokens) {
        $identifierLen = strcspn($sql2, " \n\r\t[]/-(){}*,.;+%?!<>|=:", $readOffset);

        $tokens[] = substr($sql2, $readOffset, $identifierLen);

        return $readOffset + $identifierLen;
    }

    protected function scanQuotedIdentifier($sql2, $readOffset, &$tokens) {
        $newOffset = $readOffset + 1;
        $sql2Length = strlen($sql2);

        $level = 1;
        while($newOffset < $sql2Length) {
            $newOffset += strcspn($sql2, "[]", $newOffset);

            if($newOffset < $sql2Length) {
                //++$newOffset;
                $current = substr($sql2, $newOffset, 1);

                if(']' === $current && --$level <= 0) {
                    ++$newOffset;
                    $tokens[] = substr($sql2, $readOffset, $newOffset - $readOffset);
                    break;
                } elseif('[' === $current) {
                    ++$level;
                }
            }
        }

        return $newOffset;
    }



    /**
     * Tokenize a string returned by strtok to split the string at '.', ',', '(', '='
     * and ')' characters.
     *
     * @param array  $tokens
     * @param string $token
     */
    protected function tokenize(&$tokens, $token)
    {
        $buffer = '';
        for ($i = 0; $i < strlen($token); $i++) {
            $char = trim(substr($token, $i, 1));
            if (in_array($char, array('.', ',', '(', ')', '='))) {
                if ($buffer !== '') {
                    $tokens[] = $buffer;
                    $buffer = '';
                }
                $tokens[] = $char;
            } else {
                $buffer .= $char;
            }
        }

        if ($buffer !== '') {
            $tokens[] = $buffer;
        }
    }
}
