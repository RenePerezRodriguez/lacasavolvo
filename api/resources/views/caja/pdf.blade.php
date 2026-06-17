<!DOCTYPE html><html><head><meta charset="utf-8"><title>Reporte de Caja</title>
<style>body{font-family:Helvetica,sans-serif;font-size:13px;color:#0F172A;margin:20px}
h1{font-size:20px;text-align:center;margin-bottom:4px}h2{font-size:14px;text-align:center;color:#808DA7;margin-bottom:16px}
table{width:100%;border-collapse:collapse;margin-top:12px}th{background:#F8FAFC;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#808DA7;padding:8px 10px;text-align:left;border-bottom:2px solid #E2E8F0}
td{padding:8px 10px;border-bottom:1px solid #F1F5F9;font-size:12px}
.text-right{text-align:right}.total{font-size:14px;font-weight:bold;padding-top:12px;border-top:2px solid #E2E8F0;margin-top:8px;display:flex;justify-content:space-between}
.ingreso{color:#15803D}.egreso{color:#B91C1C}
</style></head><body>
<h1>REPORTE DE CAJA</h1>
<h2>{{ $desde }} — {{ $hasta }}</h2>
<table>
    <thead><tr><th>ID</th><th>Fecha</th><th>Tipo</th><th>Clase</th><th>Cuenta</th><th>Descripción</th><th class="text-right">Ingreso</th><th class="text-right">Egreso</th></tr></thead>
    <tbody>
        @foreach($movs as $m)
        <tr>
            <td>{{ $m->id }}</td><td>{{ \Carbon\Carbon::parse($m->fecha)->format('d/m/Y') }}</td>
            <td>{{ $m->tipo }}</td><td>{{ $m->clase }}</td><td>{{ $m->cuenta->nombre ?? '—' }}</td>
            <td>{{ $m->descripcion }}</td>
            <td class="text-right ingreso">{{ $m->monto_ingreso > 0 ? 'Bs. '.number_format($m->monto_ingreso,2) : '' }}</td>
            <td class="text-right egreso">{{ $m->monto_egreso > 0 ? 'Bs. '.number_format($m->monto_egreso,2) : '' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
<div class="total">
    <span class="ingreso">Ingresos: Bs. {{ number_format($ingresos,2) }}</span>
    <span class="egreso">Egresos: Bs. {{ number_format($egresos,2) }}</span>
    <span>Saldo: Bs. {{ number_format($ingresos - $egresos,2) }}</span>
</div>
</body></html>
