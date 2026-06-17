<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Compra extends Model
{
    use HasFactory;
    protected $table = 'compras';
    protected $fillable = ['sucursal_id','fecha','tipo','cuenta_id','monto','descuento','total','acuenta','saldo','pagado','n_dev','estado','user_id'];

    protected $casts = ['fecha' => 'date'];

    public function sucursal() { return $this->belongsTo(Sucursal::class); }
    public function cuenta() { return $this->belongsTo(Cuenta::class); }
    public function detalles() { return $this->hasMany(Compradetalle::class); }
}

