<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Studio;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Admin → Products: merchandise, its variants (sizes, colours) and stock. */
class ProductController extends Controller
{
    private const NEW_ROWS = 3;

    public function index(Studio $studio): View
    {
        return view('admin.products.index', [
            'products' => $studio->products()->with(['variants', 'studio'])->orderByDesc('active')->orderBy('name')->get(),
        ]);
    }

    public function create(Studio $studio): View
    {
        return view('admin.products.form', ['product' => new Product(['taxable' => true, 'active' => true]), 'newRows' => self::NEW_ROWS]);
    }

    public function store(Request $request, Studio $studio): RedirectResponse
    {
        $product = $this->save($request, $studio, new Product(['studio_id' => $studio->id]));

        return redirect()->route('studio.admin.products.edit', ['product' => $product])->with('success', 'Product saved. It’s ready to sell at the point of sale.');
    }

    public function edit(Studio $studio, Product $product): View
    {
        return view('admin.products.form', ['product' => $product->load('variants'), 'newRows' => self::NEW_ROWS]);
    }

    public function update(Request $request, Studio $studio, Product $product): RedirectResponse
    {
        $this->save($request, $studio, $product);

        return back()->with('success', 'Product saved.');
    }

    public function destroy(Studio $studio, Product $product): RedirectResponse
    {
        if (OrderItem::whereIn('product_variant_id', $product->variants()->select('id'))->exists()) {
            $product->update(['active' => false]);

            return redirect()->route('studio.admin.products.index')->with('success', "“{$product->name}” has been sold before, so it was hidden instead of deleted.");
        }
        $product->delete();

        return redirect()->route('studio.admin.products.index')->with('success', 'Product deleted.');
    }

    private function save(Request $request, Studio $studio, Product $product): Product
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'variants' => ['array'],
            'variants.*.name' => ['required', 'string', 'max:60'],
            'variants.*.sku' => ['nullable', 'string', 'max:60'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'variants.*.stock' => ['nullable', 'integer', 'between:0,100000'],
            'variants.*.remove' => ['nullable', 'boolean'],
            'new_variants' => ['array'],
            'new_variants.*.name' => ['nullable', 'string', 'max:60'],
            'new_variants.*.sku' => ['nullable', 'string', 'max:60'],
            'new_variants.*.price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'new_variants.*.stock' => ['nullable', 'integer', 'between:0,100000'],
        ], [], ['variants.*.name' => 'variant name', 'variants.*.stock' => 'stock', 'new_variants.*.stock' => 'stock']);

        $existing = $product->exists ? $product->variants()->get()->keyBy('id') : collect();
        $kept = collect($data['variants'] ?? [])->filter(fn ($row, $id) => $existing->has($id) && empty($row['remove']));
        $added = collect($data['new_variants'] ?? [])->filter(fn ($row) => filled($row['name'] ?? null) || filled($row['stock'] ?? null) || filled($row['sku'] ?? null));
        $names = $kept->pluck('name')->merge($added->map(fn ($r) => $r['name'] ?? 'One size'))->map(fn ($n) => mb_strtolower(trim((string) $n)));
        if ($names->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['variants' => 'Give each variant its own name.']);
        }

        return DB::transaction(function () use ($request, $studio, $product, $data, $existing, $kept, $added) {
            $product->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'price_cents' => Money::toCents($data['price']),
                'taxable' => $request->boolean('taxable'),
                'active' => $request->boolean('active'),
            ])->save();

            foreach ($existing as $id => $variant) {
                if (! $kept->has($id)) {
                    $variant->delete(); // past orders keep its name

                    continue;
                }
                $row = $kept[$id];
                $variant->update([
                    'name' => trim($row['name']),
                    'sku' => $row['sku'] ?? null,
                    'price_cents' => Money::toCents($row['price'] ?? null),
                    'stock' => isset($row['stock']) && $row['stock'] !== '' ? (int) $row['stock'] : null,
                ]);
            }
            $sort = (int) $existing->max('sort');
            foreach ($added as $row) {
                $product->variants()->create([
                    'studio_id' => $studio->id,
                    'name' => trim((string) ($row['name'] ?? '')) ?: 'One size',
                    'sku' => $row['sku'] ?? null,
                    'price_cents' => Money::toCents($row['price'] ?? null),
                    'stock' => isset($row['stock']) && $row['stock'] !== '' ? (int) $row['stock'] : null,
                    'sort' => ++$sort,
                ]);
            }
            if (! $product->variants()->exists()) {
                $product->variants()->create(['studio_id' => $studio->id, 'name' => 'One size']);
            }

            return $product;
        });
    }
}
