<?php

namespace mii\core;


/**
 * ErrorException represents a PHP error.
 *
 * @author Alexander Makarov <sam@rmcreative.ru>
 * @since 2.0
 */
class ErrorException extends \ErrorException
{
    /**
     * Constructs the exception.
     * @link http://php.net/manual/en/errorexception.construct.php
     * @param $message [optional]
     * @param $code [optional]
     * @param $severity [optional]
     * @param $filename [optional]
     * @param $lineno [optional]
     * @param $previous [optional]
     */
    public function __construct($message = '', $code = 0, $severity = 1, $filename = __FILE__, $lineno = __LINE__, \Exception $previous = null)
    {
        parent::__construct($message, $code, $severity, $filename, $lineno, $previous);

        if (function_exists('xdebug_get_function_stack')) {
            $trace = array_slice(array_reverse(xdebug_get_function_stack()), 3, -1);
            foreach ($trace as &$frame) {
                if (!isset($frame['function'])) {
                    $frame['function'] = 'unknown';
                }

                // XDebug < 2.1.1: http://bugs.xdebug.org/view.php?id=695
                if (!isset($frame['type']) || $frame['type'] === 'static') {
                    $frame['type'] = '::';
                } elseif ($frame['type'] === 'dynamic') {
                    $frame['type'] = '->';
                }

                // XDebug has a different key name
                if (isset($frame['params']) && !isset($frame['args'])) {
                    $frame['args'] = $frame['params'];
                }
            }


            $ref = new \ReflectionProperty('Exception', 'trace');
            $ref->setAccessible(true);
            $ref->setValue($this, $trace);
        }
    }

    /**
     * Returns if error is one of fatal type.
     *
     * @param array $error error got from error_get_last()
     * @return boolean if error is one of fatal type
     */
    public static function is_fatal_error($error)
    {
        return isset($error['type']) && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING]);
    }

    /**
     * @return string the user-friendly name of this exception
     */
    public function get_name()
    {
        static $names = [
            E_COMPILE_ERROR => 'PHP Compile Error',
            E_COMPILE_WARNING => 'PHP Compile Warning',
            E_CORE_ERROR => 'PHP Core Error',
            E_CORE_WARNING => 'PHP Core Warning',
            E_DEPRECATED => 'PHP Deprecated Warning',
            E_ERROR => 'PHP Fatal Error',
            E_NOTICE => 'PHP Notice',
            E_PARSE => 'PHP Parse Error',
            E_RECOVERABLE_ERROR => 'PHP Recoverable Error',
            E_STRICT => 'PHP Strict Warning',
            E_USER_DEPRECATED => 'PHP User Deprecated Warning',
            E_USER_ERROR => 'PHP User Error',
            E_USER_NOTICE => 'PHP User Notice',
            E_USER_WARNING => 'PHP User Warning',
            E_WARNING => 'PHP Warning',
        ];

        return isset($names[$this->getCode()]) ? $names[$this->getCode()] : 'Error';
    }
}