<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PrinterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Impressora térmica da loja, alcançada pelo agente local por rede (IP) ou pelo Windows (compartilhada). */
#[Fillable(['name', 'connection', 'address', 'port', 'columns', 'active'])]
class Printer extends Model
{
    /** @use HasFactory<PrinterFactory> */
    use Auditable, BelongsToCompany, HasFactory;

    public const NETWORK = 'network';

    public const WINDOWS = 'windows';

    public const DEFAULT_PORT = 9100;

    protected function casts(): array
    {
        return ['active' => 'boolean', 'port' => 'integer', 'columns' => 'integer'];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * Para onde o agente manda o papel.
     * A coluna "connection" é lida com getAttribute porque $this->connection é a conexão de banco do Eloquent.
     *
     * @return array{name: string, connection: string, address: string, port: int|null}
     */
    public function target(): array
    {
        $connection = $this->getAttribute('connection');

        return [
            'name' => $this->name,
            'connection' => $connection,
            'address' => $this->address,
            'port' => $connection === self::NETWORK ? ($this->port ?? self::DEFAULT_PORT) : null,
        ];
    }
}
