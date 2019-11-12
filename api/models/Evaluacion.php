<?php
use Phalcon\Mvc\Model;

class Evaluacion extends Model
{
    public $id;

    public function initialize()
    {
        //Se define la relación con  n-1 con Propuestas
        $this->belongsTo(
            'criterio',
            'Propuestas',
            'id'
        );

        //Se define la relación con  n-1 con Convocatoriasrondascriterios
        $this->belongsTo(
            'criterio',
            'Convocatoriasrondascriterios',
            'id'
        );
    }
}
