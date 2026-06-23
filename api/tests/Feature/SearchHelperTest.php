<?php

namespace Tests\Feature;

use App\Models\Producto;
use Tests\TestCase;

/**
 * Búsqueda multi-palabra (SearchHelper) — pedido de Tefy 22/6: poder buscar por palabras
 * de la descripción aunque se tecleen conectores ("cilindro DE embrague CON depósito") y
 * sin importar acentos/mayúsculas. SearchHelper es el punto central de TODOS los buscadores
 * server-side (productos, cuentas, ventas, compras, …), así que con probarlo acá se cubren todos.
 * Marcadores ZZQA* hacen los casos deterministas pese a los ~31k productos legacy de tienda_test.
 */
class SearchHelperTest extends TestCase
{
    public function test_los_conectores_no_rompen_la_busqueda_por_descripcion(): void
    {
        $this->actingAsUser();
        $p = Producto::factory()->create(['descripcion' => 'CILINDRO EMBRAGUE C/DEPOSITO ZZQA1', 'estado' => 'ON']);

        // La frase trae "de"/"con", que el producto NO contiene literalmente.
        $ids = array_column(
            $this->getJson('/api/productos?search=' . urlencode('cilindro de embrague con deposito zzqa1'))
                 ->assertStatus(200)->json('data'),
            'id'
        );
        $this->assertContains($p->id, $ids, 'Los conectores no deben forzar el AND y dejar 0 resultados');
    }

    public function test_busqueda_insensible_a_acentos(): void
    {
        $this->actingAsUser();
        $p = Producto::factory()->create(['descripcion' => 'DEPOSITO HIDRAULICO ZZQA2', 'estado' => 'ON']);

        // Tecleando CON acento debe encontrar el dato guardado SIN acento (collation utf8*_ci).
        $ids = array_column(
            $this->getJson('/api/productos?search=' . urlencode('depósito zzqa2'))
                 ->assertStatus(200)->json('data'),
            'id'
        );
        $this->assertContains($p->id, $ids, 'La búsqueda debe ser insensible a acentos');
    }

    public function test_multipalabra_sigue_exigiendo_todas_las_palabras_de_contenido(): void
    {
        $this->actingAsUser();
        // Solo "cilindro" (no "embrague"): NO debe aparecer al buscar ambas (precisión preservada).
        $soloCilindro = Producto::factory()->create(['descripcion' => 'CILINDRO MAESTRO FRENO ZZQA3', 'estado' => 'ON']);

        $ids = array_column(
            $this->getJson('/api/productos?search=' . urlencode('cilindro embrague zzqa3'))
                 ->assertStatus(200)->json('data'),
            'id'
        );
        $this->assertNotContains($soloCilindro->id, $ids, 'El AND de contenido no debe relajarse a OR');
    }

    public function test_quicksearch_encuentra_por_palabras_de_la_descripcion(): void
    {
        $this->actingAsUser();
        $p = Producto::factory()->create(['descripcion' => 'GUIA DE VALVULA ADMISION ESCAPE ZZQA4', 'estado' => 'ON']);

        $ids = array_column(
            $this->getJson('/api/productos/quicksearch?search=' . urlencode('valvula admision zzqa4'))
                 ->assertStatus(200)->json(),
            'id'
        );
        $this->assertContains($p->id, $ids, 'El buscador de agregar (quicksearch) debe matchear por descripción');
    }
}
