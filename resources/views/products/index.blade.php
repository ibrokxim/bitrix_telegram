<h1>Products</h1>
<ul>
    @foreach ($products['result'] as $product)
        <li>{{ $product['NAME'] }} - {{ $product['PRICE'] }}</li>
        <form action="/cart/add/{{ $product['ID'] }}" method="POST">
            @csrf
            <button type="submit">Add to Cart</button>
        </form>
@endforeach
