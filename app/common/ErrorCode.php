<?php

namespace app\common;

class ErrorCode {

    const BUSINESS_HANDLE_ERROR = 9999;

    const MESSAGE = [
        self::BUSINESS_HANDLE_ERROR => "业务处理异常"
    ];

    public static function error(int $code): string {
        return self::MESSAGE[$code] ?? "";
    }
}