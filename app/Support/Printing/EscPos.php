<?php

namespace App\Support\Printing;

use Illuminate\Support\Str;

/**
 * Monta os bytes ESC/POS de um cupom não fiscal.
 *
 * O texto sai sem acentos: cada modelo de impressora usa uma tabela de caracteres
 * diferente, e o ASCII puro imprime igual em todas.
 */
class EscPos
{
    private string $bytes = "\x1B@";

    private bool $large = false;

    public function __construct(private int $columns = 48) {}

    public function center(): static
    {
        return $this->raw("\x1Ba\x01");
    }

    public function left(): static
    {
        return $this->raw("\x1Ba\x00");
    }

    public function bold(bool $on = true): static
    {
        return $this->raw("\x1BE".($on ? "\x01" : "\x00"));
    }

    /** Letra em dobro (altura e largura): cabe metade das colunas por linha. */
    public function large(bool $on = true): static
    {
        $this->large = $on;

        return $this->raw("\x1D!".($on ? "\x11" : "\x00"));
    }

    public function line(string $text = ''): static
    {
        $width = $this->large ? intdiv($this->columns, 2) : $this->columns;
        $text = self::plain($text);

        return $this->raw(($text === '' ? '' : wordwrap($text, $width, "\n", true))."\n");
    }

    /** Texto à esquerda e valor à direita na mesma linha, quebrando o texto se não couber. */
    public function pair(string $label, string $value): static
    {
        $label = self::plain($label);
        $value = self::plain($value);
        $room = max(1, $this->columns - strlen($value) - 1);
        $lines = explode("\n", wordwrap($label, $room, "\n", true));
        $last = array_pop($lines);

        foreach ($lines as $line) {
            $this->raw($line."\n");
        }

        return $this->raw(str_pad($last, $this->columns - strlen($value)).$value."\n");
    }

    public function rule(): static
    {
        return $this->raw(str_repeat('-', $this->columns)."\n");
    }

    /** Avança o papel para passar da lâmina e corta. */
    public function cut(): static
    {
        return $this->raw("\n\n\n\x1DVB\x03");
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    private function raw(string $bytes): static
    {
        $this->bytes .= $bytes;

        return $this;
    }

    private static function plain(string $text): string
    {
        return preg_replace('/[^\x20-\x7E\n]/', '', Str::ascii($text));
    }
}
