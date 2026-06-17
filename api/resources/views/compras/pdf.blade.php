@php
    use App\Helpers\NumerosEnLetras;
    $total_literal = NumerosEnLetras::convertir($compra->total);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reporte Compra #{{ $compra->id }}</title>
    <style>
        @page { margin: 4mm; }
        body { font-family: Helvetica, sans-serif; font-size: 12px; color: #000; margin: 0; }
        .table { width: 100%; border-collapse: collapse; font-size: 1.2em; }
        .table-encabezado td { border: none; padding: 4px 8px; }
        .table-items { margin-top: 0; }
        .table-items thead th { border: 1px solid black; padding: 4px 6px; font-size: 0.9em; text-align: center; }
        .table-items tbody td { border-top: 1px solid black; padding: 4px 6px; vertical-align: middle; }
        .table-items tfoot td { border: 1px solid black; padding: 4px 6px; }
        .text-left { text-align: left; } .text-center { text-align: center; } .text-right { text-align: right; }
        .box { border: 1px solid black; padding: 8px 12px; text-align: center; }
        .strong { font-weight: bold; }
    </style>
</head>
<body>
    <table class="table table-encabezado">
        <tr>
            <td style="width:40%" class="text-left">
                <span class="strong">FECHA :</span> {{ $compra->fecha->format('d/m/Y') }}<br>
                <span class="strong">PROVEEDOR :</span> {{ $compra->cuenta->nombre ?? '' }}<br>
                <span class="strong">TOTAL :</span> {{ number_format($compra->total, 2) }}
                @if($compra->tipo == 'CREDITO')
                <br><span class="strong">A CUENTA :</span> {{ number_format($compra->acuenta ?? 0, 2) }}
                &nbsp; <span class="strong">SALDO :</span> {{ number_format($compra->saldo ?? 0, 2) }}
                @endif
            </td>
            <td style="width:20%; font-size:1.5em" class="text-center">
                COMPRA {{ $compra->id }}
                @if($compra->estado == 'PROFORMA')
                <br><span style="font-size:0.7em">{{ $compra->estado }}</span>
                @endif
            </td>
            <td style="width:40%">
                <div class="box" style="margin-left:40px">
                    <span class="strong">{{ $compra->sucursal->nombre ?? '' }}</span><br>
                    <span class="strong">TIPO :</span> {{ $compra->tipo }}
                    @if($compra->tipo == 'CREDITO')
                    <br><span class="strong">ESTADO :</span> {{ $compra->pagado }}
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <table class="table table-items">
        <thead>
            <tr>
                <th style="width:30px">ID</th>
                <th style="width:150px">CÓDIGO</th>
                <th style="width:150px">MARCA</th>
                <th style="width:30px">CNT</th>
                <th style="width:30px">C/U</th>
                <th style="width:60px">SUBTOTAL</th>
            </tr>
        </thead>
        <tbody>
            @foreach($detalles as $d)
            <tr>
                <td>{{ $d->producto_id }}</td>
                <td style="font-weight:bold">{{ $d->codigo }}</td>
                <td>{{ $d->marca }}</td>
                <td style="text-align:center">{{ $d->cantidad }}</td>
                <td>{{ number_format($d->costo, 2) }}</td>
                <td class="text-right" style="font-weight:bold">{{ number_format($d->costo * $d->cantidad, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td style="font-weight:bold" class="text-right" colspan="5">TOTAL</td>
                <td style="font-weight:bold" class="text-right">{{ number_format($compra->total, 2) }}</td>
            </tr>
            <tr>
                <td style="padding:5px" colspan="6" class="text-uppercase">SON {{ $total_literal }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
