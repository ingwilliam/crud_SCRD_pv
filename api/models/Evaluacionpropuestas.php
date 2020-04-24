<?php
use Phalcon\Mvc\Model;

class Evaluacionpropuestas extends Model
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

        //hasMany 	Defines a 1-n relationship
        $this->hasMany(
            'id',
            'Evaluacioncriterios',
            'evaluacionpropuesta'
            );

    }

    /*Cesar Britto, 20-04-2020*/
    public function getEstado_nombre()
    {
          return (Estados::findFirst(" id = ". $this->estado) )->nombre;
    }
}
