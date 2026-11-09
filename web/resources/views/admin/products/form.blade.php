@extends('layouts.admin')

@section('title', $product->exists ? $product->name : 'Add a product')

@section('content')
<p class="small"><a href="{{ route('studio.admin.products.index') }}">‹ Products</a></p>
<div class="page-head">
    <h1>{{ $product->exists ? $product->name : 'Add a product' }}</h1>
    @if ($product->exists)
        <div class="actions">
            <form method="post" action="{{ route('studio.admin.products.destroy', ['product' => $product]) }}" class="inline" data-confirm="Delete {{ $product->name }}? If it’s been sold, it’s hidden instead.">
                @csrf @method('delete')
                <button class="btn btn-danger btn-small">Delete</button>
            </form>
        </div>
    @endif
</div>

@php $hasVariants = $product->exists && $product->variants->isNotEmpty(); @endphp
<form method="post" action="{{ $product->exists ? route('studio.admin.products.update', ['product' => $product]) : route('studio.admin.products.store') }}" class="form">
    @csrf
    @if ($product->exists) @method('put') @endif
    <fieldset class="fieldset form narrow">
        <legend>Product</legend>
        <x-field name="name" label="Name" :value="$product->name" required maxlength="120" placeholder="Studio Tee" />
        <x-textarea name="description" label="Description" :value="$product->description" rows="3" />
        <x-money name="price" label="Price" :value="\App\Support\Money::toAmount($product->price_cents)" :currency="$studio->currency" required hint="Before tax. A variant can have its own price." />
        <x-checkbox name="taxable" label="Charge sales tax" :checked="$product->taxable" :hint="'At '.rtrim(rtrim(number_format($studio->salesTaxPercent(), 3), '0'), '.').'%, set in Settings.'" />
        <x-checkbox name="active" label="For sale" :checked="$product->active" />
    </fieldset>

    <fieldset class="fieldset form">
        <legend>Variants and stock</legend>
        <p class="small muted" style="margin:0">Sizes or colours, each with its own stock. Leave stock empty to not track it. A product with no variants gets one, “One size”.</p>
        @error('variants')<div class="alert alert-error" style="margin:0">{{ $message }}</div>@enderror
        <div class="table-wrap">
            <table class="table apparatus-table">
                <thead><tr><th>Name</th><th>SKU</th><th>Price</th><th>In stock</th>@if ($hasVariants)<th>Remove</th>@endif</tr></thead>
                <tbody>
                @if ($hasVariants)
                    @foreach ($product->variants as $v)
                        <tr>
                            <td data-label="Name"><x-field :name="'variants['.$v->id.'][name]'" :value="$v->name" required maxlength="60" :aria-label="'Name of '.$v->name" /></td>
                            <td data-label="SKU"><x-field :name="'variants['.$v->id.'][sku]'" :value="$v->sku" maxlength="60" aria-label="SKU" /></td>
                            <td data-label="Price"><x-field :name="'variants['.$v->id.'][price]'" type="number" step="0.01" min="0" :value="\App\Support\Money::toAmount($v->price_cents)" placeholder="Same" aria-label="Price" /></td>
                            <td data-label="In stock"><x-field :name="'variants['.$v->id.'][stock]'" type="number" min="0" :value="$v->stock" placeholder="Not tracked" aria-label="In stock" /></td>
                            <td data-label="Remove"><label class="check"><input type="checkbox" name="variants[{{ $v->id }}][remove]" value="1"> <span class="sr-only">Remove {{ $v->name }}</span></label></td>
                        </tr>
                    @endforeach
                @endif
                @for ($i = 0; $i < $newRows; $i++)
                    <tr>
                        <td data-label="New variant"><x-field :name="'new_variants['.$i.'][name]'" maxlength="60" :placeholder="$hasVariants ? 'Add another' : ['Small', 'Medium', 'Large'][$i] ?? 'Add another'" aria-label="New variant name" /></td>
                        <td data-label="SKU"><x-field :name="'new_variants['.$i.'][sku]'" maxlength="60" aria-label="SKU" /></td>
                        <td data-label="Price"><x-field :name="'new_variants['.$i.'][price]'" type="number" step="0.01" min="0" placeholder="Same" aria-label="Price" /></td>
                        <td data-label="In stock"><x-field :name="'new_variants['.$i.'][stock]'" type="number" min="0" placeholder="Not tracked" aria-label="In stock" /></td>
                        @if ($hasVariants)<td></td>@endif
                    </tr>
                @endfor
                </tbody>
            </table>
        </div>
    </fieldset>
    <div class="actions"><button class="btn">{{ $product->exists ? 'Save product' : 'Create product' }}</button></div>
</form>
@endsection
