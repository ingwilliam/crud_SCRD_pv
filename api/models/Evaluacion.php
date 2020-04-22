<?php
use Phalcon\Mvc\Model;

class Evaluacion extends Model
{
    public $id;
    /*Cesar Britto, 20-04-2020*/
    public $estado_nombre;

    public function initialize()
    {
        //Se define la relación con  n-1 con Propuestas
        $this->belongsTo(
            'propuesta',
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

    /*Cesar Britto, 20-04-2020*/
    public function getEstado_nombre()
    {
          return (Estados::findFirst(" id = ". $this->estado) )->nombre;
    }
}
