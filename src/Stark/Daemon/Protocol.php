<?php
namespace Stark\Daemon;

class Protocol {
    const ERROR_SYS_PROTOCOL_VALUE = 10010;
    const ERROR_SYS_PROTOCOL_PREFIX = 10011;
    const PROTOCOL_RETRY_MAX = 5;

    /**
     * 解析指令
     * @param  string $command   指令字符串
     * @param  [type] $arguments [description]
     * @return [type]            [description]
     */
    public static function parseCommand($command, $arguments) {
        if (is_array($arguments) == false) {
            return false;
        }

        array_unshift($arguments, $command);
        return self::_parseMultiBulk($arguments);
    }

    /**
     * 往socket resource写入指令
     *     如 write($h, 'set', array('key', 'value'))
     * @param  resouce $handle    socket或者file handle
     * @param  string  $command   指令名称
     * @param  array   $arguments 指令参数数组
     * @return [type]             [description]
     */
    public static function write($handle = 0, $command = '', $arguments = array()) {
        $buffer = self::parseCommand($command, $arguments);
        return self::_writeToServer($handle, $buffer);
    }

    /**
     * 往socket resource写入指令并读取指令的响应
     *     如 writeAndRead($h, 'set', array('key', 'value'))
     * @param  resouce $handle    socket或者file handle
     * @param  string  $command   指令名称
     * @param  array   $arguments 指令参数数组
     * @return [type]             [description]
     */
    public static function writeAndRead($handle = 0, $command = '', $arguments = array()) {
        $buffer = self::parseCommand($command, $arguments);
        self::_writeToServer($handle, $buffer);
        return self::read($handle);
    }

    /**
     * 读取响应
     * @param  resource  $handle socket或者file handle
     * @param  boolean   $bulk   是否为bulk message，默认为false
     * @return string/integer/float/array 返回值
     */
    public static function read($handle = 0, $bulk = false) {
        $chunk = self::_readLineFromServer($handle);
        return self::parseResponse($chunk, $handle);
    }

    /**
     * 发送一行内容
     * @param  resouce $handle 连接fd
     * @param  string  $line    内容
     * @return [type]         [description]
     */
    public static function sendLine($handle, $line) {
        $buffer = self::_parseLine($line);
        return self::_writeToServer($handle, $buffer);
    }

    /**
     * 发送内容块
     * @param  resource $handle 连接fd
     * @param  string   $bulk   内容块字符串
     * @return [type]         [description]
     */
    public static function sendBulk($handle, $bulk) {
        $buffer = self::_parseBulk($bulk);
        return self::_writeToServer($handle, $buffer);
    }

    /**
     * 发送多块内容
     * @param  resource $handle    连接fd
     * @param  array    $multiBulk 内容块数组
     * @return [type]            [description]
     */
    public static function sendMultiBulk($handle, $multiBulk) {
        $buffer = self::_parseMultiBulk($multiBulk);
        return self::_writeToServer($handle, $buffer);
    }

    /**
     * 发送数字
     * @param  resource $handle  连接fd
     * @param  integer  $integer 数值
     * @return [type]          [description]
     */
    public static function sendInteger($handle, $integer) {
        $buffer = self::_parseInteger($integer);
        return self::_writeToServer($handle, $buffer);
    }

    /**
     * 发送错误
     * @param  resource $handle       连接fd
     * @param  string   $errorMessage 错误内容
     * @return [type]               [description]
     */
    public static function sendError($handle, $errorMessage) {
        $buffer = self::_parseError($errorMessage);
        return self::_writeToServer($handle, $buffer);
    }

    /**
     * 解析指令返回值
     * @param  string  $chunk  指令返回值
     * @param  resouce $handle socket或者file handle
     * @return [type]          [description]
     */
    public static function parseResponse($chunk, $handle) {
        if ($chunk === false || $chunk === '') {
            return false;
        }

        $prefix = $chunk[0];
        $payload = trim(substr($chunk, 1));

        switch ($prefix) {
            case '+':    // inline
                switch ($payload) {
                    case 'OK':
                        return true;

                    case 'QUEUED':
                        return 'QUEUED';

                    default:
                        return $payload;
                }

            case '$':    // bulk
                $size = intval($payload);
                if ($size === -1) return null;

                $bulk = self::_readFromServer($handle, $size + 2);
                return substr($bulk, 0, -2);

            case '*':    // multi bulk
                $count = intval($payload);
                if ($count === -1) return null;

                $multibulk = array();

                for ($i = 0; $i < $count; $i++) {
                    $multibulk[$i] = self::read($handle, true);
                }

                return $multibulk;

            case ':':    // integer
                return (int) $payload;

            case '-':    // error
                return self::_createResponseError($payload, self::ERROR_SYS_PROTOCOL_VALUE);

            default:
                return self::_createResponseError("Unknown prefix: '{$prefix}'", self::ERROR_SYS_PROTOCOL_PREFIX);
        }
    }

