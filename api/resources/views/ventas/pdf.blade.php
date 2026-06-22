<!DOCTYPE html><html><head><meta charset="utf-8"><title>Venta #{{ $venta->id }}</title>
<style>body{font-family:Helvetica,sans-serif;font-size:13px;color:#0F172A;margin:20px}
h1{font-size:20px;text-align:center;margin-bottom:4px}h2{font-size:14px;text-align:center;color:#808DA7;margin-bottom:16px}
.info{margin-bottom:16px;display:flex;gap:24px}.info div{min-width:120px}.info strong{display:block;font-size:10px;text-transform:uppercase;color:#B9C4DC}
table{width:100%;border-collapse:collapse;margin-top:12px}th{background:#F8FAFC;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#808DA7;padding:8px 10px;text-align:left;border-bottom:2px solid #E2E8F0}
td{padding:8px 10px;border-bottom:1px solid #F1F5F9;font-size:12px}.text-right{text-align:right}.total{font-size:16px;font-weight:bold;text-align:right;padding-top:12px;border-top:2px solid #E2E8F0;margin-top:8px}
</style></head><body>
<h1>VENTA #{{ $venta->id }}</h1>
<h2>{{ $venta->cuenta->nombre ?? '—' }} · {{ $venta->fecha->format('d/m/Y') }}</h2>
<div class="info"><div><strong>Cliente</strong>{{ $venta->cuenta->nombre ?? '—' }}</div><div><strong>Tipo</strong>{{ $venta->tipo }}</div><div><strong>Pago</strong>{{ $venta->estado === 'VALIDO' ? $venta->pagado : '—' }}</div><div><strong>Estado</strong>{{ $venta->estado }}</div></div>
<table><thead><tr><th>Código</th><th>Descripción</th><th>Marca</th><th class="text-right">Cant.</th><th class="text-right">Precio</th><th class="text-right">Subtotal</th></tr></thead>
<tbody>@foreach($detalles as $d)<tr><td>{{ $d->codigo }}</td><td>{{ $d->descripcion }}</td><td>{{ $d->marca }}</td><td class="text-right">{{ $d->cantidad }}</td><td class="text-right">Bs. {{ number_format($d->costo,2) }}</td><td class="text-right">Bs. {{ number_format($d->subtotal,2) }}</td></tr>@endforeach</tbody></table>
<p class="total">TOTAL: Bs. {{ number_format($venta->total,2) }}</p>
</body></html>
