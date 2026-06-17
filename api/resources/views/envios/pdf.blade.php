<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reporte Envío #{{ $envio->id }}</title>
    <style>
        @page { margin: 4mm; }
        body { background: none; color: black; font-size: 12px; font-family: Helvetica, Arial, sans-serif; margin: 0; }
        .table-enc { width: 100%; border-collapse: collapse; font-size: 1.2em; }
        .table-enc td { padding: 4px 8px; border: none; }
        .table-items { width: 100%; border-collapse: collapse; margin-top: 0; }
        .table-items th { border: 1px solid black; padding: 2px 6px; font-size: 0.9em; }
        .table-items td { border-top: 1px solid black; padding: 3px 6px; vertical-align: middle; }
        .box { border: 1px solid black; padding: 8px 16px; text-align: center; }
        .l { text-align: left; } .c { text-align: center; } .r { text-align: right; }
        strong { font-weight: bold; }
    </style>
</head>
<body>
    <table class="table-enc">
        <tr>
            <td width="30%" class="c">
                <strong>LA CASA VOLVO</strong><br>
                <span>Sucursal Central: Av. La Paz s/n</span><br>
                <span>Teléfono: 4563256</span><br>
                <strong>Cochabamba - Bolivia</strong>
            </td>
            <td width="40%" style="font-size:1.5em" class="c">
                ENVÍO {{ $envio->id }} - {{ $envio->sucursal->alias ?? $envio->sucursal->nombre ?? '' }}
            </td>
            <td width="30%">
                <div class="box" style="margin:0 30px">
                    <strong>ORIGEN:</strong> {{ $envio->sucursal->nombre ?? '' }}<br>
                    <strong>DESTINO:</strong> {{ $envio->cuenta->nombre ?? '' }}
                </div>
            </td>
        </tr>
        <tr>
            <td class="l"><strong>FECHA:</strong> {{ $envio->fecha->format('d/m/Y') }}</td>
            <td class="l"><strong>MEDIO:</strong> {{ $envio->medio->nombre ?? '' }}</td>
            <td class="l"><strong>MONTO:</strong> {{ number_format($envio->monto ?? 0, 2) }} [ {{ $envio->pagado ?? '' }} ]</td>
        </tr>
        <tr>
            <td class="l" colspan="3"><strong>OBSERVACIÓN:</strong> {{ $envio->observacion ?? '' }}</td>
        </tr>
    </table>

    <table class="table-items">
        <thead>
            <tr>
                <th width="30px">ID</th>
                <th width="150px">CÓDIGO</th>
                <th width="30px">CNT</th>
                <th>DESCRIPCIÓN</th>
                <th width="150px">MARCA</th>
            </tr>
        </thead>
        <tbody>
            @foreach($detalles as $d)
            <tr>
                <td>{{ $d->producto_id }}</td>
                <td style="font-weight:bold">{{ $d->codigo }}</td>
                <td class="c">{{ $d->cantidad }}</td>
                <td>{{ $d->descripcion }}</td>
                <td>{{ $d->marca }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
