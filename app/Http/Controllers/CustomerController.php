<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Rules\Cnpj;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only('search');

        return Inertia::render('customers/Index', [
            'customers' => Customer::query()
                ->withSum(['bills as open_credit' => fn ($query) => $query->where('status', 'open')], 'amount')
                ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                    fn ($query) => $query->where('name', 'like', "%$search%")->orWhere('phone', 'like', "%$search%")
                ))
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'filters' => $filters,
        ]);
    }

    /** Busca para o PDV, mesas e pedidos de entrega. */
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));

        if ($term === '') {
            return response()->json([]);
        }

        $digits = preg_replace('/\D/', '', $term);

        return response()->json(
            Customer::query()
                ->where('active', true)
                ->where(fn ($query) => $query->where('name', 'like', "%$term%")
                    ->when($digits !== '', fn ($query) => $query->orWhere('phone', 'like', "%$digits%")))
                ->with('addresses')
                ->withSum(['bills as open_credit' => fn ($query) => $query->where('status', 'open')], 'amount')
                ->orderBy('name')
                ->limit(10)
                ->get(['id', 'name', 'phone', 'allow_credit', 'credit_limit'])
        );
    }

    public function create(): Response
    {
        return Inertia::render('customers/Form');
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $customer = DB::transaction(function () use ($request) {
            $data = $this->validated($request);

            $customer = new Customer($data);
            $customer->active = true;
            $customer->save();

            $this->saveAddresses($customer, $data['addresses'] ?? []);

            return $customer;
        });

        if ($request->expectsJson()) {
            return response()->json($customer->load('addresses'));
        }

        return redirect()->route('customers.index')->with('success', 'Cliente criado.');
    }

    public function show(Customer $customer): Response
    {
        $customer->load('addresses');

        return Inertia::render('customers/Show', [
            'customer' => $customer->only('id', 'name', 'phone', 'document', 'notes', 'allow_credit', 'credit_limit', 'credit_days') + [
                'addresses' => $customer->addresses,
                'open_credit' => $customer->openCredit(),
            ],
            'bills' => $customer->bills()->latest('id')->limit(30)->get()->map(fn ($bill) => [
                'id' => $bill->id,
                'description' => $bill->fullDescription(),
                'amount' => $bill->amount,
                'due_date' => $bill->due_date->format('d/m/Y'),
                'status' => $bill->statusKey(),
                'status_label' => __("finance.status.{$bill->statusKey()}"),
            ]),
        ]);
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('customers/Form', [
            'customer' => $customer->load('addresses')->only('id', 'name', 'phone', 'document', 'notes', 'allow_credit', 'credit_limit', 'credit_days', 'addresses'),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        DB::transaction(function () use ($request, $customer) {
            $data = $this->validated($request);

            $customer->update($data);
            $customer->addresses()->delete();
            $this->saveAddresses($customer, $data['addresses'] ?? []);
        });

        return redirect()->route('customers.show', $customer)->with('success', 'Cliente atualizado.');
    }

    private function saveAddresses(Customer $customer, array $addresses): void
    {
        foreach ($addresses as $address) {
            $saved = $customer->addresses()->make($address);
            $saved->company_id = $customer->company_id;
            $saved->save();
        }
    }

    private function validated(Request $request): array
    {
        $request->merge(['document' => Cnpj::normalize($request->input('document')) ?: null]);

        // Fiado é crédito concedido: quem só cadastra clientes não mexe nisso.
        if (! $request->user()->hasPermission('sales.credit')) {
            $request->request->remove('allow_credit');
            $request->request->remove('credit_limit');
            $request->request->remove('credit_days');
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'document' => ['nullable', 'string', 'max:14'],
            'notes' => ['nullable', 'string', 'max:255'],
            'allow_credit' => ['sometimes', 'boolean'],
            'credit_limit' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'credit_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'addresses' => ['sometimes', 'array', 'max:10'],
            'addresses.*.zip_code' => ['nullable', 'string', 'max:9'],
            'addresses.*.street' => ['required', 'string', 'max:255'],
            'addresses.*.number' => ['required', 'string', 'max:20'],
            'addresses.*.complement' => ['nullable', 'string', 'max:100'],
            'addresses.*.district' => ['required', 'string', 'max:100'],
            'addresses.*.city' => ['nullable', 'string', 'max:100'],
            'addresses.*.state' => ['nullable', 'string', 'size:2'],
            'addresses.*.reference' => ['nullable', 'string', 'max:150'],
        ], [], [
            'name' => 'nome',
            'phone' => 'telefone',
            'credit_limit' => 'limite de fiado',
            'credit_days' => 'prazo',
            'addresses.*.street' => 'rua',
            'addresses.*.number' => 'número',
            'addresses.*.district' => 'bairro',
        ]);
    }
}
