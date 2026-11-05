# Delivery SaaS

Sistema de gestão para delivery, multiempresa. Laravel 13, Vue 3, Inertia 3, Tailwind 4 e MariaDB.

## Como rodar

Requer PHP 8.3+, Node 22+ e MariaDB (o do XAMPP atende).

```sh
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

Acesse http://127.0.0.1:8000. Para alterar o frontend com recarga automática, rode `npm run dev` junto.

## Acessos de desenvolvimento

Criados pelo seeder, todos com a senha `password`. Nunca rode o seeder em produção.

| Usuário | E-mail |
| --- | --- |
| Super administrador da plataforma | super@delivery.test |
| Administrador da Pizzaria Demo | admin@delivery.test |
| Gerente | gerente@delivery.test |
| Caixa | caixa@delivery.test |

Em desenvolvimento os e-mails de redefinição de senha vão para `storage/logs/laravel.log`.

## Testes

```sh
php artisan test
```

Os testes usam SQLite em memória, então os triggers de auditoria, que existem só no MariaDB/MySQL, não são exercitados por eles.

## Convenções para novos módulos

- **Isolamento:** toda tabela de dados de empresa tem `company_id` e o modelo usa o trait `BelongsToCompany`.
- **Auditoria:** todo modelo usa o trait `Auditable`. Ações sem alteração de modelo usam `Audit::record()`, com um rótulo em `lang/pt_BR/audit.php`.
- **Permissões:** cada módulo adiciona seu grupo em `config/permissions.php` e protege as rotas com `can:`.
- **Estoque:** o saldo só muda pela ação `MoveStock`, que trava o produto e grava a movimentação. Vendas usam a ação `ConsumeProduct`, que baixa os insumos da ficha técnica ou o saldo do próprio produto.
- **Campos sensíveis:** ficam fora do `Fillable` e são atribuídos um a um no controller.
