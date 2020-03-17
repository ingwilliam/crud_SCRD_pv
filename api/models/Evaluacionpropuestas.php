<?php
use Phalcon\Mvc\Model;

class Evaluacionpropuestas extends Model
{
    public $id;

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
}
