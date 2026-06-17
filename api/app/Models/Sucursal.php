<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sucursal extends Model
{
    use HasFactory;
    protected $table = 'sucursals';

    protected $fillable = [
        'nombre', 'alias', 'nit', 'direccion', 'telefono', 'email', 'supervisor', 'estado', 'ultimo_cierre',
    ];

    public function accesos()
    {
        return $this->hasMany(Acceso::class);
    }
}
