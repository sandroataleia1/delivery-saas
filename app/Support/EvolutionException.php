<?php

namespace App\Support;

use RuntimeException;
use Throwable;

class EvolutionException extends RuntimeException
{
    /**
     * @param  bool  $retriable  false quando o envio pode ter chegado à Evolution (reenviar duplicaria a mensagem).
     * @param  int|null  $status  Status HTTP da resposta, quando houve resposta.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly bool $retriable = true,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
