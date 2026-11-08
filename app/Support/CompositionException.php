<?php

namespace App\Support;

use RuntimeException;

/** Escolha inválida de um item; ItemComposer a converte em ValidationException. */
class CompositionException extends RuntimeException {}