    private static function _parseMultiBulk($multiBulk) {
        if (is_array($multiBulk) === false) {
            return false;
        }

        $bulkCount = count($multiBulk);
        $buffer = "";

        foreach ($multiBulk as $bulk) {
            $bulkLength = strlen($bulk);

            if ($bulkLength == 0) {
                $bulkCount--;
                continue;
            }

            $buffer .= "\${$bulkLength}\r\n{$bulk}\r\n";
        }

        return "*{$bulkCount}\r\n{$buffer}";
    }

    private static function _parseLine($line) {
        return "+{$line}\r\n";
    }

    private static function _parseBulk($bulk) {
        $bulkLength = strlen($bulk);

        if ($bulkLength == 0) return "+\r\n";

        return "\${$bulkLength}\r\n{$bulk}\r\n";
    }

    private static function _parseInteger($integer) {
        $integer = intval($integer);
        return ":{$integer}\r\n";
    }

    private static function _parseError($errorMessage) {
        return "-{$errorMessage}\r\n";
    }

    private static function _writeToServer($handle, $buffer) {
        if ($handle == false) {
            return false;
        }

        $retryCount = 0;
        $length = strlen($buffer);
        $offset = 0;

        while ($offset < $length) { 
            $sent = @socket_write($handle, substr($buffer, $offset), $length - $offset); 

            if ($sent === false) {
                $errorCode = socket_last_error($handle);

                if ($errorCode === SOCKET_ECONNRESET || $errorCode === SOCKET_EPIPE) {
                    break;
                }

                if ($errorCode === SOCKET_EAGAIN || $errorCode === SOCKET_EWOULDBLOCK) {
                    if ($retryCount++ > self::PROTOCOL_RETRY_MAX) {
                        break;
                    }

                    continue;
                }

                break;
            }

            $offset += $sent; 
        }

        if ($offset < $length) {
            return false;
        }

        return $length;
    }

    private static function _readFromServer($handle, $size) {
        if ($handle == false) {
            return false;
        }

        $retryCount = 0;
        $offset = 0;
        $socketData = '';

        while ($offset < $size) {
            $buffer = socket_read($handle, $size - $offset);

            if ($buffer === false) {
                $errorCode = socket_last_error($handle);

                if ($errorCode === SOCKET_ECONNRESET || $errorCode === SOCKET_EPIPE) {
                    return false;
                }

                if ($errorCode === SOCKET_EAGAIN || $errorCode === SOCKET_EWOULDBLOCK) {
                    if ($retryCount++ > self::PROTOCOL_RETRY_MAX) {
                        return false;
                    }
                }

                continue;
            }

            if ($buffer === '') {
                return $socketData;
            }

            $retryCount = 0;
            $bufferLength = strlen($buffer);
            $offset += $bufferLength;
            $socketData .= $buffer;

            if ($bufferLength == 0) {
                break;
            }
        }

        return $socketData;
    }

    private static function _readLineFromServer($handle) {
        if ($handle == false) {
            return false;
        }
        
        $retryCount = 0;
        $buffer = '';
        $lastChar = '';

        while (true) {
            $char = socket_read($handle, 1);

            if ($char === false) {
                $errorCode = socket_last_error($handle);

                if ($errorCode === SOCKET_EAGAIN || $errorCode === SOCKET_EWOULDBLOCK) {
                    if ($retryCount++ > self::PROTOCOL_RETRY_MAX) {
                        return false;
                    }
                }

                continue;
            }

            $retryCount = 0;
            $buffer .= $char;

            if ($char === '') {
                return $buffer;
            }

            if ($char === "\n" && $lastChar == "\r") {
                return $buffer;
            }

            $lastChar = $char;
        }
    }

    private static function _createResponseError($message, $code) {
        return array(
            'error' => $message,
            'code' => $code,
        );
    }
}