<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Imagens públicas da loja online (logo e foto de produto), no disco `public`. */
class PublicImage
{
    /** Regras de validação compartilhadas pelos uploads. */
    public const RULES = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000'];

    public static function store(UploadedFile $file, int $companyId): string
    {
        return Storage::disk('public')->putFile("online/{$companyId}", $file);
    }

    public static function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    /** Apaga o arquivo antigo só depois do commit da transação em andamento. */
    public static function deleteAfterCommit(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        DB::afterCommit(fn () => Storage::disk('public')->delete($path));
    }

    /** Remove na hora um arquivo recém-gravado cuja transação falhou. */
    public static function discard(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
