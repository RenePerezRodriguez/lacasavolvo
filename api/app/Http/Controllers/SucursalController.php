<?php

namespace App\Http\Controllers;

use App\Exceptions\SucursalFueraDeRango;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Acceso;
use App\Models\Cuenta;
use App\Models\Apertura;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Carbon\Carbon;

class SucursalController extends Controller
{
    public function api()
    {
        return response()->json(
            Sucursal::orderBy('id')->get(['id', 'nombre', 'alias', 'nit', 'direccion', 'telefono', 'email', 'supervisor', 'estado'])
        );
    }

    /**
     * Información mínima PÚBLICA para la pantalla de login (sin autenticar).
     * Devuelve SOLO el conteo de sucursales activas — ningún dato sensible (ni nombres,
     * ni NITs, ni direcciones) porque es una vista pública. Reemplaza el "5 sucursales"
     * hardcodeado del login, que mostraba un número irreal (hay 4 activas, no 5).
     *
     * @return \Illuminate\Http\JsonResponse {"sucursales": int}
     */
    public function publicInfo()
    {
        return response()->json([
            'sucursales' => Sucursal::where('estado', 'ON')->count(),
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('sucursales.create');

        // INVARIANTE DURA: el stock vive en columnas FIJAS stock1..stock5 de
        // `productos`, accedidas como 'stock'.$sucursal_id. Una sucursal con id
        // que no tenga columna `stockN` (id > 5) rompería ventas/compras/envíos/
        // ajustes. Se bloquea con DOS capas (defensa en profundidad):
        //   (a) count() >= 5  → ya hay 5 sucursales, no caben más (chequeo barato,
        //       cubre el caso común de "crear la 6ª").
        //   (b) el id REALMENTE asignado por el INSERT > 5 → re-chequeo DENTRO de la
        //       transacción (más abajo). Cubre el caso de ids no contiguos: con
        //       filas borradas y AUTO_INCREMENT alto, `count()` y `max('id')` pueden
        //       ser < 5 mientras el próximo id es > 5. No se confía en
        //       information_schema (su AUTO_INCREMENT se cachea por conexión y puede
        //       quedar desactualizado) → se lee el id efectivo del modelo insertado.
        if (Sucursal::count() >= 5) {
            return response()->json([
                'error' => 'El sistema soporta un máximo de 5 sucursales (columnas stock1–stock5). '
                         . 'Para agregar más se debe ampliar el esquema de stock primero.',
            ], 422);
        }

        $data = $request->validate([
            'nombre' => 'required|string|max:191',
            'alias' => 'nullable|string|max:191',
            'nit' => 'nullable|string|max:191',
            'direccion' => 'nullable|string|max:191',
            'telefono' => 'nullable|string|max:191',
            'email' => 'nullable|email|max:191',
            'supervisor' => 'nullable|string|max:191',
        ]);

        // Las columnas alias/nit/direccion/telefono/email son NOT NULL sin default
        // en `sucursals`; un payload mínimo (solo `nombre`) reventaría el INSERT
        // (1364) → 500. Se rellenan con cadena vacía cuando no vienen.
        $data += [
            'alias' => '', 'nit' => '', 'direccion' => '', 'telefono' => '', 'email' => '',
        ];

        // Atomicidad: la sucursal + sus efectos colaterales (Acceso por usuario,
        // cuenta INTERNO "SUCURSAL X" que los ENVÍOS usan como destino, y la
        // apertura inicial de caja) deben crearse como un TODO. Si algo falla a
        // mitad, se revierte completo → no quedan sucursales huérfanas sin cuenta.
        try {
            $sucursal = DB::transaction(function () use ($data) {
                $sucursal = Sucursal::create($data + ['estado' => 'ON', 'ultimo_cierre' => Carbon::now()->subYear()->toDateString()]);

                // Capa (b): el id realmente asignado NUNCA puede ser > 5 (no tendría
                // columna stockN). Si lo fuera (ids no contiguos + AUTO_INCREMENT
                // alto), se aborta y la transacción REVIERTE el INSERT → no queda
                // ninguna sucursal corrupta. Se relanza como sentinela para devolver
                // un 422 limpio (no un 500).
                if ($sucursal->id > 5) {
                    throw new SucursalFueraDeRango();
                }

                foreach (User::all() as $usuario) {
                    Acceso::create([
                        'user_id' => $usuario->id,
                        'sucursal_id' => $sucursal->id,
                        'estado' => $usuario->id == 1 ? 'ON' : 'OFF',
                    ]);
                }

                // La cuenta INTERNO "SUCURSAL X" es el destino de los ENVÍOS hacia
                // esta sucursal. En la BD legacy/producción ya existe una cuenta con
                // el id de la sucursal (convención 1..5); si no existe (BD fresca), se
                // CREA para que la sucursal nunca quede sin destino de envíos.
                $cuenta = Cuenta::find($sucursal->id);
                if ($cuenta) {
                    $cuenta->update([
                        'nombre' => 'SUCURSAL ' . $sucursal->nombre,
                        'nit' => $sucursal->nit,
                        'direccion' => $sucursal->direccion,
                        'telefono' => $sucursal->telefono,
                        'estado' => 'ON',
                    ]);
                } else {
                    // `id` no está en $fillable de Cuenta → no se puede mass-assign
                    // (Eloquent lo descarta y la cuenta quedaría con otro id,
                    // rompiendo la convención cuenta.id == sucursal.id). Se fija
                    // el id explícitamente.
                    $cuenta = new Cuenta([
                        'nombre' => 'SUCURSAL ' . $sucursal->nombre,
                        'nit' => $sucursal->nit ?: '---',
                        'empresa_id' => 1,
                        'departamento' => 'COCHABAMBA',
                        'localidad_id' => 1,
                        'direccion' => $sucursal->direccion ?: '---',
                        'telefono' => $sucursal->telefono ?: '---',
                        'saldo' => 0,
                        'tipo' => 'CLIE-PROV',
                        'estado' => 'ON',
                    ]);
                    $cuenta->id = $sucursal->id;
                    $cuenta->save();
                }

                Apertura::create([
                    'sucursal_id' => $sucursal->id,
                    'fecha' => Carbon::now()->format('Y-m-d'),
                    'apertura' => 0,
                    'user_id' => Auth::id(),
                    'cerrado' => 'NO',
                    'estado' => 'ON',
                ]);

                return $sucursal;
            });
        } catch (SucursalFueraDeRango $e) {
            return response()->json([
                'error' => 'No se puede crear la sucursal: el identificador que recibiría queda fuera '
                         . 'del rango de columnas de stock (stock1–stock5). El esquema de stock debe '
                         . 'ampliarse antes de agregar más sucursales.',
            ], 422);
        }

        return response()->json(['id' => $sucursal->id]);
    }

    public function update(Request $request, Sucursal $sucursal)
    {
        Gate::authorize('sucursales.edit');
        // Anchos alineados al esquema real: TODAS las columnas de `sucursals` son
        // varchar(191). `direccion` con max:255 dejaba pasar 192..255 chars que
        // reventaban el UPDATE (1406) → 500. Se acota a max:191 (4xx limpio).
        $data = $request->validate([
            'nombre' => 'required|string|max:191',
            'alias' => 'nullable|string|max:191',
            'nit' => 'nullable|string|max:191',
            'direccion' => 'nullable|string|max:191',
            'telefono' => 'nullable|string|max:191',
            'email' => 'nullable|email|max:191',
            'supervisor' => 'nullable|string|max:191',
            'estado' => 'nullable|in:ON,OFF',
        ]);
        if ($sucursal->id == 1) {
            unset($data['estado']);
        }
        $sucursal->update($data);

        $cuenta = Cuenta::find($sucursal->id);
        if ($cuenta) {
            $cuenta->update(['nombre' => 'SUCURSAL ' . $sucursal->nombre]);
        }

        return response()->json(['ok' => true]);
    }

    public function destroy(Sucursal $sucursal)
    {
        Gate::authorize('sucursales.destroy');
        abort_if($sucursal->id == 1, 403, 'No se puede desactivar la sucursal central del sistema.');
        $sucursal->update(['estado' => 'OFF']);
        return response()->json(['ok' => true]);
    }

    public function toggle(Sucursal $sucursal)
    {
        Gate::authorize('sucursales.edit');
        abort_if($sucursal->id == 1, 403, 'No se puede desactivar la sucursal central del sistema.');
        $sucursal->estado = $sucursal->estado === 'ON' ? 'OFF' : 'ON';
        $sucursal->save();
        return response()->json(['ok' => true]);
    }
}
