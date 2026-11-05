<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockEntryController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store']);

    Route::get('cadastro', [RegisterController::class, 'create'])->name('register');
    Route::post('cadastro', [RegisterController::class, 'store'])
        ->middleware(['throttle:signup', 'precognitive']);

    Route::get('esqueci-senha', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('esqueci-senha', [PasswordResetController::class, 'email'])
        ->middleware('throttle:5,1')->name('password.email');
    Route::get('redefinir-senha/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('redefinir-senha', [PasswordResetController::class, 'update'])
        ->middleware('throttle:5,1')->name('password.update');
});

Route::middleware(['auth', 'account', 'password.changed'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('minha-conta', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('minha-conta', [AccountController::class, 'update'])->name('account.update');
    Route::put('minha-conta/senha', [AccountController::class, 'password'])->name('account.password');

    // Área da empresa.
    Route::middleware('company')->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::middleware('can:products.view')->group(function () {
            Route::get('produtos', [ProductController::class, 'index'])->name('products.index');
            Route::get('categorias', [CategoryController::class, 'index'])->name('categories.index');
        });
        Route::get('produtos/buscar', [ProductController::class, 'search'])->name('products.search');
        Route::middleware('can:products.manage')->group(function () {
            Route::get('produtos/novo', [ProductController::class, 'create'])->name('products.create');
            Route::post('produtos', [ProductController::class, 'store'])->name('products.store');
            Route::get('produtos/{product}/editar', [ProductController::class, 'edit'])->name('products.edit');
            Route::put('produtos/{product}', [ProductController::class, 'update'])->name('products.update');
            Route::patch('produtos/{product}/status', [ProductController::class, 'toggleStatus'])->name('products.status');

            Route::post('categorias', [CategoryController::class, 'store'])->name('categories.store');
            Route::put('categorias/{category}', [CategoryController::class, 'update'])->name('categories.update');
            Route::delete('categorias/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        });

        Route::middleware('can:suppliers.manage')->group(function () {
            Route::get('fornecedores', [SupplierController::class, 'index'])->name('suppliers.index');
            Route::get('fornecedores/novo', [SupplierController::class, 'create'])->name('suppliers.create');
            Route::post('fornecedores', [SupplierController::class, 'store'])->name('suppliers.store');
            Route::get('fornecedores/{supplier}/editar', [SupplierController::class, 'edit'])->name('suppliers.edit');
            Route::put('fornecedores/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
            Route::patch('fornecedores/{supplier}/status', [SupplierController::class, 'toggleStatus'])->name('suppliers.status');
        });

        Route::middleware('can:stock.view')->group(function () {
            Route::get('estoque', [StockController::class, 'index'])->name('stock.index');
            Route::get('estoque/movimentacoes', [StockController::class, 'movements'])->name('stock.movements');
            Route::get('entradas', [StockEntryController::class, 'index'])->name('entries.index');
        });
        Route::middleware('can:stock.adjust')->post('estoque/{product}/ajuste', [StockController::class, 'adjust'])->name('stock.adjust');
        Route::middleware('can:entries.manage')->group(function () {
            Route::get('entradas/nova', [StockEntryController::class, 'create'])->name('entries.create');
            Route::post('entradas', [StockEntryController::class, 'store'])->name('entries.store');
            Route::post('entradas/{entry}/cancelar', [StockEntryController::class, 'cancel'])->name('entries.cancel');
        });
        Route::middleware('can:stock.view')->get('entradas/{entry}', [StockEntryController::class, 'show'])->name('entries.show');

        Route::middleware('can:users.view')->get('usuarios', [UserController::class, 'index'])->name('users.index');
        Route::middleware('can:users.manage')->group(function () {
            Route::get('usuarios/novo', [UserController::class, 'create'])->name('users.create');
            Route::post('usuarios', [UserController::class, 'store'])->name('users.store');
            Route::get('usuarios/{user}/editar', [UserController::class, 'edit'])->name('users.edit');
            Route::put('usuarios/{user}', [UserController::class, 'update'])->name('users.update');
            Route::patch('usuarios/{user}/status', [UserController::class, 'toggleStatus'])->name('users.status');
        });

        Route::middleware('can:roles.view')->get('perfis', [RoleController::class, 'index'])->name('roles.index');
        Route::middleware('can:roles.manage')->group(function () {
            Route::get('perfis/novo', [RoleController::class, 'create'])->name('roles.create');
            Route::post('perfis', [RoleController::class, 'store'])->name('roles.store');
            Route::get('perfis/{role}/editar', [RoleController::class, 'edit'])->name('roles.edit');
            Route::put('perfis/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('perfis/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        });

        Route::middleware('can:audit.view')->get('auditoria', [AuditController::class, 'index'])->name('audit.index');

        Route::middleware('can:company.manage')->group(function () {
            Route::get('empresa', [CompanyController::class, 'edit'])->name('company.edit');
            Route::put('empresa', [CompanyController::class, 'update'])->name('company.update');
        });
    });

    // Painel da plataforma, só para o super administrador.
    Route::middleware('super')->prefix('admin')->name('admin.')->group(function () {
        Route::get('empresas', [AdminCompanyController::class, 'index'])->name('companies.index');
        Route::get('empresas/nova', [AdminCompanyController::class, 'create'])->name('companies.create');
        Route::post('empresas', [AdminCompanyController::class, 'store'])->name('companies.store');
        Route::get('empresas/{company}/editar', [AdminCompanyController::class, 'edit'])->name('companies.edit');
        Route::put('empresas/{company}', [AdminCompanyController::class, 'update'])->name('companies.update');
        Route::patch('empresas/{company}/status', [AdminCompanyController::class, 'toggleStatus'])->name('companies.status');

        Route::get('auditoria', [AuditController::class, 'index'])->name('audit.index');
    });
});
