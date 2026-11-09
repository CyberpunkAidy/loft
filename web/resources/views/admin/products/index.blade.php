@extends('layouts.admin')

@section('title', 'Products')

@section('content')
<div class="page-head">
    <div>
        <h1>Products</h1>
        <p>Merchandise you sell at the front desk, with sizes or colours and stock.</p>
    </div>
    <div class="actions">
        <a class="btn btn-secondary" href="{{ route('studio.admin.pos') }}">Point of sale</a>
        <a class="btn" href="{{ route('studio.admin.products.create') }}">Add a product</a>
    </div>
</div>
@if ($products->isEmpty())
    <div class="card empty"><p>No products yet. Add T-shirts, grip aid, water bottles: anything you sell besides classes.</p></div>
@else
    <div class="card card-flush table-wrap">
        <table class="table">
            <thead><tr><th>Product</th><th>Price</th><th>Variants</th><th>In stock</th></tr></thead>
            <tbody>
            @foreach ($products as $product)
                @php $stock = $product->stock(); $low = $product->variants->where('active', true)->filter(fn ($v) => $v->isTracked() && $v->stock <= \App\Models\ProductVariant::LOW_STOCK); @endphp
                <tr @class(['is-cancelled' => ! $product->active])>
                    <td>
                        <a href="{{ route('studio.admin.products.edit', ['product' => $product]) }}"><strong>{{ $product->name }}</strong></a>
                        @unless ($product->active)<span class="badge badge-muted">Hidden</span>@endunless
                        @unless ($product->taxable)<div class="small muted">Not taxed</div>@endunless
                    </td>
                    <td>{{ $product->priceLabel() }}</td>
                    <td class="small">{{ $product->variants->pluck('name')->join(', ') }}</td>
                    <td>
                        {{ $stock ?? 'Not tracked' }}
                        @if ($low->isNotEmpty())<div class="small error-text">Low: {{ $low->map(fn ($v) => $v->name.' ('.$v->stock.')')->join(', ') }}</div>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
