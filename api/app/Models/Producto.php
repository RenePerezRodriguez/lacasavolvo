<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    use HasFactory;
    protected $table = 'productos';

    protected $fillable = [
        'codigo', 'descripcion', 'marca_id', 'industria_id', 'unidad',
        'p_comp', 'p_norm', 'p_fact',
        'stock1', 'stock2', 'stock3', 'stock4', 'stock5',
        'estado',
    ];

    public function marca()
    {
        return $this->belongsTo(Marca::class);
    }

    public function industria()
    {
        return $this->belongsTo(Industria::class);
    }

    public function stockActual(int $sucursalId): int
    {
        $columna = 'stock' . $sucursalId;
        return (int) ($this->$columna ?? 0);
    }
}
