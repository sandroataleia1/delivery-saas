<?php

namespace App\Http\Requests\Concerns;

/** Regras de formato da escolha de tamanho, sabores e opções. As regras de negócio ficam em ItemComposer. */
trait ValidatesComposition
{
    /**
     * @param  string  $prefix  Caminho até o item (por exemplo "items.*"), ou vazio.
     * @return array<string, array<int, string>>
     */
    protected function compositionRules(string $prefix = ''): array
    {
        $prefix = $prefix === '' ? '' : rtrim($prefix, '.').'.';

        return [
            "{$prefix}size_id" => ['nullable', 'integer'],
            "{$prefix}flavor_ids" => ['nullable', 'array', 'max:4'],
            "{$prefix}flavor_ids.*" => ['integer'],
            "{$prefix}options" => ['nullable', 'array', 'max:20'],
            "{$prefix}options.*.option_id" => ['required', 'integer'],
            "{$prefix}options.*.quantity" => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }
}
