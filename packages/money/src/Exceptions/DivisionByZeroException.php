<?php

namespace Toneflix\Money\Exceptions;

use ArithmeticError;

class DivisionByZeroException extends ArithmeticError
{
    public function __construct()
    {
        parent::__construct('Division by zero');
    }
}
