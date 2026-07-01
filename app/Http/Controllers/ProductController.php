<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        $products = Product::query()
            ->when($request->string('search')->toString(), fn ($q, $s) => $q
                ->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Products/Index', [
            'products' => $products,
            'filters' => $request->only('search'),
            'can' => [
                'create' => $request->user()->can('create', Product::class),
            ],
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $this->authorize('create', Product::class);
        Product::create($request->validated());

        return back()->with('success', 'Номенклатура создана.');
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);
        $product->update($request->validated());

        return back()->with('success', 'Номенклатура обновлена.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);
        $product->delete();

        return back()->with('success', 'Номенклатура удалена.');
    }
}
