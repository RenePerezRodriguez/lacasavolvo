<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $pedido->sucursal->nombre ?? '' }} Pedido #{{ $pedido->id }}</title>
    <style>
        @page { margin: 4mm; }
        body { background: none; color: black; font-size: 14px; font-family: Helvetica, Arial, sans-serif; margin: 0; }
        .header { border: 1px solid black; padding: 6px 10px; margin-bottom: 6px; }
        .header .title { font-size: 16px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; border: 1px solid black; }
        th, td { border: 1px solid black; padding: 4px 6px; vertical-align: middle; font-size: 13px; }
        th { font-size: 11px; }
        .c { text-align: center; } .l { text-align: left; } .r { text-align: right; }
        .small { font-size: 11px; }
        .hist td { border: none; padding: 1px 4px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="header">
        <span class="title">PEDIDO # {{ $pedido->id }} - {{ $pedido->sucursal->nombre ?? '' }}</span><br>
        {{ $pedido->fecha->format('d/m/Y') }} <b>Observación:</b> {{ $pedido->observacion ?? '' }}
    </div>

    <table>
        <thead>
            <tr>
                <th width="5%">CNT</th>
                <th width="{{ auth()->user()->hasAnyRole(['ADMIN','GERENTE']) ? '63%' : '95%' }}">PRODUCTO</th>
                @if(auth()->user()->hasAnyRole(['ADMIN','GERENTE']))
                <th width="32%">HISTORIAL</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($detalles as $detalle)
            <tr>
                <td class="c"><b>{{ $detalle->cantidad }}</b></td>
                <td>
                    <b>{{ $detalle->codigo }}</b> - {{ $detalle->marca }} [{{ $detalle->producto_id }}]<br>
                    <span class="small">
                        @if(auth()->user()->hasAnyRole(['ADMIN','GERENTE']))
                            Compra: {{ $detalle->producto->p_comp ?? '—' }} | Normal: {{ $detalle->producto->p_norm ?? '—' }} | Fact: {{ $detalle->producto->p_fact ?? '—' }}
                        @endif
                    </span><br>
                    {{ $detalle->descripcion }}
                </td>
                @if(auth()->user()->hasAnyRole(['ADMIN','GERENTE']))
                <td>
                    <table class="hist" width="100%">
                        @foreach($historiales->get($detalle->producto_id, collect()) as $dc)
                        <tr>
                            <td class="l" width="55%">{{ $dc->nombre }}</td>
                            <td class="c" width="25%">{{ $dc->fecha }}</td>
                            <td class="r" width="20%">{{ $dc->costo }}</td>
                        </tr>
                        @endforeach
                    </table>
                </td>
                @endif
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
