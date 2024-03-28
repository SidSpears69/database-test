<?php

namespace FpDbTest;

use mysqli;
use Random\RandomException;
use RuntimeException;

class Database implements DatabaseInterface
{
    const ALLOWED_TYPES = ['string', 'int', 'double', 'bool'];
    private mysqli $mysqli;
    private string $skipBlock;

    /**
     * @throws RandomException
     */
    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
        $this->skipBlock = bin2hex(random_bytes(5));
    }

    /**
     * @throws RuntimeException
     */
    public function buildQuery(string $query, array $args = []): string
    {
        if(preg_match('/\{[^{]*[{][^}]*}/', $query)) {
            throw new RuntimeException('Incorrect syntax');
        }

        if ($args) {
            preg_match_all('/\?.?/', $query, $matches);
            $parameters = array_map('trim', $matches[0]);
            foreach ($parameters as $key => $param) {
                $arg = $args[$key] ?? null;
                if ($arg === $this->skipBlock) {
                    $query =  preg_replace('/\{[^{]*[?][^}]*\}/', '', $query, 1);
                    continue;
                }
                $arg = $this->processArgument($arg, $param);
                $result = preg_replace('/' . preg_quote($param, '/') . '/', $arg, $query, 1);
                if (is_string($result)) {
                    $query = $result;
                } else {
                    throw new RuntimeException('preg_replace error');
                }
            }
        }

        return str_replace(['{', '}'], '', $query);
    }

    public function skip(): string
    {
        return $this->skipBlock;
    }

    private function processArgument($arg, $param): string
    {
        switch ($param) {
            case '?d':
                $arg = (int)$arg;
                break;
            case '?f':
                $arg = (float)$arg;
                break;
            case '?a':
                if (!is_array($arg)) {
                    throw new RuntimeException();
                }
                $arg = $this->processArrayArgument($arg);
                break;
            case '?#':
                $arg = $this->processSpecialCharacterArgument($arg);
                break;
            case '?':
                $arg = $this->processGenericArgument($arg);
                break;
            default:
                throw new RuntimeException('Placeholder not allowed');
        }

        return $arg;
    }

    private function processArrayArgument($arg): string
    {
        $that = $this;
        if ($this->isAssociativeArray($arg)) {
            return $this->processAssociativeArray($arg);
        }
        $arg = array_map(static function ($item) use ($that) {
            if(is_string($item)) {
                $item = $that->wrapString($item);
            }
            return $item;
        }, $arg);
        return implode(', ', $arg);
    }

    private function processSpecialCharacterArgument($arg): string
    {
        if (is_array($arg)) {
            return $this->processArrayItems($arg);
        }

        return $this->wrapString($arg, '`');
    }

    private function processArrayItems(array $arg): string
    {
        $that = $this;
        return implode(', ', array_map(static function ($item) use ($that) {
            return $that->wrapString($item, '`');
        }, $arg));
    }

    private function processGenericArgument($arg): string
    {
        $type = gettype($arg);
        if (!in_array($type, self::ALLOWED_TYPES)) {
            throw new RuntimeException('Argument not allowed');
        }
        if ($type === 'string') {
            $arg = $this->wrapString($arg);
        }

        return $arg;
    }

    private function wrapString($item, $wrapper = "'"): string
    {
        return $wrapper . addslahes($item) . $wrapper;
    }

    private function isAssociativeArray(array $arr): bool
    {
        $keys = array_keys($arr);
        return !empty($keys) && !is_int(end($keys));
    }

    private function processAssociativeArray(array $arg): string
    {
        $that = $this;
        $parts = array_map(static function ($name, $item) use ($that) {
            if (is_string($item)) {
                $item = $that->wrapString($item);
            } elseif (empty($item)) {
                $item = 'NULL';
            }
            return $that->wrapString($name, '`') . ' = ' . $item;
        }, array_keys($arg), $arg);

        return implode(', ', $parts);
    }
}
